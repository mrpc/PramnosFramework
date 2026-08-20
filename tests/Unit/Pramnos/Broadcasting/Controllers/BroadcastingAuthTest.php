<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Broadcasting\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Apps\AppRegistryInterface;
use Pramnos\Broadcasting\Apps\BroadcastApp;
use Pramnos\Broadcasting\Apps\ConfigAppRegistry;
use Pramnos\Broadcasting\Auth\ChannelRegistry;
use Pramnos\Broadcasting\Auth\PusherAuthorizer;
use Pramnos\Broadcasting\Controllers\Broadcasting;
use Pramnos\Http\Response;

/**
 * Covers the channel-authorization endpoint.
 *
 * The tests are mostly about refusals, because that is where an auth endpoint is
 * either correct or a hole: an unmatched channel, a public channel, an unknown app
 * key and a malformed socket id must all be turned away, and the first three must
 * be turned away identically so a prober learns nothing from the difference.
 */
#[CoversClass(Broadcasting::class)]
class BroadcastingAuthTest extends TestCase
{
    private const KEY    = 'test-key';
    private const SECRET = 'test-secret';

    /** @var array<string,mixed> Saved $_POST/$_REQUEST to restore. */
    private array $savedPost = [];
    private array $savedRequest = [];

    protected function setUp(): void
    {
        $this->savedPost    = $_POST;
        $this->savedRequest = $_REQUEST;
    }

    protected function tearDown(): void
    {
        $_POST    = $this->savedPost;
        $_REQUEST = $this->savedRequest;
    }

    /**
     * Build the controller with all three of its seams supplied, so no database,
     * session or container is involved.
     */
    private function controller(
        ChannelRegistry $channels,
        ?AppRegistryInterface $apps = null,
        ?object $user = null
    ): Broadcasting {
        $apps ??= new ConfigAppRegistry([
            'pusher' => ['app_key' => self::KEY, 'app_secret' => self::SECRET],
        ]);

        return new class($channels, $apps, $user) extends Broadcasting {
            public function __construct(
                private ChannelRegistry $testChannels,
                private AppRegistryInterface $testApps,
                private ?object $testUser,
            ) {
                // Deliberately not calling parent::__construct(): it would build an
                // Application, and every path under test is supplied below.
            }

            protected function channels(): ChannelRegistry
            {
                return $this->testChannels;
            }

            protected function appRegistry(): AppRegistryInterface
            {
                return $this->testApps;
            }

            protected function resolveUser()
            {
                return $this->testUser ?? false;
            }
        };
    }

    /** Set the request body the endpoint reads. */
    private function post(array $fields): void
    {
        $_POST    = $fields;
        $_REQUEST = $fields;
    }

    /** @return array{0:int,1:array<string,mixed>} [status, decoded body] */
    private function call(Broadcasting $controller): array
    {
        $response = $controller->postAuth();
        $this->assertInstanceOf(Response::class, $response);

        return [$response->getStatusCode(), (array) json_decode($response->getBody(), true)];
    }

    private function user(int $id): object
    {
        return (object) ['userid' => $id, 'name' => 'User ' . $id];
    }

    /**
     * An authorized private channel returns a token the shipped authorizer
     * accepts.
     *
     * End to end through the endpoint: the rule, the signer and the verifier all
     * agreeing is the property that makes the endpoint usable at all.
     */
    public function testSignsAnAuthorizedPrivateChannel(): void
    {
        // Arrange
        $channels = (new ChannelRegistry())->channel(
            'order.{id}',
            fn (?object $user, string $id): bool => $user !== null && $id === '42'
        );
        $this->post(['socket_id' => '123.456', 'channel_name' => 'private-order.42']);

        // Act
        [$status, $body] = $this->call($this->controller($channels, null, $this->user(7)));

        // Assert
        $this->assertSame(200, $status);
        $this->assertTrue(
            (new PusherAuthorizer(self::KEY, self::SECRET))
                ->authorizeChannel('private-order.42', '123.456', $body['auth']),
            'the endpoint must issue a token the server accepts'
        );
    }

    /**
     * A presence channel returns channel_data alongside the token, and the pair
     * verifies together.
     */
    public function testSignsAPresenceChannelWithMemberData(): void
    {
        // Arrange
        $channels = (new ChannelRegistry())->channel(
            'room.{room}',
            fn (?object $user, string $room): array => [
                'user_id'   => (string) $user->userid,
                'user_info' => ['name' => $user->name],
            ]
        );
        $this->post(['socket_id' => '1.2', 'channel_name' => 'presence-room.lobby']);

        // Act
        [$status, $body] = $this->call($this->controller($channels, null, $this->user(9)));

        // Assert
        $this->assertSame(200, $status);
        $this->assertArrayHasKey('channel_data', $body);
        $this->assertTrue(
            (new PusherAuthorizer(self::KEY, self::SECRET))
                ->authorizeChannel('presence-room.lobby', '1.2', $body['auth'], $body['channel_data'])
        );
    }

    /**
     * A rule that denies produces 403 and no token.
     */
    public function testDeniedChannelReturnsForbidden(): void
    {
        // Arrange
        $channels = (new ChannelRegistry())->channel('order.{id}', fn (): bool => false);
        $this->post(['socket_id' => '1.2', 'channel_name' => 'private-order.42']);

        // Act
        [$status, $body] = $this->call($this->controller($channels, null, $this->user(7)));

        // Assert
        $this->assertSame(403, $status);
        $this->assertArrayNotHasKey('auth', $body);
    }

    /**
     * A channel with no rule produces the same 403 as a denied one.
     *
     * Identical responses on purpose: telling a caller that a channel exists but
     * has no rule is information about the server's configuration.
     */
    public function testUnmatchedChannelIsIndistinguishableFromDenied(): void
    {
        // Arrange
        $this->post(['socket_id' => '1.2', 'channel_name' => 'private-unmapped.1']);
        $denied = (new ChannelRegistry())->channel('unmapped.{id}', fn (): bool => false);

        // Act
        [$statusUnmatched, $bodyUnmatched] = $this->call($this->controller(new ChannelRegistry(), null, $this->user(7)));
        [$statusDenied, $bodyDenied]       = $this->call($this->controller($denied, null, $this->user(7)));

        // Assert
        $this->assertSame(403, $statusUnmatched);
        $this->assertSame($statusDenied, $statusUnmatched);
        $this->assertSame($bodyDenied, $bodyUnmatched, 'the two refusals must be identical');
    }

    /**
     * A public channel is refused rather than signed.
     *
     * The protocol never requests a token for one, so issuing it would imply a
     * guard that does not exist on that channel.
     */
    public function testPublicChannelIsRefused(): void
    {
        // Arrange
        $channels = (new ChannelRegistry())->channel('updates', fn (): bool => true);
        $this->post(['socket_id' => '1.2', 'channel_name' => 'updates']);

        // Act
        [$status, $body] = $this->call($this->controller($channels, null, $this->user(7)));

        // Assert
        $this->assertSame(403, $status);
        $this->assertStringContainsString('Public channels', (string) $body['message']);
    }

    /**
     * An unauthenticated caller reaches the rule with a null user, so the rule
     * decides — most rules deny, but a channel open to anonymous visitors is a
     * legitimate design the endpoint must not override.
     */
    public function testUnauthenticatedUserReachesTheRuleAsNull(): void
    {
        // Arrange
        $seen     = 'not-called';
        $channels = (new ChannelRegistry())->channel('lobby', function (?object $user) use (&$seen): bool {
            $seen = $user;
            return $user === null;          // deliberately admits anonymous
        });
        $this->post(['socket_id' => '1.2', 'channel_name' => 'private-lobby']);

        // Act
        [$status] = $this->call($this->controller($channels, null, null));

        // Assert
        $this->assertNull($seen, 'the rule is told there is no user');
        $this->assertSame(200, $status);
    }

    /**
     * A user row with no id counts as unauthenticated.
     *
     * Guest sessions and partially-built user objects both show up this way, and
     * treating one as authenticated would hand it whatever a rule grants a
     * logged-in user.
     */
    public function testUserWithoutIdIsTreatedAsAnonymous(): void
    {
        // Arrange
        $channels = (new ChannelRegistry())->channel('x', fn (?object $user): bool => $user !== null);
        $this->post(['socket_id' => '1.2', 'channel_name' => 'private-x']);

        // Act
        [$status] = $this->call($this->controller($channels, null, (object) ['userid' => 0]));

        // Assert
        $this->assertSame(403, $status);
    }

    /**
     * Missing fields are a 400, not a 403 — the request is malformed rather than
     * refused, and a client author needs to know which.
     */
    public function testMissingFieldsAreABadRequest(): void
    {
        // Arrange
        $channels = (new ChannelRegistry())->channel('x', fn (): bool => true);

        foreach ([[], ['socket_id' => '1.2'], ['channel_name' => 'private-x']] as $fields) {
            $this->post($fields);

            // Act
            [$status, $body] = $this->call($this->controller($channels, null, $this->user(1)));

            // Assert
            $this->assertSame(400, $status, 'fields: ' . json_encode($fields));
            $this->assertSame('invalid_request', $body['error']);
        }
    }

    /**
     * A malformed socket id is refused before anything is signed.
     *
     * The socket id is signed verbatim, and the signed string is colon-delimited —
     * so an id containing a colon could shift the field boundary and make a token
     * for one channel verify for another. Validating the shape closes that.
     */
    public function testMalformedSocketIdIsRejected(): void
    {
        // Arrange
        $channels = (new ChannelRegistry())->channel('x', fn (): bool => true);

        foreach (['1.2:private-y', 'abc', '1', '1.', '.2', '1.2.3'] as $socketId) {
            $this->post(['socket_id' => $socketId, 'channel_name' => 'private-x']);

            // Act
            [$status, $body] = $this->call($this->controller($channels, null, $this->user(1)));

            // Assert
            $this->assertSame(400, $status, 'socket_id: ' . $socketId);
            $this->assertStringContainsString('malformed', (string) $body['message']);
        }
    }

    /**
     * A named app key that no registry knows is refused exactly like a denied
     * rule, so a prober cannot enumerate valid keys.
     */
    public function testUnknownAppKeyIsRefusedLikeADenial(): void
    {
        // Arrange
        $channels = (new ChannelRegistry())->channel('x', fn (): bool => true);
        $this->post([
            'socket_id'    => '1.2',
            'channel_name' => 'private-x',
            'app_key'      => 'not-a-real-key',
        ]);

        // Act
        [$status, $body] = $this->call($this->controller($channels, null, $this->user(1)));

        // Assert
        $this->assertSame(403, $status);
        $this->assertSame(['error' => 'forbidden'], $body);
    }

    /**
     * A named app key that is known signs with that app's secret.
     */
    public function testNamedAppKeySignsWithThatAppsSecret(): void
    {
        // Arrange
        $channels = (new ChannelRegistry())->channel('x', fn (): bool => true);
        $apps = new class implements AppRegistryInterface {
            public function findByKey(string $key): ?BroadcastApp
            {
                return $key === 'tenant-b' ? new BroadcastApp('tenant-b', 'secret-b') : null;
            }

            public function defaultApp(): ?BroadcastApp
            {
                return null;
            }
        };
        $this->post(['socket_id' => '1.2', 'channel_name' => 'private-x', 'app_key' => 'tenant-b']);

        // Act
        [$status, $body] = $this->call($this->controller($channels, $apps, $this->user(1)));

        // Assert
        $this->assertSame(200, $status);
        $this->assertTrue(
            (new PusherAuthorizer('tenant-b', 'secret-b'))
                ->authorizeChannel('private-x', '1.2', $body['auth'])
        );
    }

    /**
     * An app with no secret is a 500, not a 403.
     *
     * The distinction is for whoever debugs it: "forbidden" sends them to look at
     * permissions, when the fix is a missing app_secret in app.php or a NULL
     * broadcast_secret on an applications row.
     */
    public function testAppWithoutSecretIsAServerError(): void
    {
        // Arrange
        $channels = (new ChannelRegistry())->channel('x', fn (): bool => true);
        $apps = new ConfigAppRegistry(['pusher' => ['app_key' => 'keyless']]);
        $this->post(['socket_id' => '1.2', 'channel_name' => 'private-x']);

        // Act
        [$status, $body] = $this->call($this->controller($channels, $apps, $this->user(1)));

        // Assert
        $this->assertSame(500, $status);
        $this->assertSame('server_misconfigured', $body['error']);
    }

    /**
     * A presence rule that mistakenly returns true is reported as a server error
     * rather than signed without member data.
     */
    public function testPresenceRuleReturningBooleanIsAServerError(): void
    {
        // Arrange
        $channels = (new ChannelRegistry())->channel('room', fn (): bool => true);
        $this->post(['socket_id' => '1.2', 'channel_name' => 'presence-room']);

        // Act
        [$status, $body] = $this->call($this->controller($channels, null, $this->user(1)));

        // Assert
        $this->assertSame(500, $status);
        $this->assertSame('server_misconfigured', $body['error']);
    }

    // -------------------------------------------------------------------------
    // The real seams: container, config, and the reserved-name constraint
    // -------------------------------------------------------------------------

    /**
     * Build a controller through its real constructor, with a real container and
     * applicationInfo — so the resolution paths the tests above replace are the
     * ones exercised here.
     */
    private function realController(array $bindings, array $info): Broadcasting
    {
        $container = new \Pramnos\Application\Container();
        foreach ($bindings as $id => $value) {
            $container->singleton($id, static fn () => $value);
        }

        $app = new class($container, $info) extends \Pramnos\Application\Application {
            public function __construct(\Pramnos\Application\Container $c, array $info)
            {
                $this->_data['container'] = $c;
                $this->applicationInfo    = $info;
            }
        };

        return new class($app) extends Broadcasting {
            public function __construct($application)
            {
                $this->application = $application;
                $this->addaction('postAuth');
            }

            public function callChannels(): ChannelRegistry
            {
                return $this->channels();
            }

            public function callAppRegistry(): AppRegistryInterface
            {
                return $this->appRegistry();
            }

            protected function resolveUser()
            {
                return (object) ['userid' => 5];
            }
        };
    }

    /**
     * The channel registry comes from the container, so an application registers
     * its rules once in its own provider.
     */
    public function testResolvesTheChannelRegistryFromTheContainer(): void
    {
        // Arrange
        $registry   = (new ChannelRegistry())->channel('x', fn (): bool => true);
        $controller = $this->realController(['broadcasting.channels' => $registry], []);

        // Act & Assert
        $this->assertSame($registry, $controller->callChannels());
    }

    /**
     * With nothing bound, an empty registry is used — which denies everything.
     *
     * The fallback must be closed rather than open: a deployment that routed the
     * endpoint without registering rules should refuse subscriptions, not grant
     * them.
     */
    public function testFallsBackToAnEmptyDenyingRegistry(): void
    {
        // Arrange
        $controller = $this->realController([], []);

        // Act
        $registry = $controller->callChannels();

        // Assert
        $this->assertFalse($registry->authorize('private-anything', null));
    }

    /**
     * A binding of the wrong type is ignored rather than trusted.
     */
    public function testIgnoresAWronglyTypedBinding(): void
    {
        // Arrange
        $controller = $this->realController(['broadcasting.channels' => new \stdClass()], []);

        // Act & Assert
        $this->assertInstanceOf(ChannelRegistry::class, $controller->callChannels());
        $this->assertFalse($controller->callChannels()->authorize('private-x', null));
    }

    /**
     * The app registry is built from applicationInfo, and follows the features
     * list the same way everything else does.
     */
    public function testBuildsTheAppRegistryFromApplicationInfo(): void
    {
        // Arrange
        $controller = $this->realController([], [
            'broadcasting' => ['pusher' => ['app_key' => 'ck', 'app_secret' => 'cs']],
            'features'     => ['broadcasting'],
        ]);

        // Act
        $registry = $controller->callAppRegistry();

        // Assert
        $this->assertInstanceOf(ConfigAppRegistry::class, $registry);
        $this->assertSame('cs', $registry->findByKey('ck')?->secret);
    }

    /**
     * With the authserver feature enabled the controller reads apps from it.
     */
    public function testUsesTheAuthserverRegistryWhenTheFeatureIsEnabled(): void
    {
        // Arrange
        $controller = $this->realController([], ['features' => ['authserver']]);

        // Act & Assert
        $this->assertInstanceOf(
            \Pramnos\Broadcasting\Apps\AuthServerAppRegistry::class,
            $controller->callAppRegistry()
        );
    }

    /**
     * The action is named `postAuth`, and `auth` is left alone.
     *
     * `Controller::auth($action)` is the framework's per-action authorization gate,
     * called by `exec()` on every dispatch. An action named `auth` would collide
     * with it — PHP refuses the incompatible signature outright, which is how this
     * was found. The name is also exactly what the dispatcher looks for: for a
     * non-GET request `exec()` resolves `strtolower(METHOD . ucfirst($action))`, so
     * POST on the `auth` segment lands on `postAuth` with no route entry.
     */
    public function testActionIsNamedForThePostDispatchConvention(): void
    {
        // Assert
        $this->assertTrue(method_exists(Broadcasting::class, 'postAuth'));
        $this->assertSame(
            'postauth',
            strtolower('POST' . ucfirst('auth')),
            'the dispatcher resolves POST /broadcasting/auth to this method name'
        );

        // The inherited gate must still take an action argument, i.e. be unshadowed.
        $gate = new \ReflectionMethod(Broadcasting::class, 'auth');
        $this->assertSame(
            \Pramnos\Application\Controller::class,
            $gate->getDeclaringClass()->getName(),
            'the authorization gate must remain the framework\'s, not an action'
        );
    }

    /**
     * The real constructor registers the action and keeps the framework's own
     * dispatch state intact.
     *
     * Constructed for real rather than bypassed, because the constructor is where
     * the action registration lives — and an action the dispatcher cannot find is
     * an endpoint that returns nothing with no error anywhere.
     */
    public function testRealConstructorRegistersTheAction(): void
    {
        // Arrange
        $container = new \Pramnos\Application\Container();
        $app = new class($container) extends \Pramnos\Application\Application {
            public function __construct(\Pramnos\Application\Container $c)
            {
                $this->_data['container'] = $c;
                $this->applicationInfo    = [];
            }
        };

        // Act
        $controller = new Broadcasting($app);

        // Assert
        $this->assertContains('postAuth', $controller->actions);
        $this->assertContains('display', $controller->actions, 'the inherited default survives');
    }
}
