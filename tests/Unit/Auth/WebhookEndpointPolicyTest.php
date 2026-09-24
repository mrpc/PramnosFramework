<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\WebhookService;
use Pramnos\Database\Database;
use Pramnos\Http\Client;
use Pramnos\Http\ClientResponse;
use Pramnos\Security\OutboundUrl;

/**
 * Which addresses a webhook may be delivered to, and who decides.
 *
 * Two answers, depending on who put the address there:
 *
 * - **The application**, through `/Webhook/register`. An address somebody outside chose, so
 *   it is judged against `authserver.webhooks` in `app.php` — `allow_private` (on by
 *   default) for the organisation's private network, `allow_private_ranges` for anything
 *   more specific — at registration and again, pinned, at every delivery.
 * - **An administrator**, on the application's screen. The operator's own statement about
 *   their network, delivered to as written.
 *
 * Every URL here is an IP literal, so the guard decides without DNS, and every delivery is
 * answered by a fake, so nothing leaves the container. A fake that answers `*` also means a
 * guard that failed open would make a refusal test fail rather than pass.
 */
#[CoversClass(WebhookService::class)]
class WebhookEndpointPolicyTest extends TestCase
{
    private bool $hadConfig = false;

    private mixed $savedConfig = null;

    protected function setUp(): void
    {
        $app = Application::getInstance();
        $this->hadConfig   = isset($app->applicationInfo['authserver']);
        $this->savedConfig = $app->applicationInfo['authserver'] ?? null;
        unset($app->applicationInfo['authserver']);

        Client::resetFakes();
    }

    protected function tearDown(): void
    {
        $app = Application::getInstance();
        if ($this->hadConfig) {
            $app->applicationInfo['authserver'] = $this->savedConfig;
        } else {
            unset($app->applicationInfo['authserver']);
        }

        Client::resetFakes();
    }

    /** Set `authserver.webhooks` for this test. */
    private function configure(array $webhooks): void
    {
        Application::getInstance()->applicationInfo['authserver'] = ['webhooks' => $webhooks];
    }

    // ── What the configuration allows ─────────────────────────────────────────

    /**
     * With nothing configured, the private network is allowed.
     *
     * The default is on because the common deployment is an authorisation server and its
     * relying parties on one private network, and a default that stopped their webhooks
     * the day it shipped would be a default everybody turns off.
     */
    public function testThePrivateNetworkIsAllowedByDefault(): void
    {
        // Act
        $ranges = WebhookService::allowedPrivateRanges();

        // Assert
        $this->assertSame(OutboundUrl::PRIVATE_NETWORK_RANGES, $ranges);
    }

    /**
     * Named ranges are the whole list once `allow_private` is off.
     *
     * The narrow configuration: the VPN and nothing else of the private network.
     */
    public function testWithAllowPrivateOffOnlyTheNamedRangesAreAllowed(): void
    {
        // Arrange
        $this->configure(['allow_private' => false, 'allow_private_ranges' => ['10.8.0.0/24']]);

        // Act
        $ranges = WebhookService::allowedPrivateRanges();

        // Assert
        $this->assertSame(['10.8.0.0/24'], $ranges);
    }

    /**
     * Named ranges add to the private network when `allow_private` is on.
     *
     * Which is how a range outside it — the receiver on this very host — is allowed: by
     * naming it, not by switching anything wider on.
     */
    public function testNamedRangesAddToThePrivateNetwork(): void
    {
        // Arrange
        $this->configure(['allow_private_ranges' => ['127.0.0.1/32']]);

        // Act
        $ranges = WebhookService::allowedPrivateRanges();

        // Assert
        $this->assertSame(
            array_merge(OutboundUrl::PRIVATE_NETWORK_RANGES, ['127.0.0.1/32']),
            $ranges
        );
    }

    /**
     * Whether a client may register an address, under each configuration.
     *
     * @param array       $config What `authserver.webhooks` says
     * @param string      $url    The endpoint
     * @param bool        $refused
     */
    #[DataProvider('registrations')]
    public function testAddressRefusalFollowsTheConfiguration(array $config, string $url, bool $refused): void
    {
        // Arrange
        $this->configure($config);

        // Act
        $refusal = WebhookService::addressRefusal($url);

        // Assert
        $refused
            ? $this->assertSame('endpoint_url resolves to an address inside this network', $refusal)
            : $this->assertNull($refusal);
    }

    /** @return array<string, array{array, string, bool}> */
    public static function registrations(): array
    {
        $narrow = ['allow_private' => false, 'allow_private_ranges' => ['10.8.0.0/24']];

        return [
            'public, by default'                 => [[], 'https://93.184.216.34/in', false],
            'private network, by default'        => [[], 'https://10.0.0.5/in', false],
            'loopback, by default'               => [[], 'https://127.0.0.1/in', true],
            'metadata, by default'               => [[], 'https://169.254.169.254/in', true],
            'private, with allow_private off'    => [['allow_private' => false], 'https://10.0.0.5/in', true],
            'the VPN, narrowly allowed'          => [$narrow, 'https://10.8.0.5/in', false],
            'the LAN beside it, narrowly'        => [$narrow, 'https://192.168.1.5/in', true],
            'loopback, when named'               => [['allow_private_ranges' => ['127.0.0.1']], 'https://127.0.0.1/in', false],
            'a name that does not resolve yet'   => [['allow_private' => false], 'https://hooks.not-yet.invalid/in', false],
        ];
    }

    // ── What a delivery does with it ──────────────────────────────────────────

    /** The service with deliverEvent() and lastError reachable. */
    private function service(): WebhookService
    {
        return new class($this->createMock(Database::class)) extends WebhookService {
            public function deliver(array $event): bool
            {
                return $this->deliverEvent($event);
            }

            public function lastError(): string
            {
                return (new \ReflectionProperty(WebhookService::class, 'lastError'))->getValue($this);
            }
        };
    }

    /** One queued event for $url, set by $registeredBy (null: a row older than the column). */
    private function event(string $url, ?string $registeredBy): array
    {
        $event = [
            'payload'         => '{"event":"test"}',
            'secret_key'      => 'test-secret',
            'endpoint_url'    => $url,
            'event_type'      => 'token_revoked',
            'timeout_seconds' => 1,
        ];

        if ($registeredBy !== null) {
            $event['registered_by'] = $registeredBy;
        }

        return $event;
    }

    /**
     * Who set the address decides whether the guard applies.
     *
     * The row without `registered_by` is a database that has not run the migration yet: it
     * must still deliver, and as `client` — the stricter answer.
     *
     * @param array       $config       What `authserver.webhooks` says
     * @param string      $url          The endpoint
     * @param string|null $registeredBy Who set it
     * @param bool        $delivered    Whether the request is made
     */
    #[DataProvider('deliveries')]
    public function testDeliveryTrustsAnAdministratorAndGuardsAClient(
        array $config,
        string $url,
        ?string $registeredBy,
        bool $delivered
    ): void {
        // Arrange
        $this->configure($config);
        $requested = false;
        Client::fake(['*' => function () use (&$requested) {
            $requested = true;
            return ClientResponse::make('', 204);
        }]);
        $service = $this->service();

        // Act
        $result = $service->deliver($this->event($url, $registeredBy));

        // Assert
        $this->assertSame($delivered, $result, $service->lastError());
        $this->assertSame($delivered, $requested, 'whether a request was made');
        if (!$delivered) {
            $this->assertStringStartsWith('Delivery refused or failed:', $service->lastError());
        }
    }

    /** @return array<string, array{array, string, ?string, bool}> */
    public static function deliveries(): array
    {
        $admin  = WebhookService::REGISTERED_BY_ADMIN;
        $client = WebhookService::REGISTERED_BY_CLIENT;

        return [
            'client, private network, default'   => [[], 'https://10.0.0.5/in', $client, true],
            'client, private, allow_private off' => [['allow_private' => false], 'https://10.0.0.5/in', $client, false],
            'client, loopback'                   => [[], 'https://127.0.0.1/in', $client, false],
            'client, metadata'                   => [[], 'https://169.254.169.254/in', $client, false],
            'row older than the column'          => [[], 'https://127.0.0.1/in', null, false],
            'admin, loopback'                    => [[], 'https://127.0.0.1/in', $admin, true],
            'admin, private, allow_private off'  => [['allow_private' => false], 'https://10.0.0.5/in', $admin, true],
        ];
    }

    /**
     * An administrator's endpoint is trusted for its address, not for a redirect.
     *
     * What was typed is the operator's; where the receiver bounces the signed body to is
     * not. A `30x` fails the delivery whoever set the address.
     */
    public function testAnAdministratorsEndpointDoesNotFollowARedirect(): void
    {
        // Arrange
        $hops = 0;
        Client::fake(['*' => function () use (&$hops) {
            $hops++;
            return ClientResponse::make('', 302, ['Location' => 'https://169.254.169.254/']);
        }]);
        $service = $this->service();

        // Act
        $result = $service->deliver($this->event('https://10.0.0.5/in', WebhookService::REGISTERED_BY_ADMIN));

        // Assert
        $this->assertFalse($result);
        $this->assertSame(1, $hops, 'the redirect must not be followed');
        $this->assertSame('HTTP 302: ', $service->lastError());
    }
}
