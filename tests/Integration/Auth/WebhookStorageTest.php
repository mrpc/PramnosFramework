<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Controllers\Webhook;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * The webhook controller against a real database — the half its unit tests cannot reach.
 *
 * {@see \Pramnos\Tests\Unit\Auth\Controllers\WebhookControllerTest} already covers the
 * validation: https required, an unusable URL refused, an unknown event type named, credentials
 * demanded, a GET refused. Those decisions are taken before any query runs, so a double proves
 * them and there is nothing to repeat here.
 *
 * What a double could not prove is everything downstream of `->table(...)`, which was 84 of the
 * controller's 157 statements: the registration upsert, the listing, the ownership `WHERE`, and
 * the `stats()` aggregate over a join. Its stubbed queue in particular hid a real fault — see
 * {@see testTestQueuesAnEventForItsOwnEndpointOnly}.
 *
 * Runs on every backend: {@see WebhookStoragePostgreSQLTest} re-runs the class against
 * PostgreSQL/TimescaleDB. Worth the second lane, because these are the parts that differ — the
 * upsert compiles to two different statements, `applications.oauth2_webhook_endpoints` is a schema
 * on one engine and a table prefix on the other, and only PostgreSQL has the `CHECK` constraint
 * that {@see testEveryAdvertisedEventTypeCanBeRegistered} holds the controller's constant against.
 */
#[CoversClass(Webhook::class)]
#[CoversClass(\Pramnos\Auth\WebhookService::class)]
#[CoversClass(\Pramnos\Auth\Controllers\ApplicationsController::class)]
#[CoversClass(\Pramnos\Framework\Migrations\AuthServer\AddRegisteredByToOauth2WebhookEndpoints::class)]
class WebhookStorageTest extends BaseTestCase
{
    private $db;

    /** The application this test acts as. */
    private int $appId = 0;

    /** Somebody else's application, whose endpoints must stay out of reach. */
    private int $otherAppId = 0;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        /*
         * Real application rows, because on PostgreSQL the endpoint's `appid` is a foreign key to
         * `public.applications` — an invented number is refused by the database, and "one client
         * cannot reach another's endpoint" is only a claim about two rows that exist.
         */
        $this->runMigrations([
            \Pramnos\Framework\Migrations\AuthServer\CreateApplicationsSchema::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateApplicationsTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateOauth2WebhooksTables::class,
            \Pramnos\Framework\Migrations\AuthServer\AllowPermissionsChangedWebhook::class,
            \Pramnos\Framework\Migrations\AuthServer\AddRegisteredByToOauth2WebhookEndpoints::class,
        ], $this->db);

        $this->appId      = $this->registerApplication('Webhook test client');
        $this->otherAppId = $this->registerApplication('Another client entirely');

        $this->clear();

        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function tearDown(): void
    {
        $this->clear();
        $this->forget();

        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    // ── What the tables end up holding ────────────────────────────────────────

    /**
     * The secret goes into the table and never comes back out of the listing.
     *
     * The property that makes the endpoint list safe to expose at all: a listing carrying
     * `secret_key` would turn "may I see my endpoints" into "give me the key to sign as this
     * application". Asserted against the real row rather than a stub, because the column is
     * excluded by the `select()` in `list()` — a `SELECT *` would pass a double that returns a
     * fixed array and fail here.
     */
    public function testTheListingShowsTheEndpointAndNotItsSecret(): void
    {
        // Arrange
        $_POST = ['endpoint_url' => 'https://example.com/hook', 'webhook_type' => 'token_revoked'];
        $secret = (string) ($this->decode($this->controller()->register())['secret'] ?? '');
        $this->assertNotSame('', $secret, 'precondition: an endpoint was registered');

        // Act
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $listing   = $this->decode($this->controller()->list());
        $endpoints = (array) ($listing['endpoints'] ?? []);

        // Assert
        $this->assertCount(1, $endpoints, 'the listing shows nothing');
        $this->assertSame('https://example.com/hook', $endpoints[0]['endpoint_url'] ?? null);
        $this->assertArrayNotHasKey('secret_key', $endpoints[0]);
        $this->assertStringNotContainsString(
            $secret,
            (string) json_encode($listing),
            'the secret is in the listing'
        );
        $this->assertSame($secret, $this->storedSecret(), 'the stored secret is not the one issued');
    }

    /**
     * Registering the same event type again replaces the endpoint rather than adding one.
     *
     * `storeEndpoint()` is an upsert, and an upsert is exactly the shape that compiles to two
     * different statements — `ON CONFLICT` on one engine, a read-then-branch on the other. "One
     * endpoint per application per event type" is a claim about both, and the unique key exists to
     * enforce it, so a second insert is a constraint violation rather than a duplicate row.
     */
    public function testRegisteringTheSameTypeAgainReplacesIt(): void
    {
        // Arrange & Act
        $_POST = ['endpoint_url' => 'https://one.example/hook', 'webhook_type' => 'token_revoked'];
        $first = (string) ($this->decode($this->controller()->register())['secret'] ?? '');

        $_POST = ['endpoint_url' => 'https://two.example/hook', 'webhook_type' => 'token_revoked'];
        $second = (string) ($this->decode($this->controller()->register())['secret'] ?? '');

        // Assert
        $this->assertSame(1, $this->endpointCount(), 'the same event type was registered twice');
        $this->assertNotSame($first, $second, 'the secret was reused for a new registration');
        $this->assertSame($second, $this->storedSecret(), 'the row kept the superseded secret');
        $this->assertSame('https://two.example/hook', $this->storedUrl());
    }

    /**
     * Every event type the controller advertises can actually be stored.
     *
     * `supported_types` in the listing is what an integrator reads to decide what to subscribe to,
     * and on PostgreSQL the column carries a `CHECK` constraint naming the same set. The two are
     * written in different files — the controller's constant and the migration's DDL — so nothing
     * but a test keeps them in step, and a type advertised but refused by the database is a 500 on
     * the integrator's first attempt. This is also why the class runs on both lanes: on MySQL
     * there is no constraint to disagree with, so the MySQL run cannot fail this.
     */
    public function testEveryAdvertisedEventTypeCanBeRegistered(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $advertised = (array) ($this->decode($this->controller()->list())['supported_types'] ?? []);
        $this->assertNotSame([], $advertised, 'the controller advertises no event types at all');

        // Act & Assert
        foreach ($advertised as $index => $type) {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = [
                'endpoint_url' => 'https://example.com/hook/' . $index,
                'webhook_type' => $type,
            ];

            $answer = $this->decode($this->controller()->register());
            $this->assertArrayHasKey(
                'secret',
                $answer,
                'an advertised type the database refuses: ' . $type
                . ' — ' . (string) ($answer['error_description'] ?? ($answer['error'] ?? ''))
            );
        }

        $this->assertSame(count($advertised), $this->endpointCount());
    }

    // ── Whose endpoint it is ──────────────────────────────────────────────────




    /**
     * One client cannot delete another's endpoint.
     *
     * A `webhook_id` is a small integer in a form. The lookup carries `appid`, and that is the
     * only thing making a guessed id useless — without it, this endpoint would let any registered
     * client unregister anybody's webhook.
     */
    public function testOneClientCannotDeleteAnothersEndpoint(): void
    {
        // Arrange — an endpoint owned by somebody else.
        $controller = $this->controller(appId: $this->otherAppId);
        $_POST = ['endpoint_url' => 'https://theirs.example/hook', 'webhook_type' => 'token_revoked'];
        $controller->register();
        $id = $this->firstEndpointId();
        $this->assertGreaterThan(0, $id, 'precondition: their endpoint exists');

        // Act — as a different client, naming their id.
        $mine  = $this->controller(appId: $this->appId);
        $_POST = ['webhook_id' => (string) $id];
        $answer = $this->decode($mine->delete());

        // Assert
        $this->assertNotSame('deleted', $answer['status'] ?? null);
        $this->assertSame(1, $this->endpointCount(), "another client's endpoint was deleted");
    }

    /** And cannot queue a test event against it either. */
    public function testOneClientCannotTestAnothersEndpoint(): void
    {
        // Arrange
        $theirs = $this->controller(appId: $this->otherAppId);
        $_POST  = ['endpoint_url' => 'https://theirs.example/hook', 'webhook_type' => 'token_revoked'];
        $theirs->register();
        $id = $this->firstEndpointId();

        // Act
        $_POST  = ['webhook_id' => (string) $id];
        $answer = $this->decode($this->controller(appId: $this->appId)->test());

        // Assert
        $this->assertSame('not_found', $answer['error'] ?? null);
        $this->assertSame(0, $this->queuedFor($id), 'an event was queued to somebody else\'s endpoint');
    }

    /**
     * Testing one's own endpoint queues an event for it — and only for it.
     *
     * The answer is 202 rather than 200 on purpose: the event is queued, not delivered, so it
     * travels the same signing and retry path a real event does. Delivery happens on the
     * `auth:webhook-deliver` schedule, and a caller told "200 OK" would reasonably read that as
     * "my endpoint was reached".
     *
     * The second half is the part worth a test: an endpoint belonging to another application, on
     * the same event type, must get nothing. A test ping is a request this application caused to
     * be sent, and fanning it out to every subscriber makes one client able to generate traffic
     * against another's URL.
     */
    public function testTestQueuesAnEventForItsOwnEndpointOnly(): void
    {
        // Arrange — both applications subscribe to the same event type.
        $_POST = ['endpoint_url' => 'https://theirs.example/hook', 'webhook_type' => 'token_revoked'];
        $this->controller(appId: $this->otherAppId)->register();
        $theirs = $this->firstEndpointId();

        $_POST = ['endpoint_url' => 'https://mine.example/hook', 'webhook_type' => 'token_revoked'];
        $this->controller(appId: $this->appId)->register();
        $mine = $this->firstEndpointId();
        $this->assertNotSame($theirs, $mine, 'precondition: two distinct endpoints');

        // Act
        $_POST  = ['webhook_id' => (string) $mine];
        $answer = $this->decode($this->controller(appId: $this->appId)->test());

        // Assert
        $this->assertTrue($answer['queued'] ?? false, 'nothing was queued: ' . json_encode($answer));
        $this->assertSame(1, $this->queuedFor($mine));
        $this->assertSame(
            0,
            $this->queuedFor($theirs),
            "a test ping for one application's endpoint was fanned out to another's"
        );
    }

    /**
     * `stats()` counts the caller's own deliveries and nobody else's.
     *
     * The figure an operator watches to notice the delivery schedule has stopped. Counting another
     * application's pending events would make a stalled queue look like somebody else's problem —
     * or invent one that is not there.
     */
    public function testStatsCountsOnlyItsOwnEvents(): void
    {
        // Arrange
        $_POST = ['endpoint_url' => 'https://theirs.example/hook', 'webhook_type' => 'token_revoked'];
        $this->controller(appId: $this->otherAppId)->register();
        $theirs = $this->firstEndpointId();

        $_POST = ['endpoint_url' => 'https://mine.example/hook', 'webhook_type' => 'gdpr_request'];
        $this->controller(appId: $this->appId)->register();
        $mine = $this->firstEndpointId();

        $this->queue($mine, 'pending');
        $this->queue($mine, 'pending');
        $this->queue($mine, 'failed');
        $this->queue($theirs, 'pending');

        // Act
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $counts = (array) ($this->decode($this->controller(appId: $this->appId)->stats())['events'] ?? []);

        // Assert
        $this->assertSame(2, (int) ($counts['pending'] ?? -1), 'got: ' . json_encode($counts));
        $this->assertSame(1, (int) ($counts['failed'] ?? -1));
        $this->assertSame(0, (int) ($counts['sent'] ?? -1));
    }

    /** Its owner can delete it. */
    public function testTheOwnerCanDeleteItsOwnEndpoint(): void
    {
        // Arrange
        $controller = $this->controller();
        $_POST = ['endpoint_url' => 'https://mine.example/hook', 'webhook_type' => 'token_revoked'];
        $controller->register();
        $id = $this->firstEndpointId();

        // Act
        $_POST = ['webhook_id' => (string) $id];
        $this->controller()->delete();

        // Assert
        $this->assertSame(0, $this->endpointCount());
    }

    // ── What an administrator can do from the application's screen ────────────

    /** The store the admin screen writes through. */
    private function service(): \Pramnos\Auth\WebhookService
    {
        return new \Pramnos\Auth\WebhookService($this->db);
    }

    /**
     * An administrator's endpoint is recorded as theirs, and becomes the client's again
     * when the client re-registers it.
     *
     * `registered_by` decides whether a delivery is guarded, so it has to follow whoever
     * last set the address: an application re-registering over an administrator's entry
     * must not inherit the trust that came with it.
     */
    public function testRegisteredByFollowsWhoeverLastSetTheAddress(): void
    {
        // Arrange
        $this->service()->saveEndpoint(
            $this->appId,
            'https://10.8.0.5/hooks',
            'token_revoked',
            'admin-secret',
            \Pramnos\Auth\WebhookService::REGISTERED_BY_ADMIN
        );
        $this->assertSame('admin', $this->endpointRow()['registered_by'] ?? null, 'precondition');

        // Act — the application registers the same type through the API
        $_POST = ['endpoint_url' => 'https://example.com/hook', 'webhook_type' => 'token_revoked'];
        $this->controller()->register();

        // Assert
        $this->assertSame(1, $this->endpointCount(), 'the endpoint was replaced, not added');
        $this->assertSame('client', $this->endpointRow()['registered_by'] ?? null);
        $this->assertSame('https://example.com/hook', $this->storedUrl());
    }

    /**
     * The listing for the screen counts deliveries per endpoint and carries no secret.
     *
     * The counts come from a grouped query over the events table, which is the part a
     * double cannot check; the other application's endpoint and events must not appear.
     */
    public function testEndpointsForCountsDeliveriesAndLeavesOutTheSecret(): void
    {
        // Arrange
        $this->service()->saveEndpoint($this->appId, 'https://example.com/a', 'token_revoked', 's1');
        $mine = $this->firstEndpointId();
        $this->service()->saveEndpoint($this->otherAppId, 'https://example.com/b', 'token_revoked', 's2');
        $theirs = $this->firstEndpointId();
        $this->queue($mine, 'sent');
        $this->queue($mine, 'sent');
        $this->queue($mine, 'failed');
        $this->queue($theirs, 'pending');

        // Act
        $endpoints = $this->service()->endpointsFor($this->appId);

        // Assert
        $this->assertCount(1, $endpoints, 'only this application\'s endpoint is listed');
        $this->assertArrayNotHasKey('secret_key', $endpoints[0]);
        $this->assertSame('client', $endpoints[0]['registered_by']);
        $this->assertSame(
            ['pending' => 0, 'sent' => 2, 'failed' => 1, 'cancelled' => 0],
            $endpoints[0]['events']
        );
    }

    /** An application with no endpoints lists none, without querying the events. */
    public function testEndpointsForAnApplicationWithNoneIsEmpty(): void
    {
        // Act
        $endpoints = $this->service()->endpointsFor($this->appId);

        // Assert
        $this->assertSame([], $endpoints);
    }

    /**
     * A new secret is issued only to the endpoint's own application, and is what is stored.
     *
     * The admin screen passes the application id from the page; an id belonging to
     * another application must change nothing.
     */
    public function testRotatingASecretIsScopedToTheOwner(): void
    {
        // Arrange
        $this->service()->saveEndpoint($this->appId, 'https://example.com/a', 'token_revoked', 'original');
        $id = $this->firstEndpointId();

        // Act
        $refused = $this->service()->rotateEndpointSecret($this->otherAppId, $id);
        $issued  = $this->service()->rotateEndpointSecret($this->appId, $id);

        // Assert
        $this->assertNull($refused, 'another application rotated this secret');
        $this->assertIsString($issued);
        $this->assertSame(64, strlen((string) $issued));
        $this->assertSame(
            $issued,
            \Pramnos\Security\Encrypter::maybeDecrypt($this->storedSecret()),
            'the stored secret is not the one issued'
        );
        $this->assertNull($this->service()->rotateEndpointSecret($this->appId, 0), 'no id, no rotation');
    }

    /**
     * An endpoint is removed only by its own application.
     */
    public function testDeletingAnEndpointIsScopedToTheOwner(): void
    {
        // Arrange
        $this->service()->saveEndpoint($this->appId, 'https://example.com/a', 'token_revoked', 's');
        $id = $this->firstEndpointId();

        // Act
        $refused = $this->service()->deleteEndpoint($this->otherAppId, $id);
        $count   = $this->endpointCount();
        $deleted = $this->service()->deleteEndpoint($this->appId, $id);

        // Assert
        $this->assertFalse($refused);
        $this->assertSame(1, $count, 'another application removed this endpoint');
        $this->assertTrue($deleted);
        $this->assertSame(0, $this->endpointCount());
    }

    /**
     * The migration adds the column with `client` as its default, and takes it away again.
     *
     * The default is the property that matters: rows written before the column existed,
     * and rows written by an application sharing this database with its own code, must
     * come out as `client` — the guarded answer.
     */
    public function testTheMigrationAddsTheColumnWithClientAsItsDefault(): void
    {
        // Arrange
        // The same stand-in application `runMigrations()` gives a migration: a database and nothing else.
        $application = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        $application->database = $this->db;
        $migration = new \Pramnos\Framework\Migrations\AuthServer\AddRegisteredByToOauth2WebhookEndpoints(
            $application
        );
        $schema = $this->db->schema();

        try {
            // Act — down, then up again
            $migration->down();
            $afterDown = $schema->hasColumn('applications.oauth2_webhook_endpoints', 'registered_by');
            $migration->down();   // a second down finds nothing to drop
            $migration->up();
            $migration->up();     // and a second up finds the column there

            $this->db->queryBuilder()->table('applications.oauth2_webhook_endpoints')->insert([
                'appid'        => $this->appId,
                'endpoint_url' => 'https://example.com/raw',
                'webhook_type' => 'token_revoked',
                'secret_key'   => 'raw',
            ]);

            // Assert
            $this->assertFalse($afterDown, 'down() left the column');
            $this->assertSame('client', $this->endpointRow()['registered_by'] ?? null);
        } finally {
            $migration->up();
        }
    }

    /** The applications screen, with its gate open and its view and redirect captured. */
    private function applicationsScreen(): \Pramnos\Auth\Controllers\ApplicationsController
    {
        return new class(null) extends \Pramnos\Auth\Controllers\ApplicationsController {
            public ?object $shown = null;

            public ?string $redirectedTo = null;

            protected function requireMinUserType(int $minType): bool
            {
                return false;
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->redirectedTo = (string) $url;
            }

            public function &getView($name = '', $type = '', $args = [])
            {
                $view = new #[\AllowDynamicProperties] class {
                    public function display($layout = ''): mixed
                    {
                        return true;
                    }
                };
                $this->shown = $view;

                return $view;
            }
        };
    }

    /**
     * An administrator's endpoint, saved through the screen, is the administrator's.
     *
     * The whole path against the database: the application is looked up, the row is
     * written with `registered_by = admin`, and the page it returns to lists it that way.
     */
    public function testTheScreenSavesAnAdministratorsEndpointAndListsIt(): void
    {
        // Arrange
        $token = \Pramnos\Http\Session::getInstance()->getCsrfToken();
        $_POST = [
            '_csrf_token'  => $token,
            'appid'        => (string) $this->appId,
            'endpoint_url' => 'https://127.0.0.1:8443/hooks',
            'webhook_type' => 'account_deleted',
        ];
        $screen = $this->applicationsScreen();

        try {
            // Act
            $screen->webhook();
            $_GET['_option'] = (string) $this->appId;
            $page = $this->applicationsScreen();
            $page->view();

            // Assert
            $this->assertSame('admin', $this->endpointRow()['registered_by'] ?? null);
            $this->assertSame('https://127.0.0.1:8443/hooks', $this->storedUrl());
            $listed = $page->shown->webhooks ?? [];
            $this->assertCount(1, $listed);
            $this->assertSame('admin', $listed[0]['registered_by']);
            $this->assertSame(\Pramnos\Auth\WebhookService::EVENT_TYPES, $page->shown->webhookTypes);
        } finally {
            unset($_GET['_option']);
        }
    }

    /**
     * An endpoint for an application id that does not exist is refused.
     *
     * The id comes from a hidden field, so it is whatever the form was made to say.
     */
    public function testTheScreenRefusesAnEndpointForAnApplicationThatDoesNotExist(): void
    {
        // Arrange
        $token = \Pramnos\Http\Session::getInstance()->getCsrfToken();
        $_POST = [
            '_csrf_token'  => $token,
            'appid'        => '987654321',
            'endpoint_url' => 'https://example.com/hooks',
            'webhook_type' => 'account_deleted',
        ];

        // Act
        $this->applicationsScreen()->webhook();

        // Assert
        $this->assertSame(0, $this->endpointCount());
        $orphan = $this->db->queryBuilder()->table('applications.oauth2_webhook_endpoints')
            ->where('appid', 987654321)->count();
        $this->assertSame(0, (int) $orphan);
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /**
     * The controller with the client identified and the delivery service replaced.
     *
     * `requireClient()` is the seam for authentication — whether a particular secret verifies is
     * `Auth\Application`'s subject and has its own tests. The delivery service is the real one:
     * `test()` only *queues*, so nothing here reaches the network, and the queued row is the thing
     * worth asserting on.
     */
    private function controller(bool $authenticated = true, ?int $appId = null): object
    {
        return new class ($authenticated, $appId ?? $this->appId, $this->db) extends Webhook {
            public function __construct(
                private bool $authenticated,
                private int $appId,
                private $connection
            ) {
            }

            protected function requireClient(): mixed
            {
                return $this->authenticated
                    ? $this->appId
                    : \Pramnos\Http\Response::json(['error' => 'invalid_client'], 401);
            }

            protected function database(): mixed
            {
                return $this->connection;
            }

        };
    }

    /** The body of a response, decoded. */
    private function decode(mixed $answer): array
    {
        if (is_array($answer)) {
            return $answer;
        }

        return (array) json_decode((string) $answer->getBody(), true);
    }

    /** The secret as the table holds it — the value `list()` must never echo. */
    private function storedSecret(): string
    {
        return (string) ($this->endpointRow()['secret_key'] ?? '');
    }

    private function storedUrl(): string
    {
        return (string) ($this->endpointRow()['endpoint_url'] ?? '');
    }

    /** @return array<string, mixed> */
    private function endpointRow(): array
    {
        $row = $this->db->queryBuilder()
            ->table('applications.oauth2_webhook_endpoints')
            ->where('appid', $this->appId)
            ->orderBy('webhook_id', 'desc')
            ->first();

        return $row ? (array) $row->fields : [];
    }

    private function endpointCount(): int
    {
        return (int) $this->db->queryBuilder()
            ->table('applications.oauth2_webhook_endpoints')
            ->whereIn('appid', [$this->appId, $this->otherAppId])
            ->count();
    }

    private function firstEndpointId(): int
    {
        $row = $this->db->queryBuilder()
            ->table('applications.oauth2_webhook_endpoints')
            ->select(['webhook_id'])
            ->whereIn('appid', [$this->appId, $this->otherAppId])
            ->orderBy('webhook_id', 'desc')
            ->first();

        return (int) ($row->fields['webhook_id'] ?? 0);
    }

    /** One application row, returning the identifier the database gave it. */
    private function registerApplication(string $name): int
    {
        $this->db->queryBuilder()->table('applications')->insert(['name' => $name]);

        $row = $this->db->queryBuilder()->table('applications')
            ->select(['appid'])->where('name', $name)->orderBy('appid', 'desc')->first();
        $appid = (int) ($row->fields['appid'] ?? 0);

        $this->assertGreaterThan(0, $appid, 'the fixture application was not created');

        return $appid;
    }

    /** How many events are queued against one endpoint. */
    private function queuedFor(int $webhookId): int
    {
        return (int) $this->db->queryBuilder()
            ->table('applications.oauth2_webhook_events')
            ->where('webhook_id', $webhookId)
            ->count();
    }

    /** One event row against an endpoint, in a given delivery state. */
    private function queue(int $webhookId, string $status): void
    {
        $this->db->queryBuilder()->table('applications.oauth2_webhook_events')->insert([
            'webhook_id'      => $webhookId,
            'event_type'      => 'token_revoked',
            'payload'         => '{"test":true}',
            'status'          => $status,
            'attempts'        => 0,
            'max_attempts'    => 3,
            'next_attempt_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function clear(): void
    {
        foreach ([$this->appId, $this->otherAppId] as $appid) {
            if ($appid === 0) {
                continue;
            }

            try {
                /*
                 * Events first: `oauth2_webhook_events.webhook_id` is a foreign key, and on
                 * PostgreSQL the delete below is refused while a queued row still points at it.
                 */
                $ids = $this->db->queryBuilder()
                    ->table('applications.oauth2_webhook_endpoints')
                    ->select(['webhook_id'])->where('appid', $appid)->get();

                while ($ids && $ids->fetch()) {
                    $this->db->queryBuilder()->table('applications.oauth2_webhook_events')
                        ->where('webhook_id', (int) $ids->fields['webhook_id'])->delete();
                }

                $this->db->queryBuilder()->table('applications.oauth2_webhook_endpoints')
                    ->where('appid', $appid)->delete();
            } catch (\Throwable $exception) {
                // No table on a lane mid-migration; nothing to clear.
            }
        }
    }

    /** The fixture applications go too, so a run leaves the table as it found it. */
    private function forget(): void
    {
        foreach ([$this->appId, $this->otherAppId] as $appid) {
            if ($appid === 0) {
                continue;
            }

            try {
                $this->db->queryBuilder()->table('applications')
                    ->where('appid', $appid)->delete();
            } catch (\Throwable $exception) {
                // Already gone.
            }
        }

        $this->appId = $this->otherAppId = 0;
    }
}
