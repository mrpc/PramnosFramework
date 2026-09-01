<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Security\OutboundUrl;

/**
 * The check that has to happen before the server fetches a URL somebody else chose.
 *
 * The server sits inside a network the visitor cannot reach: a cloud provider's metadata endpoint on
 * 169.254.169.254, an unauthenticated admin panel on loopback, a database on a private address, a
 * service on the same subnet that trusts anything arriving from inside. Handing a visitor-supplied
 * URL to `file_get_contents()` turns the application into a proxy into its own network, and the
 * response is usually handed straight back to whoever supplied the address.
 *
 * Every case below is a way that goes wrong, and every one of them looks like a URL.
 */
#[CoversClass(OutboundUrl::class)]
class OutboundUrlTest extends TestCase
{
    /**
     * An address inside this network is refused, however it is spelled.
     *
     * Six spellings of the same idea, because a check written against one of them passes the others.
     * `localhost` is the one that catches a hand-rolled guard looking for digits; `[::1]` catches an
     * IPv4-only one; `169.254.169.254` is not a private range at all, it is link-local, and it is
     * where every cloud provider serves credentials to whatever asks.
     *
     * @param string $url
     */
    #[DataProvider('insideTheNetwork')]
    public function testAnAddressInsideThisNetworkIsRefused(string $url): void
    {
        // Act
        $reason = null;
        $allowed = OutboundUrl::isPublic($url, $reason);

        // Assert
        $this->assertFalse($allowed, $url . ' was allowed');
        $this->assertIsString($reason, 'a refusal with no reason cannot be logged usefully');
        $this->assertStringNotContainsString(
            '127.0.0.1',
            $reason,
            'the reason names an internal address, which hands over the network map'
        );
    }

    /** @return array<string, array{string}> */
    public static function insideTheNetwork(): array
    {
        return [
            'loopback'         => ['http://127.0.0.1/thing'],
            'loopback by name' => ['http://localhost/thing'],
            'IPv6 loopback'    => ['http://[::1]/thing'],
            'cloud metadata'   => ['http://169.254.169.254/latest/meta-data/'],
            'private 10/8'     => ['http://10.0.0.5/thing'],
            'private 192.168'  => ['https://192.168.1.1/thing'],
        ];
    }

    /**
     * Only http and https, which is why the list is an allowlist.
     *
     * `file:///etc/passwd` is a perfectly well-formed URL. A fetch helper that accepts any scheme PHP
     * knows reads local files for whoever supplies the address, and `php://filter` reads the
     * application's own source. Neither is a network request, and neither is what a denylist of «not
     * file://» would have caught.
     *
     * @param string $url
     */
    #[DataProvider('notFetchable')]
    public function testOnlyHttpAndHttpsAreFetched(string $url): void
    {
        // Act + Assert
        $this->assertFalse(OutboundUrl::isPublic($url), $url . ' was allowed');
    }

    /** @return array<string, array{string}> */
    public static function notFetchable(): array
    {
        return [
            'local file'   => ['file:///etc/passwd'],
            'php filter'   => ['php://filter/read=convert.base64-encode/resource=index.php'],
            'data'         => ['data://text/plain;base64,SSBhbSBhbiBpbWFnZQ=='],
            'ftp'          => ['ftp://example.com/logo.png'],
            'gopher'       => ['gopher://example.com/'],
            'no scheme'    => ['//example.com/logo.png'],
            'not a url'    => ['this is not a url'],
            'empty'        => [''],
        ];
    }

    /**
     * A URL with credentials in it is refused rather than read past.
     *
     * `http://expected.example@10.0.0.1/` reads as a URL for `expected.example` — to a person
     * skimming a log, and to any check that looks at the string rather than at `parse_url()`'s host.
     * The host is `10.0.0.1`. Nothing legitimate puts credentials in a URL any more, so refusing the
     * shape costs nothing and removes a class of misreading, the reviewer's included.
     */
    public function testAUrlWithCredentialsIsRefused(): void
    {
        // Act
        $reason = null;
        $allowed = OutboundUrl::isPublic('http://expected.example@10.0.0.1/logo.png', $reason);

        // Assert
        $this->assertFalse($allowed);
        $this->assertStringContainsString('credentials', (string) $reason);
    }

    /**
     * A host that does not resolve is refused, not quietly allowed.
     *
     * The failure mode this guards is specific and easy to write: a loop that checks every resolved
     * address passes trivially when there are no addresses to check, so «this name does not exist»
     * becomes «no address failed the check».
     */
    public function testAHostThatDoesNotResolveIsRefused(): void
    {
        // Act — .invalid is reserved by RFC 2606 precisely so it can never resolve
        $reason = null;
        $allowed = OutboundUrl::isPublic('https://nothing.invalid/logo.png', $reason);

        // Assert
        $this->assertFalse($allowed);
        $this->assertStringContainsString('resolve', (string) $reason);
    }

    /**
     * A public address passes, or the check would be a way of turning the feature off.
     *
     * An IP literal rather than a name, so this asserts the address logic and not somebody's DNS.
     */
    public function testAPublicAddressIsAllowed(): void
    {
        // Act
        $reason = null;
        $allowed = OutboundUrl::isPublic('https://93.184.216.34/logo.png', $reason);

        // Assert
        $this->assertTrue($allowed, (string) $reason);
        $this->assertNull($reason);
    }

    /**
     * `addressesOf()` returns the literal unchanged, and unbracketed for IPv6.
     *
     * The brackets belong to the URL syntax, not to the address, and a check that forgets to strip
     * them asks `filter_var()` about `[::1]` — which is not an IP, so the answer is «not private» and
     * loopback passes.
     */
    public function testAnIpLiteralIsItsOwnResolution(): void
    {
        // Act + Assert
        $this->assertSame(['93.184.216.34'], OutboundUrl::addressesOf('93.184.216.34'));
        $this->assertSame(['::1'], OutboundUrl::addressesOf('[::1]'));
        $this->assertFalse(OutboundUrl::isPublicAddress('::1'));
    }

    /**
     * `fetch()` refuses before it opens anything.
     *
     * The point of the combined helper is that there is no gap between the check and the request for
     * a second DNS answer to arrive in. This asserts the first half of that: a refused URL produces
     * `false` and a reason, and never reaches the wrapper.
     */
    public function testFetchRefusesWithoutMakingARequest(): void
    {
        // Act
        $reason = null;
        $body = OutboundUrl::fetch('http://169.254.169.254/latest/meta-data/', 1024, $reason);

        // Assert
        $this->assertFalse($body);
        $this->assertIsString($reason);
    }

    /**
     * A redirect to an address inside this network is refused, on the hop.
     *
     * The fault this exists for, and it was measured in the field rather than imagined: an importer
     * checked the host in its catalogue once and then let the stream wrapper follow up to three
     * redirects, so a station's website answering `302 Location: http://169.254.169.254/…` was
     * fetched by the server — with the second address receiving the connection.
     *
     * A `302` is a second address, chosen by the server being fetched. Checking the first one is not
     * checking it.
     */
    public function testARedirectIntoThisNetworkIsRefused(): void
    {
        // Act
        $reason = null;
        $hop = OutboundUrl::nextHop(
            'https://example.test/logo.png',
            ['HTTP/1.1 302 Found', 'Location: http://169.254.169.254/latest/meta-data/'],
            $reason
        );

        // Assert
        $this->assertFalse($hop, 'the hop was approved');
        $this->assertStringContainsString('redirect', (string) $reason);
    }

    /**
     * A response that is not a redirect produces no hop, and no refusal either.
     *
     * `null` and `false` are different answers and a caller loops on the difference: `null` means
     * «this is the response», `false` means «stop, and do not use what you have». Collapsing them
     * into a falsy check turns every ordinary 200 into a failure.
     */
    public function testAPlainResponseProducesNoHop(): void
    {
        // Act
        $reason = null;
        $hop = OutboundUrl::nextHop(
            'https://example.test/logo.png',
            ['HTTP/1.1 200 OK', 'Content-Type: image/png'],
            $reason
        );

        // Assert
        $this->assertNull($hop);
        $this->assertNull($reason);
    }

    /**
     * A redirect with no `Location` is refused rather than treated as a response.
     *
     * A 3xx with nothing to follow is a broken server, and the body of one is not the resource that
     * was asked for — so handing it back would store a redirect notice as somebody's logo.
     */
    public function testARedirectWithNoLocationIsRefused(): void
    {
        // Act
        $reason = null;
        $hop = OutboundUrl::nextHop('https://example.test/a', ['HTTP/1.1 301 Moved'], $reason);

        // Assert
        $this->assertFalse($hop);
        $this->assertStringContainsString('no Location', (string) $reason);
    }

    /**
     * The *last* `Location` wins, because that is the one a client acts on.
     *
     * A misconfigured server can send two. A check that read the first would approve an address the
     * fetch does not dial, which is worse than no check: it produces a record saying the safe one was
     * verified.
     */
    public function testTheLastLocationIsTheOneChecked(): void
    {
        // Act
        $reason = null;
        $hop = OutboundUrl::nextHop(
            'https://example.test/a',
            [
                'HTTP/1.1 302 Found',
                'Location: https://93.184.216.34/first',
                'Location: http://127.0.0.1/second',
            ],
            $reason
        );

        // Assert
        $this->assertFalse($hop, 'the first Location was checked and the second would be dialled');
    }

    /**
     * Every shape a `Location` arrives in resolves to the address that will actually be dialled.
     *
     * The part the filing that asked for this called out as easy to get subtly wrong, and it is:
     *
     *  - **protocol-relative** `//host/path` inherits the scheme and **not** the host. Read as a path,
     *    it turns somebody else's host into a directory on this one — and the fetch then goes
     *    somewhere nobody checked.
     *  - **relative** is relative to the *directory*, not to the path: `/a/b` + `c` is `/a/c`.
     *  - `..` is collapsed, because the address that gets checked has to be the string that gets
     *    dialled.
     *
     * @param string $from
     * @param string $location
     * @param string $expected
     */
    #[DataProvider('locations')]
    public function testALocationResolvesToWhatWillBeDialled(
        string $from,
        string $location,
        string $expected
    ): void {
        // Act & Assert
        $this->assertSame($expected, OutboundUrl::resolveLocation($from, $location));
    }

    /** @return array<string, array{string, string, string}> */
    public static function locations(): array
    {
        return [
            'absolute' => [
                'https://a.test/x/y',
                'https://b.test/logo.png',
                'https://b.test/logo.png',
            ],
            'protocol-relative keeps the scheme and takes the host' => [
                'https://a.test/x/y',
                '//b.test/logo.png',
                'https://b.test/logo.png',
            ],
            'absolute path' => [
                'https://a.test/x/y',
                '/logo.png',
                'https://a.test/logo.png',
            ],
            'relative is relative to the directory' => [
                'https://a.test/x/y',
                'logo.png',
                'https://a.test/x/logo.png',
            ],
            'dot-dot is collapsed' => [
                'https://a.test/x/y/z',
                '../logo.png',
                'https://a.test/x/logo.png',
            ],
            'the port travels' => [
                'https://a.test:8443/x/y',
                '/logo.png',
                'https://a.test:8443/logo.png',
            ],
            'a query-only Location keeps the path' => [
                'https://a.test/x/y',
                '?size=large',
                'https://a.test/x/y?size=large',
            ],
            'a query survives normalisation' => [
                'https://a.test/x/y',
                '../logo.png?v=2',
                'https://a.test/logo.png?v=2',
            ],
            'nothing resolves to nothing' => ['https://a.test/x', '', ''],
        ];
    }

    /**
     * A `Location` naming a scheme this class refuses comes back as a refusal, not as a resolution.
     *
     * `resolveLocation()` hands back absolute addresses as they are — including `file:///etc/passwd`,
     * which is a well-formed absolute URL. The refusal belongs to `isPublic()`, and `nextHop()` is
     * where the two meet: a resolver that quietly dropped such a value would leave the caller
     * following the previous address again, in a loop.
     */
    public function testARedirectToAnotherSchemeIsRefusedRatherThanDropped(): void
    {
        // Act — a local file, which is a well-formed absolute URL with no host
        $reason = null;
        $localFile = OutboundUrl::nextHop(
            'https://example.test/a',
            ['HTTP/1.1 302 Found', 'Location: file:///etc/passwd'],
            $reason
        );

        // …and a scheme that has one, so the scheme itself is what is refused
        $ftpReason = null;
        $ftp = OutboundUrl::nextHop(
            'https://example.test/a',
            ['HTTP/1.1 302 Found', 'Location: ftp://elsewhere.test/logo.png'],
            $ftpReason
        );

        // Assert
        $this->assertFalse($localFile);
        $this->assertStringContainsString('A redirect was refused', (string) $reason);

        $this->assertFalse($ftp);
        $this->assertStringContainsString(
            'http',
            (string) $ftpReason,
            'the refusal does not say which schemes are allowed'
        );
    }

    /**
     * With following switched off, a redirect fails rather than looking like an empty success.
     *
     * This is the trap the filing named. `ignore_errors => true` is what lets a caller read a 404
     * body, and it also meant a `302` came back as a *successful fetch of an empty string* — with no
     * status and no `Location` anywhere in the return, so a caller could neither act on it nor know
     * it had happened. `false` and a reason is the only honest answer available at `maxRedirects = 0`.
     *
     * Asserted on the reason rather than on a live redirect: every address this class would follow to
     * is by definition outside this network, and reaching one from a test is a network call the suite
     * does not make.
     */
    public function testAnUnfollowedRedirectIsAFailure(): void
    {
        // Arrange — the loop's decision, in isolation from the socket
        $reason = null;

        // Act
        $hop = OutboundUrl::nextHop(
            'https://example.test/a',
            ['HTTP/1.1 302 Found', 'Location: https://93.184.216.34/b'],
            $reason
        );

        // Assert — an approved hop, which `fetch()` then refuses to take unless asked
        $this->assertSame('https://93.184.216.34/b', $hop);
        $this->assertNull($reason);

        $this->assertSame(
            5,
            (new \ReflectionMethod(OutboundUrl::class, 'fetch'))->getNumberOfParameters(),
            'fetch() has no maxRedirects, so a caller still cannot follow one safely'
        );
    }

    /**
     * The status is read from the last status line, not the first.
     *
     * A chain the wrapper followed itself leaves several, and the one describing the response in hand
     * is the last. Reading the first would classify the final 200 of a chain as a redirect and send
     * the caller round again.
     */
    public function testTheStatusComesFromTheLastStatusLine(): void
    {
        // Act
        $hop = OutboundUrl::nextHop(
            'https://example.test/a',
            [
                'HTTP/1.1 301 Moved',
                'Location: https://example.test/b',
                'HTTP/1.1 200 OK',
                'Content-Type: image/png',
            ]
        );

        // Assert
        $this->assertNull($hop, 'the finished chain was read as still redirecting');
    }
}
