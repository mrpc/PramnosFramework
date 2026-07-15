<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\InternalPermissions;
use Pramnos\Auth\PermissionResolverInterface;

/**
 * Testable subclass overriding the auth and resolver seams so the endpoint flow
 * can be driven without a live request or database.
 */
class TestableInternalPermissions extends InternalPermissions
{
    public ?array $creds = null;
    public ?int $authAppId = null;
    public array $resolved = ['user_id' => 0, 'app_id' => 0, 'permissions' => []];
    /** Records the (userId, appId) the resolver was called with. */
    public ?array $resolveArgs = null;

    protected function extractClientCredentials(): ?array
    {
        return $this->creds;
    }

    protected function authenticateClient(string $clientId, string $clientSecret): ?int
    {
        return $this->authAppId;
    }

    protected function resolver(): PermissionResolverInterface
    {
        $result = $this->resolved;
        $owner  = $this;
        return new class ($result, $owner) implements PermissionResolverInterface {
            public function __construct(private array $r, private TestableInternalPermissions $owner) {}
            public function resolve(int $userId, ?int $appId): array
            {
                $this->owner->resolveArgs = ['user' => $userId, 'app' => $appId];
                return $this->r;
            }
        };
    }
}

/**
 * Unit tests for the internal permissions endpoint (feature 6).
 *
 * Covers every branch of index(): method guard, client authentication,
 * cross-client protection, user_id validation, and the success response.
 */
class InternalPermissionsControllerTest extends TestCase
{
    private TestableInternalPermissions $controller;

    protected function setUp(): void
    {
        $this->controller = new TestableInternalPermissions(null);
        $_GET  = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REQUEST_METHOD']);
    }

    public function testWrongMethodReturns405(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->assertSame(405, $this->controller->index()->getStatusCode());
    }

    public function testMissingCredentialsReturns401(): void
    {
        $this->controller->creds = null;

        $r = $this->controller->index();
        $this->assertSame(401, $r->getStatusCode());
        $this->assertStringContainsString('invalid_client', $r->getBody());
    }

    public function testInvalidCredentialsReturns401(): void
    {
        $this->controller->creds     = ['client_id' => 'a', 'client_secret' => 'bad'];
        $this->controller->authAppId = null;

        $this->assertSame(401, $this->controller->index()->getStatusCode());
    }

    public function testCrossClientRequestReturns403(): void
    {
        $this->controller->creds     = ['client_id' => 'a', 'client_secret' => 's'];
        $this->controller->authAppId = 5;
        $_GET['client_id'] = 'b'; // asking for another client's audience
        $_GET['user_id']   = '10';

        $r = $this->controller->index();
        $this->assertSame(403, $r->getStatusCode());
        $this->assertStringContainsString('forbidden', $r->getBody());
    }

    public function testMissingUserIdReturns400(): void
    {
        $this->controller->creds     = ['client_id' => 'a', 'client_secret' => 's'];
        $this->controller->authAppId = 5;
        // no user_id

        $r = $this->controller->index();
        $this->assertSame(400, $r->getStatusCode());
        $this->assertStringContainsString('invalid_request', $r->getBody());
    }

    public function testInvalidUserIdReturns400(): void
    {
        $this->controller->creds     = ['client_id' => 'a', 'client_secret' => 's'];
        $this->controller->authAppId = 5;
        $_GET['user_id'] = '0';

        $this->assertSame(400, $this->controller->index()->getStatusCode());
    }

    public function testSuccessResolvesForAuthenticatedAppAndUser(): void
    {
        $this->controller->creds     = ['client_id' => 'a', 'client_secret' => 's'];
        $this->controller->authAppId = 5;
        $this->controller->resolved  = [
            'user_id' => 10, 'app_id' => 5,
            'permissions' => [['object_type' => 'invoice', 'object_id' => '*', 'action' => 'read', 'grant' => 'allow', 'conditions' => null]],
        ];
        $_GET['user_id']   = '10';
        $_GET['client_id'] = 'a'; // matches authenticated client

        $r = $this->controller->index();

        $this->assertSame(200, $r->getStatusCode());
        $this->assertStringContainsString('"grant":"allow"', $r->getBody());
        $this->assertSame(['user' => 10, 'app' => 5], $this->controller->resolveArgs,
            'Resolver must be called with the query user_id and the authenticated appId');
    }
}
