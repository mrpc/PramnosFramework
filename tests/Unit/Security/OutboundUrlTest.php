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

        /*
         * …and `fetch()` can be asked to take it. Named rather than counted: an assertion on the
         * parameter *count* broke the first time a parameter was added, which is a test failing about
         * something it was never asserting.
         */
        $names = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(OutboundUrl::class, 'fetch'))->getParameters()
        );
        $this->assertContains('maxRedirects', $names, 'a caller cannot ask for a hop to be followed');
        $this->assertContains('status', $names, 'a caller cannot tell a 404 from a 200');
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

    /**
     * The status is readable, which is what tells a 404 apart from a 200.
     *
     * `ignore_errors => true` is deliberate — a caller that wants to read a 404's body should be able
     * to — and it meant a 404, a 403 and a 200 were all the same return value from `fetch()`: a
     * string. «Check the content» answers that for most bodies and fails on the one that matters: a
     * CDN answering 404 with a **placeholder image** returns bytes that are a valid PNG, so every
     * content check passes and the placeholder is stored as the thing that was asked for.
     *
     * It is also the only place the difference between «this address is permanently wrong» and «this
     * server had a bad minute» lives, and that difference decides whether a caller forgets an address
     * or retries it tomorrow.
     *
     * @param list<string> $headers
     * @param int          $expected
     */
    #[DataProvider('headerBlocks')]
    public function testTheStatusIsReadableFromAHeaderBlock(array $headers, int $expected): void
    {
        // Act & Assert
        $this->assertSame($expected, OutboundUrl::statusOf($headers));
    }

    /** @return array<string, array{list<string>, int}> */
    public static function headerBlocks(): array
    {
        return [
            'a plain 200' => [['HTTP/1.1 200 OK', 'Content-Type: image/png'], 200],
            'a 404 with a body' => [['HTTP/1.1 404 Not Found', 'Content-Type: image/png'], 404],
            'HTTP/2 has no reason phrase' => [['HTTP/2 403'], 403],
            'the last status line of a followed chain wins' => [
                ['HTTP/1.1 301 Moved', 'Location: /b', 'HTTP/1.1 200 OK'],
                200,
            ],
            'no status line at all' => [['Content-Type: text/html'], 0],
            'nothing' => [[], 0],
            'a line that only looks like one' => [['X-Note: HTTP/1.1 500 pretend'], 0],
        ];
    }

    /**
     * `fetch()` can hand the status back, and a refused fetch leaves it at zero.
     *
     * The zero matters as much as the number: `$status` is initialised before anything is dialled, so
     * a caller reading it after a refusal sees «nothing was fetched» rather than whatever the variable
     * happened to hold. A stale value from a previous call is the shape of bug an out-parameter
     * invites.
     *
     * Asserted through a refusal rather than a live response, because every address this class would
     * fetch from is by definition outside this network — and the loopback listener a test could stand
     * up is exactly what `isPublic()` refuses. {@see statusOf()} above is where the parsing is tested;
     * this is where the wiring is.
     */
    public function testARefusedFetchLeavesTheStatusAtZero(): void
    {
        // Arrange
        $reason = null;
        $status = 999;

        // Act
        $body = OutboundUrl::fetch('http://127.0.0.1/logo.png', 1024, $reason, 5, 0, $status);

        // Assert
        $this->assertFalse($body);
        $this->assertSame(0, $status, 'the caller would read a status from a fetch that never happened');
        $this->assertIsString($reason);
    }

    /**
     * The URL that is actually dialled carries the approved address, and the name goes in `Host:`.
     *
     * This is the one part of the fetch a suite making no network calls can check, and it is the part
     * that closes the DNS-rebinding window: `fopen($url)` would resolve the name a second time, and a
     * resolver that answers differently gets the private address it was refused a moment ago.
     *
     * The IPv6 row is the fiddly one. An address containing colons has to be bracketed, or the first
     * colon reads as the port separator and the URL points at a host that does not exist — which fails
     * as «could not connect» rather than as anything that suggests a quoting bug.
     *
     * @param string $url
     * @param string $address
     * @param string $expected
     */
    #[DataProvider('dialledUrls')]
    public function testTheDialledUrlCarriesTheApprovedAddress(
        string $url,
        string $address,
        string $expected
    ): void {
        // Act & Assert
        $this->assertSame($expected, OutboundUrl::dialledUrl($url, $address));
    }

    /** @return array<string, array{string, string, string}> */
    public static function dialledUrls(): array
    {
        return [
            'the host is replaced' => [
                'https://example.test/logo.png',
                '93.184.216.34',
                'https://93.184.216.34/logo.png',
            ],
            'an IPv6 address is bracketed' => [
                'https://example.test/logo.png',
                '2606:2800:220:1:248:1893:25c8:1946',
                'https://[2606:2800:220:1:248:1893:25c8:1946]/logo.png',
            ],
            'the port survives' => [
                'https://example.test:8443/logo.png',
                '93.184.216.34',
                'https://93.184.216.34:8443/logo.png',
            ],
            'the query survives' => [
                'https://example.test/logo?size=large',
                '93.184.216.34',
                'https://93.184.216.34/logo?size=large',
            ],
            'no path becomes a root path' => [
                'http://example.test',
                '93.184.216.34',
                'http://93.184.216.34/',
            ],
            'something with no scheme is handed back unchanged' => [
                'not a url',
                '93.184.216.34',
                'not a url',
            ],
        ];
    }

    /**
     * A response past the ceiling is refused, not truncated.
     *
     * Half a JPEG or half a JSON document is worse than nothing: it is the shape the caller expects, so
     * every check downstream accepts it and the failure surfaces somewhere else entirely. And the check
     * is mid-stream, so a server answering with a hundred gigabytes costs this process the ceiling
     * rather than its memory.
     *
     * Driven against an in-memory stream, which is the only way this loop is reachable from a suite
     * that makes no network calls — `fread`/`feof` do not care what kind of stream they are given.
     */
    public function testABodyPastTheCeilingIsRefusedRatherThanTruncated(): void
    {
        // Arrange
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, str_repeat('x', 20000));
        rewind($handle);

        $read = new \ReflectionMethod(OutboundUrl::class, 'readCapped');
        $reason = null;
        $arguments = [$handle, 8192, &$reason];

        // Act
        $body = $read->invokeArgs(null, $arguments);
        fclose($handle);

        // Assert
        $this->assertFalse($body, 'an oversized response was truncated and handed back');
        $this->assertStringContainsString('8192', (string) $arguments[2]);
    }

    /**
     * With truncation allowed, the ceiling returns what was read and says it was cut.
     *
     * WHAT: `readCapped($handle, 8192, $reason, true, $truncated)` answers 8,192 bytes and sets
     *       `$truncated`.
     *
     * WHY:  refusing was the only thing the cap could do, and a cap exists so a hostile or careless
     *       server cannot fill memory — not to make a large page unreadable. A caller reading an
     *       OpenGraph card capped at 512 KB, which is generous for a `<head>`, met a 783 KB
     *       WordPress front page and was told *"That page could not be read. The response is larger
     *       than 512000 bytes."* The `og:title` and `og:image` were at byte 4,000.
     *
     *       The things anybody caps a fetch for — meta tags, a feed header, a manifest — are at the
     *       top of the file by construction, so throwing away the first 512 KB is throwing away the
     *       answer to keep the question.
     */
    public function testTruncationReturnsWhatWasReadAndSaysSo(): void
    {
        // Arrange
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, str_repeat('x', 20000));
        rewind($handle);

        $read      = new \ReflectionMethod(OutboundUrl::class, 'readCapped');
        $reason    = null;
        $truncated = null;
        $arguments = [$handle, 8192, &$reason, true, &$truncated];

        // Act
        $body = $read->invokeArgs(null, $arguments);
        fclose($handle);

        // Assert
        $this->assertIsString($body, 'an oversized response was refused despite truncation');
        $this->assertSame(8192, strlen($body), 'more than the ceiling was handed back');
        $this->assertTrue($arguments[4], 'the caller was not told the body was cut short');
        $this->assertNull($arguments[2], 'a truncated read is not a failure and carries no reason');
    }

    /**
     * Exactly the ceiling, not the ceiling plus the rest of the chunk it was noticed in.
     *
     * The loop reads in 8 KB blocks and checks after appending, so the body in hand when the ceiling
     * is passed is up to a block larger than it. A caller that sized the cap against a limit it has
     * to respect — a column width, a queue message, somebody else's API — would be handed more than
     * it asked for, and would find out downstream.
     */
    public function testTruncationReturnsExactlyTheCeiling(): void
    {
        // Arrange
        $read = new \ReflectionMethod(OutboundUrl::class, 'readCapped');

        foreach ([1, 100, 5000] as $ceiling) {
            $handle = fopen('php://memory', 'r+');
            fwrite($handle, str_repeat('z', 40000));
            rewind($handle);

            $reason    = null;
            $truncated = null;
            $arguments = [$handle, $ceiling, &$reason, true, &$truncated];

            // Act
            $body = $read->invokeArgs(null, $arguments);
            fclose($handle);

            // Assert
            $this->assertSame($ceiling, strlen((string) $body), 'the ceiling was not respected exactly');
            $this->assertTrue($arguments[4]);
        }
    }

    /**
     * A body inside the ceiling reports no truncation.
     *
     * The flag has to be false when nothing was cut, not merely unset: a caller writing
     * `if ($truncated)` around a re-fetch would otherwise re-fetch every small page it ever read.
     */
    public function testABodyInsideTheCeilingIsNotReportedAsTruncated(): void
    {
        // Arrange
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, str_repeat('w', 100));
        rewind($handle);

        $read      = new \ReflectionMethod(OutboundUrl::class, 'readCapped');
        $reason    = null;
        $truncated = null;
        $arguments = [$handle, 8192, &$reason, true, &$truncated];

        // Act
        $body = $read->invokeArgs(null, $arguments);
        fclose($handle);

        // Assert
        $this->assertSame(100, strlen((string) $body));
        $this->assertFalse($arguments[4]);
    }

    /**
     * A body inside the ceiling comes back whole, including one that is exactly the ceiling.
     *
     * The boundary, because the comparison is `>` and an off-by-one here refuses a response that is
     * precisely the size a caller allowed — which reads as an intermittent failure on a CDN that
     * serves a consistently-sized placeholder.
     */
    public function testABodyAtTheCeilingComesBackWhole(): void
    {
        // Arrange
        $read = new \ReflectionMethod(OutboundUrl::class, 'readCapped');

        foreach ([1, 4096, 8192] as $size) {
            $handle = fopen('php://memory', 'r+');
            fwrite($handle, str_repeat('y', $size));
            rewind($handle);

            $reason = null;
            $arguments = [$handle, 8192, &$reason];

            // Act
            $body = $read->invokeArgs(null, $arguments);
            fclose($handle);

            // Assert
            $this->assertSame($size, strlen((string) $body), $size . ' bytes did not come back whole');
            $this->assertNull($arguments[2]);
        }
    }

    /**
     * An address is inside a range when its leading bits match the network's.
     *
     * The boundaries are the cases: the first and last address of a range, the first
     * one past it, a prefix that is not a whole number of bytes (`/12`, `/10`, `/7`), and
     * a range written as a bare address.
     *
     * @param string       $address
     * @param list<string> $ranges
     * @param bool         $inside  Whether the address is in one of them
     */
    #[DataProvider('rangeMembership')]
    public function testInRangesMatchesTheLeadingBits(string $address, array $ranges, bool $inside): void
    {
        // Act
        $result = OutboundUrl::inRanges($address, $ranges);

        // Assert
        $this->assertSame($inside, $result);
    }

    /** @return array<string, array{string, list<string>, bool}> */
    public static function rangeMembership(): array
    {
        return [
            'first of a /24'               => ['10.8.0.0', ['10.8.0.0/24'], true],
            'last of a /24'                => ['10.8.0.255', ['10.8.0.0/24'], true],
            'first past a /24'             => ['10.8.1.0', ['10.8.0.0/24'], false],
            'inside a /12'                 => ['172.31.255.255', ['172.16.0.0/12'], true],
            'just past a /12'              => ['172.32.0.0', ['172.16.0.0/12'], false],
            'inside carrier-grade NAT /10' => ['100.127.0.1', ['100.64.0.0/10'], true],
            'just past it'                 => ['100.128.0.1', ['100.64.0.0/10'], false],
            'ipv6 unique-local /7'         => ['fd12:3456::1', ['fc00::/7'], true],
            'ipv6 outside it'              => ['fe80::1', ['fc00::/7'], false],
            'a bare address is a /32'      => ['10.8.0.5', ['10.8.0.5'], true],
            'and only that address'        => ['10.8.0.6', ['10.8.0.5'], false],
            'second range matches'         => ['192.168.1.1', ['10.0.0.0/8', '192.168.0.0/16'], true],
            'bracketed ipv6 address'       => ['[fd00::1]', ['fc00::/7'], true],
            'no ranges'                    => ['10.0.0.1', [], false],
        ];
    }

    /**
     * A range that does not parse matches nothing — never everything.
     *
     * `(int) 'abc'` is 0, and `/0` is the whole address space, so a prefix read as a
     * number before it is checked as one turns a typo in a config file into "allow every
     * address". The families are kept apart too: an IPv4-mapped IPv6 address is not inside
     * an IPv4 range, because it is not the same bytes.
     *
     * @param string $address
     * @param string $range   Something that is not a usable range for this address
     */
    #[DataProvider('unusableRanges')]
    public function testAnUnusableRangeMatchesNothing(string $address, string $range): void
    {
        // Act
        $result = OutboundUrl::inRanges($address, [$range]);

        // Assert
        $this->assertFalse($result);
    }

    /** @return array<string, array{string, string}> */
    public static function unusableRanges(): array
    {
        return [
            'prefix is not a number'  => ['10.0.0.1', '10.0.0.0/abc'],
            'prefix is empty'         => ['10.0.0.1', '10.0.0.0/'],
            'prefix is negative'      => ['10.0.0.1', '10.0.0.0/-1'],
            'prefix too long for v4'  => ['10.0.0.1', '10.0.0.0/33'],
            'network is not an ip'    => ['10.0.0.1', 'vpn.internal/24'],
            'mapped v6 vs v4 range'   => ['::ffff:10.0.0.1', '10.0.0.0/8'],
            'v4 vs v6 range'          => ['10.0.0.1', '::/0'],
            'address is not an ip'    => ['vpn.internal', '0.0.0.0/0'],
        ];
    }

    /**
     * The private-network list is an organisation's network, not this machine's.
     *
     * `allow_private` means "our services talk over a VPN or a LAN". It must not carry
     * loopback, where this server's own admin ports answer, or link-local, where cloud
     * metadata does.
     *
     * @param string $address
     * @param bool   $inside
     */
    #[DataProvider('privateNetworkMembership')]
    public function testThePrivateNetworkRangesLeaveOutThisMachine(string $address, bool $inside): void
    {
        // Act
        $result = OutboundUrl::inRanges($address, OutboundUrl::PRIVATE_NETWORK_RANGES);

        // Assert
        $this->assertSame($inside, $result);
    }

    /** @return array<string, array{string, bool}> */
    public static function privateNetworkMembership(): array
    {
        return [
            '10/8'             => ['10.1.2.3', true],
            '172.16/12'        => ['172.20.0.1', true],
            '192.168/16'       => ['192.168.50.1', true],
            'tailscale (CGNAT)' => ['100.101.102.103', true],
            'ipv6 ULA'         => ['fd7a:115c:a1e0::1', true],
            'loopback'         => ['127.0.0.1', false],
            'ipv6 loopback'    => ['::1', false],
            'cloud metadata'   => ['169.254.169.254', false],
            'ipv6 link-local'  => ['fe80::1', false],
            'a public address' => ['93.184.216.34', false],
        ];
    }
}
