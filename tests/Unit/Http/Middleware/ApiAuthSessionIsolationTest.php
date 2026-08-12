<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Middleware\ApiAuthMiddleware;
use Pramnos\Http\Request;

/**
 * The API must not read the browser's session, and must not write it either.
 *
 * An application that serves a website and an API from one origin shares a
 * session cookie between them. That made the API's identity handling a
 * cross-wire in both directions, and both directions were real:
 *
 *  - **writing** — a call authenticated with one user's token set
 *    `$_SESSION['user']` and `['uid']`, so the browser's *next page* belonged to
 *    that user, whoever was signed in on it;
 *  - **erasing** — an anonymous call ran `unset($_SESSION['user'], ['logged'],
 *    ['uid'])` to make sure a cookie could not authenticate it. That achieved
 *    the goal by destroying the session, so one unauthenticated poll from a
 *    widget signed the user out of the website.
 *
 * The fix is that a request's identity is request-scoped: the middleware
 * publishes `Application::$currentUser` and touches nothing that outlives the
 * call. These tests hold that line, because the failure is invisible from inside
 * either half — the API works, the website works, and only using both together
 * shows it.
 */
#[CoversClass(ApiAuthMiddleware::class)]
class ApiAuthSessionIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        foreach (['HTTP_APIKEY', 'HTTP_ACCESSTOKEN', 'HTTP_AUTHORIZATION', 'HTTP_USERAUTH'] as $header) {
            unset($_SERVER[$header]);
        }
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    /**
     * An anonymous API call leaves the website's session exactly as it found it.
     *
     * This is the one that logged people out. A page with a widget that polls an
     * unauthenticated endpoint would sign its own reader out, and the only clue
     * was that it happened "sometimes".
     */
    public function testAnAnonymousCallDoesNotTouchTheBrowsersSession(): void
    {
        // Arrange — a browser signed in to the website, calling an API endpoint
        // with no token of its own
        $_SESSION = [
            'logged' => true,
            'uid'    => 7,
            'user'   => (object) ['userid' => 7, 'username' => 'alice'],
            'cart'   => ['a', 'b'],
        ];
        $before = $_SESSION;

        $_SERVER['HTTP_APIKEY'] = 'valid-key';

        $middleware = new ApiAuthMiddleware(
            apiKeyChecker: static fn(string $key): bool => true,
            authKey: 'irrelevant-here',
        );

        // Act
        $middleware->handle(new Request(), static fn(): string => 'ok');

        // Assert — every key still there, unchanged
        $this->assertSame($before, $_SESSION, 'the session belongs to the browser, not to the API');
    }

    /**
     * The API does not authenticate from a session cookie.
     *
     * The isolation has to hold in this direction too, or the fix above would
     * have turned "signs people out" into "lets a cookie authenticate an API
     * call", which is worse.
     */
    public function testASessionCookieDoesNotAuthenticateAnApiCall(): void
    {
        // Arrange — signed in on the website, no token presented
        $_SESSION = [
            'logged' => true,
            'uid'    => 7,
            'user'   => (object) ['userid' => 7, 'username' => 'alice'],
        ];
        $_SERVER['HTTP_APIKEY'] = 'valid-key';

        $app = \Pramnos\Application\Application::getInstance();
        if ($app) {
            $app->currentUser = null;
        }

        $middleware = new ApiAuthMiddleware(
            apiKeyChecker: static fn(string $key): bool => true,
            authKey: 'irrelevant-here',
        );

        // Act
        $middleware->handle(new Request(), static fn(): string => 'ok');

        // Assert — the request has no identity of its own
        $this->assertNull(
            $app?->currentUser,
            'a website cookie must not identify an API request'
        );
    }

    /**
     * A missing API key is refused before any of this is reached, and still
     * leaves the session alone.
     */
    public function testARefusedCallLeavesTheSessionAlone(): void
    {
        // Arrange
        $_SESSION = ['logged' => true, 'uid' => 7, 'user' => (object) ['userid' => 7]];
        $before   = $_SESSION;

        $middleware = new ApiAuthMiddleware(
            apiKeyChecker: static fn(string $key): bool => true,
            authKey: 'irrelevant-here',
        );

        // Act — no apiKey header at all
        $response = $middleware->handle(new Request(), static fn(): string => 'never reached');

        // Assert
        $this->assertNotSame('never reached', $response, 'the call was refused');
        $this->assertSame($before, $_SESSION);
    }
}
