<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Push as PushController;

/**
 * The three endpoints a browser needs to subscribe to notifications, and to stop.
 *
 * `GET /push/key` is public on purpose — it is the half of the pair the browser is *supposed* to
 * hold, and `subscribe()` cannot be called without it. The other two require a session, because a
 * subscription belongs to an account: without one there is nobody to notify.
 *
 * That is deliberately the **opposite** of the one-click mail endpoints beside it, where
 * requiring a session would break every request a mailbox provider makes. The difference is worth
 * asserting rather than assuming somebody copied the right neighbour.
 */
#[CoversClass(PushController::class)]
class PushControllerTest extends TestCase
{
    /**
     * The public key is served to anybody, signed in or not.
     */
    public function testTheKeyIsPublic(): void
    {
        // Arrange
        $controller = $this->controller(user: null, vapid: ['publicKey' => 'BPublicKey']);

        // Act
        $controller->key();

        // Assert
        $this->assertSame(200, $controller->status);
        $this->assertSame(['publicKey' => 'BPublicKey'], $controller->payload);
    }

    /**
     * An installation with no key pair says so, with the command that fixes it.
     *
     * 503 rather than 500: the endpoint is fine, the installation is unfinished — and a page that
     * gets a 503 here knows not to offer the subscribe button at all.
     */
    public function testNoKeyPairIsAServiceUnavailableThatNamesTheCommand(): void
    {
        // Arrange
        $controller = $this->controller(user: null, vapid: null);

        // Act
        $controller->key();

        // Assert
        $this->assertSame(503, $controller->status);
        $this->assertStringContainsString('push:vapid-generate', $controller->payload['error']);
    }

    /**
     * Subscribing without a session is refused.
     *
     * Not pedantry: a subscription stored against nobody is delivered to nobody and revoked by
     * nobody — a row only a migration will ever remove.
     */
    public function testSubscribingWithoutASessionIsRefused(): void
    {
        // Arrange
        $controller = $this->controller(user: null, body: $this->subscription());

        // Act
        $controller->subscribe();

        // Assert
        $this->assertSame(401, $controller->status);
        $this->assertSame([], $controller->stored);
    }

    /**
     * And so is unsubscribing, for the same reason: one account must not revoke another's device.
     */
    public function testUnsubscribingWithoutASessionIsRefused(): void
    {
        // Arrange
        $controller = $this->controller(user: null, body: ['endpoint' => 'https://a.example/1']);

        // Act
        $controller->unsubscribe();

        // Assert
        $this->assertSame(401, $controller->status);
        $this->assertSame([], $controller->forgotten);
    }

    /**
     * A signed-in browser's subscription is stored against that account, with its user agent.
     */
    public function testASubscriptionIsStoredAgainstTheSignedInAccount(): void
    {
        // Arrange
        $_SERVER['HTTP_USER_AGENT'] = 'Firefox/143.0';
        $controller = $this->controller(user: 42, body: $this->subscription());

        try {
            // Act
            $controller->subscribe();

            // Assert
            $this->assertSame(200, $controller->status);
            $this->assertSame(['ok' => true], $controller->payload);
            $this->assertSame(42, $controller->stored[0][0]);
            $this->assertSame('https://a.example/1', $controller->stored[0][1]['endpoint']);
            $this->assertSame('Firefox/143.0', $controller->stored[0][2],
                'so a person can recognise which device they are revoking');
        } finally {
            unset($_SERVER['HTTP_USER_AGENT']);
        }
    }

    /**
     * An empty body is a 400, not a stored empty subscription.
     */
    public function testAnEmptyBodyIsRefused(): void
    {
        // Arrange
        $controller = $this->controller(user: 42, body: []);

        // Act
        $controller->subscribe();

        // Assert
        $this->assertSame(400, $controller->status);
        $this->assertSame([], $controller->stored);
    }

    /**
     * A subscription the store refuses is reported as a 400 that says what is wrong with it.
     *
     * The page that posted it can only act on this if it is told; answering 200 would leave a
     * browser believing it is subscribed for ever.
     */
    public function testAnUnusableSubscriptionIsReportedAsSuch(): void
    {
        // Arrange
        $controller = $this->controller(user: 42, body: ['endpoint' => 'http://a.example/1'], accepted: false);

        // Act
        $controller->subscribe();

        // Assert
        $this->assertSame(400, $controller->status);
        $this->assertStringContainsString('https', $controller->payload['error']);
    }

    /**
     * Unsubscribing is scoped to the account, and answers success.
     *
     * Success even when there was nothing to forget: the browser has already unsubscribed by the
     * time it calls this, and reporting a failure for something in exactly the state the caller
     * asked for is not useful — it leaves a page showing an error about a thing that worked.
     */
    public function testUnsubscribingIsScopedToTheAccountAndAlwaysSucceeds(): void
    {
        // Arrange
        $controller = $this->controller(user: 42, body: ['endpoint' => 'https://a.example/1']);

        // Act
        $controller->unsubscribe();

        // Assert
        $this->assertSame(200, $controller->status);
        $this->assertSame([['https://a.example/1', 42]], $controller->forgotten);
    }

    /**
     * Unsubscribing without naming an endpoint is a 400 rather than a silent no-op.
     */
    public function testUnsubscribingWithoutAnEndpointIsRefused(): void
    {
        // Arrange
        $controller = $this->controller(user: 42, body: []);

        // Act
        $controller->unsubscribe();

        // Assert
        $this->assertSame(400, $controller->status);
        $this->assertSame([], $controller->forgotten);
    }

    /**
     * The guest account is not a signed-in account.
     *
     * `getCurrentUser()` answers with user 1 — the guest — rather than null for a visitor with no
     * session, so a check for "is there a user" is true for everybody. Every subscription would
     * be stored against the same row.
     */
    public function testTheGuestAccountIsNotSignedIn(): void
    {
        // Arrange
        $controller = $this->controller(user: 1, body: $this->subscription());

        // Act
        $controller->subscribe();

        // Assert
        $this->assertSame(401, $controller->status);
    }

    /**
     * The body is parsed as JSON, because `PushSubscription.toJSON()` is what a page has to hand.
     *
     * Posting it as a form would mean flattening a nested object for no reason, and the nested
     * object is where the keys are.
     */
    public function testTheBodyIsReadAsJson(): void
    {
        // Arrange
        $controller = $this->parsing('{"endpoint":"https://a.example/1","keys":{"auth":"x"}}');

        // Act
        $body = $controller->parsedBody();

        // Assert
        $this->assertSame('https://a.example/1', $body['endpoint']);
        $this->assertSame(['auth' => 'x'], $body['keys']);
    }

    /**
     * A body that is not JSON, or is JSON but not an object, is an empty array rather than a
     * crash.
     *
     * These endpoints are reachable by anything that can make a request, so the malformed body
     * is not hypothetical — and the caller has already decided what an empty one means.
     */
    public function testAnUnusableBodyIsEmptyRatherThanFatal(): void
    {
        // Assert
        $this->assertSame([], $this->parsing('')->parsedBody());
        $this->assertSame([], $this->parsing('   ')->parsedBody());
        $this->assertSame([], $this->parsing('not json at all')->parsedBody());
        $this->assertSame([], $this->parsing('"a string"')->parsedBody());
        $this->assertSame([], $this->parsing('42')->parsedBody());
    }

    /**
     * With no session, the real `currentUser()` answers null — not the guest account.
     *
     * `getCurrentUser()` returns user 1 for a visitor with no session rather than null, so a
     * check for "is there a user" is true for everybody, and every subscription in the
     * installation would be stored against the same row.
     */
    public function testTheRealCurrentUserIsNullForAVisitor(): void
    {
        // Assert
        $this->assertNull($this->real()->probeCurrentUser());
    }

    /**
     * And with no key pair on disk, the real `keyPair()` answers null rather than half a pair.
     */
    public function testTheRealKeyPairIsNullOnAnInstallationWithoutOne(): void
    {
        // Assert
        $this->assertNull($this->real()->probeKeyPair());
    }

    /**
     * The status is carried **by the Response**, not set beside it.
     *
     * A returned Response is dispatched by the application, which sets the HTTP code from the
     * object. A status set with `http_response_code()` next to a Response built without one is
     * overwritten by that object's default 200 — so every refusal would answer 200 with an
     * error in the body, and a page checking `response.ok` would read «sign in first» as
     * success. The status is the whole protocol here: it is how a page tells "sign in first"
     * from "that subscription is unusable".
     */
    public function testTheStatusIsCarriedByTheResponseItself(): void
    {
        // Arrange
        $controller = $this->real();

        // Act
        $ok      = $controller->probeJson(['ok' => true], 200);
        $refused = $controller->probeJson(['error' => 'Sign in first.'], 401);

        // Assert
        $this->assertInstanceOf(\Pramnos\Http\Response::class, $ok);
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertSame(401, $refused->getStatusCode(),
            'a refusal that answers 200 is a refusal no caller can detect');
        $this->assertSame(['error' => 'Sign in first.'], json_decode($refused->getBody(), true));
        $this->assertSame('application/json', $refused->getHeaders()['Content-Type']);
    }

    /** A controller with nothing stubbed but the raw body. */
    private function parsing(string $raw): object
    {
        return new class ($raw) extends PushController {
            public function __construct(private string $raw) {}

            protected function rawBody(): string { return $this->raw; }

            /** @return array<string, mixed> */
            public function parsedBody(): array { return $this->body(); }
        };
    }

    /** A controller with nothing stubbed at all. */
    private function real(): object
    {
        return new class extends PushController {
            public function __construct() {}

            public function probeCurrentUser(): ?int { return $this->currentUser(); }

            public function probeKeyPair(): ?array { return $this->keyPair(); }

            public function probeJson(array $data, int $status): mixed
            {
                return $this->json($data, $status);
            }
        };
    }

    /** @return array<string, mixed> */
    private function subscription(): array
    {
        return [
            'endpoint' => 'https://a.example/1',
            'keys'     => ['p256dh' => 'BBrowserKey', 'auth' => 'AuthSecret'],
        ];
    }

    private function controller(
        ?int $user,
        array $body = [],
        ?array $vapid = null,
        bool $accepted = true
    ): object {
        return new class ($user, $body, $vapid, $accepted) extends PushController {
            public int $status = 0;

            /** @var array<string, mixed> */
            public array $payload = [];

            /** @var list<array{0:int,1:array,2:string}> */
            public array $stored = [];

            /** @var list<array{0:string,1:int|null}> */
            public array $forgotten = [];

            public function __construct(
                private ?int $user,
                private array $requestBody,
                private ?array $keys,
                private bool $accepted
            ) {
                // No parent::__construct(): it registers actions against an application this
                // test does not have.
            }

            protected function currentUser(): ?int
            {
                // The real one reads the session; what it *decides* — that guest is not signed
                // in — is asserted by the caller passing 1.
                return $this->user !== null && $this->user > 1 ? $this->user : null;
            }

            protected function body(): array { return $this->requestBody; }

            protected function keyPair(): ?array { return $this->keys; }

            protected function store(int $userId, array $subscription, string $userAgent): bool
            {
                $this->stored[] = [$userId, $subscription, $userAgent];

                return $this->accepted;
            }

            protected function forget(string $endpoint, int $userId): void
            {
                $this->forgotten[] = [$endpoint, $userId];
            }

            protected function json(array $data, int $status = 200): mixed
            {
                $this->status  = $status;
                $this->payload = $data;

                return null;
            }
        };
    }
}
