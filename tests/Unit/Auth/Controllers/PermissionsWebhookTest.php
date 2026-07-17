<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Auth\Controllers\PermissionsController;
use Pramnos\Auth\WebhookService;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;

/**
 * A WebhookService spy that records queueEvent() calls without a database.
 */
class SpyWebhookService extends WebhookService
{
    public array $calls = [];
    public bool $throw = false;

    public function __construct() { /* skip parent (no DB needed) */ }

    public function queueEvent(string $eventType, int $userId, array $payload, ?string $deviceCode = null, ?int $tokenId = null): int
    {
        $this->calls[] = ['event' => $eventType, 'user' => $userId, 'payload' => $payload];
        if ($this->throw) {
            throw new \RuntimeException('webhook boom');
        }
        return count($this->calls);
    }
}

/**
 * Testable controller: bypass the auth gate and redirect, and inject the spy.
 */
class TestablePermissionsController extends PermissionsController
{
    public SpyWebhookService $spy;

    public function __construct()
    {
        parent::__construct(null);
        $this->spy = new SpyWebhookService();
    }

    protected function requireMinUserType($type): bool
    {
        return false; // not blocked → action proceeds
    }

    public function redirect($url = null, $quit = true, $code = '302')
    {
        // no-op for tests
    }

    protected function webhookService(): WebhookService
    {
        return $this->spy;
    }

    public function callEmit(string $subjectType, int $subjectId, array $context): void
    {
        $this->emitPermissionsChanged($subjectType, $subjectId, $context);
    }
}

/**
 * Unit tests for the permissions_changed webhook emission (feature 7).
 *
 * Covers the emit helper (user vs role targeting, failure-swallowing) and the
 * save()/delete() call sites that fire it.
 */
class PermissionsWebhookTest extends BaseTestCase
{
    private TestablePermissionsController $controller;
    private $originalDb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new TestablePermissionsController();
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        if ($this->originalDb !== null) {
            $ref = &Database::getInstance();
            $ref = $this->originalDb;
            $this->originalDb = null;
        }
    }

    /** Install a mock DB singleton with a configurable QueryBuilder. */
    private function mockDb(callable $configureQb): void
    {
        $ref = &Database::getInstance();
        $this->originalDb = $ref;

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('table')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('select')->willReturnSelf();
        $configureQb($qb);

        $db = $this->createMock(Database::class);
        $db->method('queryBuilder')->willReturn($qb);
        $ref = $db;
    }

    // ── emitPermissionsChanged() ─────────────────────────────────────────────

    /** A user-subject targets that user id. */
    public function testEmitForUserTargetsThatUser(): void
    {
        $this->controller->callEmit('user', 55, ['operation' => 'create']);

        $this->assertCount(1, $this->controller->spy->calls);
        $call = $this->controller->spy->calls[0];
        $this->assertSame('permissions_changed', $call['event']);
        $this->assertSame(55, $call['user']);
        $this->assertSame('user', $call['payload']['subject_type']);
        $this->assertSame('create', $call['payload']['operation']);
    }

    /** A role-subject targets user 0 and carries the subject in the payload. */
    public function testEmitForRoleTargetsZeroWithSubjectInPayload(): void
    {
        $this->controller->callEmit('role', 9, ['operation' => 'delete']);

        $call = $this->controller->spy->calls[0];
        $this->assertSame(0, $call['user'], 'Role changes target user 0 (broad invalidation)');
        $this->assertSame('role', $call['payload']['subject_type']);
        $this->assertSame(9, $call['payload']['subject_id']);
    }

    /** A failing webhook must not propagate (permission admin must not break). */
    public function testEmitSwallowsWebhookFailure(): void
    {
        $this->controller->spy->throw = true;

        $this->controller->callEmit('user', 1, []); // must not throw

        $this->assertCount(1, $this->controller->spy->calls);
    }

    /** The real webhookService() factory builds a WebhookService from the DB. */
    public function testWebhookServiceFactoryReturnsInstance(): void
    {
        // A mock DB singleton is enough for the constructor.
        $this->mockDb(fn($qb) => null);

        $rm  = new \ReflectionMethod(PermissionsController::class, 'webhookService');
        $svc = $rm->invoke(new PermissionsController(null));

        $this->assertInstanceOf(WebhookService::class, $svc);
    }

    // ── save() / delete() call sites ─────────────────────────────────────────

    /** save() (create) fires permissions_changed after writing. */
    public function testSaveEmitsAfterInsert(): void
    {
        $this->mockDb(function ($qb): void {
            $qb->method('insert')->willReturn(true);
            $qb->method('update')->willReturn(true);
        });

        $_POST = [
            'permissionid' => 0,
            'subject_type' => 'user', 'subject_id' => 77,
            'object_type'  => 'invoice', 'object_id' => '*', 'action' => 'read',
            'grant_type'   => 'allow',
        ];

        $this->controller->save();

        $this->assertCount(1, $this->controller->spy->calls);
        $this->assertSame(77, $this->controller->spy->calls[0]['user']);
        $this->assertSame('create', $this->controller->spy->calls[0]['payload']['operation']);
    }

    /** save() (update, permissionid > 0) fires permissions_changed with operation=update. */
    public function testSaveEmitsAfterUpdate(): void
    {
        $this->mockDb(function ($qb): void {
            $qb->method('insert')->willReturn(true);
            $qb->method('update')->willReturn(true);
        });

        $_POST = [
            'permissionid' => 5,
            'subject_type' => 'user', 'subject_id' => 88,
            'object_type'  => 'invoice', 'object_id' => '*', 'action' => 'read',
            'grant_type'   => 'deny',
        ];

        $this->controller->save();

        $this->assertCount(1, $this->controller->spy->calls);
        $this->assertSame('update', $this->controller->spy->calls[0]['payload']['operation']);
        $this->assertSame(88, $this->controller->spy->calls[0]['user']);
    }

    /** save() with required fields missing redirects WITHOUT emitting. */
    public function testSaveMissingFieldsDoesNotEmit(): void
    {
        $_POST = ['permissionid' => 0, 'subject_type' => '', 'object_type' => '', 'action' => ''];

        $this->controller->save();

        $this->assertCount(0, $this->controller->spy->calls, 'No event when validation fails');
    }

    /** delete() reads the row then fires permissions_changed with operation=delete. */
    public function testDeleteEmitsAfterDeletion(): void
    {
        $row = (object) ['numRows' => 1, 'fields' => ['subject_type' => 'role', 'subject_id' => 3]];
        $this->mockDb(function ($qb) use ($row): void {
            $qb->method('first')->willReturn($row);
            $qb->method('delete')->willReturn(true);
        });

        $_GET['_option'] = 42;

        $this->controller->delete(42);

        $this->assertCount(1, $this->controller->spy->calls);
        $this->assertSame('delete', $this->controller->spy->calls[0]['payload']['operation']);
        $this->assertSame(0, $this->controller->spy->calls[0]['user'], 'role subject → user 0');
        $this->assertSame(3, $this->controller->spy->calls[0]['payload']['subject_id']);
    }

    /** delete() with an invalid id redirects WITHOUT touching the DB or emitting. */
    public function testDeleteInvalidIdDoesNotEmit(): void
    {
        $this->controller->delete(0);

        $this->assertCount(0, $this->controller->spy->calls);
    }
}
