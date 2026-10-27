<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * `Database::getCurrentQuery()` — the SQL this connection last ran, after `prepare()`/the
 * query builder resolved it. Added for a consumer whose tests assert on the exact SQL a
 * dynamic `WHERE` clause built, to verify its escaping — `$currentQuery` itself was already
 * tracked, only reachable from outside the class.
 */
class GetCurrentQueryTest extends TestCase
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

    /** @return array<string, array{0: string}> */
    public static function engines(): array
    {
        return [
            'PostgreSQL' => ['postgresql'],
            'MySQL'      => ['mysql'],
        ];
    }

    /** A fresh connection has run nothing yet. */
    #[DataProvider('engines')]
    public function testInitiallyEmpty(string $type): void
    {
        // Arrange / Act / Assert
        $this->assertSame('', $this->connect($type)->getCurrentQuery());
    }

    /** After a query, it is exactly the SQL that ran — not the caller's prepare() template. */
    #[DataProvider('engines')]
    public function testReturnsTheLastQueryRun(string $type): void
    {
        // Arrange
        $db = $this->connect($type);

        // Act
        $db->query($db->prepareQuery('SELECT %d AS answer', 42));

        // Assert
        $this->assertStringContainsString('42', $db->getCurrentQuery());
        $this->assertStringNotContainsString('%d', $db->getCurrentQuery());
    }
}
