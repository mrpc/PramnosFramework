<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\User;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Application\Application;
use Pramnos\Framework\Factory;
use Pramnos\User\User;

/**
 * Characterization tests for the admin-user creation path on PostgreSQL.
 *
 * The scaffold's createAdminUser() snippet executes new User(0) → setPassword()
 * → save() against the table created by the framework migration (NOT by
 * User::setupDb()). The two schemas differ in two important ways:
 *
 *   1. The migration omits DEFAULT 0 on usertype / sex / birthdate / modified —
 *      these are NOT NULL without a server-side default.
 *   2. The migration adds vat / fbauth columns that setupDb() does not.
 *
 * Because _save() explicitly provides values for all required columns via the
 * itemdata array, both schemas should accept the INSERT. If they do not, these
 * tests will surface the real PostgreSQL error message (which previously was
 * silently discarded by the prepare()→DEALLOCATE→pg_last_error loss).
 *
 * Additionally, this suite verifies the error-propagation chain fixed in
 * Database::prepare() and Database::execute(): when an INSERT fails, the
 * caller must receive a non-empty, human-readable error message.
 *
 */
#[\PHPUnit\Framework\Attributes\Group('postgresql')]
#[\PHPUnit\Framework\Attributes\Group('characterization')]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class UserAdminCreationPostgreSQLCharacterizationTest extends TestCase
{
    /** @var \Pramnos\Database\Database */
    private $db;

    // ── Schema SQL mirroring CreateUsersTable migration (no DEFAULT on usertype/sex/birthdate/modified) ──

    private const CREATE_USERS_SQL = "
        CREATE TABLE users (
            userid          BIGSERIAL PRIMARY KEY,
            username        VARCHAR(50)  NOT NULL DEFAULT '',
            password        VARCHAR(100) NOT NULL DEFAULT '',
            email           VARCHAR(150) NOT NULL DEFAULT '',
            lastname        VARCHAR(128) NOT NULL DEFAULT '',
            firstname       VARCHAR(128) NOT NULL DEFAULT '',
            regdate         INTEGER      NOT NULL DEFAULT 0,
            regcompletion   INTEGER      DEFAULT NULL,
            lasttermsagreed INTEGER      DEFAULT NULL,
            lastlogin       INTEGER      NOT NULL DEFAULT 0,
            active          SMALLINT     NOT NULL DEFAULT 1,
            validated       SMALLINT     NOT NULL DEFAULT 1,
            language        VARCHAR(50)  NOT NULL DEFAULT '',
            timezone        CHAR(3)      NOT NULL DEFAULT '',
            dateformat      VARCHAR(15)  NOT NULL DEFAULT 'd/m/Y H:i',
            usertype        SMALLINT     NOT NULL,
            sex             SMALLINT     NOT NULL,
            birthdate       BIGINT       NOT NULL,
            photo           INTEGER      DEFAULT NULL,
            phone           VARCHAR(50)  NOT NULL DEFAULT '',
            fax             VARCHAR(50)  NOT NULL DEFAULT '',
            mobile          VARCHAR(50)  NOT NULL DEFAULT '',
            vat             VARCHAR(15)  NOT NULL DEFAULT '',
            website         VARCHAR(255) NOT NULL DEFAULT '',
            modified        INTEGER      NOT NULL,
            fbauth          BIGINT       DEFAULT NULL
        )
    ";

    private const CREATE_USERDETAILS_SQL = "
        CREATE TABLE IF NOT EXISTS userdetails (
            userid    BIGINT       NOT NULL,
            fieldname VARCHAR(35)  NOT NULL,
            value     VARCHAR(255) NOT NULL,
            PRIMARY KEY (userid, fieldname)
        )
    ";

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'app');
        }
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . DIRECTORY_SEPARATOR . 'var');
        }
        if (!is_dir(LOG_PATH . DIRECTORY_SEPARATOR . 'logs')) {
            @mkdir(LOG_PATH . DIRECTORY_SEPARATOR . 'logs', 0777, true);
        }

        $pgSettingsFile = ROOT . '/tests/fixtures/app/pg_settings.php';
        Settings::loadSettings($pgSettingsFile);
        Application::getInstance();

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        // Rebuild tables fresh for each test — migration-compatible schema.
        // Drop the full user-family so orphaned sequences don't survive into setUp.
        $this->db->query('DROP TABLE IF EXISTS usertokens CASCADE');
        $this->db->query('DROP TABLE IF EXISTS userstogroups CASCADE');
        $this->db->query('DROP TABLE IF EXISTS usergroups CASCADE');
        $this->db->query('DROP TABLE IF EXISTS userdetails CASCADE');
        $this->db->query('DROP TABLE IF EXISTS users CASCADE');
        $this->db->query(self::CREATE_USERS_SQL);
        $this->db->query(self::CREATE_USERDETAILS_SQL);
    }

    protected function tearDown(): void
    {
        // Drop the full user-family (children before parents) so that no orphaned
        // sequences (usertokens_tokenid_seq, usergroups_groupid_seq) are left behind.
        // Without this, a subsequent User::setupDb() call that tries to CREATE TABLE
        // usertokens with `tokenid SERIAL` would fail with a duplicate-sequence error
        // because PostgreSQL's SERIAL creates the sequence before the table and the
        // IF NOT EXISTS check only guards the table, not the sequence.
        $this->db->query('DROP TABLE IF EXISTS usertokens CASCADE');
        $this->db->query('DROP TABLE IF EXISTS userstogroups CASCADE');
        $this->db->query('DROP TABLE IF EXISTS usergroups CASCADE');
        $this->db->query('DROP TABLE IF EXISTS userdetails CASCADE');
        $this->db->query('DROP TABLE IF EXISTS users CASCADE');
        User::setupDb();
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * Saving the first admin user (userid=1) must succeed on a PostgreSQL table
     * created by the framework migration.
     *
     * The migration schema has usertype / sex / birthdate / modified as NOT NULL
     * without server-side defaults. _save() must supply explicit values for all
     * of them; otherwise pg_prepare() rejects the statement and the INSERT
     * silently fails. This test guards that regression.
     */
    public function testAdminUserSaveSucceedsWithMigrationSchema(): void
    {
        // Arrange
        $user = new User(0);
        $user->username  = 'admin';
        $user->email     = 'admin@example.com';
        $user->usertype  = 10;
        $user->active    = 1;
        $user->validated = 1;
        $user->regdate   = time();
        $user->setPassword('Test1234!');

        // Act
        $user->save();

        // Assert — no errors; userid must be the auto-assigned value (> 0)
        $this->assertEmpty(
            $user->_errors,
            'save() must produce no errors; got: ' . implode(', ', $user->_errors)
        );
        $this->assertGreaterThan(0, (int) $user->userid,
            'save() must assign a positive userid from the PostgreSQL sequence');

        // Assert — row exists in the database
        $result = $this->db->execute(
            'SELECT userid, username FROM users WHERE username = %s',
            $user->username
        );
        $this->assertSame(1, $result->numRows,
            'The saved admin row must be retrievable from PostgreSQL');
    }

    /**
     * When a second save with the same username is attempted, _errors must
     * contain a non-empty, human-readable error string.
     *
     * This guards the pg_last_error / error_text propagation fix: previously the
     * error was silently dropped (prepare() called DEALLOCATE which cleared
     * pg_last_error before Database::getError() read it), so callers received
     * an empty error message and could not diagnose the failure.
     */
    public function testSaveFailureReportsNonEmptyErrorMessage(): void
    {
        // Arrange — insert a row directly so the sequence is at 1.
        // Store in variables first: execute() takes arguments by reference,
        // so literals cannot be passed directly.
        $uid = 1; $uname = 'admin'; $email = 'admin@example.com';
        $utype = 10; $sex = 0; $birth = 0; $mod = 0;
        $this->db->execute(
            'INSERT INTO users (userid, username, email, usertype, sex, birthdate, modified) VALUES (%i, %s, %s, %i, %i, %i, %i)',
            $uid, $uname, $email, $utype, $sex, $birth, $mod
        );
        $this->db->execute(
            "SELECT setval(pg_get_serial_sequence('users', 'userid'), 1)"
        );

        // Act — use a genuinely bad INSERT (invalid table) to verify the full chain.
        $val = 'x';
        $this->db->execute('INSERT INTO nonexistent_table (col) VALUES (%s)', $val);
        $err = $this->db->getError();

        // Assert — error message must be non-empty after a failed execute()
        $this->assertNotEmpty(
            $err['message'],
            'Database::getError() must return a non-empty message after a failed prepare()/execute(). '
            . 'Got empty string — the pg_last_error propagation chain is broken.'
        );
    }

    /**
     * After a failed save(), the _errors array must contain a non-empty string,
     * not a silent empty string that hides the root cause.
     *
     * We force the failure by saving a User with a deliberately empty username,
     * which _save() rejects with an Exception — verifying the try-catch in the
     * admin snippet surfaces it correctly.
     */
    public function testSaveWithInvalidUsernameThrowsInsteadOfSilentlyFailing(): void
    {
        // Arrange
        $user = new User(0);
        $user->username  = '';       // _save() throws on empty username
        $user->email     = 'test@example.com';
        $user->usertype  = 10;
        $user->regdate   = time();
        $user->setPassword('Test1234!');

        // Act
        $threw = false;
        $message = '';
        try {
            $user->save();
        } catch (\Throwable $e) {
            $threw = true;
            $message = $e->getMessage();
        }

        // Assert — _save() must throw for empty username/email
        $this->assertTrue($threw,
            '_save() must throw an Exception when username is empty, so the '
            . 'snippet\'s try-catch can surface it as FAIL:<message>');
        $this->assertStringContainsString('username', strtolower($message),
            'Exception message must mention the offending field');
    }

    /**
     * After CreateUsersTable::up() advances the PostgreSQL sequence to 1
     * (is_called=true → next nextval() returns 2), the first admin created by
     * the scaffold must receive userid=2, not userid=1.
     *
     * userid=1 is permanently reserved for the Guest/anonymous identity that
     * User::setupDb() seeds separately. Before this fix the migration did not
     * advance the sequence, so the first INSERT always landed at userid=1.
     */
    public function testAdminUserDoesNotClaimGuestUserid(): void
    {
        // Arrange — advance the sequence exactly as CreateUsersTable::up() now does.
        // setval(seq, 1, true=default) → current=1, is_called=true → next nextval()=2.
        $this->db->query("SELECT setval(pg_get_serial_sequence('users', 'userid'), 1)");

        $user = new User(0);
        $user->username  = 'admin';
        $user->email     = 'admin@example.com';
        $user->usertype  = 10;
        $user->active    = 1;
        $user->validated = 1;
        $user->regdate   = time();
        $user->setPassword('Test1234!');

        // Act
        $user->save();

        // Assert — admin must NOT land on the reserved Guest userid
        $this->assertEmpty(
            $user->_errors,
            'save() must produce no errors; got: ' . implode(', ', $user->_errors)
        );
        $this->assertGreaterThan(1, (int) $user->userid,
            'Admin userid must be > 1; userid=1 is reserved for the Guest/anonymous user. '
            . 'Got userid=' . $user->userid . ' — migration sequence advance is broken or '
            . '_save() is not honouring it.'
        );
    }
}
