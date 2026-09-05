<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Mcp\Tools\DbInspectTool;
use Pramnos\Security\PersonalDataRegistry;

/**
 * Asking a production database a question without opening a shell on it.
 *
 * The tool replaces SSH for one narrow purpose, and the reason that is an
 * improvement is entirely in what it refuses. So the tests below are mostly
 * refusals: a statement that writes, a table that holds people, a column that
 * looks like one, and a read-only account that is configured but broken.
 */
#[CoversClass(DbInspectTool::class)]
class DbInspectToolTest extends TestCase
{
    protected function setUp(): void
    {
        PersonalDataRegistry::reset();
        \Pramnos\Application\Settings::setSetting('database_readonly_dsn', '', false);
    }

    protected function tearDown(): void
    {
        PersonalDataRegistry::reset();
        \Pramnos\Application\Settings::setSetting('database_readonly_dsn', '', false);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * A Database whose query() returns the given rows and records the SQL it saw.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string>               $sqlLog Filled with executed SQL
     */
    private function db(array $rows, array &$sqlLog = []): Database
    {
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use ($rows, &$sqlLog): object {
                $sqlLog[] = $sql;
                return new class ($rows) {
                    public array $fields = [];
                    private int $index = 0;
                    public function __construct(private array $rows)
                    {
                    }
                    public function fetch(): bool
                    {
                        if (!isset($this->rows[$this->index])) {
                            return false;
                        }
                        $this->fields = $this->rows[$this->index];
                        $this->index++;
                        return true;
                    }
                };
            }
        );

        return $db;
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * The tool announces the scope it needs, and it is not a scope anybody has by
     * accident. A tool reachable with `mcp` alone would be handed to every token
     * the moment one is issued.
     */
    public function testItDeclaresItsOwnScope(): void
    {
        // Assert
        $tool = new DbInspectTool($this->db([]));
        $this->assertSame('db-inspect', $tool->name());
        $this->assertSame('mcp:db_read', $tool->requiredScope());
        $this->assertArrayHasKey('sql', $tool->inputSchema()['properties']);
    }

    /**
     * A statement that writes is refused before anything reaches the database.
     *
     * The assertion that matters is the empty SQL log: refusal has to happen
     * without the statement being sent, not by discarding its result.
     */
    public function testAWriteNeverReachesTheDatabase(): void
    {
        // Arrange
        $log  = [];
        $tool = new DbInspectTool($this->db([['id' => 1]], $log));

        // Act
        $answer = $tool->execute(array(
            'sql' => 'WITH gone AS (DELETE FROM images RETURNING *) SELECT count(*) FROM gone',
        ));

        // Assert
        $this->assertSame('refused', $answer['error']);
        $this->assertStringContainsStringIgnoringCase('DELETE', $answer['reason']);
        $this->assertSame([], $log, 'the statement must not be executed at all');
    }

    /**
     * A query against a declared-personal table answers with a count, not rows.
     *
     * This is the tool's central promise. «How many live tokens have no digest»
     * is answerable and exposes nobody; the rows themselves are a different
     * request, and one somebody should have to make deliberately.
     */
    public function testAPersonalTableAnswersWithACountAndNoRows(): void
    {
        // Arrange
        $tool = new DbInspectTool($this->db(array(
            array('tokenid' => 1, 'token' => 'secret-value'),
            array('tokenid' => 2, 'token' => 'another'),
        )));

        // Act
        $answer = $tool->execute(array('sql' => 'SELECT tokenid, token FROM usertokens'));

        // Assert
        $this->assertTrue($answer['personal_data']);
        $this->assertTrue($answer['rows_withheld']);
        $this->assertSame(2, $answer['row_count']);
        $this->assertSame(array('tokenid', 'token'), $answer['columns']);
        $this->assertArrayNotHasKey('rows', $answer);
        // The value itself must be nowhere in the answer, at any depth
        $this->assertStringNotContainsString('secret-value', json_encode($answer));
    }

    /**
     * A JOIN onto a personal table is enough to withhold the rows.
     *
     * Scanning `FROM` alone would return `images` rows carrying a joined-in email,
     * which is the leak the whole check exists to stop.
     */
    public function testAJoinOntoAPersonalTableCountsAsTouchingIt(): void
    {
        // Arrange
        $tool = new DbInspectTool($this->db(array(array('id' => 1))));

        // Act
        $answer = $tool->execute(array(
            'sql' => 'SELECT i.id FROM images i JOIN users u ON u.userid = i.owner',
        ));

        // Assert
        $this->assertTrue($answer['personal_data']);
        $this->assertContains('users', $answer['tables']);
    }

    /**
     * An ordinary table returns rows, with personal-looking columns emptied and
     * named.
     *
     * The column stays in the answer so the caller can see that something was
     * withheld rather than wonder why the shape is wrong.
     */
    public function testPersonalColumnsAreWithheldFromAnOrdinaryTable(): void
    {
        // Arrange — `invoices` is nobody's declared table
        $tool = new DbInspectTool($this->db(array(
            array('id' => 7, 'billing_email' => 'someone@example.com', 'total' => 12),
        )));

        // Act
        $answer = $tool->execute(array('sql' => 'SELECT id, billing_email, total FROM invoices'));

        // Assert
        $this->assertFalse($answer['personal_data']);
        $this->assertSame(array('billing_email'), $answer['columns_withheld']);
        $this->assertSame('[withheld]', $answer['rows'][0]['billing_email']);
        $this->assertSame(12, $answer['rows'][0]['total'], 'ordinary columns are untouched');
        $this->assertStringNotContainsString('someone@example.com', json_encode($answer));
    }

    /**
     * An application's own declaration is honoured, not just the framework's.
     *
     * This is the path an app.php `personal_data` block takes, and without it the
     * denial list would only ever cover the framework's twenty tables.
     */
    public function testAnApplicationDeclarationIsHonoured(): void
    {
        // Arrange
        PersonalDataRegistry::loadFromConfig(array('tables' => array('invoices')));
        $tool = new DbInspectTool($this->db(array(array('id' => 7, 'total' => 12))));

        // Act
        $answer = $tool->execute(array('sql' => 'SELECT id, total FROM invoices'));

        // Assert
        $this->assertTrue($answer['personal_data']);
    }

    /**
     * A ceiling is appended when the caller wrote none, and left alone when they
     * did — `LIMIT 5` means five, and rewriting it would answer a different
     * question than the one asked.
     */
    public function testTheRowCeiling(): void
    {
        // Arrange
        $log  = [];
        $tool = new DbInspectTool($this->db(array(), $log));

        // Act
        $tool->execute(array('sql' => 'SELECT id FROM images'));
        $tool->execute(array('sql' => 'SELECT id FROM images LIMIT 5'));

        // Assert
        $this->assertStringEndsWith('LIMIT ' . DbInspectTool::MAX_ROWS, $log[0]);
        $this->assertSame('SELECT id FROM images LIMIT 5', $log[1]);
    }

    /**
     * The fetch loop stops at the ceiling however many rows the driver offers.
     *
     * `LIMIT 100000` in the caller's own statement suppresses the appended limit,
     * so this loop is the only thing standing between a diagnostic question and a
     * whole table arriving over the transport.
     */
    public function testTheFetchLoopStopsAtTheCeilingEvenWithTheCallersOwnLimit(): void
    {
        // Arrange — more rows available than the ceiling allows
        $rows = array_map(fn(int $i): array => array('id' => $i), range(1, DbInspectTool::MAX_ROWS + 50));
        $tool = new DbInspectTool($this->db($rows));

        // Act
        $answer = $tool->execute(array('sql' => 'SELECT id FROM images LIMIT 100000'));

        // Assert
        $this->assertSame(DbInspectTool::MAX_ROWS, $answer['row_count']);
        $this->assertTrue($answer['truncated']);
    }

    /**
     * A requested limit above the ceiling is clamped, and one below it is
     * respected.
     */
    public function testARequestedLimitIsClamped(): void
    {
        // Arrange
        $rows = array_map(fn(int $i): array => array('id' => $i), range(1, 20));
        $tool = new DbInspectTool($this->db($rows));

        // Act
        $clamped   = $tool->execute(array('sql' => 'SELECT id FROM images', 'limit' => 9999));
        $respected = $tool->execute(array('sql' => 'SELECT id FROM images', 'limit' => 3));

        // Assert
        $this->assertSame(20, $clamped['row_count']);
        $this->assertSame(3, $respected['row_count']);
    }

    /**
     * A failing query answers with the driver's message rather than throwing.
     *
     * An MCP tool that throws becomes a transport-level error with no statement
     * attached; the caller needs to know it was their SQL.
     */
    public function testAFailingQueryIsReportedNotThrown(): void
    {
        // Arrange
        $db = $this->createMock(Database::class);
        $db->method('query')->willThrowException(new \RuntimeException('relation does not exist'));
        $tool = new DbInspectTool($db);

        // Act
        $answer = $tool->execute(array('sql' => 'SELECT id FROM nope'));

        // Assert
        $this->assertSame('query_failed', $answer['error']);
        $this->assertStringContainsString('relation does not exist', $answer['reason']);
    }

    /**
     * A driver returning nothing at all is reported rather than read as an empty
     * result — `pg_query()` answers `false` where mysqli throws, so this branch is
     * the PostgreSQL half of the same failure.
     */
    public function testADriverReturningNothingIsReported(): void
    {
        // Arrange
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn(null);
        $tool = new DbInspectTool($db);

        // Act
        $answer = $tool->execute(array('sql' => 'SELECT id FROM images'));

        // Assert
        $this->assertSame('query_failed', $answer['error']);
    }

    /**
     * A configured but unusable read-only account is an error, never a silent
     * fall back to the writable connection.
     *
     * This is the failure where somebody believes they have a boundary and does
     * not — the worst of the three outcomes, because it is invisible.
     */
    public function testABrokenReadOnlyAccountRefusesRatherThanDowngrades(): void
    {
        // Arrange
        \Pramnos\Application\Settings::setSetting(
            'database_readonly_dsn', 'not-a-dsn', false
        );
        $tool = new DbInspectTool($this->db(array(array('id' => 1))));

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/read-only database account/');

        // Act
        $tool->execute(array('sql' => 'SELECT id FROM images'));
    }

    /**
     * With no read-only account configured the ordinary connection is used, which
     * is the supported arrangement: setting one up is real work, and an
     * installation may reasonably decide its developers are trusted.
     */
    public function testNoReadOnlyAccountUsesTheOrdinaryConnection(): void
    {
        // Arrange
        $log  = [];
        $tool = new DbInspectTool($this->db(array(array('id' => 1)), $log));

        // Act
        $answer = $tool->execute(array('sql' => 'SELECT id FROM images'));

        // Assert
        $this->assertCount(1, $log);
        $this->assertSame(1, $answer['row_count']);
    }

    /**
     * An empty result set is an empty answer, not a crash — `columns` has nothing
     * to read off when there is no first row.
     */
    public function testAnEmptyResultIsHandled(): void
    {
        // Arrange
        $tool = new DbInspectTool($this->db(array()));

        // Act
        $ordinary = $tool->execute(array('sql' => 'SELECT id FROM images'));
        $personal = $tool->execute(array('sql' => 'SELECT userid FROM users'));

        // Assert
        $this->assertSame(0, $ordinary['row_count']);
        $this->assertSame(array(), $ordinary['rows']);
        $this->assertSame(array(), $personal['columns']);
    }
}
