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
     * One server on local files is **`ok`** — not a fault, not a warning.
     *
     * This answered `degraded` first and then `notice`, and both were wrong for the same
     * reason: **the existence of a shared-store option does not make not using it a
     * fault.** On one machine `files` is free, has no dependency and nothing to configure.
     * The dashboard put a red badge and a paragraph of alarming prose beside it, on a site
     * that was working exactly as designed.
     *
     * What the check is for survives in `ok`: the detail still names the store, which is
     * how somebody finds a Redis pointed at the wrong host.
     */
    public function testOneServerOnLocalFilesIsOk(): void
    {
        // Act — the handler is injected rather than set: `session.save_handler` cannot
        // be changed once a session is active, so a test that used `ini_set()` passed
        // alone and failed in a full run, where something earlier had started one.
        $result = (new SessionStorageCheck('files', '', 1))->run();

        // Assert
        $this->assertSame('session_storage', $result->name);
        $this->assertSame('ok', $result->status->value);
        $this->assertSame('files', $result->details['handler'], 'the store is still named');
        $this->assertStringContainsString('correct for a single server', $result->message);

        // The multi-server advice is still there — as a detail somebody can go and read,
        // rather than as a severity that interrupts them.
        $this->assertArrayHasKey('before_a_second_server', $result->details);
    }

    /**
     * With nothing declared, it is one server.
     *
     * The default that decides what most installations see, and the one worth asserting
     * directly: an application that never mentions `servers` is single-server, so local
     * files are `ok`. Reading it any other way would put a red badge on every scaffolded
     * project on the day it was generated.
     */
    public function testNothingDeclaredMeansOneServer(): void
    {
        // Act — no third argument, so the application and settings are consulted, and
        // neither says anything in a unit test
        $result = (new SessionStorageCheck('files', ''))->run();

        // Assert
        $this->assertSame('ok', $result->status->value);
    }

    /**
     * An application that declares more than one server **is** degraded on local files.
     *
     * The other half, and what keeps the check worth having: the failure it was written
     * for is real, and on a declared cluster it is reported as a fault rather than as a
     * note. Declared rather than detected because a node cannot count its own siblings.
     */
    public function testMoreThanOneDeclaredServerOnLocalFilesIsDegraded(): void
    {
        // Act
        $result = (new SessionStorageCheck('files', '', 3))->run();

        // Assert
        $this->assertSame('degraded', $result->status->value);
        $this->assertFalse($result->status->isHealthy());
        $this->assertStringContainsString('declares 3 servers', $result->message);
        $this->assertArrayHasKey('fix', $result->details);
    }

    /**
     * A handler this check has never heard of is a `notice`, because it cannot tell.
     *
     * `user` is a handler the application registered itself. It may reach a second server
     * and it may write to local disk, and answering either way would be a guess. `notice`
     * says so, answers 200 and does not fail `health:check`.
     */
    public function testAnUnrecognisedHandlerIsANotice(): void
    {
        // Act
        $result = (new SessionStorageCheck('user', '', 1))->run();

        // Assert
        $this->assertSame('notice', $result->status->value);
        $this->assertTrue($result->status->isHealthy());
        $this->assertStringContainsString('does not recognise', $result->message);
    }

    /**
     * The fix does not advise putting session ids in somebody else's Redis.
     *
     * It used to end "with no path it reuses the cache host", which on a shared server —
     * Virtualmin, cPanel, one Redis and many vhosts — is advice to move session ids into a
     * store every other site on the machine can read. **A session id is an account**, and
     * the cache host is exactly the case where it is not this application's to reuse.
     */
    public function testTheFixDoesNotRecommendASharedRedis(): void
    {
        // Act — the fix detail appears where there is something to fix
        $fix = (new SessionStorageCheck('files', '', 2))->run()->details['fix'];

        // Assert
        $this->assertStringContainsString('this application controls', $fix);
        $this->assertStringContainsString('shared server', $fix);
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
