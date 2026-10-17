<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\Migrations\AddMissingForeignKeysToExistingTables;
use Pramnos\Framework\Migrations\Auth\RepairGdprRequestsAndAuthserverForeignKeys;

/**
 * A foreign key in another database on the same MySQL server is not this database's.
 *
 * `INFORMATION_SCHEMA` spans the server. Two migrations asked it for a key by table and name
 * only, so a same-named key in any other database — a `schema:diff` reference, a copy, a
 * neighbour — read as present here, and `migrate` recorded the repair as run without adding its
 * keys. PostgreSQL's catalogue is per database and never had this.
 */
#[CoversClass(AddMissingForeignKeysToExistingTables::class)]
#[CoversClass(RepairGdprRequestsAndAuthserverForeignKeys::class)]
final class ForeignKeyLookupStaysInItsDatabaseTest extends TestCase
{
    private ?Database $db = null;

    private string $other = '';

    private string $table = '';

    protected function tearDown(): void
    {
        if ($this->db !== null && $this->other !== '') {
            $this->db->query('DROP DATABASE IF EXISTS `' . $this->other . '`');
            $this->db->query('DROP TABLE IF EXISTS `' . $this->table . '`');
            $this->db->query('DROP TABLE IF EXISTS `' . $this->table . '_parent`');
        }
    }

    /**
     * With a parent and child table and their key only in a neighbouring database, both
     * migrations find no such key in this one.
     */
    public function testAKeyInAnotherDatabaseIsNotThisOnes(): void
    {
        // Arrange
        $db           = new Database();
        $db->type     = 'mysql';
        $db->server   = 'db';
        $db->port     = 3306;
        $db->user     = 'root';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped('db not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('db not reachable: ' . $e->getMessage());
        }
        $this->db    = $db;
        $this->other = 'pfneighbour_' . bin2hex(random_bytes(3));
        $table       = 'fkl' . bin2hex(random_bytes(2));
        $this->table = $table;
        // Here: the same tables, without the key.
        $db->query('CREATE TABLE `' . $table . '_parent` (id INT PRIMARY KEY) ENGINE=InnoDB');
        $db->query('CREATE TABLE `' . $table . '` (id INT PRIMARY KEY, parent_id INT) ENGINE=InnoDB');
        // There: the tables with it.
        $db->query('CREATE DATABASE `' . $this->other . '`');
        $db->query('CREATE TABLE `' . $this->other . '`.`' . $table . '_parent` (id INT PRIMARY KEY) ENGINE=InnoDB');
        $db->query('CREATE TABLE `' . $this->other . '`.`' . $table . '` (id INT PRIMARY KEY, parent_id INT,'
            . ' CONSTRAINT `fk_' . $table . '` FOREIGN KEY (parent_id) REFERENCES `' . $this->other . '`.`' . $table . '_parent` (id)) ENGINE=InnoDB');
        $app = $this->getMockBuilder(\Pramnos\Application\Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $db;

        // Act
        $missing  = (new \ReflectionMethod(AddMissingForeignKeysToExistingTables::class, 'constraintDoesNotExist'))
            ->invoke(new AddMissingForeignKeysToExistingTables($app), $table, 'fk_' . $table);
        $exists   = (new \ReflectionMethod(RepairGdprRequestsAndAuthserverForeignKeys::class, 'constraintExists'))
            ->invoke(new RepairGdprRequestsAndAuthserverForeignKeys($app), $table, 'fk_' . $table);

        // Assert
        $this->assertTrue($missing, 'not in this database, so missing here');
        $this->assertFalse($exists);
    }
}
