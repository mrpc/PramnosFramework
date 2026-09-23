<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Health\Checks\SessionStorageCheck;

/**
 * The check that says whether sessions survive a second server.
 *
 * `files` is PHP's default and is right on one machine. On two it is the quietest failure
 * in a deployment: a visitor whose next request lands on the other node has no session, so
 * they are signed out at random on a site that is otherwise working — and it reads as an
 * expiry, a cookie problem, a `SameSite` mistake. Everything except a load balancer.
 *
 * Nothing else reports it. The application works, every test passes, and the failure exists
 * only in a topology the developer's machine does not have.
 */
#[CoversClass(SessionStorageCheck::class)]
class SessionStorageCheckTest extends TestCase
{
    /**
     * `files` is degraded, and the message says what it costs rather than what it is.
     *
     * **Degraded, not down.** Most installations are single-server and are not broken; a
     * check that pages somebody for a working site is a check that gets muted.
     */
    public function testLocalFilesAreDegradedWithTheReason(): void
    {
        // Act — the handler is injected rather than set: `session.save_handler` cannot
        // be changed once a session is active, so a test that used `ini_set()` passed
        // alone and failed in a full run, where something earlier had started one.
        $result = (new SessionStorageCheck('files', ''))->run();

        // Assert
        $this->assertSame('session_storage', $result->name);
        $this->assertSame('degraded', $result->status->value);
        $this->assertSame('files', $result->details['handler']);
        $this->assertStringContainsString('local to this machine', $result->message);
        $this->assertStringContainsString('APP_SESSION_HANDLER', $result->details['fix']);
    }

    /**
     * A shared store is `ok`.
     *
     * The control: a check that said "degraded" whatever the handler would pass the test
     * above and be a permanent yellow nobody reads.
     */
    public function testASharedStoreIsOk(): void
    {
        // Act
        $result = (new SessionStorageCheck('redis', 'tcp://redis:6379'))->run();

        // Assert
        $this->assertSame('ok', $result->status->value);
        $this->assertSame('redis', $result->details['handler']);
    }

    /**
     * Credentials in the save path are not printed.
     *
     * `tcp://redis:6379?auth=hunter2` is a valid Redis save path, and `health:check` output
     * is pasted into tickets and chat. The host is the useful half and the only half worth
     * printing.
     */
    public function testCredentialsInThePathAreNotReported(): void
    {
        // Act
        $result = (new SessionStorageCheck('files', 'tcp://redis:6379?auth=hunter2'))->run();

        // Assert
        $this->assertStringNotContainsString('hunter2', json_encode($result->details));
        $this->assertStringContainsString('tcp://redis:6379', $result->details['path']);
    }

    /**
     * With nothing injected it reads the live settings.
     *
     * The seam above is for tests, and a check that only worked when something was handed
     * to it would report on nothing in production — which is the failure mode of every
     * seam nobody exercises from the real path.
     */
    public function testWithNothingInjectedItReadsTheLiveSettings(): void
    {
        // Act
        $result = (new SessionStorageCheck())->run();

        // Assert
        $this->assertSame(
            (string) ini_get('session.save_handler'),
            $result->details['handler']
        );
    }

    /**
     * It is registered by default.
     *
     * A health check nobody registers reports nothing, and this one exists precisely
     * because the condition is invisible.
     */
    public function testItIsRegisteredWithTheDefaultChecks(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Application/Application.php'
        );

        // Act + Assert
        $this->assertStringContainsString(
            'new \Pramnos\Health\Checks\SessionStorageCheck()',
            $source
        );
    }
}
