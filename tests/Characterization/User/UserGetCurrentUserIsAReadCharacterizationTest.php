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
 * Characterization tests for the one promise User::getCurrentUser() makes about
 * itself: it is a read.
 *
 * It was not. On the second and later calls within a request — the branch that
 * returns the user already cached on the application — it compared
 * `users.language` against the interface language and, when they differed,
 * overwrote the column and called save().
 *
 * That branch is reached through ordinary use, not through an edge case. The
 * first call loads the user and caches it; every call after it in the same
 * request takes the cached branch, and a page whose theme header asks who is
 * signed in and whose controller asks again does so twice as a matter of
 * course.
 *
 * Two consequences, and the tests below pin both:
 *
 *   1. `users.language` reads as the user's stored preference. Treating it as a
 *      cache of the interface language meant an operator who chose one language
 *      in a bilingual admin panel had that choice reverted by opening a page
 *      rendered in the other — silently, and only for the accounts that had
 *      actually used the feature.
 *   2. On an account with no email address — ordinary for one created by an
 *      admin rather than by self-registration — the save could raise from
 *      _save()'s address validation, so a lookup ended the request.
 *
 * Run against the live database because the defect was a write: only the row
 * can say whether one happened.
 *
 * The MySQL counterpart of this file is
 * UserGetCurrentUserIsAReadMySQLCharacterizationTest.
 */
#[CoversClass(User::class)]
#[\PHPUnit\Framework\Attributes\Group('postgresql')]
#[\PHPUnit\Framework\Attributes\Group('characterization')]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class UserGetCurrentUserIsAReadCharacterizationTest extends TestCase
{
    private \Pramnos\Database\Database $db;

    private Application $app;

    protected function setUp(): void
    {
        // Arrange — a live PostgreSQL connection and the users tables.
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . DS . 'var');
        }

        Settings::loadSettings(
            ROOT . '/tests/fixtures/app/pg_settings.php'
        );
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
     * Create a user with a known stored language, and sign them in.
     *
     * @param string      $language Value for users.language.
     * @param string|null $email    Null to reproduce the account with no
     *                              address, which is what made the incidental
     *                              save throw.
     * @return User
     */
    private function signedInUser(string $language, ?string $email): User
    {
        $username = 'lang_' . bin2hex(random_bytes(4));

        $user = new User();
        $user->username = $username;
        $user->email = $email ?? ($username . '@example.com');
        $user->setPassword('secret123');
        $user->save();

        // The stored preference is written directly: save() has opinions about
        // the address, and this test is about the language column alone.
        $this->db->queryBuilder()->table('users')
            ->where('userid', $user->userid)
            ->update(['language' => $language]);

        if ($email === null) {
            $this->db->queryBuilder()->table('users')
                ->where('userid', $user->userid)
                ->update(['email' => '']);
        }

        $loaded = new User();
        $loaded->load($user->userid);

        $_SESSION['logged'] = true;
        $_SESSION['uid'] = $user->userid;
        $this->app->currentUser = $loaded;

        return $loaded;
    }

    /**
     * Read the language column straight from the database, bypassing any
     * in-memory copy — the question is what was persisted.
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
     * The reported failure: two ordinary calls in one request, with a stored
     * language that differs from the interface language, must leave the stored
     * language alone.
     *
     * The first call caches the user on the application; the second takes the
     * cached branch, which is where the write was. Asserting after the second
     * call is therefore the assertion that matters — after the first alone, the
     * old code passed too.
     */
    public function testASecondLookupDoesNotOverwriteTheStoredLanguage(): void
    {
        // Arrange — stored preference 'greek', interface language 'english'.
        $user = $this->signedInUser('greek', null);
        Factory::getLanguage()->setLang('english');

        // Act — the pattern a page produces: theme header, then controller.
        User::getCurrentUser();
        User::getCurrentUser();

        // Assert — the preference is still the user's own.
        $this->assertSame('greek', $this->storedLanguage((int) $user->userid));
    }

    /**
     * The same, on an account with no email address: the lookup must return the
     * user rather than raise.
     *
     * The incidental save ran _save(), whose address validation rejects an
     * empty email — so a method that promises a read could end the request over
     * a column it was not asked about. With no save there is nothing to
     * validate.
     */
    public function testALookupOnAnAccountWithNoEmailDoesNotThrow(): void
    {
        // Arrange
        $user = $this->signedInUser('greek', null);
        Factory::getLanguage()->setLang('english');
        User::getCurrentUser();

        // Act — this is the call that used to raise.
        $returned = User::getCurrentUser();

        // Assert — an identity came back, and the row is untouched.
        $this->assertInstanceOf(User::class, $returned);
        $this->assertSame((int) $user->userid, (int) $returned->userid);
        $this->assertSame('greek', $this->storedLanguage((int) $user->userid));
    }

    /**
     * The write was guarded by an adminlogin check, so it also has to be gone
     * on the path where an administrator is impersonating nobody in particular
     * — the guard's own default, with no adminlogin key in the session at all.
     *
     * Stated separately because "the write is gone" and "the write is gone on
     * every branch that could reach it" are different claims, and only the
     * second is worth having.
     */
    public function testNoWriteWhenAdminloginIsSetToAnotherAccount(): void
    {
        // Arrange — adminlogin naming a different user closed the old guard,
        // which is the one combination where the old code did NOT write.
        $user = $this->signedInUser('greek', null);
        $_SESSION['adminlogin'] = ((int) $user->userid) + 1000;
        Factory::getLanguage()->setLang('english');

        // Act
        User::getCurrentUser();
        User::getCurrentUser();

        // Assert — unchanged, as it always was on this branch.
        $this->assertSame('greek', $this->storedLanguage((int) $user->userid));
    }

    /**
     * A matching language is the case that never wrote, because the old
     * comparison found nothing to change. It must still not write, and it must
     * still return the user — this is the regression guard for the removal
     * itself rather than for the bug.
     */
    public function testAMatchingLanguageIsUnchangedAndTheUserIsReturned(): void
    {
        // Arrange
        $user = $this->signedInUser('english', null);
        Factory::getLanguage()->setLang('english');

        // Act
        User::getCurrentUser();
        $returned = User::getCurrentUser();

        // Assert
        $this->assertInstanceOf(User::class, $returned);
        $this->assertSame('english', $this->storedLanguage((int) $user->userid));
    }

    /**
     * An application that does want the two kept in step can still do it —
     * explicitly, where a reader can see it. This is the replacement the
     * removal implies, asserted so the guidance in the docblock is not just
     * prose.
     */
    public function testAnApplicationCanStillWriteTheLanguageItself(): void
    {
        // Arrange
        $user = $this->signedInUser('greek', 'has@example.com');

        // Act — the explicit form, from wherever the language is chosen.
        $current = User::getCurrentUser();
        $current->language = 'english';
        $current->save();

        // Assert
        $this->assertSame(
            'english', $this->storedLanguage((int) $user->userid)
        );
    }
}
