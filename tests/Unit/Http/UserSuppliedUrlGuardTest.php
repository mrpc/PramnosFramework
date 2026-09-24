<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Client;
use Pramnos\Http\ClientException;

/**
 * A Client whose DNS answers whatever the test says, without a network.
 *
 * The refusals worth asserting are for addresses this machine cannot be made to resolve
 * to — `169.254.169.254`, a carrier-grade NAT block, an IPv4-mapped loopback. `resolveHost()`
 * is `protected` exactly so they can be reached.
 */
class FakeDnsClient extends Client
{
    /** @var array<string, list<string>> */
    public array $answers = [];

    protected function resolveHost(string $host): array
    {
        return $this->answers[$host] ?? parent::resolveHost($host);
    }
}

/**
 * Fetching an address somebody else chose.
 *
 * `Client` followed up to five redirects and offered no way to say no, which makes it
 * unsafe for the thing it is most often wanted for. **Fetching a user-supplied URL is
 * server-side request forgery unless redirects stop at a host that has been checked**: a
 * perfectly public server answering `302 http://169.254.169.254/` defeats any amount of
 * pre-flight DNS checking, because the check happened before the hop.
 *
 * The need is ordinary — "verify your site", "import from a URL", a webhook tester, an
 * OG-preview fetcher, an RSS reader — and the cost of getting it wrong is cloud metadata
 * credentials rather than a broken page. So the guard is here rather than in a comment
 * telling every application to be careful.
 *
 * These tests are the refusals. The hop-by-hop checking is in `ClientTransportTest`,
 * against a server that really answers a redirect.
 */
#[CoversClass(Client::class)]
class UserSuppliedUrlGuardTest extends TestCase
{
    protected function setUp(): void
    {
        Client::resetFakes();
    }

    /** A guarded client whose DNS is scripted. */
    private function client(string $url, array $answers, bool $allowHttp = false): FakeDnsClient
    {
        $client = new FakeDnsClient();
        $client->answers = $answers;

        return $client->make('GET', $url)->forUserSuppliedUrl($allowHttp);
    }

    /**
     * The address that matters most, and the one a pre-flight check is written for.
     *
     * `169.254.169.254` is where AWS, GCP and Azure serve instance credentials. A URL
     * whose host resolves to it must not be fetched, whatever the host is called.
     */
    public function testTheCloudMetadataAddressIsRefused(): void
    {
        // Arrange
        $client = $this->client('https://looks-fine.example', ['looks-fine.example' => ['169.254.169.254']]);

        // Assert
        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('169.254.169.254');

        // Act
        $client->send();
    }

    /**
     * Every range that is not the public internet.
     *
     * A table rather than one case, because the ones people forget are not loopback —
     * they are carrier-grade NAT, which is a real address space on a real network, and an
     * IPv4-mapped IPv6 loopback, which is loopback wearing a different notation.
     *
     * @param string $address The address the host resolves to
     */
    #[DataProvider('nonPublicAddresses')]
    public function testANonPublicAddressIsRefused(string $address): void
    {
        // Arrange
        $client = $this->client('https://host.example', ['host.example' => [$address]]);

        // Assert
        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('not a public address');

        // Act
        $client->send();
    }

    /** @return array<string, array{string}> */
    public static function nonPublicAddresses(): array
    {
        return [
            'loopback'             => ['127.0.0.1'],
            'loopback, not .1'     => ['127.8.8.8'],
            'private 10/8'         => ['10.0.0.5'],
            'private 172.16/12'    => ['172.20.1.1'],
            'private 192.168/16'   => ['192.168.1.1'],
            'link-local metadata'  => ['169.254.169.254'],
            'this network 0/8'     => ['0.0.0.0'],
            'carrier-grade NAT'    => ['100.100.0.1'],
            'IETF protocol block'  => ['192.0.0.8'],
            'benchmarking'         => ['198.19.0.1'],
            'multicast'            => ['239.1.1.1'],
            'reserved 240/4'       => ['240.0.0.1'],
            'IPv6 loopback'        => ['::1'],
            'IPv6 unique local'    => ['fd00::1'],
            'IPv6 link-local'      => ['fe80::1'],
            'IPv4-mapped loopback' => ['::ffff:127.0.0.1'],
        ];
    }

    /**
     * One private address among several public ones is still a refusal.
     *
     * A name answering both is the shape of a rebinding attempt: whichever record the
     * fetch happens to use decides whether it reached the internet or the loopback, and
     * "whichever" is not a security property. All or nothing.
     */
    public function testAnyPrivateAddressRefusesTheWholeHost(): void
    {
        // Arrange
        $client = $this->client('https://mixed.example', [
            'mixed.example' => ['93.184.216.34', '127.0.0.1'],
        ]);

        // Assert
        $this->expectException(ClientException::class);

        // Act
        $client->send();
    }

    /**
     * A scheme allowlist, because `file://` and `gopher://` are also URLs.
     */
    public function testANonHttpSchemeIsRefused(): void
    {
        // Arrange
        $client = $this->client('file:///etc/passwd', []);

        // Assert
        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('only https');

        // Act
        $client->send();
    }

    /**
     * Plain `http` is refused by default and allowed on request.
     *
     * Default-deny, because a user-supplied address fetched over `http` can be answered by
     * anybody on the path. Allowed on request because "verify your site" reaches sites
     * that have not got a certificate yet, and refusing outright would push the
     * application into writing its own client — which is the outcome this exists to avoid.
     */
    public function testHttpIsRefusedUnlessAskedFor(): void
    {
        // Arrange
        $refused = $this->client('http://host.example', ['host.example' => ['93.184.216.34']]);

        // Act
        $message = '';
        try {
            $refused->send();
        } catch (ClientException $e) {
            $message = $e->getMessage();
        }

        // Assert — checked outside the catch, so a failure here is not swallowed by it
        $this->assertStringContainsString('only https', $message);

        // And with it allowed, the scheme is no longer the objection: the request gets as
        // far as the network, where a made-up address fails for a different reason.
        $allowed = $this->client('http://host.example', ['host.example' => ['192.168.1.1']], true);
        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('not a public address');
        $allowed->send();
    }

    /**
     * A host that does not resolve is refused rather than attempted.
     */
    public function testAHostThatDoesNotResolveIsRefused(): void
    {
        // Arrange
        $client = $this->client('https://nowhere.example', ['nowhere.example' => []]);

        // Assert
        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('does not resolve');

        // Act
        $client->send();
    }

    /**
     * An IP literal is checked as itself, with no lookup.
     *
     * `https://127.0.0.1/` is the most direct version of the attack and must not need a
     * DNS answer to be refused.
     */
    public function testAnAddressLiteralIsCheckedDirectly(): void
    {
        // Arrange — no scripted answer, so this goes through the real resolver
        $client = new FakeDnsClient();

        // Assert
        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('not a public address');

        // Act
        $client->make('GET', 'https://127.0.0.1/')->forUserSuppliedUrl()->send();
    }

    /**
     * **The hop is checked before it is taken** — the whole finding, as one test.
     *
     * A perfectly public host answers `302 http://169.254.169.254/`. Checking the first
     * URL cannot prevent that, because the check happened before the redirect existed; a
     * client that hands the chain to cURL makes the second request to an address nothing
     * examined. Here the redirect is followed by the client itself, so hop two goes
     * through the same refusal as hop one.
     *
     * The fake answers in place of the network, and the guard runs before it — which is
     * what makes a two-hop chain assertable without either host being real.
     */
    public function testARedirectToAnInternalAddressIsRefused(): void
    {
        // Arrange — hop one is genuinely public; hop two is the metadata service
        Client::fake([
            'https://public.example*' => \Pramnos\Http\ClientResponse::make('', 302, [
                'Location' => 'http://169.254.169.254/latest/meta-data/',
            ]),
        ]);
        $client = $this->client('https://public.example/page', [
            'public.example' => ['93.184.216.34'],
        ], true);

        // Assert
        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('169.254.169.254');

        // Act
        $client->send();
    }

    /**
     * A redirect to another public host is followed, and the response is the last one.
     *
     * The control. A guard that refused every redirect would pass the test above and be
     * useless for the case it was written for — "verify your site" reaches `example.com`
     * and lands on `www.example.com` more often than not.
     */
    public function testARedirectBetweenPublicHostsIsFollowed(): void
    {
        // Arrange
        Client::fake([
            'https://first.example*'  => \Pramnos\Http\ClientResponse::make('', 301, [
                'Location' => 'https://second.example/landed',
            ]),
            'https://second.example*' => \Pramnos\Http\ClientResponse::make('arrived', 200),
        ]);
        $client = $this->client('https://first.example/', [
            'first.example'  => ['93.184.216.34'],
            'second.example' => ['93.184.216.35'],
        ]);

        // Act
        $response = $client->send();

        // Assert
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('arrived', $response->body());
    }

    /**
     * `withoutRedirects()` hands the `30x` back instead of following it.
     *
     * Enough on its own for a caller that would rather decide, and the smallest thing the
     * client was missing.
     */
    public function testWithoutRedirectsReturnsTheRedirect(): void
    {
        // Arrange
        Client::fake([
            'https://first.example*' => \Pramnos\Http\ClientResponse::make('', 302, [
                'Location' => 'https://second.example/',
            ]),
        ]);
        $client = $this->client('https://first.example/', [
            'first.example' => ['93.184.216.34'],
        ])->withoutRedirects();

        // Act
        $response = $client->send();

        // Assert
        $this->assertSame(302, $response->status());
        $this->assertSame('https://second.example/', $response->header('Location'));
    }

    /**
     * A chain longer than the limit stops, and stops by handing back the `30x`.
     *
     * Not by raising: a redirect loop is a property of the site being fetched, not an
     * error in the application doing the fetching, and the caller can see exactly where
     * it gave up.
     */
    public function testAChainLongerThanTheLimitStops(): void
    {
        // Arrange — a site that redirects to itself for ever
        Client::fake([
            'https://loop.example*' => \Pramnos\Http\ClientResponse::make('', 302, [
                'Location' => 'https://loop.example/again',
            ]),
        ]);
        $client = $this->client('https://loop.example/', [
            'loop.example' => ['93.184.216.34'],
        ])->maxRedirects(2);

        // Act
        $response = $client->send();

        // Assert
        $this->assertSame(302, $response->status());
    }

    /**
     * A root-relative `Location` is resolved against the URL it came from.
     *
     * The resolution is this class's job now, because the hop has to exist as an absolute
     * URL before it can be checked. Relative targets are common enough that getting this
     * wrong would look like the guard refusing valid sites.
     */
    public function testARelativeRedirectIsResolvedAndChecked(): void
    {
        // Arrange
        Client::fake([
            'https://site.example/start'  => \Pramnos\Http\ClientResponse::make('', 302, [
                'Location' => '/finish',
            ]),
            'https://site.example/finish' => \Pramnos\Http\ClientResponse::make('done', 200),
        ]);
        $client = $this->client('https://site.example/start', [
            'site.example' => ['93.184.216.34'],
        ]);

        // Act
        $response = $client->send();

        // Assert
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('done', $response->body());
    }

    /**
     * A URL with a scheme and no host, and one that is not a URL at all.
     *
     * Both reach the guard, and `parse_url()` answers `false` for the second — reading a
     * key off `false` is a fatal, so the refusal has to come before the parse is trusted.
     */
    public function testAMalformedUrlIsRefusedRatherThanFatal(): void
    {
        // Arrange + Act — scheme, no host
        $noHost = '';
        try {
            $this->client('https:/just-a-path', [])->send();
        } catch (ClientException $e) {
            $noHost = $e->getMessage();
        }

        // Act — not a URL at all
        $notAUrl = '';
        try {
            $this->client('http://:80', [])->send();
        } catch (ClientException $e) {
            $notAUrl = $e->getMessage();
        }

        // Assert — captured and checked outside the catches
        $this->assertStringContainsString('no host', $noHost);
        $this->assertNotSame('', $notAUrl, 'a malformed URL must be refused, not fatal');
    }

    /**
     * A public IPv6 address is allowed.
     *
     * The v4 range table does not apply to it, and a guard that fell through to "refuse"
     * for anything it could not parse as v4 would block every IPv6-only host.
     */
    public function testAPublicIpv6AddressIsAllowed(): void
    {
        // Arrange
        Client::fake(['https://v6.example*' => \Pramnos\Http\ClientResponse::make('ok', 200)]);
        $client = $this->client('https://v6.example/', ['v6.example' => ['2606:4700:4700::1111']]);

        // Act
        $response = $client->send();

        // Assert
        $this->assertSame(200, $response->status());
    }

    /**
     * A protocol-relative and a path-relative `Location`.
     *
     * `//host/path` inherits the scheme and `next` is relative to the current directory.
     * Both turn up in the wild, and getting either wrong would look like the guard
     * refusing a valid site rather than like a URL being built badly.
     */
    public function testProtocolRelativeAndPathRelativeRedirects(): void
    {
        // Arrange — //other.example/here
        Client::fake([
            'https://start.example/a/b' => \Pramnos\Http\ClientResponse::make('', 302, [
                'Location' => '//other.example/here',
            ]),
            'https://other.example/here' => \Pramnos\Http\ClientResponse::make('scheme kept', 200),
        ]);
        $answers = [
            'start.example' => ['93.184.216.34'],
            'other.example' => ['93.184.216.35'],
        ];

        // Act
        $response = $this->client('https://start.example/a/b', $answers)->send();

        // Assert
        $this->assertStringContainsString('scheme kept', $response->body());

        // Arrange — a bare `next`, resolved against /a/
        Client::fake([
            'https://start.example/a/b'    => \Pramnos\Http\ClientResponse::make('', 302, [
                'Location' => 'next',
            ]),
            'https://start.example/a/next' => \Pramnos\Http\ClientResponse::make('relative', 200),
        ]);

        // Act
        $response = $this->client('https://start.example/a/b', $answers)->send();

        // Assert
        $this->assertStringContainsString('relative', $response->body());
    }

    /**
     * The real resolver is used when nothing is scripted.
     *
     * `localhost` needs no external DNS and answers the loopback, so this exercises the
     * live lookup *and* the refusal in one — the two halves that a scripted answer skips.
     */
    public function testTheRealResolverIsUsedAndItsAnswerIsChecked(): void
    {
        // Arrange — no scripted answers at all
        $client = (new FakeDnsClient())->make('GET', 'http://localhost/')->forUserSuppliedUrl(true);

        // Assert
        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('not a public address');

        // Act
        $client->send();
    }

    /**
     * Without the guard, nothing changes.
     *
     * The client is used all over this framework to talk to addresses the application
     * chose, and none of that may start refusing anything. A faked response never reaches
     * the network, which is what makes this assertable without one.
     */
    public function testAnUnguardedRequestIsUnaffected(): void
    {
        // Arrange
        Client::fake(['*' => \Pramnos\Http\ClientResponse::make('fine', 200)]);

        // Act
        $response = (new Client())->make('GET', 'http://127.0.0.1/anything')->send();

        // Assert
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('fine', $response->body());
    }
}
