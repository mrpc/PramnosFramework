<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Security\OutboundUrl;

/**
 * `OutboundUrl::fetch()` over a real socket.
 *
 * The whole job of this class is to fetch a URL somebody else chose, safely — and its fetch had
 * never executed. Not for want of tests: every address a container can reach is private, loopback
 * or link-local, and refusing exactly those is what the class is *for*. The gap was in the
 * environment, not in the suite.
 *
 * So the environment gained one address. `203.0.113.2` is in TEST-NET-3 (RFC 5737), a block
 * reserved for documentation and routed nowhere — and PHP's `NO_PRIV_RANGE | NO_RES_RANGE` does not
 * exclude the documentation blocks, so it reads as public while a container holding it can still
 * only reach itself. **The guard is untouched**: nothing is overridden, no seam widened, no
 * subclass relaxes `isPublicAddress()`. See `docker-compose.yml`.
 *
 * Which means these are the first assertions about what this class does with a live server rather
 * than about what it decides before dialling one.
 */
#[CoversClass(OutboundUrl::class)]
class OutboundFetchTest extends TestCase
{
    /** The fixture host — public-looking, reachable only from inside the container. */
    private const BASE = 'http://203.0.113.2/tests/fixtures/outbound/';

    protected function setUp(): void
    {
        parent::setUp();

        // A checkout without the fixture network — somebody running the suite outside the
        // project's own compose file — should skip rather than fail on an address it cannot dial.
        $probe = @fsockopen('203.0.113.2', 80, $errno, $errstr, 2);

        if ($probe === false) {
            $this->markTestSkipped(
                'The outbound fixture address is not configured on this host: ' . $errstr
            );
        }

        fclose($probe);
    }

    /**
     * A real fetch returns the body.
     *
     * The statement none of the previous tests could reach. Everything about this class had been
     * asserted up to the point of opening a socket.
     */
    public function testARealFetchReturnsTheBody(): void
    {
        // Act
        $reason = null;
        $body   = OutboundUrl::fetch(self::BASE . 'hello.txt', 1048576, $reason);

        // Assert
        $this->assertIsString($body, 'the fetch failed: ' . (string) $reason);
        $this->assertStringContainsString('over a real socket', $body);
        $this->assertNull($reason, 'a successful fetch should give no reason');
    }

    /**
     * The status comes back, so a caller can tell a 404 from a 200.
     *
     * `ignore_errors` stays on — a caller that wants a 404's body keeps being able to read it —
     * so the status is the only thing distinguishing them. "Check the content" fails on the case
     * that matters: a CDN answering 404 with a placeholder image returns bytes that are a valid
     * PNG, and every content check passes while the placeholder is stored as the thing asked for.
     */
    public function testTheStatusIsReported(): void
    {
        // Act
        $reason = null;
        $status = null;
        $body   = OutboundUrl::fetch(self::BASE . 'hello.txt', 1048576, $reason, 10, 0, $status);

        // Assert
        $this->assertSame(200, $status);
        $this->assertIsString($body);
    }

    /**
     * A missing file is a 404 with a body, not a failure.
     *
     * The distinction the out-parameter exists for: "this address is permanently wrong" is a
     * different thing from "the fetch broke", and only one of them is worth retrying.
     */
    public function testAMissingFileIsAStatusRatherThanAFailure(): void
    {
        // Act
        $reason = null;
        $status = null;
        $body   = OutboundUrl::fetch(self::BASE . 'no-such-file.txt', 1048576, $reason, 10, 0, $status);

        // Assert
        $this->assertSame(404, $status);
        $this->assertIsString($body, 'a 404 body should still be readable');
    }

    /**
     * A response past the byte cap is refused, with the cap named.
     *
     * The cap is read *while* the body arrives rather than checked afterwards, which is the
     * difference between refusing a large response and downloading it first. A fetcher that
     * checked `strlen()` at the end would have already spent the memory it was trying not to.
     */
    public function testAResponsePastTheCapIsRefused(): void
    {
        // Act — the fixture is 40,000 bytes
        $reason = null;
        $body   = OutboundUrl::fetch(self::BASE . 'large.txt', 1024, $reason);

        // Assert
        $this->assertFalse($body);
        $this->assertIsString($reason);
        $this->assertStringContainsString('larger than 1024 bytes', $reason);
    }

    /**
     * A response inside the cap is returned whole.
     *
     * The control. A cap implemented as "refuse everything" would pass the test above.
     */
    public function testAResponseInsideTheCapIsReturnedWhole(): void
    {
        // Act
        $reason = null;
        $body   = OutboundUrl::fetch(self::BASE . 'large.txt', 1048576, $reason);

        // Assert
        $this->assertIsString($body, (string) $reason);
        $this->assertSame(40000, strlen($body));
    }

    /**
     * With no redirects allowed, a 302 is answered as a 302 rather than followed.
     *
     * `$maxRedirects` defaults to `0`, so a caller gets the redirect and decides — which is the
     * safe default for a URL somebody else chose, because the second address is chosen by the
     * server being fetched rather than by the caller.
     */
    public function testWithNoRedirectsAllowedTheRedirectIsNotFollowed(): void
    {
        // Act
        $reason = null;
        $status = null;
        OutboundUrl::fetch(self::BASE . 'redirect.php', 1048576, $reason, 10, 0, $status);

        // Assert
        $this->assertSame(302, $status, 'the redirect was followed with maxRedirects at 0');
    }

    /**
     * With one allowed, it is followed and the destination's body comes back.
     *
     * And the status is the destination's, not the redirect's — a caller that saw `302` after a
     * successful follow could not tell the two situations apart.
     */
    public function testWithOneAllowedTheRedirectIsFollowed(): void
    {
        // Act
        $reason = null;
        $status = null;
        $body   = OutboundUrl::fetch(self::BASE . 'redirect.php', 1048576, $reason, 10, 1, $status);

        // Assert
        $this->assertIsString($body, (string) $reason);
        $this->assertStringContainsString('over a real socket', $body);
        $this->assertSame(200, $status);
    }

    /**
     * A redirect to a private address is refused, even though the first hop was allowed.
     *
     * The reason each hop is a fresh question. A host that passed the check can answer
     * `Location: http://169.254.169.254/…`, and a fetcher that only checked the address it was
     * given would dial cloud metadata without asking anybody. Measured in the wild on an importer
     * reading logo addresses out of a catalogue.
     */
    public function testARedirectToAPrivateAddressIsRefused(): void
    {
        // Act — the fixture redirects wherever it is told
        $reason = null;
        $body   = OutboundUrl::fetch(
            self::BASE . 'redirect.php?to=' . rawurlencode('http://169.254.169.254/latest/meta-data/'),
            1048576,
            $reason,
            10,
            1
        );

        // Assert
        $this->assertFalse($body, 'a hop to the metadata address was followed');
        $this->assertIsString($reason);
    }

    /**
     * And a private address asked for directly is refused before any socket opens.
     *
     * The check that was always covered, asserted here alongside the ones that were not — because
     * the interesting claim is that adding a fetchable address did not widen it.
     */
    public function testAPrivateAddressIsStillRefused(): void
    {
        // Act
        $reason = null;

        // Assert
        $this->assertFalse(OutboundUrl::fetch('http://127.0.0.1/README.md', 1048576, $reason));
        $this->assertIsString($reason);
        $this->assertFalse(OutboundUrl::fetch('http://10.0.0.1/', 1048576, $reason));
        $this->assertFalse(OutboundUrl::fetch('http://169.254.169.254/', 1048576, $reason));
    }
}
