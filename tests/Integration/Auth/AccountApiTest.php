<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Controllers\Account;
use Pramnos\Auth\Controllers\AccountApi;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\User;

/**
 * The signed-in person's account under the API (`AccountApi`), on MySQL here and PostgreSQL in the
 * sibling: sessions listed and signed out, the password and the profile changed through the same
 * code as the pages — their refusals too — the export, the trusted devices and the applications,
 * the picture taken away, and the account deleted; nobody signed in refused; every route
 * described for the API's document.
 */
#[CoversClass(AccountApi::class)]
#[CoversClass(Account::class)]
class AccountApiTest extends BaseTestCase
{
    private $db;

    private int $uid = 0;

    private array $originalInfo = [];

    private const OLD = 'the-old-password-3!';

    private const NEW = 'a-brand-new-password-9!';

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        $application = Application::getInstance();
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
        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->schema()->quoteTable('authserver.user_activity_log'));
        $this->runMigrations([
            \Pramnos\Framework\Migrations\Auth\CreateUserActivityLogTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreatePasswordHistoryTable::class,
            \Pramnos\Framework\Migrations\Core\CreateSessionsTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateApplicationsTable::class,
            \Pramnos\Framework\Migrations\Applications\CreateApplicationSettingsTable::class,
            \Pramnos\Framework\Migrations\Auth\CreateUsertokensTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateOauth2UserConsentsTable::class,
        ], $this->db);
        \Pramnos\Auth\ActivityLog::resetTableCache();
        $this->originalInfo = (array) $application->applicationInfo;
        $application->applicationInfo['features'] = ['auth', 'authserver'];
        \Pramnos\Application\FeatureRegistry::loadFromConfig(['auth', 'authserver']);

        $user = new User();
        $user->username = 'accountapi_' . bin2hex(random_bytes(4));
        $user->email    = $user->username . '@example.test';
        $user->save();
        $this->uid = (int) $user->userid;
        $withPassword = new User($this->uid);
        $withPassword->setPassword(self::OLD);
        $withPassword->save();
        \Pramnos\Http\RequestIdentity::seal(new User($this->uid), 'test');
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        \Pramnos\Http\Request::resetInstance();
    }

    protected function tearDown(): void
    {
        \Pramnos\Http\RequestIdentity::reset();
        Application::currentInstance()->applicationInfo = $this->originalInfo;
        foreach (['authserver.password_history', 'authserver.user_activity_log', '#PREFIX#sessions', '#PREFIX#userdetails', '#PREFIX#users'] as $table) {
            try {
                $this->db->queryBuilder()->table($table)->where('userid', $this->uid)->delete();
            } catch (\Throwable) {
                // Nothing to undo.
            }
        }
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        \Pramnos\Http\Request::setRawInput('');
        \Pramnos\Http\Request::resetInstance();
        User::clearUserCache();
        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    /** A call with this JSON body. @param array<string, mixed> $body @return array<string, mixed> */
    private function call(string $method, array $body = [], mixed ...$args): array
    {
        \Pramnos\Http\Request::setRawInput((string) json_encode($body));
        \Pramnos\Http\Request::resetInstance();
        $_POST = $body;

        return (new AccountApi())->{$method}(...$args);
    }

    /**
     * Sessions, password, profile, export, devices, applications and the picture, with their
     * refusals in the API's shape.
     */
    public function testTheAccountUnderTheApi(): void
    {
        // Arrange — a session of the account's own
        $this->db->queryBuilder()->table('#PREFIX#sessions')->insert(['sid' => 'api-sid-' . $this->uid, 'visitorid' => 'v', 'uname' => 'u',
            'time' => time(), 'host_addr' => '127.0.0.1', 'guest' => 0, 'userid' => $this->uid, 'logout' => 0, 'agent' => 'Phone', 'url' => '/', 'history' => '']);

        // Act
        $sessions  = $this->call('sessionList');
        $signedOut = $this->call('signOutSession', [], 'api-sid-' . $this->uid);
        $after     = $this->call('sessionList');
        $wrongPass = $this->call('password', ['current_password' => 'nope', 'new_password' => self::NEW, 'confirm_password' => self::NEW]);
        $changed   = $this->call('password', ['current_password' => self::OLD, 'new_password' => self::NEW, 'confirm_password' => self::NEW]);
        $oldAgain  = $this->call('password', ['current_password' => self::OLD, 'new_password' => 'x-Another-1!', 'confirm_password' => 'x-Another-1!']);
        $badEmail  = $this->call('saveProfile', ['firstname' => 'Ελένη', 'email' => 'not-an-address']);
        $profile   = $this->call('saveProfile', ['firstname' => 'Ελένη', 'lastname' => 'Σ.', 'email' => 'eleni.' . $this->uid . '@example.test', 'phone' => '']);
        $export    = $this->call('export');
        $devices   = $this->call('deviceList');
        $noDevice  = $this->call('forgetDevice', [], 999999);
        $apps      = $this->call('applicationList');
        $noApp     = $this->call('revokeApplicationAccess', [], 'no-such-client');
        $photo     = $this->call('removePhoto');
        \Pramnos\Http\RequestIdentity::reset();
        $nobody    = $this->call('sessionList');

        // Assert
        $this->assertContains('api-sid-' . $this->uid, array_column($sessions['data']['sessions'], 'sid'));
        $this->assertSame(200, $signedOut['status']);
        $this->assertNotContains('api-sid-' . $this->uid, array_column($after['data']['sessions'], 'sid'), 'signed out');
        $this->assertSame(422, $wrongPass['status'], 'the current password checked, as on the page');
        $this->assertSame([200, true], [$changed['status'], $changed['data']['changed']]);
        $this->assertSame(422, $oldAgain['status'], 'the old password no longer the current one');
        $this->assertSame(422, $badEmail['status']);
        $this->assertSame(['Ελένη', 'Σ.'], [$profile['data']['profile']['firstname'], $profile['data']['profile']['lastname']]);
        $this->assertSame((new User($this->uid))->email, $profile['data']['profile']['email'], 'kept');
        $this->assertArrayHasKey('profile', $export['data'], 'the export, as the page gives it');
        $this->assertSame([], $devices['data']['devices']);
        $this->assertSame(404, $noDevice['status']);
        $this->assertSame([200, 404], [$apps['status'], $noApp['status']]);
        $this->assertSame([200, null], [$photo['status'], $photo['data']['photo']]);
        $this->assertSame(401, $nobody['status']);
    }

    /**
     * Deleting the account: the password and the word DELETE asked first, then everything erased.
     */
    public function testTheAccountIsDeleted(): void
    {
        // Act
        $wrongPass = $this->call('eraseAccount', ['password' => 'nope', 'confirmation' => 'DELETE']);
        $noWord    = $this->call('eraseAccount', ['password' => self::OLD, 'confirmation' => 'delete']);
        $deleted   = $this->call('eraseAccount', ['password' => self::OLD, 'confirmation' => 'DELETE']);

        // Assert
        $this->assertSame([422, 422], [$wrongPass['status'], $noWord['status']]);
        $this->assertSame([200, true], [$deleted['status'], $deleted['data']['deleted']]);
        $this->assertSame(0, $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $this->uid)->count(), 'erased');
    }

    /** Every route mounted, under the path chosen, and described for the API's document. */
    public function testTheRoutesAreMountedAndDescribed(): void
    {
        // Arrange
        $router = new \Pramnos\Routing\Router(Application::getInstance());

        // Act
        AccountApi::routes($router, '/account/me');
        $routes = $router->getRoutesWithPermissions();

        // Assert
        $this->assertArrayHasKey('/account/me/password', $routes['POST']);
        $this->assertArrayHasKey('/account/me/sessions/{sid}', $routes['DELETE']);
        $this->assertSame('Delete one\'s account for good: `password`, and `confirmation` = `DELETE`.',
            $routes['POST']['/account/me/delete']['route']->getDoc()['summary']);
    }
}
