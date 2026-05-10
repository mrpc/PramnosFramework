<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\User;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Application\Application;
use Pramnos\Framework\Factory;
use Pramnos\User\User;

/**
 * Characterization tests for the admin-user creation path on MySQL.
 *
 * Mirrors UserAdminCreationPostgreSQLCharacterizationTest but targets MySQL 8.
 * The scaffold's createAdminUser() snippet executes new User(0) → setPassword()
 * → save() against the table created by the framework migration (NOT by
 * User::setupDb()). The two schemas differ:
 *
 *   1. The migration omits DEFAULT 0 on usertype / sex / birthdate / modified —
 *      these are NOT NULL without a server-side default.
 *   2. The migration adds vat / fbauth columns that setupDb() does not.
 *
 * Because _save() explicitly provides values for all required columns via the
 * itemdata array, both schemas should accept the INSERT. If they do not, these
 * tests surface the real MySQL error rather than a silent failure.
 *
 */
#[\PHPUnit\Framework\Attributes\Group('mysql')]
#[\PHPUnit\Framework\Attributes\Group('characterization')]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class UserAdminCreationMySQLCharacterizationTest extends TestCase
{
    /** @var \Pramnos\Database\Database */
    private $db;

    // ── Schema SQL mirroring CreateUsersTable migration (no DEFAULT on usertype/sex/birthdate/modified) ──

    private const CREATE_USERS_SQL = "
        CREATE TABLE users (
            userid      BIGINT       NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username    VARCHAR(50)  NOT NULL DEFAULT '',
            password    VARCHAR(100) NOT NULL DEFAULT '',
            email       VARCHAR(150) NOT NULL DEFAULT '',
            lastname    VARCHAR(128) NOT NULL DEFAULT '',
            firstname   VARCHAR(128) NOT NULL DEFAULT '',
            regdate     INT          NOT NULL DEFAULT 0,
            regcompletion   INT      DEFAULT NULL,
            lasttermsagreed INT      DEFAULT NULL,
            lastlogin   INT          NOT NULL DEFAULT 0,
            active      TINYINT      NOT NULL DEFAULT 1,
            validated   TINYINT      NOT NULL DEFAULT 1,
            language    VARCHAR(50)  NOT NULL DEFAULT '',
            timezone    CHAR(3)      NOT NULL DEFAULT '',
            dateformat  VARCHAR(15)  NOT NULL DEFAULT 'd/m/Y H:i',
            usertype    TINYINT      NOT NULL,
            sex         TINYINT      NOT NULL,
            birthdate   BIGINT       NOT NULL,
            photo       INT          DEFAULT NULL,
            phone       VARCHAR(50)  NOT NULL DEFAULT '',
            fax         VARCHAR(50)  NOT NULL DEFAULT '',
            mobile      VARCHAR(50)  NOT NULL DEFAULT '',
            vat         VARCHAR(15)  NOT NULL DEFAULT '',
            website     VARCHAR(255) NOT NULL DEFAULT '',
            modified    INT          NOT NULL,
            fbauth      BIGINT       DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    private const CREATE_USERDETAILS_SQL = "
        CREATE TABLE IF NOT EXISTS userdetails (
            userid    BIGINT       NOT NULL,
            fieldname VARCHAR(35)  NOT NULL,
            value     VARCHAR(255) NOT NULL,
            PRIMARY KEY (userid, fieldname)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
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

        $settingsFile = ROOT . '/tests/fixtures/app/settings.php';
        Settings::loadSettings($settingsFile);
        Application::getInstance();

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        // Drop all user-family tables (FK_CHECKS=0) and rebuild with migration schema.
        // We drop usertokens/userstogroups/usergroups too because setupDb() creates them
        // with FKs pointing to users; leaving them orphaned between tests causes
        // "Table doesn't exist" errors in the next test's tearDown.
        $this->db->query('SET FOREIGN_KEY_CHECKS=0');
        $this->db->query('DROP TABLE IF EXISTS usertokens');
        $this->db->query('DROP TABLE IF EXISTS userstogroups');
        $this->db->query('DROP TABLE IF EXISTS usergroups');
        $this->db->query('DROP TABLE IF EXISTS userdetails');
        $this->db->query('DROP TABLE IF EXISTS users');
        $this->db->query('SET FOREIGN_KEY_CHECKS=1');
        $this->db->query(self::CREATE_USERS_SQL);
        $this->db->query(self::CREATE_USERDETAILS_SQL);
    }

    protected function tearDown(): void
    {
        // Drop all user-family tables then restore via setupDb() so that other
        // test classes in the same suite run see a consistent schema.
        $this->db->query('SET FOREIGN_KEY_CHECKS=0');
        $this->db->query('DROP TABLE IF EXISTS usertokens');
        $this->db->query('DROP TABLE IF EXISTS userstogroups');
        $this->db->query('DROP TABLE IF EXISTS usergroups');
        $this->db->query('DROP TABLE IF EXISTS userdetails');
        $this->db->query('DROP TABLE IF EXISTS users');
        $this->db->query('SET FOREIGN_KEY_CHECKS=1');
        User::setupDb();
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * Saving the first admin user (userid=1) must succeed on a MySQL table
     * created by the framework migration.
     *
     * The migration schema has usertype / sex / birthdate / modified as NOT NULL
     * without server-side defaults. _save() must supply explicit values for all
     * of them; otherwise MySQL rejects the INSERT. This test guards that
     * regression.
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
            'save() must assign a positive userid from the MySQL AUTO_INCREMENT');

        // Assert — row exists in the database
        $result = $this->db->execute(
            'SELECT userid, username FROM users WHERE username = %s',
            $user->username
        );
        $this->assertSame(1, $result->numRows,
            'The saved admin row must be retrievable from MySQL');
    }

    /**
     * When a subsequent save is performed after the initial insert, the second
     * call must not insert a duplicate row but update the existing one.
     *
     * User::_save() has a conditional that re-enters the INSERT path when
     * userid == 1 (_isnew check). After first save, _isnew=0 and userid=1;
     * this verifies the second save triggers UPDATE, not INSERT, leaving
     * exactly one row in the table.
     */
    public function testSecondSaveUpdatesRatherThanDuplicating(): void
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
        $user->save();

        // Act — second save (simulates password re-set after initial save)
        $user->setPassword('Test1234!');
        $user->save();

        // Assert — still exactly one row (UPDATE, not INSERT)
        $uname = 'admin';
        $result = $this->db->execute(
            'SELECT userid FROM users WHERE username = %s',
            $uname
        );
        $this->assertSame(1, $result->numRows,
            'Second save must UPDATE the existing row, not INSERT a duplicate');
    }

    /**
     * After a failed execute(), a non-empty error message must be available to
     * the caller — either via Database::getError() or via the exception thrown
     * by the driver.
     *
     * On MySQL, mysqli_report(MYSQLI_REPORT_STRICT) is active, so
     * prepare()/execute() throw mysqli_sql_exception rather than returning false.
     * The test therefore accepts EITHER behaviour:
     *   (a) the exception is thrown with a non-empty message, OR
     *   (b) execute() returns false and getError()['message'] is non-empty.
     * Both outcomes prove the error is surfaced, not silently swallowed.
     */
    public function testSaveFailureReportsNonEmptyErrorMessage(): void
    {
        // Act — INSERT into a non-existent table to provoke a MySQL error.
        // Store in variable: execute() takes arguments by reference.
        $val = 'x';
        $errorMessage = '';
        try {
            $this->db->execute('INSERT INTO nonexistent_table (col) VALUES (%s)', $val);
            $err = $this->db->getError();
            $errorMessage = $err['message'] ?? '';
        } catch (\Throwable $e) {
            // MySQL strict mode throws mysqli_sql_exception — capture the message
            $errorMessage = $e->getMessage();
        }

        // Assert — a non-empty error must be surfaced by one of the two paths
        $this->assertNotEmpty(
            $errorMessage,
            'A failed MySQL execute() must produce a non-empty error message '
            . '(either via getError() or via thrown exception). '
            . 'Got empty string — the error propagation chain is broken.'
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
        $threw   = false;
        $message = '';
        try {
            $user->save();
        } catch (\Throwable $e) {
            $threw   = true;
            $message = $e->getMessage();
        }

        // Assert — _save() must throw for empty username/email
        $this->assertTrue($threw,
            '_save() must throw an Exception when username is empty, so the '
            . "snippet's try-catch can surface it as FAIL:<message>");
        $this->assertStringContainsString('username', strtolower($message),
            'Exception message must mention the offending field');
    }
}
