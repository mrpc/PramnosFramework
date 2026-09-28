<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Framework\Testing\Connection;

/**
 * Two databases behind one cache do not answer for each other.
 *
 * The column cache was keyed by table name with the prefix resolved and **the database
 * not**, so one installation's test and dev databases — or two installations on one Redis —
 * shared a column list per table name.
 *
 * Measured rather than imagined: a model saved against a test database silently dropped
 * three columns a migration had just added, because a command against the dev database, on
 * the same Redis with the migration not yet run, had rewritten `schema_columns_properties`
 * with its older list. `Model::_save()` used that list, the columns read back as null, and
 * nothing refused anything.
 *
 * Test and dev are the common case **and** the one that hurts most, because the two are
 * meant to differ while a migration is being written — which is exactly when somebody is
 * looking at the result and drawing conclusions from it.
 *
 * Asserted through `getColumns()` on two real connections rather than on the key, because
 * a key that is right while the caller passes nothing is the shape these failures keep
 * taking: the neighbouring unit test holds the format, and this holds the wiring.
 */
#[CoversClass(Database::class)]
class ColumnCacheIsPerDatabaseTest extends BaseTestCase
{
    private Database $primary;

    private string $otherDatabase = '';

    private string $table = '';

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(
            ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php'
        );
        Application::getInstance();

        $this->primary = Connection::fresh();

        if (!$this->primary->connected || $this->primary->type === 'postgresql') {
            $this->markTestSkipped('This case needs the MySQL fixture connection.');
        }

        $this->table         = 'ccpd_' . bin2hex(random_bytes(4));
        $this->otherDatabase = 'ccpd_other_' . bin2hex(random_bytes(4));

        // The same table name in two databases, with different columns — which is what a
        // migration run on one and not the other looks like.
        $this->primary->query(
            'CREATE TABLE `' . $this->table . '` (`id` INT NOT NULL, `name` VARCHAR(50) NULL)'
        );
        $this->primary->query('CREATE DATABASE `' . $this->otherDatabase . '`');
        $this->primary->query(
            'CREATE TABLE `' . $this->otherDatabase . '`.`' . $this->table . '` '
            . '(`id` INT NOT NULL, `name` VARCHAR(50) NULL, `migrated_later` INT NULL)'
        );
    }

    protected function tearDown(): void
    {
        if ($this->table !== '') {
            $this->primary->query('DROP TABLE IF EXISTS `' . $this->table . '`');
        }
        if ($this->otherDatabase !== '') {
            $this->primary->query('DROP DATABASE IF EXISTS `' . $this->otherDatabase . '`');
        }
    }

    /** A second connection, to the other database, sharing the same cache. */
    private function otherConnection(): Database
    {
        $other = new Database();
        $other->type     = $this->primary->type;
        $other->server   = $this->primary->server;
        $other->user     = $this->primary->user;
        $other->password = $this->primary->password;
        $other->port     = $this->primary->port;
        $other->prefix   = $this->primary->prefix;
        $other->database = $this->otherDatabase;
        $other->connect(true);

        return $other;
    }

    /** The column names a connection answers with, cache and all. */
    private function columnsFrom(Database $db): array
    {
        $result = $db->getColumns($this->table);

        if ($result === false) {
            return [];
        }

        $names = [];
        while ($result->fetch()) {
            $names[] = (string) ($result->fields['Field'] ?? '');
        }

        return $names;
    }

    /**
     * One database's column list does not become the other's.
     *
     * Read in the order that broke it: the database *without* the new column first, so its
     * shorter list is the one sitting in the cache when the other asks.
     */
    public function testEachDatabaseKeepsItsOwnColumnList(): void
    {
        // Arrange
        $other = $this->otherConnection();

        // Act — the un-migrated shape first, warming the cache
        $primaryColumns = $this->columnsFrom($this->primary);
        $otherColumns   = $this->columnsFrom($other);

        // Assert
        $this->assertSame(['id', 'name'], $primaryColumns);
        $this->assertSame(
            ['id', 'name', 'migrated_later'],
            $otherColumns,
            "the second database was answered with the first one's column list"
        );
    }

    /**
     * And in the other order, because a cache bug is usually only wrong one way round.
     *
     * Reading the longer list first and the shorter one second is the case where the
     * damage is the opposite: a column that does not exist is claimed to, and the failure
     * is a query error rather than a silently dropped value.
     */
    public function testTheOrderTheyAreReadInDoesNotMatter(): void
    {
        // Arrange
        $other = $this->otherConnection();

        // Act — migrated first this time
        $otherColumns   = $this->columnsFrom($other);
        $primaryColumns = $this->columnsFrom($this->primary);

        // Assert
        $this->assertSame(['id', 'name', 'migrated_later'], $otherColumns);
        $this->assertSame(
            ['id', 'name'],
            $primaryColumns,
            "the first database was answered with the second one's column list"
        );
    }

    /**
     * A flush reaches the entry it wrote.
     *
     * The other half, and the one that fails silently: a `forgetColumns()` computing the
     * key differently from the read is a flush that reports success and leaves the stale
     * answer in place. Asserted by changing the table behind the cache and checking the
     * flush is what makes the change visible.
     */
    public function testAFlushReachesTheEntryTheSameConnectionWrote(): void
    {
        // Arrange — warm it, then add a column behind its back
        $this->assertSame(['id', 'name'], $this->columnsFrom($this->primary));
        $this->primary->query(
            'ALTER TABLE `' . $this->table . '` ADD COLUMN `added_behind_it` INT NULL'
        );
        $this->assertSame(
            ['id', 'name'],
            $this->columnsFrom($this->primary),
            'the fixture is not caching, so this case cannot prove anything'
        );

        // Act
        $this->primary->forgetColumns($this->table);

        // Assert
        $this->assertContains('added_behind_it', $this->columnsFrom($this->primary));
    }
}
