<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\User;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\User;

/**
 * The per-user settings store — 56 statements across four accessors, never executed.
 *
 * The framework had two places to keep something about a user and neither fits: `users` columns are
 * the schema every application shares, so an application cannot add to them, and `userdetails` is a
 * blob written by `__set()` — fine for a value the application's own code reads, unusable for one an
 * operator has to see and change, because a blob has no list, no per-key delete and no per-key
 * history. This is the third place, and the one with a screen behind it.
 *
 * Which is what makes the four decisions here worth pinning, since all four exist for the operator
 * rather than for the code:
 *
 * - **the value is JSON, not text.** A setting is as likely to be a list as a string, and a store
 *   that flattened everything to text would hand back `"1"` for a boolean and `"[1,2]"` for a list.
 * - **a write upserts.** Checked-then-written, two requests saving the same switch race into two
 *   rows and "the value" stops having an answer — which no later read can resolve.
 * - **removing a setting deletes the row.** A null value is a switch somebody turned off; no row is
 *   a switch nobody has touched, and only the second lets the application's own default apply
 *   again. Conflating them means a default can never come back.
 * - **a value that is not valid JSON is returned raw.** Rows get written by hand in a database
 *   client, and refusing to read one is the store deciding an operator's edit did not happen.
 *
 * Both backends, and here it earns its keep: the `value` column is `text` holding JSON — MySQL and
 * PostgreSQL differ on what a `text` round-trip does to it — and the upsert leans on a composite
 * unique index that the two spell differently. {@see UserSettingsPostgreSQLTest} re-runs all of it.
 */
#[CoversClass(User::class)]
class UserSettingsTest extends BaseTestCase
{
    private $db;

    private int $uid = 0;

    private int $otherUid = 0;

    /** @var array<string, bool> Which lanes have rebuilt their table this run. */
    private static array $migrated = [];

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

        User::setupDb();

        /*
         * Once per class. Dropping and re-migrating on every test costs more than the whole class
         * takes to run, and the shape cannot change between tests of one class — the drop is there
         * to defeat a shape left by a *different* one. Keyed by class so each backend lane rebuilds
         * its own.
         */
        if (!isset(self::$migrated[static::class])) {
            $this->migrateSettingsTable();
            self::$migrated[static::class] = true;
        }

        $this->uid      = $this->makeUser('settings');
        $this->otherUid = $this->makeUser('neighbour');
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    /**
     * Built from the shipped migration, dropped first.
     *
     * The table survives between runs and `runMigrations()` is a no-op when it already exists, so a
     * shape left by an earlier run would silently decide whether the writes below succeed. The
     * composite unique index is the part that matters: without it the upsert has nothing to
     * protect and a test asserting "one row" would pass for the wrong reason.
     */
    private function migrateSettingsTable(): void
    {
        $this->db->query(
            'DROP TABLE IF EXISTS ' . $this->db->schema()->quoteTable('#PREFIX#usersettings')
        );
        $this->runMigrations([
            \Pramnos\Framework\Migrations\Core\CreateUsersettingsTable::class,
        ], $this->db);
    }

    private function makeUser(string $prefix): int
    {
        $user = new User();
        $user->username = $prefix . '_' . bin2hex(random_bytes(4));
        $user->email    = $user->username . '@example.test';
        $user->save();

        return (int) $user->userid;
    }

    protected function tearDown(): void
    {
        foreach ([$this->uid, $this->otherUid] as $uid) {
            if ($uid <= 0) {
                continue;
            }

            foreach (['#PREFIX#usersettings', '#PREFIX#userdetails', '#PREFIX#users'] as $table) {
                try {
                    $this->db->queryBuilder()->table($table)->where('userid', $uid)->delete();
                } catch (\Throwable) {
                    // Nothing to undo.
                }
            }
        }

        User::clearUserCache();

        parent::tearDown();
    }

    private function user(): User
    {
        return new User($this->uid);
    }

    private function rowCount(string $setting): int
    {
        $result = $this->db->queryBuilder()
            ->table('#PREFIX#usersettings')
            ->where('userid', $this->uid)
            ->where('setting', $setting)
            ->get();

        return (int) ($result->numRows ?? 0);
    }

    // ── The value keeps its type ──────────────────────────────────────────────

    /**
     * A list stays a list, a number stays a number, a bool stays a bool.
     *
     * The reason the column holds JSON rather than text. A store that flattened everything would
     * hand back `"1"` for a boolean — truthy, and also truthy for the string `"0"`, which is the
     * classic way a switch ends up permanently on.
     */
    public function testEveryTypeSurvivesTheRoundTrip(): void
    {
        // Arrange
        $user = $this->user();
        $cases = [
            'a-string'  => 'plain text',
            'an-int'    => 42,
            'a-float'   => 1.5,
            'a-true'    => true,
            'a-false'   => false,
            'a-list'    => [1, 2, 3],
            'an-object' => ['nested' => ['deep' => 'value']],
            'greek'     => 'Νέα ρύθμιση',
            'empty-str' => '',
            'zero'      => 0,
        ];

        // Act
        foreach ($cases as $name => $value) {
            $this->assertTrue($user->setSetting($name, $value), $name);
        }

        // Assert — a fresh object, so nothing is answered from memory
        $reader = new User($this->uid);

        foreach ($cases as $name => $value) {
            $this->assertSame($value, $reader->getSetting($name), $name . ' changed shape');
        }
    }

    /**
     * `false` and `0` read back as themselves rather than as the default.
     *
     * The failure this store would have if it tested truthiness anywhere: a switch explicitly set
     * to off reads as never-set, so the application's default (usually on) applies and the setting
     * cannot be turned off at all. It is the same bug as `isset()` versus `array_key_exists()`, in
     * a place where the answer is a person's preference.
     */
    public function testFalseAndZeroAreValuesNotAbsences(): void
    {
        // Arrange
        $user = $this->user();
        $user->setSetting('notify', false);
        $user->setSetting('retries', 0);

        // Act & Assert
        $reader = new User($this->uid);
        $this->assertFalse($reader->getSetting('notify', true), 'off read as never-set');
        $this->assertSame(0, $reader->getSetting('retries', 5));
    }

    /**
     * A row written by hand that is not valid JSON reads back as the raw string.
     *
     * Rows get edited in a database client — that is half the point of a store an operator can see.
     * Refusing to read one, or answering with the default, is the store deciding somebody's edit
     * did not happen; handing back what is there lets them see what they typed.
     */
    public function testAValueThatIsNotJsonComesBackRaw(): void
    {
        // Arrange — as if typed into a database client
        $this->db->queryBuilder()->table('#PREFIX#usersettings')->insert([
            'userid'     => $this->uid,
            'setting'    => 'hand-written',
            'value'      => 'not json at all',
            'updated_at' => time(),
            'updated_by' => null,
        ]);

        // Act & Assert
        $this->assertSame('not json at all', $this->user()->getSetting('hand-written'));
    }

    // ── Writing ───────────────────────────────────────────────────────────────

    /**
     * A second write replaces the value and leaves one row.
     *
     * Upserted rather than checked-then-written: two requests saving the same switch would race
     * into two rows, and "the value" would stop having an answer that any read could resolve. One
     * row is the assertion — the value alone would be satisfied by two rows read in the lucky
     * order.
     */
    public function testASecondWriteReplacesRatherThanDuplicates(): void
    {
        // Arrange
        $user = $this->user();
        $user->setSetting('theme', 'light');

        // Act
        $this->assertTrue($user->setSetting('theme', 'dark'));

        // Assert
        $this->assertSame(1, $this->rowCount('theme'), 'the same setting is stored twice');
        $this->assertSame('dark', (new User($this->uid))->getSetting('theme'));
    }

    /**
     * A stored `null` is not the same as no row.
     *
     * The distinction the delete exists for, from the reading side: a null value answers null even
     * when a default was offered, because somebody deliberately set the setting to nothing. A
     * default that overrode it would make "unset this" impossible to express.
     */
    public function testAStoredNullIsNotAnAbsentSetting(): void
    {
        // Arrange
        $user = $this->user();
        $user->setSetting('signature', null);

        // Act & Assert
        $reader = new User($this->uid);
        $this->assertNull($reader->getSetting('signature', 'the default'), 'a default overrode a null');
        $this->assertSame(
            'the default',
            $reader->getSetting('never-set-at-all', 'the default'),
            'an absent setting did not fall back'
        );
        $this->assertSame(1, $this->rowCount('signature'), 'a null value wrote no row');
    }

    /**
     * Who wrote it is recorded, and the application writing it is recorded as nobody.
     *
     * The column exists for the operator asking "who set this on this account". A userid where the
     * application wrote it would name somebody who did nothing, which is worse than null: it is a
     * wrong answer to an accountability question rather than an absent one.
     */
    public function testWhoWroteASettingIsRecorded(): void
    {
        // Arrange
        $user = $this->user();

        // Act
        $user->setSetting('by-an-admin', 'yes', $this->otherUid);
        $user->setSetting('by-the-app', 'yes');

        // Assert
        $byName = [];
        foreach ($user->listSettings() as $row) {
            $byName[$row['setting']] = $row;
        }

        $this->assertSame($this->otherUid, $byName['by-an-admin']['updated_by']);
        $this->assertNull($byName['by-the-app']['updated_by'], 'the application was named as a user');
        $this->assertGreaterThan(0, (int) $byName['by-the-app']['updated_at']);
    }

    // ── Removing ──────────────────────────────────────────────────────────────

    /**
     * Deleting a setting brings the application's default back.
     *
     * Which is the whole reason it is a delete and not a write of null. An operator undoing a
     * switch they set expects the account to behave like an account nobody has touched, and with a
     * null row it never does again.
     */
    public function testDeletingASettingRestoresTheDefault(): void
    {
        // Arrange
        $user = $this->user();
        $user->setSetting('theme', 'dark');

        // Act
        $this->assertTrue($user->deleteSetting('theme'));

        // Assert
        $this->assertSame(0, $this->rowCount('theme'));
        $this->assertSame(
            'light',
            (new User($this->uid))->getSetting('theme', 'light'),
            'the default did not come back'
        );
    }

    /** Deleting something that was never there is not an error. */
    public function testDeletingAnAbsentSettingSucceeds(): void
    {
        // Act & Assert
        $this->assertTrue($this->user()->deleteSetting('never-existed'));
    }

    // ── Listing ───────────────────────────────────────────────────────────────

    /**
     * The list is ordered by name, decoded, and carries when each was written.
     *
     * It is read by a person looking for one switch among many, and insertion order tells them
     * nothing about where to look. The values are decoded because a screen showing `"[1,2]"` in a
     * text field cannot be edited back into a list.
     */
    public function testTheListIsOrderedByNameAndDecoded(): void
    {
        // Arrange
        $user = $this->user();
        $user->setSetting('zulu', ['last']);
        $user->setSetting('alpha', 1);
        $user->setSetting('mike', 'middle');

        // Act
        $list = $user->listSettings();

        // Assert
        $this->assertSame(['alpha', 'mike', 'zulu'], array_column($list, 'setting'));
        $this->assertSame(1, $list[0]['value']);
        $this->assertSame(['last'], $list[2]['value'], 'the value reached the screen still encoded');
        $this->assertGreaterThan(0, (int) $list[1]['updated_at']);
    }

    /** A user with no settings gets an empty list, not a row of nulls. */
    public function testAUserWithNoSettingsGetsAnEmptyList(): void
    {
        // Act & Assert
        $this->assertSame([], $this->user()->listSettings());
    }

    /**
     * One user's settings are not another's.
     *
     * Every read and write is scoped by userid, and this is the assertion that says so rather than
     * assuming it: the store is per-user by definition, and an administration screen showing the
     * wrong account's switches would be acted on.
     */
    public function testSettingsAreScopedToTheirUser(): void
    {
        // Arrange
        $this->user()->setSetting('theme', 'dark');

        // Act
        $neighbour = new User($this->otherUid);

        // Assert
        $this->assertSame('light', $neighbour->getSetting('theme', 'light'));
        $this->assertSame([], $neighbour->listSettings());
    }

    // ── What is refused ───────────────────────────────────────────────────────

    /**
     * Nothing is stored against the guest or system accounts, or under a blank name.
     *
     * `userid` 0 and 1 are the guest and the system account — not people, and a setting on either
     * would be a per-user preference for everybody who is not signed in. A blank name is a bug in
     * the caller, and storing it gives a row no screen can label and no reader can ask for.
     */
    public function testTheGuestAccountAndBlankNamesAreRefused(): void
    {
        // Arrange
        $guest = new User();
        $guest->userid = 0;

        $system = new User();
        $system->userid = 1;

        // Act & Assert
        foreach ([$guest, $system] as $notAPerson) {
            $this->assertFalse($notAPerson->setSetting('theme', 'dark'));
            $this->assertFalse($notAPerson->deleteSetting('theme'));
            $this->assertSame('light', $notAPerson->getSetting('theme', 'light'));
            $this->assertSame([], $notAPerson->listSettings());
        }

        $user = $this->user();
        $this->assertFalse($user->setSetting('', 'dark'), 'a nameless setting was stored');
        $this->assertFalse($user->deleteSetting(''));
        $this->assertSame('light', $user->getSetting('', 'light'));
    }

    /**
     * With no table, the readers degrade and the writers report failure.
     *
     * The split is the point, and it is not an inconsistency. A project that has not run the
     * migration has no settings, which is the same answer as a user with none — so a read degrades
     * rather than throwing out of whatever screen touched it, and a framework upgrade does not take
     * down every page that consults a preference.
     *
     * A **write** cannot degrade the same way, because a caller told it succeeded will tell
     * somebody the switch was changed. So both writers answer `false` — including the delete, which
     * did not delete anything: reporting «removed» for a row that is still there (or for a store
     * that cannot be reached) is the one answer an operator will act on and be wrong about.
     *
     * The table is put back in `finally`, because the rest of the suite shares this database.
     */
    public function testWithNoTableEveryAccessorDegrades(): void
    {
        // Arrange
        $user = $this->user();
        $user->setSetting('theme', 'dark');

        $this->db->query(
            'DROP TABLE IF EXISTS ' . $this->db->schema()->quoteTable('#PREFIX#usersettings')
        );

        try {
            // Act & Assert
            $this->assertSame('light', $user->getSetting('theme', 'light'), 'the read threw');
            $this->assertSame([], $user->listSettings());
            $this->assertFalse($user->setSetting('theme', 'dark'), 'the write claimed success');
            $this->assertFalse(
                $user->deleteSetting('theme'),
                'a delete that removed nothing reported that it had'
            );
        } finally {
            $this->migrateSettingsTable();
        }
    }
}
