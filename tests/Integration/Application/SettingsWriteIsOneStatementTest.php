<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;

/**
 * `setSetting()` asked whether a row existed and then inserted or updated.
 *
 * ```php
 * $exists = …->where('setting', $setting)->exists();   // ①
 * if ($exists) { …update(…); } else { …insert(…); }    // ②
 * ```
 *
 * Check then act, with a window between ① and ②. Two administrators saving settings in the
 * same second both see "no row", both insert, and the second gets a duplicate key error out
 * of a screen that had every reason to work.
 *
 * Low probability and entirely real — and the constraint that makes the one-statement
 * version possible was already there. `2026_05_26_000051`'s own docblock says
 * *"Settings::setSetting() relies on row-level uniqueness"*: the index existed and the code
 * was not using it.
 *
 * Two databases, because the upsert compiles differently on each — `ON CONFLICT (setting) DO
 * UPDATE` against `ON DUPLICATE KEY UPDATE` — and a unit test over a mocked builder would
 * assert the framework called a method, not that either database did the right thing.
 */
#[CoversClass(Settings::class)]
class SettingsWriteIsOneStatementTest extends TestCase
{
    protected Database $db;

    private string $table = '';

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }

        // Before anything touches `Settings` — see restoreSettingsStatics().
        $this->rememberSettingsStatics();

        $this->db = $this->connect();

        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        // Resolved the way the builder resolves it, so the DDL below and the code under
        // test are talking about the same table.
        $this->table = $this->db->schema()->resolveTableName('settings');

        /*
         * The **real** settings table, not one of this test's own.
         *
         * `Settings::setSetting()` writes to `#PREFIX#settings` and nothing redirects it, so
         * a test that wants to exercise it has to use that table. A first version created
         * and dropped it in setUp/tearDown, which destroyed shared state: 29 tests later in
         * the same run failed with «Table 'pramnos_test.settings' doesn't exist», none of
         * them anything to do with settings.
         *
         * So this touches only its own rows, and puts back anything it changes about the
         * table's shape.
         */

        /*
         * And it is **arranged**, not assumed, because in a full run it is not there.
         *
         * Every test in this class skipped in the suite while passing under `--filter`:
         * `SELECT 1 FROM "settings"` answered *relation "settings" does not exist*. Other
         * tests in the run assert that a migration's `down()` removes it, and a skip is not
         * a pass — a class that reports twenty skips proves nothing about the code it was
         * written for.
         *
         * Rebuilding it rather than skipping is also what the suite already does elsewhere
         * ({@see \Pramnos\Tests\Integration\Email\UnsubscribeFailurePathsTest}), and it
         * is safe in the direction that matters: creating a table the rest of the run
         * expects to exist cannot break a later test, while dropping one demonstrably can.
         *
         * Checked by **using** it. `hasTable('settings')` answered true in a run where the
         * `SELECT` that followed failed, so the migration's own early return cannot be
         * trusted to notice either — hence `down()` before `up()`, which is a no-op on a
         * table that is genuinely gone and clears the half-state when it is not.
         */
        if (!$this->tableIsUsable()) {
            $migration = $this->settingsMigration();
            $migration->down();
            $migration->up();
        }

        if (!$this->tableIsUsable()) {
            $this->markTestSkipped(
                'The settings table could not be created on this connection. Resolved as '
                . '`' . $this->table . '` with prefix `' . (string) $this->db->prefix
                . '`; the driver said: ' . $this->whyNotUsable
            );
        }

        $this->removeProbeRows();

        /*
         * Whether the index is there is **arranged**, not assumed.
         *
         * The suite's MySQL database has only the plain `idx_settings_name`, and its
         * PostgreSQL one has the unique index as well — so a test that assumed either shape
         * would be asserting the upsert on one backend and the fallback on the other while
         * claiming to test the same thing. The installation the framework runs on is not
         * guaranteed to have it either: `2026_05_26_000051` declines when the table holds
         * duplicates.
         */
        $this->indexWasThere = $this->indexIsThere();
        $this->ensureUniqueIndex();

        Settings::setDatabase($this->db);
        Settings::forgetSchemaFacts();
    }

    protected function tearDown(): void
    {
        $this->removeProbeRows();
        $this->restoreTheIndexAsFound();
        $this->restoreSettingsStatics();

        parent::tearDown();
    }

    /** The class-level state on `Settings` that this test replaces, as it was found. */
    private array $settingsStaticsAsFound = array();

    /** Everything `Settings` keeps at class level and this class writes to. */
    private const SETTINGS_STATICS = array(
        'settings', 'loaded', 'bulkLoaded', 'uniqueSettingIndex', 'database',
    );

    private function rememberSettingsStatics(): void
    {
        foreach (self::SETTINGS_STATICS as $name) {
            $this->settingsStaticsAsFound[$name]
                = (new \ReflectionProperty(Settings::class, $name))->getValue();
        }
    }

    /**
     * Give the rest of the run back the `Settings` it had.
     *
     * `setDatabase()` writes a **static**. It points every later caller in the process at
     * this class's connection, and nothing in the framework puts the previous one back —
     * so without this, a test written about `setSetting()` silently rewires settings for
     * every test after it.
     *
     * It went unnoticed for as long as the class was skipping: `markTestSkipped()` throws,
     * setUp never reached `setDatabase()`, and the leak could not happen. The first run in
     * which these tests actually executed produced **22 errors elsewhere in the suite** —
     * which is the argument for the restore and, separately, for not accepting a skip as a
     * result.
     *
     * The in-memory store goes back too: a `probe_*` key left in `self::$settings` is a
     * value `getSetting()` would answer with, for a name nothing ever wrote.
     */
    private function restoreSettingsStatics(): void
    {
        foreach ($this->settingsStaticsAsFound as $name => $value) {
            (new \ReflectionProperty(Settings::class, $name))->setValue(null, $value);
        }
    }

    /**
     * Every setting this class writes is named `probe_*`, so cleaning up is one statement.
     *
     * Only its own rows: dropping the table is what broke 29 unrelated tests the first time
     * this class was written.
     */
    protected function removeProbeRows(): void
    {
        try {
            $this->db->queryBuilder()
                ->table('#PREFIX#settings')
                ->where('setting', 'LIKE', 'probe\_%')
                ->delete();
        } catch (\Throwable) {
            // Cleanup, not a check — {@see tableIsUsable()} is what decides.
        }
    }

    /** The driver's reason the table could not be read, for the skip message. */
    private string $whyNotUsable = '';

    /**
     * Can this connection actually read the settings table?
     *
     * Raw, and that is the point: every builder terminator — `delete()`, `get()`, and
     * therefore `count()` — runs through `Database::execute()`, which records the error and
     * returns false. Only `query()` raises. So no builder call can tell a table that is
     * missing from one that matched no rows, and the first version of this check returned
     * true on a dropped table, which is how twenty tests skipped for a whole afternoon
     * saying the wrong reason.
     *
     * The name is the schema builder's own resolution, so this asks about exactly the table
     * the code under test writes to (rule 12: the builder cannot express *fail if this is
     * not there*).
     */
    protected function tableIsUsable(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM ' . $this->quoted($this->table) . ' LIMIT 1');

            return true;
        } catch (\Throwable $e) {
            $this->whyNotUsable = $e->getMessage();

            return false;
        }
    }

    /**
     * The framework's own migration for this table, against this connection.
     *
     * The real one rather than hand-written DDL, so the table this class writes to has the
     * columns, defaults and plain index an installation has — including `delete` defaulting
     * to 1, which nothing here asserts and everything here would be lying about.
     */
    protected function settingsMigration(): \Pramnos\Database\Migration
    {
        require_once dirname(__DIR__, 3)
            . '/database/migrations/framework/core/2020_01_01_000001_create_settings_table.php';

        /** @var \Pramnos\Application\Application&\PHPUnit\Framework\MockObject\MockObject $app */
        $app = $this->getMockBuilder(\Pramnos\Application\Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;

        return new \Pramnos\Framework\Migrations\Core\CreateSettingsTable($app);
    }

    /** Which backend this class runs against; the PostgreSQL subclass returns the other. */
    protected function connect(): Database
    {
        $db = new Database();
        $db->type     = 'mysql';
        $db->server   = 'db';
        $db->user     = 'root';
        $db->password = 'secret';
        $db->database = 'pramnos_test';
        $db->port     = 3306;

        try {
            $db->connect(true);
        } catch (\Exception) {
            // Reported by the skip in setUp().
        }

        return $db;
    }

    protected function quoted(string $name): string
    {
        return $this->db->type === 'postgresql' ? '"' . $name . '"' : '`' . $name . '`';
    }

    /** Whether the database had the unique index before this test touched anything. */
    private bool $indexWasThere = false;

    /** Is the unique index there right now? False when the table is not even readable. */
    protected function indexIsThere(): bool
    {
        try {
            return $this->db->schema()->hasIndex('settings', 'uq_settings_name');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Run one statement, and do not fail a test over the schema cleanup.
     *
     * These run in tearDown, after a test that may have been skipped because the table was
     * gone. Raising there turns a skip into an error and hides the real reason.
     */
    protected function tolerate(string $sql): void
    {
        try {
            $this->db->query($sql);
        } catch (\Throwable) {
            // See above.
        }
    }

    /** Add the unique index if it is not there. */
    protected function ensureUniqueIndex(): void
    {
        if ($this->indexIsThere()) {
            return;
        }

        $this->tolerate(
            $this->db->type === 'postgresql'
                ? 'CREATE UNIQUE INDEX IF NOT EXISTS ' . $this->quoted('uq_settings_name')
                    . ' ON ' . $this->quoted($this->table) . ' (' . $this->quoted('setting') . ')'
                : 'ALTER TABLE ' . $this->quoted($this->table) . ' ADD UNIQUE INDEX '
                    . $this->quoted('uq_settings_name') . ' (' . $this->quoted('setting') . ')'
        );
    }

    /**
     * Make the table look like an installation the unique migration declined on.
     *
     * The index, and only the index: dropping the table would take the rest of the
     * installation's settings with it, which is how this test class broke 29 unrelated
     * tests the first time it was written.
     */
    protected function removeUniqueIndex(): void
    {
        if (!$this->indexIsThere()) {
            return;
        }

        $this->tolerate(
            $this->db->type === 'postgresql'
                ? 'DROP INDEX IF EXISTS ' . $this->quoted('uq_settings_name')
                : 'ALTER TABLE ' . $this->quoted($this->table)
                    . ' DROP INDEX ' . $this->quoted('uq_settings_name')
        );
    }

    /**
     * Leave the database exactly as it was found, index and all.
     *
     * Whichever way round it started: the suite's two backends disagree about it, and a
     * test that normalised them would be changing shared schema for every test after it.
     */
    protected function restoreTheIndexAsFound(): void
    {
        if ($this->indexWasThere) {
            $this->ensureUniqueIndex();

            return;
        }

        $this->removeUniqueIndex();
    }

    /** Every row for one setting name. */
    protected function rowsFor(string $setting): array
    {
        $result = $this->db->queryBuilder()
            ->table('#PREFIX#settings')
            ->where('setting', $setting)
            ->get();

        return ($result && $result->numRows) ? $result->fetchAll() : array();
    }

    /**
     * A first save inserts.
     *
     * The control: an upsert that never inserted would pass every "no duplicate" assertion
     * below by storing nothing at all.
     */
    public function testAFirstSaveInsertsTheRow(): void
    {
        // Act
        Settings::setSetting('probe_one', 'first');

        // Assert
        $rows = $this->rowsFor('probe_one');
        $this->assertCount(1, $rows);
        $this->assertSame('first', (string) $rows[0]['value']);
    }

    /**
     * A second save updates the same row rather than adding one.
     *
     * What `ON CONFLICT … DO UPDATE` has to do, and the half a `DO NOTHING` would get wrong:
     * a setting that silently refused to change is worse than one that errors, because the
     * screen says it saved.
     */
    public function testASecondSaveUpdatesInPlace(): void
    {
        // Arrange
        Settings::setSetting('probe_two', 'first');

        // Act
        Settings::setSetting('probe_two', 'second');

        // Assert
        $rows = $this->rowsFor('probe_two');
        $this->assertCount(1, $rows, 'the second save inserted a duplicate');
        $this->assertSame('second', (string) $rows[0]['value']);
    }

    /**
     * A save that races a row appearing underneath it does not fail.
     *
     * The reported window, arranged rather than raced: the row is inserted **after**
     * `setSetting()` would have checked and before it writes — which is exactly what a
     * second administrator's save is. With check-then-act this is a duplicate key error; an
     * upsert takes the update branch.
     *
     * Arranged, because a real race needs two connections inside one second and would be
     * the kind of test that passes on a fast machine and fails in CI. What is asserted is
     * the property the race exposes: writing a setting whose row already exists, without
     * having looked first, must work.
     */
    public function testASaveOntoARowThatAppearedUnderneathItSucceeds(): void
    {
        // Arrange — the row another request just inserted
        $this->db->query(
            $this->db->prepareQuery(
                'INSERT INTO ' . $this->quoted($this->table)
                . ' (' . $this->quoted('setting') . ', ' . $this->quoted('value') . ')'
                . ' VALUES (%s, %s)',
                'probe_race',
                'theirs'
            )
        );

        // Act — ours, with no check of its own
        Settings::setSetting('probe_race', 'ours');

        // Assert — one row, last writer wins, and no exception
        $rows = $this->rowsFor('probe_race');
        $this->assertCount(1, $rows, 'the race produced a duplicate row');
        $this->assertSame('ours', (string) $rows[0]['value']);
    }

    /**
     * Repeated saves never accumulate rows.
     *
     * Ten in a row, because a settings form calls `setSetting()` once per field and an
     * upsert that inserted on every call would show up as a table growing by the number of
     * fields on every save.
     */
    public function testRepeatedSavesKeepOneRow(): void
    {
        // Act
        for ($i = 0; $i < 10; $i++) {
            Settings::setSetting('probe_many', 'value ' . $i);
        }

        // Assert
        $rows = $this->rowsFor('probe_many');
        $this->assertCount(1, $rows);
        $this->assertSame('value 9', (string) $rows[0]['value']);
    }

    /**
     * Without the unique index the old path runs, and still works.
     *
     * `2026_05_26_000051` **declines** when the table already holds two rows for one name —
     * deleting somebody's configuration is not a migration's decision — so an installation
     * can legitimately be running without the constraint.
     *
     * There the upsert is not merely unhelpful, it is wrong in two different ways:
     * PostgreSQL raises *«no unique or exclusion constraint matching the ON CONFLICT
     * specification»*, and MySQL's `ON DUPLICATE KEY UPDATE` with nothing to conflict on
     * quietly inserts another duplicate — silent, and compounding. So the check-then-act
     * path stays for those, window and all.
     */
    public function testWithoutTheUniqueIndexTheOldPathStillWrites(): void
    {
        // Arrange — the pre-constraint shape
        $this->removeUniqueIndex();
        Settings::forgetSchemaFacts();

        // Act
        Settings::setSetting('probe_nouq', 'first');
        Settings::setSetting('probe_nouq', 'second');

        // Assert — one row, updated, and no error
        $rows = $this->rowsFor('probe_nouq');
        $this->assertCount(1, $rows, 'the fallback path duplicated the row');
        $this->assertSame('second', (string) $rows[0]['value']);
    }

    /**
     * The schema fact is remembered, not re-read on every write.
     *
     * A settings form saves one field at a time; a catalogue query per field is a cost
     * nobody asked for.
     *
     * Asserted on the memo itself rather than on behaviour, and a first version of this
     * test is why. It dropped the index after the first save and asserted the second still
     * worked — which is not the memo being useful, it is the memo being **stale**: with the
     * index gone the upsert raises on PostgreSQL and inserts a duplicate on MySQL. The test
     * passed on MySQL by writing the bug it was supposed to prevent, and failed on
     * PostgreSQL by writing the error. Asserting that a wrong memo survives is asserting
     * the wrong thing.
     *
     * What a stale memo actually needs is {@see testForgettingTheFactRecoversFromAStaleOne}.
     */
    public function testTheSchemaFactIsMemoised(): void
    {
        // Arrange
        $reflected = new \ReflectionProperty(Settings::class, 'uniqueSettingIndex');
        $this->assertNull($reflected->getValue(), 'setUp left a memo behind');

        // Act
        Settings::setSetting('probe_memo', 'first');

        // Assert — asked once, and the answer kept
        $this->assertTrue(
            $reflected->getValue(),
            'the index question was not answered, so it is being asked on every write'
        );
    }

    /**
     * A memo that has gone stale is recoverable, and that is what the reset is for.
     *
     * The realistic case is not somebody dropping an index: it is a **migration running
     * inside a long-lived process**. A worker that started before `2026_05_26_000051`
     * applied would keep taking the check-then-act path — correct, just not the fast one —
     * until it was restarted. The reverse direction is the dangerous one, and this asserts
     * the way out of it exists and works.
     */
    public function testForgettingTheFactRecoversFromAStaleOne(): void
    {
        // Arrange — a memo that says "there is an index", and a table that no longer has one
        Settings::setSetting('probe_stale', 'first');

        $this->removeUniqueIndex();

        // Act — the process is told the schema changed
        Settings::forgetSchemaFacts();
        Settings::setSetting('probe_stale', 'second');

        // Assert — the fallback path ran, and wrote one row
        $rows = $this->rowsFor('probe_stale');
        $this->assertCount(1, $rows, 'the stale memo took the upsert path anyway');
        $this->assertSame('second', (string) $rows[0]['value']);
    }

    /**
     * A new connection is asked again.
     *
     * The memo belongs to the connection: a different database is a different settings
     * table, and answering for the old one would pick the wrong write path — which on MySQL
     * means a silent duplicate rather than an error.
     */
    public function testANewConnectionForgetsTheSchemaFact(): void
    {
        // Arrange — asked and answered
        Settings::setSetting('probe_conn', 'first');

        $reflected = new \ReflectionProperty(Settings::class, 'uniqueSettingIndex');
        $this->assertNotNull($reflected->getValue(), 'the fact was never memoised');

        // Act
        Settings::setDatabase($this->db);

        // Assert
        $this->assertNull($reflected->getValue(), 'a new connection kept the old answer');
    }

    /**
     * `clearSettings()` drops the connection, so it has to drop the answer about it too.
     *
     * The same reasoning as {@see testANewConnectionForgetsTheSchemaFact} from the other
     * end: that one covers a connection being replaced, this one covers it being removed.
     * A memo surviving `clearSettings()` would be answered for a database this class is no
     * longer holding.
     */
    public function testClearingTheSettingsForgetsTheSchemaFact(): void
    {
        // Arrange — asked and answered
        Settings::setSetting('probe_clear', 'first');

        $reflected = new \ReflectionProperty(Settings::class, 'uniqueSettingIndex');
        $this->assertNotNull($reflected->getValue(), 'the fact was never memoised');

        // Act
        Settings::clearSettings();

        // Assert
        $this->assertNull($reflected->getValue(), 'the answer outlived the connection');

        // Put the connection back for tearDown, which has rows to remove.
        Settings::setDatabase($this->db);
    }

    /**
     * A catalogue that cannot be read answers "no".
     *
     * `hasIndex()` needs a working connection and a readable catalogue. Neither is
     * guaranteed: a worker's MySQL connection times out, a replica fails over mid-request,
     * a restricted role cannot see `pg_indexes`. The two ways of being wrong there are not
     * symmetric — assuming the index is present turns every save into an error on
     * PostgreSQL and a silent duplicate on MySQL, while assuming it is absent takes the
     * path this framework had for its whole life. So it fails closed.
     *
     * Asked directly rather than through `setSetting()`, because a connection broken enough
     * to fail the catalogue question fails the write too, and the write raising would tell
     * us nothing about the guard.
     */
    public function testAnUnreadableCatalogueAnswersNo(): void
    {
        // Arrange — a connection whose catalogue cannot be questioned
        $unreadable = new class extends Database {
            public function schema(): never
            {
                throw new \Exception('catalogue unavailable');
            }
        };

        Settings::setDatabase($unreadable);

        // Act
        $answer = new \ReflectionMethod(Settings::class, 'hasUniqueSettingIndex');

        // Assert — no, and memoised as no rather than left to be asked on every write
        $this->assertFalse($answer->invoke(null), 'an unanswerable question must answer no');

        $reflected = new \ReflectionProperty(Settings::class, 'uniqueSettingIndex');
        $this->assertFalse(
            $reflected->getValue(),
            'the failure was not memoised, so every write will retry a catalogue that is down'
        );

        // Put the real connection back for tearDown, which has rows to remove.
        Settings::setDatabase($this->db);
    }
}
