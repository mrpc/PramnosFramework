<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * Closing a connection that another instance already closed is a no-op, not an Error.
 *
 * An application can hand one link to two `Database` objects — a legacy handle and a modern one
 * over the same connection — without going through `clone`, which is the only sharing the class
 * knows about. Both then own it, and whichever destructor runs second called `mysqli_close()`
 * on a closed link: "mysqli object is already closed", a fatal logged at shutdown after the
 * response had gone out. `pg_close()` throws the same way.
 */
class ClosingASharedConnectionTwiceTest extends TestCase
{
    /** @return array<string, array{string, string, int}> */
    public static function servers(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306],
            'postgresql' => ['postgresql', 'timescaledb', 5432],
        ];
    }

    /** A connected instance, or a skip when the server is not there. */
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
     * The second close of a link two instances hold returns false and throws nothing.
     */
    #[DataProvider('servers')]
    public function testTheSecondCloseOfASharedLinkIsANoOp(string $type, string $host, int $port): void
    {
        // Arrange — a second instance handed the first one's link, as an application does
        $first  = $this->connect($type, $host, $port);
        $second = new Database();
        $second->type = $type;
        (new \ReflectionProperty(Database::class, '_dbConnection'))
            ->setValue($second, (new \ReflectionProperty(Database::class, '_dbConnection'))->getValue($first));

        // Act
        $closedFirst  = $first->close();
        $closedSecond = $second->close();

        // Assert
        $this->assertTrue($closedFirst, 'the first close closes the link');
        $this->assertFalse($closedSecond, 'the second has nothing left to close');
    }
}
