<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Database\SchemaBuilder;
use Pramnos\Framework\Factory;

/**
 * **A schema change makes the cached introspection wrong, and now says so.**
 *
 * `Database::getColumns()` caches for an hour, on the stated grounds that
 * schemas rarely change. That is true, and it is also the problem: the moment
 * one *does* change is exactly the moment somebody asks about it again, and
 * nothing was invalidating the entry.
 *
 * The framework's own documented order of work makes this a routine sequence
 * rather than a corner: `create:migration`, migrate, `create:crud`. Anything
 * reading columns after a migration — a model hydrating its field list, a form
 * builder, an inspector — was answered with the table as it had been an hour
 * ago. The cache store is shared (files, redis), so it outlived the process:
 * re-running the command did not clear it either.
 *
 * Two things had to be true for the fix to work, and only the second was:
 *
 *   1. Something has to *ask* for the flush. Every DDL method on SchemaBuilder
 *      now does.
 *   2. The flush has to *do* something. It did not — the category
 *      `schema_columns_<table>` contains underscores, and the file adapter
 *      derived its directory by splitting the key on the first one, so entries
 *      went into `schema/` while `clear()` looked in
 *      `schema_columns_<table>/`. See CategoryWithAnUnderscoreIsClearableTest.
 *
 * The reversal that reddens the tests below: remove the `forgetCachedSchema()`
 * calls from SchemaBuilder, or revert the adapter's category handling. Either
 * one alone is enough, which is the point of testing them here rather than only
 * in isolation.
 */
#[CoversClass(Database::class)]
#[CoversClass(SchemaBuilder::class)]
#[\PHPUnit\Framework\Attributes\Group('mysql')]
#[\PHPUnit\Framework\Attributes\Group('integration')]
class SchemaChangesInvalidateTheColumnCacheTest extends TestCase
{
    private Database $db;
    private SchemaBuilder $schema;
    private string $table;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . '/tests/fixtures/app/settings.php');

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        $this->schema = new SchemaBuilder($this->db);
        // Unique per test, so a run cannot inherit an entry from a previous one
        // — which is exactly the staleness under test and would mask it.
        $this->table = 'cachecols_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `' . $this->table . '`');
        $this->db->forgetColumns($this->table);
    }

    /**
     * The column names the connection reports for the fixture table.
     *
     * Read through the cached path — no `$fresh` — because the cached path is
     * what every non-generator caller uses and what the bug was about.
     *
     * @return string[]
     */
    private function columns(): array
    {
        $result = $this->db->getColumns($this->table);
        $names  = [];
        while ($result->fetch()) {
            $names[] = $result->fields['Field'];
        }

        return $names;
    }

    /**
     * A column added through the schema builder is visible immediately.
     *
     * The first read populates the cache, which is what makes the second read
     * the interesting one: without invalidation it answers from an entry
     * written before the ALTER, and the new column simply is not there.
     */
    public function testAnAddedColumnIsVisibleAfterTheAlter(): void
    {
        // Arrange — create, then read so the cache is populated.
        $this->schema->createTable($this->table, function ($table) {
            $table->increments('id');
            $table->string('name');
        });
        $this->assertSame(['id', 'name'], $this->columns());

        // Act
        $this->schema->alterTable($this->table, function ($table) {
            $table->integer('listeners')->nullable();
        });

        // Assert
        $this->assertSame(['id', 'name', 'listeners'], $this->columns());
    }

    /**
     * A dropped column stops being reported.
     *
     * The mirror of the test above, and the more dangerous direction: a model
     * that believes a column still exists writes to it, and the insert fails at
     * the database with a message about a column nobody in the code has removed.
     */
    public function testADroppedColumnDisappearsAfterTheAlter(): void
    {
        // Arrange
        $this->schema->createTable($this->table, function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('doomed')->nullable();
        });
        $this->assertContains('doomed', $this->columns());

        // Act
        $this->schema->alterTable($this->table, function ($table) {
            $table->dropColumn('doomed');
        });

        // Assert
        $this->assertNotContains('doomed', $this->columns());
    }

    /**
     * Creating a table after something asked about it reports the real columns.
     *
     * The schema-first workflow reaches this: a generator or an inspector asks
     * about a table that does not exist yet, the miss is cached, and the
     * migration then creates it. Without invalidation the table reads as empty
     * for an hour after it was created.
     */
    public function testATableCreatedAfterAFailedLookupIsSeen(): void
    {
        // Arrange — ask before it exists.
        $this->assertSame([], $this->columns());

        // Act
        $this->schema->createTable($this->table, function ($table) {
            $table->increments('id');
            $table->string('name');
        });

        // Assert
        $this->assertSame(['id', 'name'], $this->columns());
    }

    /**
     * Dropping a table stops it reporting columns.
     *
     * Left cached, a dropped table looks alive to everything that asks — which
     * is the shape of failure where code decides a table is safe to read from
     * and the query fails instead.
     */
    public function testADroppedTableStopsReportingColumns(): void
    {
        // Arrange
        $this->schema->createTable($this->table, function ($table) {
            $table->increments('id');
        });
        $this->assertSame(['id'], $this->columns());

        // Act
        $this->schema->dropTableIfExists($this->table);

        // Assert
        $this->assertSame([], $this->columns());
    }

    /**
     * A generator's read sees a change nothing announced.
     *
     * Two mechanisms keep column reads correct and they are not the same one:
     * the invalidation above is what makes every ordinary caller correct after a
     * migration, and `$fresh` is what makes a *code generator* correct even
     * after a change nobody invalidated — a raw `ALTER`, a migration run by
     * another process, a column added by hand during development.
     *
     * Only the fresh read is asserted. Whether the *cached* read is stale
     * depends on whether caching is switched on for the run, which is an
     * environment fact rather than a contract — an earlier version of this test
     * asserted the staleness and failed in a full-suite run for exactly that
     * reason.
     */
    public function testAFreshReadSeesAChangeNothingAnnounced(): void
    {
        // Arrange
        $this->schema->createTable($this->table, function ($table) {
            $table->increments('id');
        });
        // Read once, so a cache that is active has an entry to be wrong with.
        $this->columns();

        // Act — change it behind the cache's back: no SchemaBuilder, no flush.
        $this->db->query(
            'ALTER TABLE `' . $this->table . '` ADD COLUMN sneaked INT NULL'
        );

        // Assert
        $result = $this->db->getColumns($this->table, null, false, true);
        $fresh  = [];
        while ($result->fetch()) {
            $fresh[] = $result->fields['Field'];
        }

        $this->assertContains('sneaked', $fresh,
            'a fresh read must not be answerable from a cache at all');
    }
}
