<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * A prepared plan after a failed transaction, on PostgreSQL (FW-165 §1).
 *
 * A statement that fails inside a transaction aborts it, and an aborted transaction refuses
 * everything until it is rolled back — DEALLOCATE included. The connection used to forget the
 * plan whose DEALLOCATE was refused while the server kept it, so the next prepare of that SQL
 * met «prepared statement already exists»: one application counted 1,034 such errors in its
 * suite. The plan is named after the SQL's hash, so the one on the server is the right one, and
 * it is used.
 */
#[CoversClass(Database::class)]
final class PreparedPlanAfterAbortTest extends TestCase
{
    private ?Database $db = null;

    protected function tearDown(): void
    {
        $this->db?->close();
    }

    /**
     * A statement fails inside a transaction; after the rollback, the same statement and another
     * both run, and a statement whose plan the connection kept through the abort runs again too.
     */
    public function testAFailedTransactionLeavesThePlansUsable(): void
    {
        // Arrange
        $db           = new Database();
        $db->type     = 'postgresql';
        $db->server   = 'timescaledb';
        $db->port     = 5432;
        $db->user     = 'postgres';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped('PostgreSQL not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('PostgreSQL not reachable: ' . $e->getMessage());
        }
        $this->db = $db;
        $failing  = 'SELECT 1 / %i AS v';
        $value    = static function ($result): ?int {
            if (!$result || !$result->fetch()) {
                return null;
            }

            return (int) $result->fields['v'];
        };

        // Act — the division by zero aborts the transaction; the next statement meets it aborted
        $db->startTransaction();
        $first = self::attempt(static fn () => $db->execute($failing, ...[0]));
        $inAbort = self::attempt(static fn () => $db->execute('SELECT %i AS v', ...[7]));
        $db->rollbackTransaction();
        $again   = $value(self::attempt(static fn () => $db->execute($failing, ...[1])));
        $other   = $value(self::attempt(static fn () => $db->execute('SELECT %i AS v', ...[7])));
        $twice   = $value(self::attempt(static fn () => $db->execute($failing, ...[1])));

        // Assert
        $this->assertFalse((bool) $first, 'the division by zero fails');
        $this->assertFalse((bool) $inAbort, 'an aborted transaction refuses the next statement');
        $this->assertSame(1, $again, 'after the rollback, the statement whose DEALLOCATE was refused runs');
        $this->assertSame(7, $other);
        $this->assertSame(1, $twice);
        $this->assertStringNotContainsString('already exists', (string) $db->error_text);
    }

    /** A call that may throw, as false. */
    private static function attempt(callable $call): mixed
    {
        try {
            return $call();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The order an application's log showed (FW-165 §1): a transaction opened with raw SQL, an
     * insert that fails on a duplicate inside it, its plan's DEALLOCATE refused, COMMIT; then a
     * later transaction, the same insert again. The second prepare used to collide with the plan
     * left on the server, and the collision aborted that transaction too: every user save after
     * the first failure failed.
     */
    public function testTheSameInsertInALaterTransaction(): void
    {
        // Arrange
        $db           = new Database();
        $db->type     = 'postgresql';
        $db->server   = 'timescaledb';
        $db->port     = 5432;
        $db->user     = 'postgres';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped('PostgreSQL not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('PostgreSQL not reachable: ' . $e->getMessage());
        }
        $this->db = $db;
        $table    = 'plan_abort_' . bin2hex(random_bytes(3));
        pg_query($db->getConnectionLink(), "CREATE TABLE {$table} (id INT PRIMARY KEY, email TEXT UNIQUE)");
        pg_query($db->getConnectionLink(), "INSERT INTO {$table} VALUES (1, 'taken@example.com')");
        $insert = "INSERT INTO {$table} (id, email) VALUES (%i, %s)";

        try {
            // Act
            $db->query('START TRANSACTION');
            $duplicate = self::attempt(static fn () => $db->execute($insert, ...[2, 'taken@example.com']));
            $db->query('COMMIT');
            $db->query('START TRANSACTION');
            $later = self::attempt(static fn () => $db->execute($insert, ...[3, 'new@example.com']));
            $db->query('COMMIT');
            $rows = pg_fetch_all(pg_query($db->getConnectionLink(), "SELECT id FROM {$table} ORDER BY id")) ?: [];
        } finally {
            pg_query($db->getConnectionLink(), "DROP TABLE IF EXISTS {$table}");
        }

        // Assert
        $this->assertFalse((bool) $duplicate, 'the duplicate is refused');
        $this->assertNotFalse($later, 'the later transaction\'s insert runs');
        $this->assertSame(['1', '3'], array_column($rows, 'id'), 'and its row is there');
    }

    /**
     * A plan on the server that this connection knows nothing of — whichever way it came to
     * forget it — is found inside a transaction before a prepare can collide with it, and used.
     */
    public function testAPlanTheConnectionForgotIsFoundInsideATransaction(): void
    {
        // Arrange — the plan made, then forgotten by the connection but not by the server
        $db           = new Database();
        $db->type     = 'postgresql';
        $db->server   = 'timescaledb';
        $db->port     = 5432;
        $db->user     = 'postgres';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped('PostgreSQL not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('PostgreSQL not reachable: ' . $e->getMessage());
        }
        $this->db  = $db;
        $statement = $db->prepare('SELECT %i AS v');
        (new \ReflectionProperty($db, 'statements'))->setValue($db, []);

        // Act
        $db->query('BEGIN');
        $again = $db->prepare('SELECT %i AS v');
        $result = $db->execute($again, ...[5]);
        $result->fetch();
        $db->query('COMMIT');

        // Assert
        $this->assertNotFalse($statement);
        $this->assertSame($statement->id, $again->id, 'the same plan, by its name');
        $this->assertSame(5, (int) $result->fields['v']);
    }
}
