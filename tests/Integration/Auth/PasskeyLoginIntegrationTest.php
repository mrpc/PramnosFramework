<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\Auth;
use Pramnos\Auth\Controllers\Passkey;
use Pramnos\Auth\Passkey\Config;
use Pramnos\Auth\Passkey\PasskeyService;
use Pramnos\Auth\Passkey\WebAuthnLibAdapter;
use Pramnos\Database\Database;
use Pramnos\Framework\Migrations\AuthServer\CreatePasskeyCredentialsTable;

/**
 * Integration tests for the DB-backed passkey/login seams against a real users
 * table.
 *
 * WHAT: the pieces that read the `users` table — Auth::loginById() (passwordless
 *       session bootstrap + active-status gate), PasskeyService::userIdentity()
 *       (the WebAuthn user entity built from the account), and the Passkey
 *       controller's username→id resolution.
 * WHY:  these are exercised with in-memory stubs in the unit suite; §8 requires
 *       proving them against the real schema — the active/inactive gate and the
 *       username lookup must behave on actual rows.
 *
 * The seeded accounts use distinctive usernames and only those rows are removed
 * in tearDown, so the shared `users` table is left intact.
 */
class PasskeyLoginIntegrationTest extends TestCase
{
    private const RP_ID  = 'example.com';
    private const ORIGIN = 'https://example.com';

    private Database $db;
    private Application $app;
    private bool $isPg;
    private int $activeUid;
    private int $inactiveUid;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . \DS . 'fixtures' . \DS . 'app');
        }
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        $settingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        \Pramnos\Application\Settings::loadSettings($settingsFile);

        // Use the framework's default DB singleton so Auth::loginById(),
        // the Passkey controller and PasskeyService (all Factory::getDatabase())
        // share this exact connection.
        $this->db = \Pramnos\Framework\Factory::getDatabase();
        try {
            if (!$this->db->connected && !$this->db->connect()) {
                $this->markTestSkipped('Database not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database not reachable: ' . $e->getMessage());
        }

        $this->isPg = in_array($this->db->type, ['postgresql', 'pgsql', 'timescaledb'], true);

        if ($this->isPg) {
            $this->db->statement('CREATE SCHEMA IF NOT EXISTS authserver');
        }

        $this->app = new Application();
        $this->app->database = $this->db;

        // Use the canonical users table (prefix is '' in the test settings, so
        // both #PREFIX#users and queryBuilder->table('users') resolve to it).
        \Pramnos\User\User::setupDb();
        $this->seedUsers();

        $migration = new CreatePasskeyCredentialsTable($this->app);
        $migration->down();
        $migration->up();
    }

    protected function tearDown(): void
    {
        try {
            (new CreatePasskeyCredentialsTable($this->app))->down();
        } catch (\Throwable) {
        }
        // Remove only the rows we seeded (leave the shared users table intact).
        foreach ([self::U_ACTIVE, self::U_INACTIVE] as $u) {
            try {
                $this->db->query($this->db->prepareQuery(
                    "DELETE FROM `#PREFIX#users` WHERE `username` = %s",
                    $u
                ));
            } catch (\Throwable) {
            }
        }
    }

    private const U_ACTIVE   = 'pk_login_active';
    private const U_INACTIVE = 'pk_login_inactive';

    private function seedUsers(): void
    {
        // Clean any leftovers from a previous aborted run.
        foreach ([self::U_ACTIVE, self::U_INACTIVE] as $u) {
            $this->db->query($this->db->prepareQuery(
                "DELETE FROM `#PREFIX#users` WHERE `username` = %s",
                $u
            ));
        }

        $this->db->query($this->db->prepareQuery(
            "INSERT INTO `#PREFIX#users` (`username`,`password`,`email`,`firstname`,`lastname`,`active`) "
            . "VALUES (%s,%s,%s,%s,%s,1)",
            self::U_ACTIVE, 'hash', 'alice@example.com', 'Alice', 'Smith'
        ));
        $this->activeUid = $this->uidByUsername(self::U_ACTIVE);

        $this->db->query($this->db->prepareQuery(
            "INSERT INTO `#PREFIX#users` (`username`,`password`,`email`,`active`) VALUES (%s,%s,%s,0)",
            self::U_INACTIVE, 'hash', 'bob@example.com'
        ));
        $this->inactiveUid = $this->uidByUsername(self::U_INACTIVE);
    }

    /** DB-agnostic id lookup (avoids driver-specific last-insert-id). */
    private function uidByUsername(string $username): int
    {
        $r = $this->db->query($this->db->prepareQuery(
            "SELECT `userid` FROM `#PREFIX#users` WHERE `username` = %s",
            $username
        ));
        return (int) $r->fields['userid'];
    }

    // ── Auth::loginById (buildLoginResponse real DB path) ─────────────────────

    /** loginById establishes a session for an active user (reads the real row). */
    public function testLoginByIdActiveUser(): void
    {
        // Arrange — no user addon registered, so the built-in path runs.
        $_SESSION = [];
        $auth = new Auth();

        // Act
        $result = $auth->loginById($this->activeUid);

        // Assert — session established from the real users row.
        $this->assertTrue($result);
        $this->assertTrue($_SESSION['logged'] ?? false);
        $this->assertSame($this->activeUid, $_SESSION['uid'] ?? null);
        $this->assertSame(self::U_ACTIVE, $_SESSION['username'] ?? null);
    }

    /** loginById refuses an inactive user (active-status gate). */
    public function testLoginByIdInactiveUser(): void
    {
        $_SESSION = [];
        $auth = new Auth();
        $this->assertFalse($auth->loginById($this->inactiveUid));
    }

    /** loginById refuses a non-existent user id. */
    public function testLoginByIdUnknownUser(): void
    {
        $auth = new Auth();
        $this->assertFalse($auth->loginById(9999999));
    }

    // ── PasskeyService::userIdentity (real users lookup) ──────────────────────

    /**
     * beginRegistration builds the WebAuthn user entity from the real account —
     * the account name is the username, the display name the full name.
     */
    public function testUserIdentityFromRealAccount(): void
    {
        // Arrange — a real service (no userIdentity override).
        $config  = new Config(self::RP_ID, 'Example', [self::ORIGIN]);
        $service = new PasskeyService(new WebAuthnLibAdapter($config), $this->db, $config);

        // Act
        $options = $service->beginRegistration($this->activeUid, 'Key');
        $client  = $options->toClientArray();

        // Assert
        $this->assertSame(self::U_ACTIVE, $client['user']['name']);
        $this->assertSame('Alice Smith', $client['user']['displayName']);
    }

    /** An unknown user id falls back to a synthetic account name. */
    public function testUserIdentityFallbackForUnknownUser(): void
    {
        $config  = new Config(self::RP_ID, 'Example', [self::ORIGIN]);
        $service = new PasskeyService(new WebAuthnLibAdapter($config), $this->db, $config);

        $options = $service->beginRegistration(9999999, null);
        $client  = $options->toClientArray();

        $this->assertSame('user9999999', $client['user']['name'], 'Synthetic name for unknown user');
    }

    /** With no first/last name, the display name falls back to the username. */
    public function testUserIdentityDisplayFallsBackToUsername(): void
    {
        $config  = new Config(self::RP_ID, 'Example', [self::ORIGIN]);
        $service = new PasskeyService(new WebAuthnLibAdapter($config), $this->db, $config);

        // The inactive account was seeded without first/last name.
        $options = $service->beginRegistration($this->inactiveUid, null);
        $client  = $options->toClientArray();

        $this->assertSame(self::U_INACTIVE, $client['user']['displayName']);
    }

    // ── Controller username → id resolution (real DB) ─────────────────────────

    /** loginOptions with a username resolves the real user id into the session. */
    public function testControllerResolvesUsername(): void
    {
        // Arrange
        $_SESSION = [];
        $_POST = ['username' => self::U_ACTIVE];
        $controller = new Passkey(null);

        // Act
        $response = $controller->loginOptions();

        // Assert — the real user id was resolved and pinned for the ceremony.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($this->activeUid, $_SESSION['passkey_login_userid'] ?? null);

        // Cleanup
        $_POST = [];
    }

    /**
     * A management action with a logged-in session resolves the current user
     * from the session and returns their (empty) passkey list.
     */
    public function testControllerListWithLoggedInSession(): void
    {
        // Arrange — a real logged-in session for the seeded active user.
        $_SESSION = ['logged' => true, 'uid' => $this->activeUid, 'username' => self::U_ACTIVE];
        $controller = new Passkey(null);

        // Act
        $response = $controller->list();

        // Assert — resolved the current user and returned a passkey list.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('passkeys', $response->getBody());

        // Cleanup
        $_SESSION = [];
    }
}
