<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\ClientIpResolver;

/**
 * The trusted-proxy resolver.
 *
 * WHAT: given a `$_SERVER` array and a list of trusted proxies, which address
 *       is the client's?
 * WHY:  this is a security boundary, not a convenience. Reading
 *       `X-Forwarded-For` without a trusted-proxy list hands every attacker an
 *       unlimited supply of rate-limit buckets — a fresh random header value
 *       per request defeats a per-IP limit completely, while the logs show a
 *       healthy spread of addresses. Ignoring the header entirely puts every
 *       visitor behind a proxy into one bucket. Only the trusted-proxy walk
 *       gets both cases right, so the walk itself has to be exercised
 *       adversarially.
 */
class ClientIpResolverTest extends TestCase
{
    /**
     * Build a `$_SERVER`-shaped array.
     *
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function server(string $remote, array $extra = []): array
    {
        return array_merge(['REMOTE_ADDR' => $remote], $extra);
    }

    // ── The default: trust nothing ───────────────────────────────────────────

    /**
     * With no proxies configured the answer is the connecting peer.
     *
     * This is the framework's previous behaviour and the safe default: an
     * application that has not thought about proxies must not silently start
     * believing headers.
     */
    public function testWithNoTrustedProxiesTheAnswerIsRemoteAddr(): void
    {
        // Arrange
        $resolver = new ClientIpResolver();

        // Act
        $ip = $resolver->resolve($this->server('198.51.100.9'));

        // Assert
        $this->assertSame('198.51.100.9', $ip);
    }

    /**
     * With no proxies configured, `X-Forwarded-For` is ignored outright.
     *
     * The single most important assertion here. If this fails, any client can
     * choose its own identity and every per-IP control in the framework is
     * decorative.
     */
    public function testForwardedHeaderIsIgnoredWhenNoProxiesAreTrusted(): void
    {
        // Arrange
        $resolver = new ClientIpResolver();
        $server   = $this->server('198.51.100.9', [
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ]);

        // Act
        $ip = $resolver->resolve($server);

        // Assert
        $this->assertSame('198.51.100.9', $ip, 'an unverified header must not win');
    }

    /**
     * A trusted list does not make headers from untrusted peers believable.
     *
     * The proxy list says which *peers* may speak about other addresses. A
     * direct connection from someone outside that list is just a client, no
     * matter what it puts in its headers.
     */
    public function testForwardedHeaderIsIgnoredWhenThePeerIsNotATrustedProxy(): void
    {
        // Arrange
        $resolver = new ClientIpResolver(['10.0.0.0/8']);
        $server   = $this->server('203.0.113.5', [
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ]);

        // Act
        $ip = $resolver->resolve($server);

        // Assert
        $this->assertSame('203.0.113.5', $ip);
    }

    // ── The walk ─────────────────────────────────────────────────────────────

    /**
     * A single proxy hop yields the client that reached it.
     */
    public function testSingleTrustedProxyReturnsTheForwardedClient(): void
    {
        // Arrange
        $resolver = new ClientIpResolver(['10.0.0.1']);
        $server   = $this->server('10.0.0.1', [
            'HTTP_X_FORWARDED_FOR' => '203.0.113.5',
        ]);

        // Act
        $ip = $resolver->resolve($server);

        // Assert
        $this->assertSame('203.0.113.5', $ip);
    }

    /**
     * The chain is walked right-to-left, skipping trusted hops.
     *
     * Each proxy appends the address it received from, so the rightmost entries
     * were written by our own infrastructure and are trustworthy; the leftmost
     * is whatever the original client claimed.
     */
    public function testTheChainIsWalkedFromTheRightSkippingTrustedHops(): void
    {
        // Arrange — two of our own proxies in front of a real client
        $resolver = new ClientIpResolver(['private_ranges']);
        $server   = $this->server('10.0.0.1', [
            'HTTP_X_FORWARDED_FOR' => '203.0.113.5, 10.0.0.2, 10.0.0.3',
        ]);

        // Act
        $ip = $resolver->resolve($server);

        // Assert
        $this->assertSame('203.0.113.5', $ip);
    }

    /**
     * A client that prepends a forged address does not get to choose its bucket.
     *
     * This is the attack the whole design exists to stop. The attacker sits at
     * 203.0.113.5 behind our proxy and writes `X-Forwarded-For: 9.9.9.9`; the
     * proxy appends the address it actually saw. Taking the leftmost entry
     * would return the forgery.
     */
    public function testAForgedLeadingEntryDoesNotBecomeTheClient(): void
    {
        // Arrange
        $resolver = new ClientIpResolver(['private_ranges']);
        $server   = $this->server('10.0.0.1', [
            'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 203.0.113.5',
        ]);

        // Act
        $ip = $resolver->resolve($server);

        // Assert — the address the proxy observed, not the one the client typed
        $this->assertSame('203.0.113.5', $ip, 'the leftmost entry is attacker-controlled');
    }

    /**
     * A forged entry cannot be laundered by making it look like a proxy either.
     *
     * An attacker who knows the private ranges might write a trusted-looking
     * address hoping to be skipped over and reach something further left. The
     * walk stops at the first untrusted entry from the right, so what it finds
     * is still the address our proxy observed.
     */
    public function testForgingTrustedLookingEntriesDoesNotReachPastThem(): void
    {
        // Arrange
        $resolver = new ClientIpResolver(['private_ranges']);
        $server   = $this->server('10.0.0.1', [
            'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 10.0.0.99, 203.0.113.5',
        ]);

        // Act
        $ip = $resolver->resolve($server);

        // Assert
        $this->assertSame('203.0.113.5', $ip);
    }

    /**
     * When every hop is trusted, the leftmost is the best available answer.
     *
     * This is the all-internal case — a health check traversing only our own
     * proxies. There is no untrusted entry to find, and the leftmost is the
     * closest thing to an origin we were given.
     */
    public function testAnEntirelyTrustedChainReturnsTheLeftmostEntry(): void
    {
        // Arrange
        $resolver = new ClientIpResolver(['private_ranges']);
        $server   = $this->server('10.0.0.1', [
            'HTTP_X_FORWARDED_FOR' => '10.0.0.7, 10.0.0.8',
        ]);

        // Act
        $ip = $resolver->resolve($server);

        // Assert
        $this->assertSame('10.0.0.7', $ip);
    }

    /**
     * A trusted peer sending no forwarding header is itself the client.
     */
    public function testTrustedPeerWithNoHeaderReturnsThePeer(): void
    {
        // Arrange
        $resolver = new ClientIpResolver(['private_ranges']);

        // Act
        $ip = $resolver->resolve($this->server('10.0.0.1'));

        // Assert
        $this->assertSame('10.0.0.1', $ip);
    }

    // ── Malformed input ──────────────────────────────────────────────────────

    /**
     * Entries that are not IP addresses are discarded, not used as keys.
     *
     * A client can put anything in the header. Letting `not-an-ip` through
     * would put arbitrary client-controlled text into cache keys and database
     * columns.
     */
    public function testNonAddressEntriesAreDiscarded(): void
    {
        // Arrange
        $resolver = new ClientIpResolver(['private_ranges']);
        $server   = $this->server('10.0.0.1', [
            'HTTP_X_FORWARDED_FOR' => 'not-an-ip, <script>, 203.0.113.5',
        ]);

        // Act
        $ip = $resolver->resolve($server);

        // Assert
        $this->assertSame('203.0.113.5', $ip);
    }

    /**
     * A header made entirely of rubbish falls back to the peer.
     *
     * Falling back is what keeps the limiter working: the alternative is an
     * empty key that every rubbish-sending client would share, or worse, share
     * with nobody.
     */
    public function testAnEntirelyInvalidHeaderFallsBackToThePeer(): void
    {
        // Arrange
        $resolver = new ClientIpResolver(['private_ranges']);
        $server   = $this->server('10.0.0.1', [
            'HTTP_X_FORWARDED_FOR' => 'garbage, more-garbage',
        ]);

        // Act
        $ip = $resolver->resolve($server);

        // Assert
        $this->assertSame('10.0.0.1', $ip);
    }

    /**
     * Port suffixes are stripped from both address families.
     *
     * Some proxies append the source port. Leaving it on would split one
     * client's requests across a new bucket per connection — an unlimited
     * supply, by accident rather than by attack.
     */
    public function testPortSuffixesAreStripped(): void
    {
        // Arrange
        $resolver = new ClientIpResolver(['private_ranges']);

        // Act
        $v4 = $resolver->resolve($this->server('10.0.0.1', [
            'HTTP_X_FORWARDED_FOR' => '203.0.113.5:41234',
        ]));
        $v6 = $resolver->resolve($this->server('10.0.0.1', [
            'HTTP_X_FORWARDED_FOR' => '[2001:db8::1]:443',
        ]));

        // Assert
        $this->assertSame('203.0.113.5', $v4);
        $this->assertSame('2001:db8::1', $v6);
    }

    /**
     * CLI, where there is no peer at all, resolves to the empty string.
     */
    public function testNoPeerResolvesToEmptyString(): void
    {
        // Act & Assert
        $this->assertSame('', (new ClientIpResolver())->resolve([]));
    }

    // ── IPv6 and CIDR arithmetic ─────────────────────────────────────────────

    /**
     * IPv6 proxies are matched by prefix like IPv4 ones.
     */
    public function testIpv6ProxiesAreMatchedByPrefix(): void
    {
        // Arrange
        $resolver = new ClientIpResolver(['2001:db8::/32']);
        $server   = $this->server('2001:db8::5', [
            'HTTP_X_FORWARDED_FOR' => '203.0.113.5',
        ]);

        // Act
        $ip = $resolver->resolve($server);

        // Assert
        $this->assertSame('203.0.113.5', $ip);
    }

    /**
     * An IPv4 address never matches an IPv6 range, or vice versa.
     *
     * The two pack to different byte lengths; comparing them as strings would
     * be meaningless, and a false match here would trust a stranger.
     */
    public function testAddressFamiliesDoNotCrossMatch(): void
    {
        // Arrange
        $v6Only = new ClientIpResolver(['2001:db8::/32']);
        $v4Only = new ClientIpResolver(['10.0.0.0/8']);

        // Assert
        $this->assertFalse($v6Only->isTrusted('10.0.0.1'));
        $this->assertFalse($v4Only->isTrusted('2001:db8::1'));
    }

    /**
     * Prefixes that do not fall on a byte boundary are honoured exactly.
     *
     * `172.16.0.0/12` covers 172.16–172.31 and must not leak into 172.32. A
     * sloppy implementation that compares whole bytes only would wrongly trust
     * the neighbour, and trusting one address too many is how a proxy list
     * stops being a boundary.
     */
    public function testPartialByteMasksAreExact(): void
    {
        // Arrange
        $resolver = new ClientIpResolver(['172.16.0.0/12']);

        // Assert
        $this->assertTrue($resolver->isTrusted('172.16.0.1'), 'start of the range');
        $this->assertTrue($resolver->isTrusted('172.31.255.254'), 'end of the range');
        $this->assertFalse($resolver->isTrusted('172.32.0.1'), 'one past the end');
        $this->assertFalse($resolver->isTrusted('172.15.255.254'), 'one before the start');
    }

    /**
     * A bare address in the list is an exact match, not a prefix.
     */
    public function testABareAddressMatchesOnlyItself(): void
    {
        // Arrange
        $resolver = new ClientIpResolver(['192.0.2.7']);

        // Assert
        $this->assertTrue($resolver->isTrusted('192.0.2.7'));
        $this->assertFalse($resolver->isTrusted('192.0.2.8'));
    }

    /**
     * Malformed configuration is ignored rather than trusted.
     *
     * A typo in `trusted_proxies` must fail closed. Anything else turns a
     * configuration mistake into an open door.
     */
    public function testMalformedRangesTrustNothing(): void
    {
        // Arrange
        $resolver = new ClientIpResolver(['not-a-range', '10.0.0.0/nonsense', '10.0.0.0/999']);

        // Assert
        $this->assertFalse($resolver->isTrusted('10.0.0.1'));
    }

    // ── Other header forms ───────────────────────────────────────────────────

    /**
     * `CF-Connecting-IP` is honoured from a trusted peer and ignored otherwise.
     *
     * The framework used to read this header unconditionally in three places,
     * which meant any client could dictate the address written into its session
     * and token records. It is single-valued, so there is no chain to walk and
     * the peer check is the only thing standing behind it.
     */
    public function testCloudflareHeaderNeedsATrustedPeer(): void
    {
        // Arrange
        $server = $this->server('10.0.0.1', ['HTTP_CF_CONNECTING_IP' => '203.0.113.5']);

        // Act & Assert
        $this->assertSame(
            '203.0.113.5',
            (new ClientIpResolver(['private_ranges']))->resolve($server),
            'a trusted proxy may name its client'
        );
        $this->assertSame(
            '10.0.0.1',
            (new ClientIpResolver())->resolve($server),
            'an unverified peer may not'
        );
    }

    /**
     * The `cloudflare` shorthand expands to the published edge ranges.
     */
    public function testCloudflareShorthandTrustsTheEdgeRanges(): void
    {
        // Arrange
        $resolver = new ClientIpResolver(['cloudflare']);

        // Assert
        $this->assertTrue($resolver->isTrusted('104.16.0.1'));
        $this->assertFalse($resolver->isTrusted('203.0.113.5'));
    }

    /**
     * RFC 7239 `Forwarded` is read when `X-Forwarded-For` is absent.
     */
    public function testRfc7239ForwardedHeaderIsUnderstood(): void
    {
        // Arrange
        $resolver = new ClientIpResolver(['private_ranges']);
        $server   = $this->server('10.0.0.1', [
            'HTTP_FORWARDED' => 'for=203.0.113.5;proto=https, for=10.0.0.2',
        ]);

        // Act
        $ip = $resolver->resolve($server);

        // Assert
        $this->assertSame('203.0.113.5', $ip);
    }

    /**
     * RFC 7239's obfuscated identifiers are not addresses and are dropped.
     *
     * `for=_hidden` and `for=unknown` are legal values meaning "deliberately
     * not telling you". Treating either as an address would create a bucket
     * shared by everyone who sends it.
     */
    public function testObfuscatedForwardedIdentifiersAreDropped(): void
    {
        // Arrange
        $resolver = new ClientIpResolver(['private_ranges']);
        $server   = $this->server('10.0.0.1', [
            'HTTP_FORWARDED' => 'for=_hidden, for=unknown',
        ]);

        // Act
        $ip = $resolver->resolve($server);

        // Assert
        $this->assertSame('10.0.0.1', $ip);
    }

    /**
     * `X-Forwarded-For` wins over `Forwarded` when both are present.
     *
     * Not a security property — both have passed the peer check by then — but a
     * defined precedence keeps the answer stable rather than depending on which
     * proxy in the chain happened to add which header.
     */
    public function testXForwardedForTakesPrecedenceOverForwarded(): void
    {
        // Arrange
        $resolver = new ClientIpResolver(['private_ranges']);
        $server   = $this->server('10.0.0.1', [
            'HTTP_X_FORWARDED_FOR' => '203.0.113.5',
            'HTTP_FORWARDED'       => 'for=198.51.100.9',
        ]);

        // Act
        $ip = $resolver->resolve($server);

        // Assert
        $this->assertSame('203.0.113.5', $ip);
    }
}
