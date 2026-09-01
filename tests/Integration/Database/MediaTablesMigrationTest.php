<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Migrations\Core\CreateMediaTables;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Media\MediaObject;

/**
 * The migration for the two tables `MediaObject` has used since 2020 without one.
 *
 * Every other migration in this framework was reconstructed from a running installation. These two
 * were not, because the installation it was reconstructed from does not use `MediaObject` — so the
 * model, its guide and its tests all existed while nothing created its schema, and the framework's
 * own tests worked around that by hand-writing the DDL.
 *
 * What this file checks is the part a hand-written DDL cannot: that the shape the migration produces
 * is the shape the model queries, **on both backends**. Three things here are backend-sensitive and
 * every one of them is a plausible way for the migration to be wrong on exactly one of them:
 *
 * - **`order` is a reserved word** in both MySQL and PostgreSQL. A column called that has to be
 *   quoted in the generated DDL, and a schema builder that quotes on one backend and not the other
 *   produces a syntax error on the second — which nobody sees until an installation switches.
 * - **`tinyint` does not exist in PostgreSQL.** `otherusers` and `othermodules` are `tinyint` in
 *   production; the builder has to map that to something, and the test asserts the columns exist
 *   rather than what they are called, because the mapping is the builder's business.
 * - **the cascading foreign key** across `#PREFIX#`-prefixed names, which the two backends name and
 *   qualify differently.
 *
 * And the ordering property the whole file rests on: `mediause.mediaid` references `media.mediaid`,
 * so `media` must exist first. Both are created in one `up()` precisely so that cannot be
 * misconfigured — this asserts the result rather than the arrangement.
 */
#[CoversClass(CreateMediaTables::class)]
class MediaTablesMigrationTest extends BaseTestCase
{
    private $db;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = \Pramnos\Framework\Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        /*
         * Dropped and re-migrated once per class.
         *
         * The child first: its foreign key is what would refuse the parent's drop. And dropping at
         * all is the point — the shape left by the framework's own hand-written test DDL is what
         * this migration exists to replace, and `runMigrations()` is a no-op for a table that
         * already exists.
         */
        if (!isset(self::$migrated[static::class])) {
            foreach (['#PREFIX#mediause', '#PREFIX#media'] as $table) {
                $this->db->query(
                    'DROP TABLE IF EXISTS ' . $this->db->schema()->quoteTable($table)
                );
            }

            $this->runMigrations([CreateMediaTables::class], $this->db);
            self::$migrated[static::class] = true;
        }
    }

    /** @var array<string, bool> Which lanes have rebuilt their tables this run. */
    private static array $migrated = [];

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    protected function tearDown(): void
    {
        foreach (['#PREFIX#mediause', '#PREFIX#media'] as $table) {
            try {
                $this->db->queryBuilder()->table($table)->where('module', 'mediamig_probe')->delete();
            } catch (\Throwable) {
                // Nothing to undo.
            }
        }

        parent::tearDown();
    }

    /** @return list<string> The table's column names, as the driver reports them. */
    private function columns(string $table): array
    {
        $names = [];
        $result = $this->db->getColumns($table, null, false, true);

        while ($result->fetch()) {
            $names[] = (string) $result->fields['Field'];
        }

        return $names;
    }

    // ── The tables exist, with the columns the model uses ─────────────────────

    /**
     * Both tables are created, and `media` before `mediause` — otherwise the key could not exist.
     */
    public function testBothTablesAreCreated(): void
    {
        // Act & Assert
        $schema = $this->db->schema();
        $this->assertTrue($schema->hasTable('#PREFIX#media'), 'media was not created');
        $this->assertTrue($schema->hasTable('#PREFIX#mediause'), 'mediause was not created');
    }

    /**
     * `media` has every column the model reads and writes.
     *
     * Asserted as a set rather than one at a time: a column the model writes and the table lacks is
     * an insert that fails, and a column the model reads and the table lacks is a page that renders
     * with an empty field — and the second is the one nobody reports.
     */
    public function testMediaHasEveryColumnTheModelUses(): void
    {
        // Act
        $columns = $this->columns('#PREFIX#media');

        // Assert
        foreach (
            [
                'mediaid', 'mediatype', 'userid', 'module', 'views', 'thumbnails', 'filesize',
                'description', 'x', 'y', 'order', 'name', 'filename', 'url', 'shortcut', 'tags',
                'date', 'otherusers', 'othermodules', 'md5', 'medialink', 'usages', 'extrainfo',
                'mimetype',
            ] as $column
        ) {
            $this->assertContains($column, $columns, $column . ' is missing from media');
        }
    }

    /** `mediause` likewise. */
    public function testMediauseHasEveryColumnTheModelUses(): void
    {
        // Act
        $columns = $this->columns('#PREFIX#mediause');

        // Assert
        foreach (
            ['usageid', 'mediaid', 'module', 'specific', 'date', 'title', 'description', 'tags', 'order']
            as $column
        ) {
            $this->assertContains($column, $columns, $column . ' is missing from mediause');
        }
    }

    /**
     * A column called `order` can actually be written and read.
     *
     * It is a reserved word on both backends, so this is the assertion that says the generated DDL
     * quoted it — and that a query against it can be built. The column exists for emoticon sets,
     * where the order is the entire point of the row.
     */
    public function testTheReservedWordColumnWorks(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('#PREFIX#media')->insert([
            'module' => 'mediamig_probe',
            'md5'    => str_repeat('a', 32),
            'order'  => 7,
            'name'   => 'ordered',
        ]);

        // Act
        $row = $this->db->queryBuilder()->table('#PREFIX#media')
            ->where('module', 'mediamig_probe')->first();

        // Assert
        $this->assertSame(7, (int) $row->fields['order'], 'the reserved-word column did not round-trip');
    }

    /**
     * Every column has a usable default, so a partial insert works.
     *
     * Production has these columns `NOT NULL` with no defaults, which means every insert must name
     * every one of them. Adding defaults is strictly widening — nothing that worked stops working —
     * and it is what lets the insert above name four columns out of twenty-three.
     */
    public function testAPartialInsertWorks(): void
    {
        // Act — three columns of twenty-three
        $this->db->queryBuilder()->table('#PREFIX#media')->insert([
            'module' => 'mediamig_probe',
            'md5'    => str_repeat('b', 32),
            'name'   => 'partial',
        ]);

        $row = $this->db->queryBuilder()->table('#PREFIX#media')
            ->where('md5', str_repeat('b', 32))->first();

        // Assert
        $this->assertSame('partial', (string) $row->fields['name']);
        $this->assertSame(0, (int) $row->fields['medialink'], 'medialink has no usable default');
        $this->assertSame(0, (int) $row->fields['views']);
    }

    // ── The foreign key ───────────────────────────────────────────────────────

    /**
     * Deleting a file takes its usages with it.
     *
     * The cascade is what keeps the usages honest: a usage row whose file is gone is a gallery entry
     * pointing at nothing, and nothing in the application would ever clean it up. Asserted through
     * the behaviour rather than by reading the catalogue, because that is what the two backends have
     * to agree on.
     */
    public function testDeletingAFileTakesItsUsagesWithIt(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('#PREFIX#media')->insert([
            'module' => 'mediamig_probe',
            'md5'    => str_repeat('c', 32),
            'name'   => 'cascade',
        ]);
        $file = $this->db->queryBuilder()->table('#PREFIX#media')
            ->where('md5', str_repeat('c', 32))->first();
        $mediaid = (int) $file->fields['mediaid'];
        $this->assertGreaterThan(0, $mediaid);

        $this->db->queryBuilder()->table('#PREFIX#mediause')->insert([
            'mediaid'  => $mediaid,
            'module'   => 'mediamig_probe',
            'specific' => '42',
        ]);
        $before = $this->db->queryBuilder()->table('#PREFIX#mediause')
            ->where('mediaid', $mediaid)->get();
        $this->assertSame(1, (int) $before->numRows, 'the usage row was not written');

        // Act
        $this->db->queryBuilder()->table('#PREFIX#media')->where('mediaid', $mediaid)->delete();

        // Assert
        $after = $this->db->queryBuilder()->table('#PREFIX#mediause')
            ->where('mediaid', $mediaid)->get();
        $this->assertSame(
            0,
            (int) $after->numRows,
            'the usage outlived its file, so the gallery points at nothing'
        );
    }

    /**
     * A usage naming a file that does not exist is refused.
     *
     * The other direction of the same key, and the one that keeps the table from filling with
     * orphans in the first place. `mediause.mediaid` is the only foreign key here — there is
     * deliberately none on `media.userid` or `media.medialink`, because both use `0` as a sentinel
     * and a key would reject it on the first insert.
     */
    public function testAUsageNamingNoFileIsRefused(): void
    {
        // Act & Assert
        try {
            $this->db->queryBuilder()->table('#PREFIX#mediause')->insert([
                'mediaid'  => 987654321,
                'module'   => 'mediamig_probe',
                'specific' => 'orphan',
            ]);
            $this->fail('a usage row was accepted for a file that does not exist');
        } catch (\Throwable $exception) {
            // Expected — and asserted, so this counts as a check rather than a silent catch.
            // Not on the exception's class: the two backends raise different types here.
            $this->assertNotSame('', $exception->getMessage(), 'refused with no explanation');
        }

        $orphans = $this->db->queryBuilder()->table('#PREFIX#mediause')
            ->where('specific', 'orphan')->get();
        $this->assertSame(0, (int) $orphans->numRows, 'the orphan reached the table anyway');
    }

    /**
     * `medialink` accepts the zero the model relies on.
     *
     * `uploadFile()` finds the original of a re-upload with `where md5 = %s and medialink = 0`, so
     * zero is a value rather than an absence. This is the assertion that says no well-meaning
     * foreign key has been added to that column since.
     */
    public function testTheSentinelZeroIsAcceptable(): void
    {
        // Act
        $this->db->queryBuilder()->table('#PREFIX#media')->insert([
            'module'    => 'mediamig_probe',
            'md5'       => str_repeat('d', 32),
            'medialink' => 0,
            'userid'    => 0,
        ]);

        // Assert
        $row = $this->db->queryBuilder()->table('#PREFIX#media')
            ->where('md5', str_repeat('d', 32))->first();
        $this->assertSame(0, (int) $row->fields['medialink']);
        $this->assertSame(0, (int) $row->fields['userid'], 'an upload with no signed-in user is refused');
    }

    // ── The model against the real table ─────────────────────────────────────

    /**
     * `MediaObject` can save and load against the migrated table.
     *
     * The assertion that ties the migration to the thing it exists for. Every column name here came
     * from a `SHOW CREATE TABLE` rather than from reading the model, so agreement between the two is
     * a claim to be checked, not an assumption.
     */
    public function testTheModelSavesAndLoadsAgainstTheMigratedTable(): void
    {
        // Arrange
        $media = new MediaObject();
        $media->module      = 'mediamig_probe';
        $media->name        = 'A saved file';
        $media->filename    = '/tmp/nothing.png';
        $media->url         = 'uploads/nothing.png';
        $media->md5         = str_repeat('e', 32);
        $media->mediatype   = 1;
        $media->date        = time();
        $media->description = 'Saved through the model';
        $media->mimetype    = 'image/png';

        // Act
        $media->save();
        $id = (int) $media->mediaid;

        // Assert
        $this->assertGreaterThan(0, $id, 'the model could not save against the migrated table');

        $loaded = new MediaObject();
        $loaded->load($id);
        $this->assertSame('A saved file', (string) $loaded->name);
        $this->assertSame('mediamig_probe', (string) $loaded->module);
        $this->assertSame(str_repeat('e', 32), (string) $loaded->md5);
        $this->assertSame(
            'image/png',
            (string) $loaded->mimetype,
            'the detected type does not survive a save and load, so it cannot be audited'
        );
    }
}
