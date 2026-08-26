<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Webhook;

/**
 * `Webhook` — where a relying party registers an endpoint.
 *
 * WHAT: the guards around registration, and the ownership rule.
 * WHY:  every action here acts on rows belonging to one application, and the
 *       application is identified by credentials rather than by a parameter. The
 *       tests that matter are the ones proving `appid` cannot come from the
 *       request: an endpoint id is a small integer and therefore guessable, so
 *       ownership is the only thing standing between one client and another
 *       client's webhook configuration.
 *
 *       The validation guards matter for a different reason. A plaintext endpoint
 *       URL gives away both the event — which is about a person — and the signing
 *       secret it is signed with, so `http://` is refused rather than accepted
 *       with a warning nobody reads.
 */
class WebhookControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $_POST = [];
        $_GET  = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET  = [];
        unset($_SERVER['REQUEST_METHOD']);
    }

    /** A controller with authentication and storage replaced. */
    private function controller(?int $appId = 7): StubbedWebhook
    {
        $rc         = new \ReflectionClass(StubbedWebhook::class);
        $controller = $rc->newInstanceWithoutConstructor();
        $controller->appId = $appId;

        return $controller;
    }

    /**
     * Without client credentials, nothing happens.
     *
     * Asserted on `stored` as well as on the status: a 401 that had already
     * written a row would be worse than no check at all.
     */
    public function testRegistrationRequiresClientCredentials(): void
    {
        // Arrange — authentication fails
        $controller = $this->controller(null);
        $_POST = ['endpoint_url' => 'https://app.example.com/hooks', 'webhook_type' => 'token_revoked'];

        // Act
        $response = $controller->register();

        // Assert
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([], $controller->stored);
    }

    /**
     * A GET does not register anything.
     *
     * Registration issues a secret and writes a row; neither belongs on a verb
     * a link checker or a prefetcher will follow.
     */
    public function testRegistrationRefusesAGet(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $controller = $this->controller();
        $_POST = ['endpoint_url' => 'https://app.example.com/hooks', 'webhook_type' => 'token_revoked'];

        // Act
        $response = $controller->register();

        // Assert
        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame([], $controller->stored);
    }

    /**
     * A plaintext endpoint is refused.
     *
     * The event describes a person and is signed with a shared secret. Over
     * `http://` both are readable by anything on the path, which makes the
     * signature decorative.
     */
    public function testAPlaintextEndpointIsRefused(): void
    {
        // Arrange
        $controller = $this->controller();
        $_POST = ['endpoint_url' => 'http://app.example.com/hooks', 'webhook_type' => 'token_revoked'];

        // Act
        $response = $controller->register();
        $body     = json_decode($response->getBody(), true);

        // Assert
        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('https', $body['error_description']);
        $this->assertSame([], $controller->stored);
    }

    /**
     * A malformed URL is refused before anything is written.
     *
     * @param string $url A value that is not a usable endpoint
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusableUrls')]
    public function testAnUnusableUrlIsRefused(string $url): void
    {
        // Arrange
        $controller = $this->controller();
        $_POST = ['endpoint_url' => $url, 'webhook_type' => 'token_revoked'];

        // Act
        $response = $controller->register();

        // Assert
        $this->assertSame(400, $response->getStatusCode(), $url);
        $this->assertSame([], $controller->stored);
    }

    /** @return array<string, array{0: string}> */
    public static function unusableUrls(): array
    {
        return [
            'empty'       => [''],
            'not a url'   => ['not-a-url'],
            'no scheme'   => ['app.example.com/hooks'],
            'javascript'  => ['javascript:alert(1)'],
        ];
    }

    /**
     * An unknown event type is refused, and the message says what is allowed.
     *
     * The table has a CHECK constraint for the same list. Letting the value
     * through would turn a correctable mistake into a driver-level constraint
     * violation, which tells the caller nothing about which types exist.
     */
    public function testAnUnknownEventTypeIsRefusedWithTheAlternatives(): void
    {
        // Arrange
        $controller = $this->controller();
        $_POST = ['endpoint_url' => 'https://app.example.com/hooks', 'webhook_type' => 'everything'];

        // Act
        $response = $controller->register();
        $body     = json_decode($response->getBody(), true);

        // Assert
        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('token_revoked', $body['error_description']);
        $this->assertSame([], $controller->stored);
    }

    /**
     * A valid registration stores the endpoint under the *authenticated* client.
     *
     * The `appid` assertion is the security one: it comes from the credentials,
     * and a client id in the body is ignored — so there is no parameter to point
     * at somebody else's application.
     */
    public function testAValidRegistrationStoresItUnderTheAuthenticatedClient(): void
    {
        // Arrange — a client id in the body that must be ignored
        $controller = $this->controller(7);
        $_POST = [
            'endpoint_url' => 'https://app.example.com/hooks',
            'webhook_type' => 'token_revoked',
            'appid'        => 999,
            'client_id'    => 'someone-else',
        ];

        // Act
        $response = $controller->register();
        $body     = json_decode($response->getBody(), true);

        // Assert
        $this->assertSame(201, $response->getStatusCode());
        $this->assertCount(1, $controller->stored);
        $this->assertSame(7, $controller->stored[0]['appId'], 'appid must come from the credentials');
        $this->assertSame('https://app.example.com/hooks', $controller->stored[0]['url']);
        $this->assertSame('token_revoked', $controller->stored[0]['type']);
        $this->assertNotEmpty($body['secret']);
    }

    /**
     * The secret is returned once and is long enough to be one.
     *
     * 32 bytes of CSPRNG output, hex-encoded. It is the whole of the receiver's
     * ability to tell a real event from a forged one.
     */
    public function testTheSecretIsReturnedOnceAndIsStrong(): void
    {
        // Arrange
        $controller = $this->controller();
        $_POST = ['endpoint_url' => 'https://app.example.com/hooks', 'webhook_type' => 'token_revoked'];

        // Act
        $body = json_decode($controller->register()->getBody(), true);

        // Assert
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $body['secret']);
        $this->assertSame($body['secret'], $controller->stored[0]['secret'],
            'the secret returned must be the secret stored');
    }

    /**
     * Deleting an endpoint that belongs to another application answers 404.
     *
     * Not 403: telling a caller that an id exists but is not theirs confirms the
     * id, which is exactly what somebody enumerating them wants to know.
     */
    public function testDeletingSomebodyElsesEndpointIsANotFound(): void
    {
        // Arrange — the ownership lookup finds nothing
        $controller = $this->controller();
        $controller->ownedEndpoint = null;
        $_POST = ['webhook_id' => 12345];

        // Act
        $response = $controller->delete();

        // Assert
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([], $controller->deleted);
    }

    /**
     * Testing an endpoint that is not yours answers 404 and queues nothing.
     */
    public function testTestingSomebodyElsesEndpointQueuesNothing(): void
    {
        // Arrange
        $controller = $this->controller();
        $controller->ownedEndpoint = null;
        $_POST = ['webhook_id' => 12345];

        // Act
        $response = $controller->test();

        // Assert
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([], $controller->queued);
    }

    /**
     * A test event goes through the queue, not straight down the wire.
     *
     * It travels the path a real event travels — the same signing, retries and
     * schedule — because a test that took a shortcut would only prove the
     * shortcut works.
     */
    public function testATestEventIsQueuedRatherThanDelivered(): void
    {
        // Arrange
        $controller = $this->controller();
        $controller->ownedEndpoint = ['webhook_id' => 5, 'webhook_type' => 'token_revoked'];
        $_POST = ['webhook_id' => 5];

        // Act
        $response = $controller->test();

        // Assert
        $this->assertSame(202, $response->getStatusCode());
        $this->assertCount(1, $controller->queued);
        $this->assertSame('token_revoked', $controller->queued[0]);
    }

    /** Every action is public — a server calls them with credentials, not a session. */
    public function testTheActionsAreCredentialAuthenticatedRatherThanSessionAuthenticated(): void
    {
        // Arrange / Act
        $controller = new StubbedWebhook(null);

        // Assert
        foreach (['register', 'list', 'stats', 'test', 'delete'] as $action) {
            $this->assertContains($action, $controller->actions, $action);
            $this->assertNotContains($action, $controller->actions_auth, $action);
        }
    }
}

/** Webhook with authentication, storage and the delivery service replaced. */
class StubbedWebhook extends Webhook
{
    /** The application id authentication should yield, or null to fail. */
    public ?int $appId = 7;

    /** What the ownership lookup should find. */
    public ?array $ownedEndpoint = null;

    /** @var list<array{appId: int, url: string, type: string, secret: string}> */
    public array $stored = [];

    /** @var list<int> Endpoint ids that were deleted */
    public array $deleted = [];

    /** @var list<string> Event types that were queued */
    public array $queued = [];

    protected function requireClient(): mixed
    {
        if ($this->appId === null) {
            return \Pramnos\Http\Response::json(['error' => 'invalid_client'], 401);
        }

        return $this->appId;
    }

    protected function storeEndpoint(int $appId, string $url, string $type, string $secret): void
    {
        $this->stored[] = compact('appId', 'url', 'type', 'secret');
    }

    protected function findOwnedEndpoint(int $appId, int $webhookId): ?array
    {
        return $this->ownedEndpoint;
    }

    protected function service(): \Pramnos\Auth\WebhookService
    {
        $controller = $this;

        return new class ($controller) extends \Pramnos\Auth\WebhookService {
            public function __construct(private StubbedWebhook $controller)
            {
                // No parent::__construct(): the double never reaches a database.
            }

            public function queueEvent(
                string $eventType,
                ?int $userId,
                array $payload,
                ?string $deviceCode = null,
                ?int $tokenId = null
            ): int {
                $this->controller->queued[] = $eventType;

                return 1;
            }
        };
    }

    protected function database(): mixed
    {
        $controller = $this;

        // Only delete() reaches the database directly; everything else goes
        // through a seam above.
        return new class ($controller) {
            public function __construct(private StubbedWebhook $controller)
            {
            }

            public function queryBuilder(): object
            {
                return new class ($this->controller) {
                    private int $id = 0;

                    public function __construct(private StubbedWebhook $controller)
                    {
                    }

                    public function table(string $name): static
                    {
                        return $this;
                    }

                    public function where(string $column, mixed $value = null): static
                    {
                        if ($column === 'webhook_id') {
                            $this->id = (int) $value;
                        }

                        return $this;
                    }

                    public function delete(): int
                    {
                        $this->controller->deleted[] = $this->id;

                        return 1;
                    }
                };
            }
        };
    }
}
