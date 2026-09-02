<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Adminer;

/**
 * What `/adminer` says to somebody who may not have it.
 *
 * A **404**, not a 403, and that is the whole of the decision. A 403 says "this exists and you may
 * not have it", which is a page nothing else on the site produces — so it tells whoever is looking
 * that there *is* a database console here and that they were turned away. The site's own 404 says
 * nothing.
 *
 * Nine statements, never executed, in the refusal that guards a tool that can read every table.
 *
 * It also has to work for a person, because the visitor reaching this URL is usually an
 * administrator who forgot they are signed out — hence the fallback that sets the status and a
 * plain content type when there is no application to ask.
 */
#[CoversClass(Adminer::class)]
class AdminerNotFoundTest extends TestCase
{
    /**
     * A controller whose application records the refusal instead of ending the request.
     *
     * `notFound()` on a real application ends the request itself; under PHPUnit it returns, which
     * is the accommodation this class documents. The double records it so the test can assert the
     * delegation rather than the exit.
     */
    private function controller(bool $withApplication): object
    {
        $application = $withApplication
            ? new class extends \Pramnos\Application\Application {
                public bool $refused = false;

                public function __construct() {}

                public function notFound($message = '')
                {
                    $this->refused = true;

                    return null;
                }
            }
            : null;

        return new class ($application) extends Adminer {
            public bool $terminated = false;

            public function __construct(mixed $application)
            {
                $this->application = $application;
            }

            protected function terminate(): void
            {
                $this->terminated = true;
            }

            public function exposeNotFound(): void
            {
                $this->notFound();
            }

            public function application(): mixed
            {
                return $this->application;
            }
        };
    }

    /**
     * With an application, the refusal is the application's own 404.
     *
     * Delegated rather than reimplemented, so `/adminer` refuses exactly the way every other
     * missing page on the site does — a hand-rolled 404 here would be distinguishable from the
     * real one, which is the thing this is trying to avoid.
     */
    public function testWithAnApplicationTheRefusalIsDelegated(): void
    {
        // Arrange
        $controller = $this->controller(withApplication: true);

        // Act
        $controller->exposeNotFound();

        // Assert
        $this->assertTrue(
            $controller->application()->refused,
            'the application was not asked to produce its own 404'
        );
        $this->assertFalse(
            $controller->terminated,
            'notFound() ends the request itself; terminating again would be a second ending'
        );
    }

    /**
     * With no application on the controller, it asks the one the process has.
     *
     * `$this->application ?? Application::currentInstance()` — and the fallback matters because a
     * controller reached outside the normal dispatch may not have been handed one, while the
     * process almost always has. Asserted by clearing the controller's own and checking the
     * refusal still happens.
     *
     * The branch below *that* — no application anywhere — writes the status itself and terminates.
     * It is not reachable from a test run: `currentInstance()` returns whatever earlier test built
     * an application, and there is always one by the time this file runs. Left as it is rather than
     * forced, because the alternative is a test that dismantles the process's application to prove
     * a fallback nobody reaches.
     */
    public function testWithNoApplicationOnTheControllerItAsksTheProcess(): void
    {
        // Arrange
        $controller = $this->controller(withApplication: false);

        // Act + Assert — the process's own application answers, and it ends the request itself
        $this->expectException(\Pramnos\Application\ApplicationClosedException::class);
        $controller->exposeNotFound();
    }

    /**
     * The refusal is a 404, never a 403.
     *
     * The security property, asserted on the message the application produced: a 403 says "this
     * exists and you may not have it", which tells a caller a database console is installed. A 404
     * is what every missing page on the site says.
     */
    public function testTheRefusalIsAFourOhFourAndNotAForbidden(): void
    {
        // Arrange
        $controller = $this->controller(withApplication: false);

        // Act
        try {
            $controller->exposeNotFound();
            $this->fail('the refusal did not end the request');
        } catch (\Pramnos\Application\ApplicationClosedException $closed) {
            $message = $closed->getMessage();
        }

        // Assert
        $this->assertStringContainsString('404', $message);
        $this->assertStringNotContainsString('403', $message);
        $this->assertStringNotContainsString('Forbidden', $message);
        $this->assertStringNotContainsString(
            'dminer',
            $message,
            'the refusal named the tool, which is the disclosure a 404 exists to avoid'
        );
    }

}
