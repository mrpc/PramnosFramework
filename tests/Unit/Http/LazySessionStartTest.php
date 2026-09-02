<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Session;

/**
 * Lazy sessions, and why the page cache could never store anything without them.
 *
 * `startIfPresent()` is what lazy mode calls instead of `start()`. Five statements, never
 * executed, and the whole point is what it does *not* do: a first-time anonymous visitor gets no
 * session, therefore no `Set-Cookie`, therefore a response the page cache can store and serve to
 * the next anonymous visitor.
 *
 * Start a session for everybody and every response carries a `Set-Cookie` with a session id unique
 * to that visitor. Caching that response would hand the next person somebody else's session id,
 * so the cache correctly refuses to store it — and a page cache that refuses everything is a page
 * cache that does nothing, which is how this was found.
 *
 * A returning visitor still gets exactly what they had, which is the other half: laziness that
 * dropped existing sessions would sign everybody out.
 */
#[CoversClass(Session::class)]
class LazySessionStartTest extends TestCase
{
    /** @var array<string, string> */
    private array $savedCookie = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedCookie = $_COOKIE;
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->savedCookie;
        parent::tearDown();
    }

    /**
     * A visitor with no session cookie gets no session.
     *
     * The assertion the page cache depends on. `false` here is what lets a response go out
     * without a `Set-Cookie`.
     */
    public function testAVisitorWithNoCookieGetsNoSession(): void
    {
        // Arrange
        unset($_COOKIE[session_name()]);

        // Act
        $started = Session::getInstance()->startIfPresent();

        // Assert
        $this->assertFalse($started, 'a first-time visitor was given a session, so nothing can be cached');
    }

    /**
     * An empty cookie is not a session either.
     *
     * `PHPSESSID=` is what a browser sends after the cookie has been cleared, and treating it as
     * an existing session would start one for a visitor who has none — the same outcome as no
     * laziness at all, reached by a different route.
     */
    public function testAnEmptyCookieIsNotASession(): void
    {
        // Arrange
        $_COOKIE[session_name()] = '';

        // Act + Assert
        $this->assertFalse(Session::getInstance()->startIfPresent());
    }

    /**
     * `hasExistingCookie()` is the decision, and it reads the configured session name.
     *
     * Not a hardcoded `PHPSESSID`: an installation that renamed its session cookie would
     * otherwise look sessionless to every request, which is a site where nobody can stay signed
     * in — while `start()` kept working, so the two would disagree.
     */
    public function testTheDecisionUsesTheConfiguredSessionName(): void
    {
        // Arrange
        unset($_COOKIE[session_name()]);
        $this->assertFalse(Session::hasExistingCookie());

        // Act
        $_COOKIE[session_name()] = 'abc123';

        // Assert
        $this->assertTrue(
            Session::hasExistingCookie(),
            'the configured session name is not what is looked for'
        );
    }

    /**
     * A cookie under some other name does not count.
     *
     * The converse of the test above, and the reason it is a keyed lookup rather than "are there
     * any cookies": a visitor carrying an analytics cookie and no session has no session.
     */
    public function testACookieUnderAnotherNameDoesNotCount(): void
    {
        // Arrange
        unset($_COOKIE[session_name()]);
        $_COOKIE['_ga'] = 'GA1.1.1';

        // Act + Assert
        $this->assertFalse(Session::getInstance()->startIfPresent());
    }
}
