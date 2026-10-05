<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * A cloned `Database` does not close the connection it shares with the original.
 *
 * `clone` copies the link handle, not the connection. Both objects then closed the same link:
 * destroying the clone disconnected the original, and the second close raised «mysqli
 * object is already closed» from a destructor — which is how it surfaced, as PHPUnit dying
 * between two unrelated tests long after the clone was taken. Real connections are used,
 * because the bug is in what the driver does with a closed link.
 */
#[CoversClass(Database::class)]
class DatabaseCloneOwnershipTest extends TestCase
{
    /** @return array<string, array{string, string, int}> */
    public static function databases(): array
    {
        return [
            'mysql'       => ['mysql', 'db', 3306],
            'timescaledb' => ['postgresql', 'timescaledb', 5432],
        ];
    }

    /**
     * A connected database, or a skipped test when the container is not there.
     */
    private function connect(string $type, string $host, int $port): Database
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }

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
     * Destroying a clone leaves the original connected and working.
     */
    #[DataProvider('databases')]
    public function testDestroyingACloneDoesNotDisconnectTheOriginal(string $type, string $host, int $port): void
    {
        // Arrange
        $db    = $this->connect($type, $host, $port);
        $clone = clone $db;
        // The clone can use the shared link while it exists.
        $this->assertSame(1, (int) $clone->query('SELECT 1 AS one')->fields['one']);

        // Act — the clone goes out of scope; its destructor runs here
        unset($clone);

        // Assert — the original's link is still open
        $this->assertSame(1, (int) $db->query('SELECT 1 AS one')->fields['one']);
        $this->assertTrue($db->connected);
    }

    /**
     * Destroying the original and then the clone raises nothing.
     *
     * The order that crashed: the original closed the link, then the clone's destructor
     * tried to close it again.
     */
    #[DataProvider('databases')]
    public function testDestroyingBothRaisesNothing(string $type, string $host, int $port): void
    {
        // Arrange
        $db    = $this->connect($type, $host, $port);
        $clone = clone $db;

        // Act — original first, clone second
        unset($db);
        $clone->close();
        unset($clone);

        // Assert — reaching here is the point: neither close threw
        $this->addToAssertionCount(1);
    }

    /**
     * A clone that opens its own connection owns that one and closes it.
     *
     * Not owning the inherited link must not turn into never closing anything: after
     * `connect()` the link is the clone's own, and the original's is untouched by it.
     */
    #[DataProvider('databases')]
    public function testACloneThatReconnectsOwnsItsNewConnection(string $type, string $host, int $port): void
    {
        // Arrange
        $db    = $this->connect($type, $host, $port);
        $clone = clone $db;

        // Act — a connection of its own, then closed
        $clone->connect(false);
        $closed = $clone->close();

        // Assert — the clone really closed its own link, and the original's still works
        $this->assertTrue($closed, 'a clone that reconnected must close what it opened');
        $this->assertSame(1, (int) $db->query('SELECT 1 AS one')->fields['one']);
    }
}
