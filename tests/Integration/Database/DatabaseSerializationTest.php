<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * A connected `Database` instance survives `serialize()`/`unserialize()` without PHP's own
 * "Serialization of '...' is not allowed" fatal (FW-287).
 *
 * Defense in depth, not the actual FW-287 path (a `Model` holding `$controller`, fixed at
 * `Model::__sleep()`) — this is for a `Database` instance reaching `serialize()` by some
 * other route, present or future, without this class's connection handle being the first
 * anyone hears of it. Run against both drivers, because the report that started this named
 * `PgSql\Result` specifically, and MySQL's own connection object is exactly as unserializable.
 */
final class DatabaseSerializationTest extends TestCase
{
    /** @return array<string, array{string, string, int}> */
    public static function drivers(): array
    {
        return [
            'mysql'       => ['mysql', 'db', 3306],
            'postgresql'  => ['postgresql', 'timescaledb', 5432],
        ];
    }

    private function connected(string $type, string $host, int $port): Database
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $type === 'mysql' ? 'root' : 'postgres';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }

        return $db;
    }

    /**
     * A connected instance serializes without throwing, and comes back ready to reconnect
     * lazily — `connected` false, no connection handle carried over — rather than with a
     * connection handle `unserialize()` cannot possibly have reconstructed.
     */
    #[DataProvider('drivers')]
    public function testAConnectedInstanceSurvivesSerialization(string $type, string $host, int $port): void
    {
        // Arrange
        $db = $this->connected($type, $host, $port);
        $db->query('SELECT 1'); // leaves a queryResult / statement behind to exclude too

        // Act
        $serialized = serialize($db);
        $restored   = unserialize($serialized);

        // Assert
        $this->assertInstanceOf(Database::class, $restored);
        $this->assertFalse($restored->connected, 'comes back disconnected, not with a handle it cannot have kept');
        $this->assertSame($type, $restored->type, 'the safe config survives');
        $this->assertSame($host, $restored->server);

        $db->close();
    }
}
