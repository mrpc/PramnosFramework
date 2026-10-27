<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * Integration tests for `Database::withIsolationLevel()`, against both engines.
 *
 * MySQL: the session's isolation level actually changes for the duration of the callback
 * and is restored afterward — even when the callback throws. PostgreSQL: a deliberate
 * no-op (see the method's own docblock for why — no true READ UNCOMMITTED there, and no
 * lock-contention problem for it to solve in the first place), so the callback still runs
 * and its return value still comes back, but nothing about the session changes.
 *
 * Requires the Docker containers (PostgreSQL/TimescaleDB on `timescaledb`, MySQL on `db`).
 */
class WithIsolationLevelTest extends TestCase
{
    private function connect(string $type): Database
    {
        $db = new Database();

        if ($type === 'mysql') {
            $db->type     = 'mysql';
            $db->server   = 'db';
            $db->user     = 'root';
            $db->password = 'secret';
            $db->database = TEST_DATABASE;
            $db->port     = 3306;
        } else {
            $db->type     = 'postgresql';
            $db->server   = 'timescaledb';
            $db->user     = 'postgres';
            $db->password = 'secret';
            $db->database = TEST_DATABASE;
            $db->port     = 5432;
            $db->schema   = 'public';
        }

        $db->connect(true);

        return $db;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function engines(): array
    {
        return [
            'PostgreSQL' => ['postgresql'],
            'MySQL'      => ['mysql'],
        ];
    }

    private function currentLevel(Database $db): string
    {
        $sql    = $db->type === 'postgresql'
            ? 'SHOW TRANSACTION ISOLATION LEVEL'
            : 'SELECT @@SESSION.transaction_isolation AS lvl';
        $result = $db->query($sql);

        return strtoupper(str_replace(
            '-',
            ' ',
            (string) ($result->fields['transaction_isolation'] ?? $result->fields['lvl'] ?? '')
        ));
    }

    /**
     * The callback's return value comes back unchanged, on both engines.
     */
    #[DataProvider('engines')]
    public function testReturnsTheCallbacksValue(string $type): void
    {
        // Arrange
        $db = $this->connect($type);

        // Act
        $result = $db->withIsolationLevel('read uncommitted', fn () => 'the callback ran');

        // Assert
        $this->assertSame('the callback ran', $result);
    }

    /**
     * On MySQL, the session's own isolation level is actually READ UNCOMMITTED while the
     * callback runs.
     */
    public function testMySqlActuallyChangesTheSessionLevelDuringTheCallback(): void
    {
        // Arrange
        $db = $this->connect('mysql');
        $seen = null;

        // Act
        $db->withIsolationLevel('read uncommitted', function () use ($db, &$seen) {
            $seen = $this->currentLevel($db);
        });

        // Assert
        $this->assertSame('READ UNCOMMITTED', $seen);
    }

    /**
     * On MySQL, the previous level is restored once the callback returns, even when the
     * caller had already changed it away from the server default.
     */
    public function testMySqlRestoresThePreviousLevelAfterwards(): void
    {
        // Arrange
        $db = $this->connect('mysql');
        $db->query('SET SESSION TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        $this->assertSame('SERIALIZABLE', $this->currentLevel($db), 'setup: level was not actually changed');

        // Act
        $db->withIsolationLevel('read uncommitted', fn () => null);

        // Assert
        $this->assertSame('SERIALIZABLE', $this->currentLevel($db));
    }

    /**
     * The previous level is restored even when the callback throws — a lock-contention
     * workaround must not leave the session on a weaker isolation level than the caller
     * had, for every query after it.
     */
    public function testMySqlRestoresThePreviousLevelEvenWhenTheCallbackThrows(): void
    {
        // Arrange
        $db = $this->connect('mysql');
        $before = $this->currentLevel($db);

        // Act
        try {
            $db->withIsolationLevel('read uncommitted', function (): void {
                throw new \RuntimeException('boom');
            });
            $this->fail('the exception must propagate');
        } catch (\RuntimeException $ex) {
            $this->assertSame('boom', $ex->getMessage());
        }

        // Assert
        $this->assertSame($before, $this->currentLevel($db));
    }

    /**
     * PostgreSQL: the session's isolation level is untouched — the whole point being that
     * PostgreSQL's MVCC never blocks a reader on a writer at any level, so there is nothing
     * here for this method to change.
     */
    public function testPostgreSqlLeavesTheSessionLevelUntouched(): void
    {
        // Arrange
        $db = $this->connect('postgresql');
        $before = $this->currentLevel($db);
        $seen = null;

        // Act
        $db->withIsolationLevel('read uncommitted', function () use ($db, &$seen) {
            $seen = $this->currentLevel($db);
        });

        // Assert
        $this->assertSame($before, $seen, 'PostgreSQL has no true READ UNCOMMITTED; this must be a no-op');
    }

    #[DataProvider('engines')]
    public function testAnUnknownLevelThrows(string $type): void
    {
        // Arrange
        $db = $this->connect($type);

        // Act / Assert
        $this->expectException(\InvalidArgumentException::class);
        $db->withIsolationLevel('not a real level', fn () => null);
    }
}
