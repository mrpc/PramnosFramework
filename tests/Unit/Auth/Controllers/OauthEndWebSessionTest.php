<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Oauth;

/**
 * Ending the browser session when an OAuth client asks for it.
 *
 * `logoutwebsession=1` on the OAuth logout means "and sign me out of the site too", not only "drop
 * my tokens". Four statements, never executed, and all four are the `try`/`catch` around it.
 *
 * The catch is the behaviour: **the tokens are already gone by the time this runs.** A session that
 * could not be ended is worth a log line and not worth failing the request over — failing it would
 * report an unsuccessful logout for a logout that had, in the part that matters, succeeded, and the
 * client would sensibly retry a revocation it had already completed.
 */
#[CoversClass(Oauth::class)]
class OauthEndWebSessionTest extends TestCase
{
    /** Exposes the seam, with a logout that behaves as the test says. */
    private function controller(?\Throwable $failure): object
    {
        return new class ($failure) extends Oauth {
            public bool $attempted = false;

            public function __construct(private readonly ?\Throwable $failure) {}

            protected function webLogout(): void
            {
                $this->attempted = true;

                if ($this->failure !== null) {
                    throw $this->failure;
                }
            }

            public function exposeEndWebSession(): void
            {
                $this->endWebSession();
            }
        };
    }

    /**
     * A logout that fails does not fail the request.
     *
     * The whole point of the catch. The tokens are revoked before this line, so raising here would
     * turn a successful revocation into an error the client retries.
     */
    public function testALogoutThatFailsDoesNotFailTheRequest(): void
    {
        // Arrange
        $controller = $this->controller(new \RuntimeException('session store is gone'));

        // Act — no exception is the assertion
        $controller->exposeEndWebSession();

        // Assert
        $this->assertTrue($controller->attempted, 'the logout was never attempted');
    }

    /**
     * A logout that works is simply done.
     *
     * The control: without it, an `endWebSession()` that swallowed everything — including its own
     * failure to call anything — would pass the test above.
     */
    public function testALogoutThatWorksIsDone(): void
    {
        // Arrange
        $controller = $this->controller(null);

        // Act
        $controller->exposeEndWebSession();

        // Assert
        $this->assertTrue($controller->attempted);
    }
}
