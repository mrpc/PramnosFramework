<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\CapabilitiesSyncService;
use Pramnos\Auth\Controllers\Capabilities;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;
use Pramnos\Http\Response;

/**
 * Testable subclass overriding the controller's collaborator seams so the HTTP
 * flow can be driven without a live request, DB, or php://input stream.
 */
class TestableCapabilities extends Capabilities
{
    public ?array $creds = null;
    public ?int $authAppId = null;
    public ?array $manifest = null;
    public array $syncResult = [
        'status' => 'synced', 'resources' => 2, 'scopes' => 5, 'conditions' => 1, 'deactivated' => 0,
    ];
    /** Records the appid the sync service was called with. */
    public ?int $syncedAppId = null;

    protected function extractClientCredentials(): ?array
    {
        return $this->creds;
    }

    protected function authenticateClient(string $clientId, string $clientSecret): ?int
    {
        return $this->authAppId;
    }

    protected function readManifest(): ?array
    {
        return $this->manifest;
    }

    protected function syncService(): CapabilitiesSyncService
    {
        $result = $this->syncResult;
        $owner  = $this;
        // Anonymous sync-service double: skips the parent constructor (no DB) and
        // records the appid it was asked to sync.
        return new class ($result, $owner) extends CapabilitiesSyncService {
            public function __construct(private array $r, private TestableCapabilities $owner) {}
            public function sync(int $applicationId, array $manifest, ?int $syncedBy = null): array
            {
                $this->owner->syncedAppId = $applicationId;
                return $this->r;
            }
        };
    }
}

/**
 * Unit tests for the capabilities-push controller (feature 2 endpoint).
 *
 * Verifies method-guarding, client authentication, path/credential matching,
 * manifest parsing, and the success response — every branch of sync() — plus
 * the real credential-extraction and body-parsing seams in isolation.
 */
class CapabilitiesControllerTest extends TestCase
{
    private TestableCapabilities $controller;

    protected function setUp(): void
    {
        $this->controller = new TestableCapabilities(null);
        $_POST   = [];
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REQUEST_METHOD']);
    }

    // ── sync() branches ──────────────────────────────────────────────────────

    /** A non-PUT/POST verb is rejected with 405. */
    public function testWrongMethodReturns405(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $r = $this->controller->sync('client-a');

        $this->assertSame(405, $r->getStatusCode());
    }

    /** Missing credentials yield 401 invalid_client. */
    public function testMissingCredentialsReturns401(): void
    {
        $this->controller->creds = null;

        $r = $this->controller->sync('client-a');

        $this->assertSame(401, $r->getStatusCode());
        $this->assertStringContainsString('invalid_client', $r->getBody());
    }

    /** Credentials that fail authentication yield 401. */
    public function testInvalidCredentialsReturns401(): void
    {
        $this->controller->creds     = ['client_id' => 'client-a', 'client_secret' => 'wrong'];
        $this->controller->authAppId = null; // authentication fails

        $r = $this->controller->sync('client-a');

        $this->assertSame(401, $r->getStatusCode());
    }

    /** A path client_id that differs from the authenticated client yields 403. */
    public function testClientIdMismatchReturns403(): void
    {
        $this->controller->creds     = ['client_id' => 'client-a', 'client_secret' => 's'];
        $this->controller->authAppId = 42;

        $r = $this->controller->sync('client-b'); // pushing for a different client

        $this->assertSame(403, $r->getStatusCode());
        $this->assertStringContainsString('forbidden', $r->getBody());
    }

    /** A malformed / missing manifest yields 400. */
    public function testMalformedManifestReturns400(): void
    {
        $this->controller->creds     = ['client_id' => 'client-a', 'client_secret' => 's'];
        $this->controller->authAppId = 42;
        $this->controller->manifest  = null; // could not parse JSON

        $r = $this->controller->sync('client-a');

        $this->assertSame(400, $r->getStatusCode());
        $this->assertStringContainsString('invalid_request', $r->getBody());
    }

    /** A valid push returns 200, the sync result, and syncs the right appid. */
    public function testSuccessReturns200AndSyncsAuthenticatedApp(): void
    {
        $this->controller->creds     = ['client_id' => 'client-a', 'client_secret' => 's'];
        $this->controller->authAppId = 42;
        $this->controller->manifest  = ['resources' => [['name' => 'reports', 'scopes' => ['read']]]];

        $r = $this->controller->sync('client-a');

        $this->assertSame(200, $r->getStatusCode());
        $this->assertStringContainsString('"status":"synced"', $r->getBody());
        $this->assertSame(42, $this->controller->syncedAppId,
            'Must sync the authenticated client\'s appid');
    }

    /** The path client_id is optional — omitting it still authenticates and syncs. */
    public function testOmittedPathClientIdIsAllowed(): void
    {
        $this->controller->creds     = ['client_id' => 'client-a', 'client_secret' => 's'];
        $this->controller->authAppId = 7;
        $this->controller->manifest  = ['resources' => []];

        $r = $this->controller->sync(null);

        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame(7, $this->controller->syncedAppId);
    }

    // ── real extractClientCredentials() seam ──────────────────────────────────

    /** Basic auth header is decoded into client_id/secret. */
    public function testExtractCredentialsFromBasicHeader(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('id-1:secret-1');

        $creds = $this->callProtected('extractClientCredentials');

        $this->assertSame(['client_id' => 'id-1', 'client_secret' => 'secret-1'], $creds);
    }

    /** A secret containing colons is preserved (split on first colon only). */
    public function testExtractCredentialsSecretWithColons(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('id-1:a:b:c');

        $creds = $this->callProtected('extractClientCredentials');

        $this->assertSame('a:b:c', $creds['client_secret']);
    }

    /** Falls back to POST body when no Basic header is present. */
    public function testExtractCredentialsFromPostBody(): void
    {
        $_POST['client_id']     = 'id-2';
        $_POST['client_secret'] = 'secret-2';

        $creds = $this->callProtected('extractClientCredentials');

        $this->assertSame(['client_id' => 'id-2', 'client_secret' => 'secret-2'], $creds);
    }

    /** Returns null when neither source supplies credentials. */
    public function testExtractCredentialsReturnsNullWhenAbsent(): void
    {
        $this->assertNull($this->callProtected('extractClientCredentials'));
    }

    // ── real readManifest() seam (rawRequestBody overridden) ──────────────────

    /** A valid JSON body decodes to an array. */
    public function testReadManifestDecodesValidJson(): void
    {
        $c = $this->controllerWithBody('{"resources":[]}');
        $this->assertSame(['resources' => []], $c->exposeReadManifest());
    }

    /** An empty body yields null. */
    public function testReadManifestNullOnEmptyBody(): void
    {
        $c = $this->controllerWithBody('');
        $this->assertNull($c->exposeReadManifest());
    }

    /** A non-JSON / non-array body yields null. */
    public function testReadManifestNullOnInvalidJson(): void
    {
        $this->assertNull($this->controllerWithBody('not json')->exposeReadManifest());
        $this->assertNull($this->controllerWithBody('"a string"')->exposeReadManifest());
    }

    // ── real authenticateClient() seam (DB singleton mocked) ─────────────────

    /**
     * Valid credentials resolve to the application's appid.
     * loadByApiKey() (first()) returns the row; validateCredentials() (count())
     * confirms the secret.
     */
    public function testAuthenticateClientValidReturnsAppId(): void
    {
        $this->withMockedDb(
            firstRow: (object) ['numRows' => 1, 'fields' => ['appid' => 42, 'apikey' => 'id-1']],
            count: 1,
            run: function (): void {
                $appId = $this->callProtectedArgs('authenticateClient', ['id-1', 'secret-1']);
                $this->assertSame(42, $appId);
            }
        );
    }

    /**
     * A wrong secret (loadByApiKey succeeds but validateCredentials count == 0)
     * yields null.
     */
    public function testAuthenticateClientWrongSecretReturnsNull(): void
    {
        $this->withMockedDb(
            firstRow: (object) ['numRows' => 1, 'fields' => ['appid' => 42, 'apikey' => 'id-1']],
            count: 0, // secret mismatch
            run: function (): void {
                $this->assertNull($this->callProtectedArgs('authenticateClient', ['id-1', 'bad']));
            }
        );
    }

    /**
     * An unknown client (loadByApiKey returns no row) yields null.
     */
    public function testAuthenticateClientUnknownReturnsNull(): void
    {
        $this->withMockedDb(
            firstRow: (object) ['numRows' => 0, 'fields' => []],
            count: 0,
            run: function (): void {
                $this->assertNull($this->callProtectedArgs('authenticateClient', ['ghost', 'x']));
            }
        );
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function callProtected(string $method): mixed
    {
        $rm = new \ReflectionMethod(Capabilities::class, $method);
        return $rm->invoke($this->controller);
    }

    private function callProtectedArgs(string $method, array $args): mixed
    {
        $rm = new \ReflectionMethod(Capabilities::class, $method);
        return $rm->invoke($this->controller, ...$args);
    }

    /**
     * Run $run with the global Database singleton replaced by a mock whose
     * QueryBuilder returns $firstRow from first() and $count from count().
     */
    private function withMockedDb(object $firstRow, int $count, callable $run): void
    {
        $ref      = &Database::getInstance();
        $original = $ref;

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('table')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('select')->willReturnSelf();
        $qb->method('first')->willReturn($firstRow);
        $qb->method('count')->willReturn($count);

        $db = $this->createMock(Database::class);
        $db->method('queryBuilder')->willReturn($qb);

        $ref = $db;
        try {
            $run();
        } finally {
            $ref = $original;
        }
    }

    /** A controller whose rawRequestBody() returns a fixed string. */
    private function controllerWithBody(string $body): object
    {
        return new class ($body) extends Capabilities {
            public function __construct(private string $body)
            {
                parent::__construct(null);
            }
            protected function rawRequestBody(): string
            {
                return $this->body;
            }
            public function exposeReadManifest(): ?array
            {
                return $this->readManifest();
            }
        };
    }
}
