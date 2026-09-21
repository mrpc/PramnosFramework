<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Database\Migration;
use Pramnos\Database\MigrationRunner;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Framework\Testing\Connection;

/**
 * A migration that adds a column with raw SQL does not leave the column cache stale.
 *
 * WHAT: after `MigrationRunner::run()` applies a migration that issues
 *       `ALTER TABLE … ADD COLUMN` directly, `getColumns()` answers with the new column.
 *
 * WHY:  `getColumns()` caches a table's schema for an hour. `SchemaBuilder` flushes the
 *       tables its own DDL methods touch, so a migration written against the builder is
 *       covered — but **DDL is explicitly allowed to be raw**, because the builder cannot
 *       express every engine's grammar, and a raw statement flushed nothing.
 *
 *       The cost is not an abstraction. One application added `channels.is_competitor`
 *       and every list built through `getApiList()` kept answering without that key for
 *       an hour; the payload indexed it by name, so an undefined-key warning printed
 *       ahead of the body, the JSON would not parse, and the screen read "Could not load
 *       your numbers" — with no status, because nothing had refused anything.
 *
 *       The read path's own comment called this "visible, harmless, and fixed by
 *       waiting", which is true of a reader that walks the row and false of every
 *       generated controller.
 *
 * Driven through the runner rather than by calling the flush, because "the runner does
 * it" is the contract and a test that called `forgetAllColumns()` directly would pass on
 * a runner that never does.
 */
#[CoversClass(MigrationRunner::class)]
#[CoversClass(Database::class)]
class ColumnCacheAfterMigrateTest extends BaseTestCase
{
    private Database $db;

    /**
     * A table name of its own per test.
     *
     * The cache outlives a test — that is the whole subject — so two tests sharing a name
     * share an entry, and the second reads what the first left. Getting this wrong made
     * one of them report a column the other had added.
     */
    private string $table = '';

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();

        $this->db = Connection::fresh();
        if (!$this->db->connected) {
            $this->markTestSkipped('The database is not reachable.');
        }

        $this->table = 'column_cache_probe_' . bin2hex(random_bytes(4));

        $this->drop();
        $this->db->query(
            'CREATE TABLE ' . $this->quoted() . ' ('
            . $this->quote('id') . ' INT NOT NULL, '
            . $this->quote('name') . ' VARCHAR(64) NULL)'
        );
    }

    protected function tearDown(): void
    {
        $this->drop();

        parent::tearDown();
    }

    private function quote(string $identifier): string
    {
        return $this->db->type === 'postgresql' ? '"' . $identifier . '"' : '`' . $identifier . '`';
    }

    private function quoted(): string
    {
        return $this->quote($this->db->prefix . $this->table);
    }

    private function drop(): void
    {
        if ($this->table === '') {
            return;
        }

        try {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->quoted());
        } catch (\Throwable) {
            // Nothing to remove.
        }
    }

    /** The column names `getColumns()` currently answers with, cache and all. */
    private function cachedColumns(): array
    {
        $result = $this->db->getColumns($this->db->prefix . $this->table);
        if ($result === false) {
            return [];
        }

        $names = [];
        while ($result->fetch()) {
            $names[] = (string) ($result->fields['Field'] ?? '');
        }

        return $names;
    }

    /** A migration that adds a column the way DDL is allowed to: raw. */
    private function migration(): Migration
    {
        $table = $this->quoted();
        $added = $this->quote('is_competitor');

        return new class ($this->applicationFor(), $table, $added) extends Migration {
            public string $feature = 'core';
            public string $scope   = 'app';
            public $description    = 'Adds a column with raw SQL, as DDL is allowed to';

            public function __construct(
                $application,
                private string $tableSql,
                private string $columnSql
            ) {
                parent::__construct($application);
            }

            public function up(): void
            {
                $this->DB()->query(
                    'ALTER TABLE ' . $this->tableSql . ' ADD COLUMN ' . $this->columnSql . ' INT NULL'
                );
            }

            public function down(): void
            {
            }

            /**
             * A slug of its own, per run.
             *
             * `getSlug()` derives one from the file name's timestamp prefix, or failing
             * that from the class's short name — and an anonymous class declared in a
             * test file has neither. The runner then had nothing usable to record or to
             * look up, so the second run of the suite found the migration already
             * applied and ran nothing: the test passed in isolation and failed in a full
             * run, which is the worst shape a test can have.
             */
            private string $slug = '';

            public function getSlug(): string
            {
                // Memoised. The runner builds a slug-keyed map and then looks each
                // migration up in it, so a `getSlug()` that answers differently every
                // call is a map whose keys nothing matches — `Undefined array key`, then
                // a null where a Migration belongs.
                if ($this->slug === '') {
                    $this->slug = 'column_cache_probe_' . bin2hex(random_bytes(4));
                }

                return $this->slug;
            }
        };
    }

    /** An application carrying this test's connection, for `Migration::DB()`. */
    private function applicationFor(): Application
    {
        $application = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $application->database = $this->db;

        return $application;
    }

    /**
     * The fixture really is cached before the migration runs.
     *
     * **The assertion the test depends on.** If `getColumns()` were not caching here —
     * a disabled cache, a changed key — the new column would show up without the flush
     * and this class would pass on a runner that does nothing.
     */
    public function testTheColumnListIsCachedToBeginWith(): void
    {
        // Arrange — warm the cache
        $this->assertSame(['id', 'name'], $this->cachedColumns());

        // Act — change the table behind the cache's back
        $this->db->query(
            'ALTER TABLE ' . $this->quoted() . ' ADD COLUMN ' . $this->quote('sneaked') . ' INT NULL'
        );

        // Assert — still the old answer, which is the staleness this is all about
        $this->assertSame(
            ['id', 'name'],
            $this->cachedColumns(),
            'getColumns() is not caching, so this class cannot prove anything'
        );
    }

    /**
     * After a batch that ran, the new column is visible.
     */
    public function testAMigrationThatAddsAColumnLeavesNoStaleCache(): void
    {
        // Arrange — warm the cache with the old shape
        $this->assertSame(['id', 'name'], $this->cachedColumns());

        // Act
        $runner = new MigrationRunner($this->db);
        $result = $runner->run([$this->migration()]);

        // Assert — it ran, and the column is there
        $this->assertNotEmpty($result['ran'] ?? [], 'the migration did not run: ' . json_encode($result));
        $this->assertContains(
            'is_competitor',
            $this->cachedColumns(),
            'the column cache is still holding the pre-migration list'
        );
    }

    /**
     * A batch that ran nothing does not flush.
     *
     * The flush costs one re-introspection per table on next use. That is nothing next to
     * a migration and wrong on a `migrate` that finds nothing pending — which is the
     * common case, on every deploy of a project whose schema has not moved.
     */
    public function testABatchThatRanNothingLeavesTheCacheAlone(): void
    {
        // Arrange — warm the cache, then change the table behind its back
        $this->assertSame(['id', 'name'], $this->cachedColumns());
        $this->db->query(
            'ALTER TABLE ' . $this->quoted() . ' ADD COLUMN ' . $this->quote('sneaked') . ' INT NULL'
        );

        // Act — a run with nothing in it
        (new MigrationRunner($this->db))->run([]);

        // Assert — the cache was not swept, so the column added behind it is still hidden
        $this->assertSame(
            ['id', 'name'],
            $this->cachedColumns(),
            'an empty batch flushed the cache, which every deploy would pay for'
        );
    }
}
