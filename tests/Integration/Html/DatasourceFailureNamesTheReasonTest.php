<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Html\Datatable\Datasource;

/**
 * A datatable whose query the database refuses says why (FW-165 §4).
 *
 * The message carried the SQL and «query returned no result», and nothing of the driver's reason,
 * so a list that failed on PostgreSQL — a column compared with the wrong type, a table not there
 * — said only that it had failed.
 */
#[CoversClass(Datasource::class)]
final class DatasourceFailureNamesTheReasonTest extends TestCase
{
    private ?Database $previous = null;

    /** @return array<string, array{string, string, int, string}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306, 'root'],
            'postgresql' => ['postgresql', 'timescaledb', 5432, 'postgres'],
        ];
    }

    protected function tearDown(): void
    {
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
    }

    /** A list over a table that is not there names the driver's reason, beside the SQL. */
    #[DataProvider('databases')]
    public function testTheReasonIsInTheMessage(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $user;
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $db;
        $_REQUEST       = [];

        // Act
        try {
            (new Datasource())->render('no_such_table_' . bin2hex(random_bytes(3)), ['id'], false, '', '', false);
            $message = '';
        } catch (\Throwable $e) {
            $message = $e->getMessage();
        }

        // Assert
        $this->assertStringContainsString('Error in Datasource render', $message);
        $this->assertMatchesRegularExpression('/doesn\'t exist|does not exist/', $message, 'the driver\'s reason, not only that it failed');
    }
}
