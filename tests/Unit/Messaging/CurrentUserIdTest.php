<?php

declare(strict_types=1);

namespace Tests\Unit\Messaging;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Messaging\Controllers\MessagesController;

/**
 * Who the messaging controller thinks it is serving.
 *
 * Three statements, never executed, in front of every read and write of somebody's messages. The
 * shape is what matters: **the session decides, and the user object only supplies the id.**
 *
 * ```php
 * return Session::staticIsLogged() ? (int) ($user->userid ?? 0) : 0;
 * ```
 *
 * A cached `currentUser` outlives a sign-out within a request — the object is still there after
 * the session is gone — so reading the id without asking the session would serve a signed-out
 * visitor their own messages, or worse, whoever the cached object happens to be.
 *
 * `0` is the answer for "nobody", and it has to be an id no account can have.
 */
#[CoversClass(MessagesController::class)]
class CurrentUserIdTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['logged'], $_SESSION['uid']);
        $app = Application::getInstance();
        $app->currentUser = null;
        parent::tearDown();
    }

    /** Exposes the seam every action in the controller reads. */
    private function controller(): object
    {
        return new class extends MessagesController {
            public function __construct() {}

            public function exposeCurrentUserId(): int
            {
                return $this->currentUserId();
            }
        };
    }

    /** Signs somebody in, as the session and the application see it. */
    private function signIn(int $userid): void
    {
        $user = new \Pramnos\User\User();
        $user->userid = $userid;

        Application::getInstance()->currentUser = $user;
        $_SESSION['logged'] = true;
        $_SESSION['uid']    = $userid;
    }

    /** A signed-in visitor is their own id. */
    public function testASignedInVisitorIsTheirOwnId(): void
    {
        // Arrange
        $this->signIn(4242);

        // Act + Assert
        $this->assertSame(4242, $this->controller()->exposeCurrentUserId());
    }

    /**
     * With no session, the answer is `0` even when a user object is still cached.
     *
     * The assertion this method exists for. The application caches `currentUser` for the length of
     * a request, so the object survives a sign-out — and an id read straight off it would serve
     * somebody else's messages to a visitor who no longer has a session.
     */
    public function testWithNoSessionTheAnswerIsZeroEvenWithAUserCached(): void
    {
        // Arrange — a cached user, and no session
        $user = new \Pramnos\User\User();
        $user->userid = 4242;
        Application::getInstance()->currentUser = $user;
        unset($_SESSION['logged'], $_SESSION['uid']);

        // Act + Assert
        $this->assertSame(
            0,
            $this->controller()->exposeCurrentUserId(),
            'a signed-out visitor was given a cached account\'s id'
        );
    }

    /** With nobody signed in and nothing cached, `0`. */
    public function testWithNobodyAtAllTheAnswerIsZero(): void
    {
        // Arrange
        Application::getInstance()->currentUser = null;
        unset($_SESSION['logged'], $_SESSION['uid']);

        // Act + Assert
        $this->assertSame(0, $this->controller()->exposeCurrentUserId());
    }
}
