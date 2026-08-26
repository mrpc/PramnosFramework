<?php

declare(strict_types=1);

namespace Tests\Unit\Testing;

use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Http\Session;

/**
 * `loginUser()` must actually sign the user in.
 *
 * It used to set `$_SESSION['auth']` and `$_SESSION['user_id']`. Nothing in the
 * framework reads either: `Session::staticIsLogged()` wants `logged` and a `uid`
 * above 1, and `User::getCurrentUser()` builds the user from `uid`. So the helper
 * returned having signed nobody in, and every test that called it exercised the
 * signed-out path while reading as though it covered the signed-in one. A test
 * for "an administrator can open this screen" was testing the guest redirect.
 */
class LoginUserHelperTest extends BaseTestCase
{
    protected function tearDown(): void
    {
        $this->logoutUser();
        parent::tearDown();
    }

    /**
     * After loginUser(), the framework reports somebody as signed in.
     */
    public function testLoginUserSignsTheUserIn(): void
    {
        // Arrange
        $this->logoutUser();
        $this->assertFalse(Session::staticIsLogged(), 'precondition: signed out');

        // Act
        $this->loginUser(42);

        // Assert
        $this->assertTrue(Session::staticIsLogged());
        $this->assertSame(42, $_SESSION['uid']);
    }

    /**
     * The keys it used to set are still set, for code that reads them.
     *
     * The session-tracking middleware copies `auth` into a cookie, so dropping it
     * would trade one silent gap for another.
     */
    public function testTheOlderKeysAreStillSet(): void
    {
        // Act
        $this->loginUser(42);

        // Assert
        $this->assertTrue($_SESSION['auth']);
        $this->assertSame(42, $_SESSION['user_id']);
    }

    /**
     * logoutUser() undoes it.
     *
     * The session is process-wide in a test run, so a sign-in with no way back
     * carries into every test after it — and those tests pass or fail for a
     * reason that is nowhere in them.
     */
    public function testLogoutUserSignsTheUserOut(): void
    {
        // Arrange
        $this->loginUser(42);
        $this->assertTrue(Session::staticIsLogged(), 'precondition: signed in');

        // Act
        $this->logoutUser();

        // Assert
        $this->assertFalse(Session::staticIsLogged());
        $this->assertArrayNotHasKey('uid', $_SESSION);
    }

    /**
     * A reserved id does not count as signed in.
     *
     * 0 is the guest and 1 is the built-in system account; a test that "signs in"
     * as either has to keep reading as signed out, because that is what every
     * guard in the framework decides.
     */
    public function testAReservedIdIsNotSignedIn(): void
    {
        // Act
        $this->loginUser(1);

        // Assert
        $this->assertFalse(Session::staticIsLogged());
    }
}
