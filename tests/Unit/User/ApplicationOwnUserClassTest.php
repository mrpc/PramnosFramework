<?php

declare(strict_types=1);

namespace AppWithOwnUser {
    /**
     * An application's own user class, of the kind this contract exists for.
     *
     * Applications subclass `User` to add the columns and the methods their own accounts have —
     * a billing reference, a tenant, `isSubscribed()`. The framework has to hand those objects
     * back, or every one of those methods is missing on the object the application is given.
     */
    class User extends \Pramnos\User\User
    {
        public function isTheApplicationsOwnClass(): bool
        {
            return true;
        }
    }
}

namespace Tests\Unit\User {

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\User\User;

/**
 * The framework returns the *application's* user class, not its own.
 *
 * `getUser()` and `getCurrentUser()` each carry the same four-line lookup: if the application
 * declares a namespace and a `User` class exists inside it, instantiate that. Neither branch had
 * ever run, which is worth more than the eight statements — an application whose `User` subclass is
 * silently ignored gets a framework object back, and every method and column it added to its own
 * accounts is missing on it. That failure shows up as `Call to undefined method` a long way from
 * here.
 *
 * The condition is deliberately a `class_exists()` rather than configuration: an application that
 * has not written one gets the framework's class, and does not have to say so anywhere.
 */
#[CoversClass(User::class)]
class ApplicationOwnUserClassTest extends TestCase
{
    private mixed $savedNamespace = null;

    private bool $hadNamespace = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');

        $app = Application::getInstance();
        $this->hadNamespace   = isset($app->applicationInfo['namespace']);
        $this->savedNamespace = $app->applicationInfo['namespace'] ?? null;

        /*
         * The constructor loads, so these tests need a connection even though what they assert is
         * the class of the object rather than anything in it.
         *
         * The singleton is dropped first. Under a filter this class connected fine and in the full
         * suite it failed with `No such file or directory` — a socket path — because whichever test
         * ran before it had left the singleton pointing at another lane. Dropping the reference is
         * what makes `Factory::getDatabase()` build a fresh one from the settings just loaded.
         */
        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;

        $db = \Pramnos\Framework\Factory::getDatabase();

        try {
            if (!$db->connected) {
                $db->connect();
            }
        } catch (\Throwable $exception) {
            $this->markTestSkipped('The database for this backend is not reachable: '
                . $exception->getMessage());
        }

        if (!$db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        $app->database = $db;
    }

    protected function tearDown(): void
    {
        $app = Application::getInstance();
        if ($this->hadNamespace) {
            $app->applicationInfo['namespace'] = $this->savedNamespace;
        } else {
            unset($app->applicationInfo['namespace']);
        }
        $app->currentUser = null;
        unset($_SESSION['uid'], $_SESSION['logged']);

        // The class caches by id, and these tests ask for the same ids under different
        // namespaces — so the cache has to go, or the second answer is the first one.
        $this->clearUserCache();

        parent::tearDown();
    }

    /** Empties `User::$usersCache`, which is private and static. */
    private function clearUserCache(): void
    {
        $property = new \ReflectionProperty(User::class, 'usersCache');
        $property->setValue(null, []);
    }

    /**
     * With an application namespace holding a `User` class, that is what comes back.
     */
    public function testGetUserReturnsTheApplicationsOwnClass(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['namespace'] = 'AppWithOwnUser';
        $this->clearUserCache();

        // Act
        $user = User::getUser(424242);

        // Assert
        $this->assertInstanceOf(\AppWithOwnUser\User::class, $user);
        $this->assertTrue($user->isTheApplicationsOwnClass());
    }

    /**
     * And an application that has not written one gets the framework's class.
     *
     * The same branch, taken the other way. `class_exists()` is the whole condition, so a
     * namespace that names no `User` is not a misconfiguration to report — it is the ordinary
     * case, and it must not become a fatal on a class that is not there.
     */
    public function testAnApplicationWithoutItsOwnClassGetsTheFrameworksOne(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['namespace'] = 'NoSuchApplicationNamespace';
        $this->clearUserCache();

        // Act
        $user = User::getUser(424243);

        // Assert
        $this->assertInstanceOf(User::class, $user);
        $this->assertNotInstanceOf(\AppWithOwnUser\User::class, $user);
    }

    /**
     * `getCurrentUser()` honours the override too, from its own copy of the lookup.
     *
     * Its own copy is why this is a second test rather than a second assertion: the two methods
     * repeat the four lines, and a fix applied to one of them would leave the other returning
     * framework objects for the signed-in user — the object almost every page actually holds.
     */
    public function testGetCurrentUserReturnsTheApplicationsOwnClass(): void
    {
        // Arrange
        $app = Application::getInstance();
        $app->applicationInfo['namespace'] = 'AppWithOwnUser';
        $app->currentUser = null;

        // `staticIsLogged()` wants both keys, and a uid above 1 — `1` is the anonymous account.
        $_SESSION['logged'] = true;
        $_SESSION['uid']    = 424244;
        $this->clearUserCache();

        // Act
        $user = User::getCurrentUser();

        // Assert
        $this->assertInstanceOf(\AppWithOwnUser\User::class, $user);
    }

    /**
     * With nobody signed in, `getCurrentUser()` is `false` rather than an empty user.
     *
     * The distinction the callers rely on: an empty `User` object is truthy, so returning one
     * would make every `if (getCurrentUser())` treat a guest as signed in.
     */
    public function testGetCurrentUserIsFalseWithNoSession(): void
    {
        // Arrange
        $app = Application::getInstance();
        $app->applicationInfo['namespace'] = 'AppWithOwnUser';
        $app->currentUser   = null;
        $_SESSION['logged'] = true;
        unset($_SESSION['uid']);
        $this->clearUserCache();

        // Act
        $user = User::getCurrentUser();

        // Assert
        $this->assertFalse($user);
    }
}

}
