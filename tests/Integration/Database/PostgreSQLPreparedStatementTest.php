<?php

namespace Pramnos\Tests\Integration\Database;

use Pramnos\Database\Database;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Regression test: PostgreSQL "prepared statement already exists".
 *
 * When execute() is called with $free=true (the default), the PHP-side statement
 * cache is cleared via unset(). Without a corresponding DEALLOCATE on the server,
 * the second call with identical SQL hits pg_prepare() again — same md5 plan name —
 * and PostgreSQL raises "ERROR: prepared statement "plan_..." already exists".
 *
 * The fix adds @pg_query($conn, 'DEALLOCATE "..."') inside the $free block so the
 * server-side plan is removed before the PHP cache entry.
 */
#[CoversClass(Database::class)]
class PostgreSQLPreparedStatementTest extends TestCase
{
    private Database $db;
    private string $table = 'pgsql_stmt_regression';

    protected function setUp(): void
    {
        $this->db = new Database();
        $this->db->type = 'postgresql';
        $this->db->server = 'timescaledb';
        $this->db->user = 'postgres';
        $this->db->password = 'secret';
        $this->db->database = TEST_DATABASE;
        $this->db->port = 5432;

        $connected = $this->db->connect(true);
        $this->assertTrue($connected, 'Could not connect to PostgreSQL');

        $this->db->execute("DROP TABLE IF EXISTS {$this->table}");
        $this->db->execute(
            "CREATE TABLE {$this->table} (id SERIAL PRIMARY KEY, val INTEGER NOT NULL)"
        );
    }

    protected function tearDown(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS {$this->table}");
        $this->db->close();
    }

    /**
     * Execute the same parameterized INSERT twice.
     * Without DEALLOCATE the second call throws "prepared statement already exists".
     */
    public function testSameInsertSqlCanBeExecutedTwice(): void
    {
        $sql = "INSERT INTO {$this->table} (val) VALUES (%i)";

        $v1 = 1;
        $this->db->execute($sql, $v1);
        $v2 = 2;
        $this->db->execute($sql, $v2);

        $result = $this->db->execute("SELECT COUNT(*) AS cnt FROM {$this->table}");
        $this->assertEquals(2, (int)$result->fields['cnt']);
    }

    /**
     * Execute the same SELECT three times to confirm plan reuse works
     * across more than two consecutive calls.
     */
    public function testSameSelectSqlCanBeExecutedMultipleTimes(): void
    {
        $this->db->execute("INSERT INTO {$this->table} (val) VALUES (10), (20), (30)");

        $sql = "SELECT val FROM {$this->table} WHERE val > %i ORDER BY val";

        $t0 = 0;
        $r1 = $this->db->execute($sql, $t0);
        $this->assertEquals(3, $r1->numRows);

        $t10 = 10;
        $r2 = $this->db->execute($sql, $t10);
        $this->assertEquals(2, $r2->numRows);

        $t20 = 20;
        $r3 = $this->db->execute($sql, $t20);
        $this->assertEquals(1, $r3->numRows);
    }

    /**
     * Regression: execute()'s variadic used to be declared by reference (&...$arguments),
     * which PHP refuses when the caller passes a literal rather than a variable — "Argument
     * #2 could not be passed by reference". execute() never wrote back into its arguments for
     * the caller to observe, so the "&" bought nothing and broke every literal-argument call
     * on PHP 8.1+. Mixed types (bool, int, string), passed as literals, not pre-assigned to
     * variables — the exact shape that used to be a fatal error.
     */
    public function testLiteralArgumentsDoNotNeedToBePreassignedToVariables(): void
    {
        $this->db->execute("ALTER TABLE {$this->table} ADD COLUMN active BOOLEAN, ADD COLUMN label TEXT");

        $result = $this->db->execute(
            "INSERT INTO {$this->table} (val, active, label) VALUES (%i, %b, %s)",
            42,
            true,
            'literal'
        );

        $this->assertNotFalse($result);
        $row = $this->db->execute("SELECT val, active, label FROM {$this->table} WHERE val = %i", 42)->fields;
        $this->assertSame([42, true, 'literal'], [(int) $row['val'], (bool) $row['active'], $row['label']]);
    }
}
