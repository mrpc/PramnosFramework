<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Database\Migration;
use Pramnos\Database\MigrationLoader;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * `sessions.url` and `sessions.agent` become `text` on a database that has them narrow.
 *
 * WHAT: the migration is run against a `sessions` table built the way an installation
 *       predating this change has it — `varchar(255)` on both — and the catalogue is read
 *       back afterwards.
 *
 * WHY:  the create-table declares `text` now, so every database the framework builds from
 *       scratch is already right and a test that only exercised *that* path would prove
 *       nothing about the installations this migration exists for. Those are the ones where
 *       the column overflowed, and an overflow on `url` is not hypothetical: an OAuth
 *       callback carrying three scopes is over 400 characters.
 *
 *       Read from `information_schema` rather than by inserting a long value and seeing
 *       whether it survives, because MySQL's answer to that depends on `sql_mode` — a
 *       non-strict server truncates silently and the insert «succeeds». The catalogue says
 *       what the column is on both engines.
 *
 * Both lanes: the migration is written twice (`ALTER … TYPE text` and `MODIFY … TEXT`) and
 * neither statement is portable to the other engine.
 */
#[CoversClass(\Pramnos\Framework\Migrations\Core\WidenSessionUrlAndAgent::class)]
class WidenSessionUrlAndAgentTest extends BaseTestCase
{
    private $db;

    /** An application carrying this test's connection, for `Migration::DB()`. */
    private $app;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        // `Application::getInstance()` in a test run has no `database` property set, and
        // `Migration::DB()` reads exactly that. A mock carrying this test's connection is
        // what the sibling migration test does, and it keeps the migration on the lane the
        // subclass selected rather than on whatever the singleton last connected to.
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;
        $this->app     = $app;

        $this->buildTheNarrowTable();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    protected function tearDown(): void
    {
        try {
            // Leave the table as the framework builds it, because every other class that
            // touches `sessions` expects that shape and the order tests run in is not ours
            // to assume.
            $this->db->query('DROP TABLE IF EXISTS ' . $this->tableName());
            $this->runMigrations(
                [\Pramnos\Framework\Migrations\Core\CreateSessionsTable::class],
                $this->db
            );
        } catch (\Throwable) {
            // Nothing to restore.
        }

        parent::tearDown();
    }

    /** The table, named the way this engine names it. */
    private function tableName(): string
    {
        return $this->isPostgreSQL() ? 'sessions' : $this->db->prefix . 'sessions';
    }

    private function isPostgreSQL(): bool
    {
        return $this->db->schema()->getCapabilities()->isPostgreSQL();
    }

    /**
     * `sessions` as an installation that predates this change has it.
     *
     * Only the columns the migration reads and the primary key — not the whole table. The
     * migration touches two columns and nothing else, so a faithful copy of the other nine
     * would be nine more things to keep in step with the create-table for no assertion.
     */
    private function buildTheNarrowTable(): void
    {
        $table = $this->tableName();
        $this->db->query('DROP TABLE IF EXISTS ' . $table);

        if ($this->isPostgreSQL()) {
            $this->db->query(
                'CREATE TABLE ' . $table . ' ('
                . '"visitorid" varchar(255) PRIMARY KEY, '
                . '"agent" varchar(255) NOT NULL DEFAULT \'\', '
                . '"url" varchar(255) NOT NULL DEFAULT \'\')'
            );

            return;
        }

        $this->db->query(
            'CREATE TABLE `' . $table . '` ('
            . '`visitorid` varchar(255) NOT NULL PRIMARY KEY, '
            . '`agent` varchar(255) NOT NULL DEFAULT \'\', '
            . '`url` varchar(255) NOT NULL DEFAULT \'\')'
        );
    }

    /**
     * What the catalogue says this column is.
     *
     * @return array{type: string, length: int}
     */
    private function columnShape(string $column): array
    {
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT data_type AS dtype, COALESCE(character_maximum_length, 0) AS len
                   FROM information_schema.columns
                  WHERE table_name = %s AND column_name = %s
                  ORDER BY table_schema
                  LIMIT 1",
                $this->isPostgreSQL() ? 'sessions' : $this->db->prefix . 'sessions',
                $column
            )
        );

        $this->assertNotNull($result, 'the catalogue query returned nothing');
        $this->assertGreaterThan(0, $result->numRows, 'no such column: ' . $column);

        return [
            'type'   => strtolower((string) ($result->fields['dtype'] ?? '')),
            'length' => (int) ($result->fields['len'] ?? 0),
        ];
    }

    /**
     * The migration, loaded the way `migrate` loads it.
     *
     * Migration classes are not autoloadable — they are files the loader includes, and two
     * of them may legitimately share a class name across features — so a test reaches one
     * through the loader rather than through `new`. Writing `new …\WidenSessionUrlAndAgent`
     * fails with «class not found» on a tree where nothing has run `migrate` first.
     */
    private function migration(): Migration
    {
        $base = dirname(__DIR__, 3) . '/database/migrations/framework/core';

        foreach (MigrationLoader::loadFromDirectory($base, $this->app) as $migration) {
            if ((new \ReflectionClass($migration))->getShortName() === 'WidenSessionUrlAndAgent') {
                return $migration;
            }
        }

        $this->fail('WidenSessionUrlAndAgent was not loaded');
    }

    /**
     * The fixture really is narrow before the migration runs.
     *
     * The assertion the rest of the class depends on. A `CREATE TABLE` that silently gave
     * both columns `text` — a dialect difference, a default, a typo in the DDL above —
     * would make every assertion below pass without the migration doing anything at all.
     */
    public function testTheFixtureStartsNarrow(): void
    {
        // Assert
        foreach (['url', 'agent'] as $column) {
            $shape = $this->columnShape($column);
            $this->assertNotSame('text', $shape['type'], $column . ' was not narrow to begin with');
            $this->assertSame(255, $shape['length'], $column . ' is not the width this migrates from');
        }
    }

    /**
     * Both columns are `text` afterwards.
     */
    public function testTheMigrationWidensBothColumns(): void
    {
        // Act
        $this->migration()->up();

        // Assert
        foreach (['url', 'agent'] as $column) {
            $shape = $this->columnShape($column);
            $this->assertSame('text', $shape['type'], $column . ' is still length-limited');

            // The ceiling, where each engine reports one. PostgreSQL's `text` has none and
            // says so with a NULL; MySQL's `TEXT` is a distinct type with a real limit of
            // 65,535 bytes, which no URL reaches. Asserting "no ceiling" on both would be
            // asserting a PostgreSQL detail and failing on a column that is perfectly wide.
            $this->assertGreaterThanOrEqual(
                65535,
                $shape['length'] === 0 ? PHP_INT_MAX : $shape['length'],
                $column . ' is wider than it was, but not wide enough to be done with'
            );
        }
    }

    /**
     * A URL longer than the old ceiling survives the round trip afterwards.
     *
     * The catalogue assertion above says what the column *is*; this says what it *does*, and
     * they fail for different reasons — a column that reports `text` while something else
     * truncates the value would pass the first and not this one.
     */
    public function testALongUrlSurvivesTheRoundTrip(): void
    {
        // Arrange
        $this->migration()->up();
        $url = '/connect/callback/google?' . str_repeat('scope=x&', 60);
        $this->assertGreaterThan(255, strlen($url));

        // Act
        $this->db->queryBuilder()->table($this->tableName())->insert([
            'visitorid' => 'a-visitor',
            'agent'     => 'PramnosTest/1.0',
            'url'       => $url,
        ]);

        // Assert
        $row = $this->db->queryBuilder()->table($this->tableName())
            ->where('visitorid', 'a-visitor')->first();
        $this->assertSame($url, (string) ($row->fields['url'] ?? ''), 'the URL came back short');
    }

    /**
     * Running it twice does no work the second time.
     *
     * A migration is retried whenever an earlier one in the same run declines, and on MySQL
     * this is a table rebuild — one nobody needs and one that locks a table holding live
     * sessions. `isNarrow()` reads the catalogue for exactly this, so the second run must
     * leave both columns as it found them rather than re-issuing the ALTER.
     */
    public function testRunningItTwiceIsANoOp(): void
    {
        // Arrange
        $migration = $this->migration();
        $migration->up();

        // Act
        $migration->up();

        // Assert
        foreach (['url', 'agent'] as $column) {
            $this->assertSame('text', $this->columnShape($column)['type']);
        }
    }

    /**
     * A database with no `sessions` table at all is left alone.
     *
     * The migration runs on every installation, including one whose `sessions` has not been
     * created yet because the create-table declined or was skipped by a cutoff. Reaching for
     * `information_schema` on a table that is not there must not raise — a migration that
     * throws here stops the whole run.
     */
    public function testAMissingTableIsNotAnError(): void
    {
        // Arrange
        $this->db->query('DROP TABLE IF EXISTS ' . $this->tableName());

        // Act
        $this->migration()->up();

        // Assert — reaching this line is the assertion; the migration returned rather than raised
        $this->assertFalse(
            $this->db->schema()->hasTable('sessions'),
            'the migration created a table it is only meant to alter'
        );
    }
}
