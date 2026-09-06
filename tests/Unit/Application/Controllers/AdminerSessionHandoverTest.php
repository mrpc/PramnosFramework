<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Adminer;

/**
 * Letting go of the session before Adminer starts its own.
 *
 * `session_write_close()` writes the data and releases the handle — and leaves
 * `session_id()` answering with this request's id. Adminer's bootstrap sets
 * `session_name('adminer_sid')` and calls `session_start()`, which finds an id
 * already set and **reuses ours** rather than reading its cookie.
 *
 * Measured on the installation that reported it: Adminer wrote its session over
 * the framework's file — 78 bytes replaced by 15 of `token|i:…`, so opening the
 * page destroyed the visitor's own session — and the next request's
 * `Session::start()` found `token` as an integer, saw `strlen()` under 32, and
 * replaced it with a hex string, destroying Adminer's token in turn. So Adminer's
 * CSRF token never survived a round trip and **every POST** answered *«Invalid
 * CSRF token»*. Navigation is `GET` and is not checked, which is why the tool
 * looked fine until somebody ran a query.
 *
 * Of 18,463 session files on that machine, zero held an integer `token` and zero
 * held Adminer's `pwds` slot. Its session had never once been written under an id
 * of its own.
 */
#[CoversClass(Adminer::class)]
class AdminerSessionHandoverTest extends TestCase
{
    /**
     * The controller's handover, reachable without serving Adminer.
     */
    private function controller(): object
    {
        return new class extends Adminer {
            public function __construct()
            {
                // The real constructor wants an application; the handover wants nothing.
            }

            public function exposeHandOver(): void
            {
                $this->handOverSession();
            }
        };
    }

    /**
     * After the handover our session id is not what Adminer will find.
     *
     * The assertion the old code could not make: closing a session does not clear its id,
     * so `session_start()` under a different name still lands on the same file.
     *
     * With no `adminer_sid` cookie in the request — this fixture sends none — the id is
     * left unset and Adminer starts a session of its own. What happens when the browser
     * *does* send one is the subject of {@see AdminerSessionPerRequestTest}, and it is not
     * "unset": an id explicitly set to nothing stops PHP consulting the cookie at all.
     */
    #[RunInSeparateProcess]
    public function testTheSessionIdIsCleared(): void
    {
        // Arrange — a request with a session, as every request has
        @session_start();
        $_SESSION['token'] = str_repeat('a', 64);
        $ours = session_id();

        // Act
        $this->controller()->exposeHandOver();

        // Assert
        $this->assertNotSame('', $ours, 'the fixture never had a session to hand over');
        $this->assertNotSame($ours, session_id(), 'Adminer would have inherited our session id');
        $this->assertSame('', session_id(), 'with no cookie to resume, no id should be left');
        $this->assertSame(array(), $_SESSION, '$_SESSION was left populated');
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
    }

    /**
     * And a session started afterwards gets an id of its own, not ours.
     *
     * The consequence, driven the way Adminer's bootstrap drives it: a different session
     * name and a fresh `session_start()`. Without the clear this lands on our id and the
     * two applications share one file — which is how the framework's session came to be
     * overwritten by fifteen bytes of Adminer token.
     */
    #[RunInSeparateProcess]
    public function testASessionStartedAfterwardsDoesNotLandOnOurs(): void
    {
        // Arrange
        @session_start();
        $_SESSION['token'] = str_repeat('a', 64);
        $ours = session_id();

        // Act — the handover, then what Adminer's bootstrap does
        $this->controller()->exposeHandOver();
        session_name('adminer_sid');
        @session_start();
        $theirs = session_id();

        // Assert
        $this->assertNotSame($ours, $theirs, 'Adminer reused the framework session id');
        $this->assertNotSame('', $theirs);
    }

    /**
     * Handing over twice, or with no session at all, is not an error.
     *
     * A console entry point has no session, and `display()` is not the only path that
     * might reach this. A guard that only worked from a web request would fail where
     * nobody is watching.
     */
    #[RunInSeparateProcess]
    public function testHandingOverWithoutASessionIsHarmless(): void
    {
        // Act
        $controller = $this->controller();
        $controller->exposeHandOver();
        $controller->exposeHandOver();

        // Assert
        $this->assertSame('', session_id());
        $this->assertSame(array(), $_SESSION);
    }
}
