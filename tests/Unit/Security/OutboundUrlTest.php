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
}
