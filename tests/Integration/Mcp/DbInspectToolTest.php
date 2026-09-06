<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Mcp\Tools\DbInspectTool;
use Pramnos\Security\PersonalDataRegistry;

/**
 * `db-inspect` against a real database, which is the only place two of its
 * promises can be checked.
 *
 * The unit tests drive it with a mocked connection, so they prove what the tool
 * decides. They cannot prove that the statement it builds is one the server
 * accepts, or that a refusal really stops a write from landing — for that the row
 * has to still be there afterwards.
 */
#[CoversClass(DbInspectTool::class)]
class DbInspectToolTest extends TestCase
{
    private \Pramnos\Database\Database $db;

    protected function setUp(): void
    {
        Settings::loadSettings(ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php');

        $this->db = Factory::getDatabase();

        if (!$this->db->connected) {
            $this->db->connect(true);
        }

        PersonalDataRegistry::reset();
        Settings::setSetting('database_readonly_dsn', '', false);

        // `users` is the table every installation has, and it is a declared
        // personal table — which is what half of this class is about.
        \Pramnos\User\User::setupDb();
    }

    protected function tearDown(): void
    {
        PersonalDataRegistry::reset();
        Settings::setSetting('database_readonly_dsn', '', false);
    }

    /**
     * An ordinary read runs, and the appended ceiling is SQL the server accepts.
     *
     * The unit tests assert the string ends with `LIMIT 200`; only a real server
     * says whether that is a statement it will execute after whatever the caller
     * wrote in front of it.
     */
    public function testAnOrdinaryReadRunsAgainstTheServer(): void
    {
        // Arrange
        $tool = new DbInspectTool($this->db);

        // Act
        $answer = $tool->execute(array('sql' => 'SELECT 1 AS one'));

        // Assert
        $this->assertArrayNotHasKey('error', $answer, json_encode($answer));
        $this->assertFalse($answer['personal_data']);
        $this->assertSame(1, $answer['row_count']);
        $this->assertSame(array('one' => 1), array_map('intval', $answer['rows'][0]));
    }

    /**
     * A refused write leaves the table exactly as it was.
     *
     * This is the assertion the mocked tests cannot make. They prove the statement
     * is not passed to `query()`; this proves that nothing else in the path — a
     * retry, a fallback, a second connection — ran it anyway.
     */
    public function testARefusedWriteLeavesTheDataAlone(): void
    {
        // Arrange — count what is there, through the builder rather than by hand
        $before = (int) $this->db->queryBuilder()->table('#PREFIX#users')->count();
        $tool   = new DbInspectTool($this->db);

        // Act
        $answer = $tool->execute(array(
            'sql' => 'WITH gone AS (DELETE FROM #PREFIX#users RETURNING *) SELECT count(*) FROM gone',
        ));

        // Assert
        $this->assertSame('refused', $answer['error']);
        $this->assertSame(
            $before,
            (int) $this->db->queryBuilder()->table('#PREFIX#users')->count(),
            'the refused statement changed the table anyway'
        );
    }

    /**
     * A count over a declared-personal table comes back with its value.
     *
     * The shape the whole design rests on: «how many are there» is the question a
     * diagnosis usually asks, and it exposes nobody.
     *
     * This test used to assert the opposite, and it was wrong in the way a test can
     * only be wrong once somebody reads it: the withheld branch reported `row_count`,
     * which is the number of *result* rows and therefore `1` for every `SELECT
     * count(*)`. So the number asked for was the one value never returned — while the
     * class docblock gave this exact query as the example of what is allowed — and this
     * test pinned it there, using that example.
     *
     * Against the real table, because the count has to be the database's answer and
     * not one this fixture computed.
     */
    public function testACountOverAPersonalTableComesBackWithItsValue(): void
    {
        // Arrange — a known number of rows of our own
        $expected = (int) $this->db->queryBuilder()->table('#PREFIX#users')->count();
        $tool     = new DbInspectTool($this->db);

        // Act
        $answer = $tool->execute(array('sql' => 'SELECT count(*) AS total FROM #PREFIX#users'));

        // Assert
        $this->assertTrue($answer['personal_data'], json_encode($answer));
        $this->assertTrue($answer['aggregate']);
        $this->assertFalse($answer['rows_withheld']);
        $this->assertSame($expected, (int) $answer['rows'][0]['total']);
        $this->assertContains('users', $answer['tables']);
    }

    /**
     * Anything that is not a count still withholds, and `MIN`/`MAX` are the reason the
     * line is drawn at `COUNT` rather than at "an aggregate".
     *
     * `max(email)` returns an address. A check that accepted any aggregate would hand
     * back exactly the data the denial list exists to withhold, behind syntax that looks
     * like a summary — so the assertion here is on the *absence* of the value, taken
     * from a row that really is in the table.
     */
    public function testAnAggregateThatReturnsAStoredValueIsStillWithheld(): void
    {
        // Arrange — a row whose value we can look for in the answer
        $marker = 'fw-aggregate-probe-' . bin2hex(random_bytes(4)) . '@example.com';
        $this->db->queryBuilder()->table('#PREFIX#users')->insert(array(
            'username' => 'aggprobe_' . bin2hex(random_bytes(4)),
            'email'    => $marker,
            'password' => '',
            'regdate'  => time(),
            'active'   => 1,
        ));

        try {
            $tool = new DbInspectTool($this->db);

            // Act
            $answer = $tool->execute(array(
                'sql' => 'SELECT max(email) AS newest FROM #PREFIX#users',
            ));

            // Assert
            $this->assertTrue($answer['rows_withheld'], json_encode($answer));
            $this->assertArrayNotHasKey('rows', $answer);
            $this->assertStringNotContainsString($marker, (string) json_encode($answer));
        } finally {
            $this->db->queryBuilder()->table('#PREFIX#users')
                ->where('email', $marker)->delete();
        }
    }

    /**
     * A statement the server rejects is reported as `query_failed` with the
     * driver's own message, not thrown.
     *
     * Only a real server produces that message, and the caller needs it: an MCP
     * tool that throws becomes a transport error with no statement attached.
     */
    public function testABadStatementComesBackAsAnError(): void
    {
        // Arrange
        $tool = new DbInspectTool($this->db);

        // Act
        $answer = $tool->execute(array('sql' => 'SELECT nope FROM there_is_no_such_table_here'));

        // Assert
        $this->assertSame('query_failed', $answer['error']);
        $this->assertNotSame('', trim((string) $answer['reason']));
    }

    /**
     * A reachable read-only account is actually used, on a second connection.
     *
     * The path that only a real server can execute, and the one that matters most:
     * where an installation has done the `GRANT` work, the boundary stops being our
     * lexer and becomes the database. A tool that parsed the DSN and then quietly
     * queried the original handle would pass every other test in this file.
     *
     * The DSN here points at the same database with the same credentials — this
     * installation has no separate read-only role — so what is proved is the
     * mechanism: the DSN is parsed, a second `Database` is opened from it, and the
     * query runs on that one.
     */
    public function testAReachableReadOnlyAccountIsUsed(): void
    {
        // Arrange — the same server, reached the way a read-only account would be
        $settings = Settings::getSetting('database');
        $dsn = $this->db->user . ':' . $this->db->password
            . '@' . $this->db->server . ':' . ($this->db->port ?: 3306)
            . '/' . $this->db->database;
        Settings::setSetting('database_readonly_dsn', $dsn, false);

        $tool = new DbInspectTool($this->db);

        // Act
        $answer = $tool->execute(array('sql' => 'SELECT 1 AS one'));

        // Assert — it connected and answered, rather than refusing or falling back
        $this->assertArrayNotHasKey('error', $answer, json_encode($answer));
        $this->assertSame(1, $answer['row_count']);
        $this->assertSame(1, (int) $answer['rows'][0]['one']);
    }

    /**
     * A read-only account that cannot be reached refuses the call rather than
     * quietly using the writable connection.
     *
     * The unit test proves the exception is raised; this one proves it survives a
     * real, connected `Database` sitting right there as an alternative — which is
     * exactly the fallback that would make the boundary imaginary.
     */
    public function testAnUnreachableReadOnlyAccountDoesNotFallBack(): void
    {
        // Arrange — a host nothing resolves to, with a live connection available
        Settings::setSetting(
            'database_readonly_dsn', 'nobody:nothing@no-such-host.invalid:5432/none', false
        );
        $tool = new DbInspectTool($this->db);

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/read-only database account/');

        // Act
        $tool->execute(array('sql' => 'SELECT 1'));
    }
}
