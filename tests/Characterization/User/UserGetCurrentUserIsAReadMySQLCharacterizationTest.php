<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\User\User;

/**
 * The MySQL counterpart of UserGetCurrentUserIsAReadCharacterizationTest.
 *
 * Same invariant: User::getCurrentUser() is a read and must write nothing. It
 * used to refresh `users.language` from the interface language and save() on
 * every call after the first in a request — see the PostgreSQL file for the
 * full account of what that cost.
 *
 * Worth running on both engines rather than one. The defect was a write that
 * went through save(), and the save path differs between them in the places
 * that decide whether a write lands or raises: MySQL's users table here has
 * NOT NULL columns with no server-side default, so an incidental save has more
 * ways to fail than the PostgreSQL one does.
 */
#[CoversClass(User::class)]
#[\PHPUnit\Framework\Attributes\Group('mysql')]
#[\PHPUnit\Framework\Attributes\Group('characterization')]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class UserGetCurrentUserIsAReadMySQLCharacterizationTest extends TestCase
{
    /** @var \Pramnos\Database\Database */
    private $db;

    private Application $app;

    protected function setUp(): void
    {
        // Arrange — live MySQL, with the framework's own user tables.
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DIRECTORY_SEPARATOR . 'fixtures'
                . DIRECTORY_SEPARATOR . 'app');
        }
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . DIRECTORY_SEPARATOR . 'var');
        }
        if (!is_dir(LOG_PATH . DIRECTORY_SEPARATOR . 'logs')) {
            @mkdir(LOG_PATH . DIRECTORY_SEPARATOR . 'logs', 0777, true);
        }

        Settings::loadSettings(ROOT . '/tests/fixtures/app/settings.php');
        $this->app = Application::getInstance();

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        User::setupDb();

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->app->currentUser = null;
    }

    /**
     * Create a user with a known stored language and sign them in.
     *
     * @param string $language Value for users.language.
     * @param bool   $withEmail False to blank the address, reproducing the
     *                          account an admin created rather than one that
     *                          self-registered.
     * @return User
     */
    private function signedInUser(string $language, bool $withEmail): User
    {
        $username = 'lang_' . bin2hex(random_bytes(4));

        $user = new User();
        $user->username = $username;
        $user->email = $username . '@example.com';
        $user->setPassword('secret123');
        $user->save();

        // Written directly: save() has opinions about the address, and the
        // column under test is the language.
        $update = ['language' => $language];
        if (!$withEmail) {
            $update['email'] = '';
        }
        $this->db->queryBuilder()->table('users')
            ->where('userid', $user->userid)
            ->update($update);

        $loaded = new User();
        $loaded->load($user->userid);

        $_SESSION['logged'] = true;
        $_SESSION['uid'] = $user->userid;
        $this->app->currentUser = $loaded;

        return $loaded;
    }

    /**
     * Read the persisted language, bypassing any in-memory copy.
     *
     * @param int $userid
     * @return string
     */
    private function storedLanguage(int $userid): string
    {
        return (string) $this->db->queryBuilder()->from('users')
            ->where('userid', $userid)
            ->first()->language;
    }

    /**
     * The reported failure on MySQL: the second lookup of a request must leave
     * a stored language that differs from the interface language alone.
     *
     * The first call caches the user on the application and the second takes
     * the cached branch, which is where the write lived — so the assertion has
     * to come after two calls, not one.
     */
    public function testASecondLookupDoesNotOverwriteTheStoredLanguage(): void
    {
        // Arrange — stored 'greek', interface 'english'.
        $user = $this->signedInUser('greek', false);
        Factory::getLanguage()->setLang('english');

        // Act — theme header, then controller.
        User::getCurrentUser();
        User::getCurrentUser();

        // Assert
        $this->assertSame('greek', $this->storedLanguage((int) $user->userid));
    }

    /**
     * An account with no email address must not make the lookup raise.
     *
     * The incidental save ran _save(), whose address validation rejects an
     * empty email, so a read could end the request. With no save there is
     * nothing to validate.
     */
    public function testALookupOnAnAccountWithNoEmailDoesNotThrow(): void
    {
        // Arrange
        $user = $this->signedInUser('greek', false);
        Factory::getLanguage()->setLang('english');
        User::getCurrentUser();

        // Act — the call that used to raise.
        $returned = User::getCurrentUser();

        // Assert
        $this->assertInstanceOf(User::class, $returned);
        $this->assertSame((int) $user->userid, (int) $returned->userid);
        $this->assertSame('greek', $this->storedLanguage((int) $user->userid));
    }

    /**
     * A matching language never triggered the old write, and must still not
     * write — the regression guard for the removal rather than for the bug.
     */
    public function testAMatchingLanguageIsUnchangedAndTheUserIsReturned(): void
    {
        // Arrange
        $user = $this->signedInUser('english', true);
        Factory::getLanguage()->setLang('english');

        // Act
        User::getCurrentUser();
        $returned = User::getCurrentUser();

        // Assert
        $this->assertInstanceOf(User::class, $returned);
        $this->assertSame('english', $this->storedLanguage((int) $user->userid));
    }

    /**
     * An application that wants the two kept in step can still write the column
     * itself, explicitly. This is the replacement the removal implies.
     */
    public function testAnApplicationCanStillWriteTheLanguageItself(): void
    {
        // Arrange
        $user = $this->signedInUser('greek', true);

        // Act
        $current = User::getCurrentUser();
        $current->language = 'english';
        $current->save();

        // Assert
        $this->assertSame(
            'english', $this->storedLanguage((int) $user->userid)
        );
    }
}
