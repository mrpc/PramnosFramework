<?php

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;

/**
 * Settings against a live PostgreSQL, which is where its raw SQL broke.
 *
 * `loadAllSettings()` read every setting in one query — as a hand-written string
 * with MySQL backticks, passed straight to `query()` without going through
 * `prepareQuery()`, so nothing translated it. On PostgreSQL that is a syntax
 * error, and the `catch (\Throwable)` around it turned the error into silence:
 *
 *   syntax error at or near ","   …   select `setting`, `value` from `settings`
 *
 * Nothing broke visibly. The bulk read simply did nothing, every lookup fell
 * back to a query of its own — the N round-trips the bulk read exists to
 * replace — and each failed request wrote another line into the error log. It
 * ran that way in a real application until the log was read for another reason.
 *
 * The unit tests could not have caught it: they exercise the in-memory store,
 * where no SQL is generated at all. This talks to the database, because the bug
 * was in the dialect and only a dialect can report it.
 *
 * Requires the Docker TimescaleDB/PostgreSQL container (host: timescaledb).
 */
class SettingsPostgreSQLTest extends TestCase
{
    /** @var Database Live PostgreSQL connection used by all tests here. */
    protected Database $db;

    /** The settings table, as the application's prefix makes it. */
    protected string $table;

    protected function setUp(): void
    {
        $this->db = new Database();
        $this->db->type     = 'postgresql';
        $this->db->server   = 'timescaledb';
        $this->db->user     = 'postgres';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 5432;
        $this->db->schema   = 'public';
        $this->db->connect(true);

        $this->table = $this->db->prefix . 'settings';

        $this->db->execute('DROP TABLE IF EXISTS "' . $this->table . '"');
        $this->db->execute('CREATE TABLE "' . $this->table . '" (
            "setting" VARCHAR(255) PRIMARY KEY,
            "value"   TEXT
        )');

        $this->resetStatics();
        Settings::setDatabase($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS "' . $this->table . '"');
        $this->db->close();
        $this->resetStatics();
    }

    /**
     * The bulk read must actually read, on PostgreSQL.
     *
     * This is the regression itself: a value written straight into the table,
     * then read through `getSetting()` with an empty in-memory store, so the
     * only way to the answer is `loadAllSettings()`. With backticks in the SQL
     * this returned the default and logged an error nobody saw.
     */
    public function testBulkLoadReadsEverySettingOnPostgreSQL(): void
    {
        // Arrange — two rows the in-memory store has never seen
        $this->db->execute(
            'INSERT INTO "' . $this->table . '" ("setting", "value") '
            . "VALUES ('sitename', 'Integration'), ('timezone', 'Europe/Athens')"
        );

        // The bulk read is cached for Settings::CACHE_TTL under the "settings"
        // category, keyed on the query — which does not change when setUp drops and
        // recreates the table. A run that populated that entry made this test read a
        // stale empty result and fail on the default for the next five minutes, with
        // nothing in the diff to explain it. Only setSetting() invalidates the
        // category, and the rows above were inserted around it.
        $this->db->cacheflush('settings');

        // Act — one call, which must bulk-load both
        $sitename = Settings::getSetting('sitename');

        // Assert — the row, not the default
        $this->assertSame('Integration', $sitename);
        // And the second one came back in the same read, without another query:
        // the store now answers for it directly.
        $this->assertSame('Europe/Athens', Settings::getSetting('timezone'));
    }

    /**
     * The bulk read must not write errors into the log while appearing to work.
     *
     * The failure mode that hid this for so long was a caught exception: the
     * application carried on, slower, while the log filled up. Asserting that
     * the read *succeeded* is the direct test; asserting the query log carries
     * no failed statement is what would notice the same shape of mistake again.
     */
    public function testBulkLoadIssuesAValidStatement(): void
    {
        // Arrange
        $this->db->execute(
            'INSERT INTO "' . $this->table . '" ("setting", "value") '
            . "VALUES ('flavour', 'postgres')"
        );
        $this->db->enableQueryLog();

        // Act
        Settings::getSetting('flavour');

        // Assert — no backtick reached the server
        $log = $this->db->getQueryLog();
        $this->assertNotEmpty($log, 'the bulk read must have run a query');
        foreach ($log as $entry) {
            $this->assertStringNotContainsString(
                '`',
                (string) ($entry['sql'] ?? ''),
                'a MySQL backtick in a PostgreSQL statement is a syntax error'
            );
        }
    }

    /**
     * setSetting() must write, and the value must survive a fresh read.
     *
     * Its three statements — the existence check, the update and the insert —
     * were hand-built too, and only the write path exercises them.
     */
    public function testSetSettingWritesAndUpdatesOnPostgreSQL(): void
    {
        // Act — insert, then overwrite the same key
        Settings::setSetting('greeting', 'hello');
        $this->resetStatics();
        Settings::setDatabase($this->db);
        $afterInsert = Settings::getSetting('greeting');

        Settings::setSetting('greeting', 'goodbye');
        $this->resetStatics();
        Settings::setDatabase($this->db);
        $afterUpdate = Settings::getSetting('greeting');

        // Assert — the second write updated the row rather than adding one
        $this->assertSame('hello', $afterInsert);
        $this->assertSame('goodbye', $afterUpdate);

        $rows = $this->db->query(
            'SELECT COUNT(*) AS c FROM "' . $this->table . '" WHERE "setting" = \'greeting\''
        );
        $this->assertSame(1, (int) $rows->fields['c'], 'one key, one row');
    }

    /**
     * deleteSetting() removes the row.
     */
    public function testDeleteSettingRemovesTheRowOnPostgreSQL(): void
    {
        // Arrange
        Settings::setSetting('temporary', 'yes');

        // Act
        Settings::deleteSetting('temporary');
        $this->resetStatics();
        Settings::setDatabase($this->db);

        // Assert
        $this->assertFalse(Settings::getSetting('temporary'));
    }

    /**
     * Wipe Settings' static state so each test starts from an empty store.
     *
     * The class is static by design, so without this a value read in one test
     * answers for the next one and the database is never consulted — which
     * would make every test here pass without proving anything.
     */
    private function resetStatics(): void
    {
        $ref = new \ReflectionClass(Settings::class);
        $ref->getProperty('settings')->setValue(null, []);
        $ref->getProperty('loaded')->setValue(null, false);
        $ref->getProperty('bulkLoaded')->setValue(null, false);
        $ref->getProperty('database')->setValue(null, null);
    }
}
