<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->withAppKey();
        PersonalDataRegistry::reset();
        \Pramnos\Application\Settings::setSetting('database_readonly_dsn', '', false);
    }

    protected function tearDown(): void
    {
        $this->restoreAppKey();
        PersonalDataRegistry::reset();
        \Pramnos\Application\Settings::setSetting('database_readonly_dsn', '', false);
    }


    /** @var string|null The APP_KEY as the environment had it */
    private ?string $originalAppKey = null;

    /**
     * `database_readonly_dsn` is refused without one — see
     * {@see \Pramnos\Application\Settings::KEY_REQUIRED_SETTINGS}. A real installation
     * configuring a read-only account has a key, so a fixture that stores a DSN needs one
     * too; without it these tests would be asserting against the refusal.
     */
    private function withAppKey(): void
    {
        $this->originalAppKey = getenv('APP_KEY') === false ? null : (string) getenv('APP_KEY');
        putenv('APP_KEY=test-key-for-db-inspect');
        $_ENV['APP_KEY'] = 'test-key-for-db-inspect';
    }

    private function restoreAppKey(): void
    {
        if ($this->originalAppKey === null) {
            putenv('APP_KEY');
            unset($_ENV['APP_KEY']);

            return;
        }

        putenv('APP_KEY=' . $this->originalAppKey);
        $_ENV['APP_KEY'] = $this->originalAppKey;
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
     * A projection of nothing but `COUNT()` is answered in full, in every spelling
     * somebody actually writes.
     *
     * The promise the class docblock has always made — and it was broken, because the
     * withheld branch reported `row_count`, the number of *result* rows, which is `1`
     * for every count query. The number asked for was the one value never returned.
     */
    #[DataProvider('countProvider')]
    public function testACountOnlyProjectionIsAnswered(string $sql): void
    {
        // Arrange
        $tool = new DbInspectTool($this->db(array(array('total' => 42))));

        // Act
        $answer = $tool->execute(array('sql' => $sql));

        // Assert
        $this->assertTrue($answer['personal_data'], json_encode($answer));
        $this->assertTrue($answer['aggregate']);
        $this->assertFalse($answer['rows_withheld']);
        $this->assertSame(42, $answer['rows'][0]['total']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function countProvider(): array
    {
        return array(
            'count star'          => array('SELECT count(*) FROM usertokens'),
            'aliased'             => array('SELECT count(*) AS total FROM usertokens'),
            'alias without AS'    => array('SELECT count(*) total FROM usertokens'),
            'distinct'            => array('SELECT count(distinct userid) FROM usertokens'),
            'two counts'          => array('SELECT count(*), count(token) FROM usertokens'),
            'lower case, filtered' => array(
                'select count(*) from usertokens where token_lookup is null',
            ),
            'derived table'       => array('SELECT count(*) FROM (SELECT 1 FROM usertokens) t'),
        );
    }

    /**
     * Everything else still withholds — and the list is the reason the line is `COUNT`
     * rather than "an aggregate".
     *
     * `max(email)` returns an address and `avg(salary)` over a filter matching one row
     * returns that person's salary. A check that accepted any aggregate would have
     * handed back exactly what the denial list exists to withhold, behind syntax that
     * reads as a summary. The rest are shapes a lexer should not claim to understand.
     */
    #[DataProvider('notACountProvider')]
    public function testAnythingOtherThanACountStillWithholds(string $sql): void
    {
        // Arrange
        $tool = new DbInspectTool($this->db(array(array('value' => 'someone@example.com'))));

        // Act
        $answer = $tool->execute(array('sql' => $sql));

        // Assert
        $this->assertTrue($answer['rows_withheld'], $sql . ' was answered in full');
        $this->assertArrayNotHasKey('rows', $answer);
        $this->assertStringNotContainsString('someone@example.com', (string) json_encode($answer));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function notACountProvider(): array
    {
        return array(
            'max returns a stored value' => array('SELECT max(token) FROM usertokens'),
            'min returns a stored value' => array('SELECT min(token) FROM usertokens'),
            'avg over one row'           => array('SELECT avg(userid) FROM usertokens'),
            'sum over one row'           => array('SELECT sum(userid) FROM usertokens'),
            'a count and a column'       => array('SELECT count(*), token FROM usertokens'),
            'everything'                 => array('SELECT * FROM usertokens'),
            'a bare column'              => array('SELECT token FROM usertokens'),
            'a window function'          => array('SELECT count(*) OVER () FROM usertokens'),
            'arithmetic on the count'    => array('SELECT count(*) + 1 FROM usertokens'),
            'a subquery in the count'    => array('SELECT count((SELECT 1)) FROM usertokens'),
            'a WITH clause'              => array(
                'WITH x AS (SELECT 1 AS n) SELECT count(*) FROM usertokens',
            ),
            // The personal table is reached only from inside a subquery, so the outer
            // statement has no `FROM` of its own for the projection scan to stop at.
            // Unreadable is withheld.
            'the table only inside a subquery' => array(
                'SELECT (SELECT count(*) FROM usertokens) AS n',
            ),
            // Unbalanced parentheses: the server will refuse it too, but this method
            // must not decide it is a count on the way there.
            'parentheses that do not close' => array('SELECT count(* FROM usertokens'),
        );
    }

    /**
     * A count over an ordinary table is unaffected: it was never withheld and it does
     * not gain the aggregate flag.
     *
     * The control. A change that routed every count down the new branch would satisfy
     * everything above and quietly stop withholding personal *columns* from ordinary
     * tables.
     */
    public function testACountOverAnOrdinaryTableTakesTheOrdinaryPath(): void
    {
        // Arrange
        $tool = new DbInspectTool($this->db(array(array('total' => 7))));

        // Act
        $answer = $tool->execute(array('sql' => 'SELECT count(*) AS total FROM images'));

        // Assert
        $this->assertFalse($answer['personal_data']);
        $this->assertArrayNotHasKey('aggregate', $answer);
        $this->assertSame(7, $answer['rows'][0]['total']);
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
     * A driver handing back something that is not a row array stops the loop.
     *
     * `fields` is whatever the driver put there. A `false` or a string — which is
     * what a `pg_*` call answers on a result it cannot read — would otherwise be
     * appended as a row and reach the caller as a malformed answer, or be indexed
     * into further down.
     */
    public function testANonArrayRowStopsTheFetchLoop(): void
    {
        // Arrange — a result whose fields are not a row
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn(new class {
            public mixed $fields = 'not a row';
            private int $calls = 0;
            public function fetch(): bool
            {
                $this->calls++;
                return $this->calls < 3;
            }
        });

        // Act
        $answer = (new DbInspectTool($db))->execute(array('sql' => 'SELECT id FROM images'));

        // Assert
        $this->assertSame(0, $answer['row_count']);
        $this->assertSame(array(), $answer['rows']);
    }

    /**
     * A read-only DSN with nothing that parses as a host is refused by name.
     *
     * Separate from the connection failure below it: this one never dials, and the
     * message has to say which setting is wrong rather than report a network error
     * for a string that was never a network address.
     */
    public function testAReadOnlyDsnWithNoHostIsNamed(): void
    {
        // Arrange — a path with no authority at all
        \Pramnos\Application\Settings::setSetting('database_readonly_dsn', '/', false);
        $tool = new DbInspectTool($this->db(array()));

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/user:pass@host:port\/name/');

        // Act
        $tool->execute(array('sql' => 'SELECT 1'));
    }

    /**
     * The tool says what it is for, in the sentence a model reads when deciding
     * whether to call it — and that sentence has to mention the withholding, or a
     * model asks for rows it will not get and reports the count as a failure.
     */
    public function testItDescribesWhatItWithholds(): void
    {
        // Act
        $description = (new DbInspectTool($this->db(array())))->description();

        // Assert
        $this->assertStringContainsString('read-only', $description);
        $this->assertStringContainsString('personal data', $description);
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
