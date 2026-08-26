<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;

/**
 * A redirect must leave a trace of where it went.
 *
 * `setRedirect()` recorded a destination for `render()` to act on later, and
 * `redirect($url)` acted on one immediately and recorded nothing. So anything that
 * had to work out afterwards where the request had been sent — a test client, a
 * worker, anything not ending in `exit` — could read the first kind and not the
 * second.
 *
 * That is why the administration area's refusal was untestable: it redirects, and
 * the redirect left no destination behind.
 */
class RedirectDestinationTest extends TestCase
{
    /**
     * An inline destination is recorded before it is acted on.
     */
    public function testAnInlineDestinationIsRecorded(): void
    {
        // Arrange
        $app = new class extends \Pramnos\Application\Application {
            public function __construct()
            {
            }
        };

        // Act — quit false, so it records and returns rather than ending
        ob_start();
        $app->redirect('/somewhere', false);
        ob_get_clean();

        // Assert
        $this->assertSame('/somewhere', $app->getRedirect());
    }

    /**
     * A destination set for later is readable too.
     */
    public function testAPendingDestinationIsReadable(): void
    {
        // Arrange
        $app = new class extends \Pramnos\Application\Application {
            public function __construct()
            {
            }
        };

        // Act
        $app->setRedirect('/later');

        // Assert
        $this->assertSame('/later', $app->getRedirect());
    }

    /**
     * With no redirect at all there is nothing to read.
     *
     * A caller distinguishes "sent somewhere" from "refused without saying where",
     * and an empty string would collapse the two.
     */
    public function testNoRedirectReadsAsNothing(): void
    {
        // Arrange
        $app = new class extends \Pramnos\Application\Application {
            public function __construct()
            {
            }
        };

        // Assert
        $this->assertNull($app->getRedirect());
    }

    /**
     * Ending the request on a redirect carries the redirect's status.
     *
     * Under test `close()` throws rather than exiting, and it used to throw with
     * no status — so a 302 and a fault were the same exception and a client could
     * only render both as errors.
     */
    public function testEndingOnARedirectCarriesTheStatus(): void
    {
        // Arrange
        $app = new class extends \Pramnos\Application\Application {
            public function __construct()
            {
            }
        };

        // Act
        ob_start();
        try {
            $app->redirect('/gone', true, 301);
            $this->fail('a redirect that quits must end the request');
        } catch (\Pramnos\Application\ApplicationClosedException $e) {
            // Assert
            $this->assertSame(301, $e->getStatusCode());
            $this->assertSame('/gone', $app->getRedirect());
        } finally {
            ob_get_clean();
        }
    }
}
