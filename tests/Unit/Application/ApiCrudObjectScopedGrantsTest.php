<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\ApiCrudController;
use Pramnos\Auth\PermissionResolverInterface;

/**
 * A resolver over grants the test writes, so the matching rules can be exercised
 * without an authserver schema.
 */
class CannedPermissionResolver implements PermissionResolverInterface
{
    /** @param list<array<string, mixed>> $grants */
    public function __construct(private array $grants) {}

    /**
     * @param int      $userId Ignored — the grants are fixed for the test.
     * @param int|null $appId  Ignored, likewise.
     * @return array<string, mixed>
     */
    public function resolve(int $userId, ?int $appId): array
    {
        return ['user_id' => $userId, 'app_id' => $appId, 'permissions' => $this->grants];
    }
}

/**
 * A CRUD controller for the `invoice` resource, reading canned grants.
 */
class ObjectScopedCrudController extends ApiCrudController
{
    /** @var list<array<string, mixed>> */
    private array $grants = [];

    public function __construct() {}

    /** @param list<array<string, mixed>> $grants */
    public function withGrants(array $grants): static
    {
        $this->grants = $grants;

        return $this;
    }

    protected function permissionResolver(): PermissionResolverInterface
    {
        return new CannedPermissionResolver($this->grants);
    }

    protected function resourceName(): string
    {
        return 'invoice';
    }

    /** The endpoint-level question, as authorize() asks it. */
    public function askEndpoint(string $action): ?bool
    {
        return $this->askPermissionResolver(7, $action);
    }

    /** The record-level question. */
    public function askObject(string $action, string $objectId): ?bool
    {
        return $this->permissionForObjectAsUser(7, $action, $objectId);
    }

    /** permissionForObject() without the request-user lookup a unit test cannot do. */
    private function permissionForObjectAsUser(int $userId, string $action, string $objectId): ?bool
    {
        $method = new \ReflectionMethod(ApiCrudController::class, 'resolverVerdict');

        return $method->invoke($this, $userId, $action, $objectId);
    }
}

/**
 * Grants that name one record must not authorise the whole collection.
 *
 * `object_id` is the permission store's only per-record mechanism, and the loop
 * behind `authorize()` used to ignore it: any grant matching (object_type, action)
 * counted, whatever record it named. A grant written as "read invoice 42" — the
 * careful, narrow thing an administrator reaches for — was therefore read by the
 * generated endpoints as "read invoices", and `list` returned all of them.
 *
 * The widening is the finding; the two questions are the fix. "May this user read
 * invoices" and "may this user read invoice 42" are different, and a grant answers
 * one of them.
 */
#[CoversClass(ApiCrudController::class)]
class ApiCrudObjectScopedGrantsTest extends TestCase
{
    /**
     * Build one grant row in the shape PermissionResolver emits.
     *
     * @param string|null $objectId NULL means "every object of this type".
     */
    private function grant(
        string $action,
        ?string $objectId,
        string $type = 'allow',
        string $objectType = 'invoice',
        mixed $conditions = null
    ): array {
        return [
            'object_type' => $objectType,
            'object_id'   => $objectId,
            'action'      => $action,
            'grant'       => $type,
            'conditions'  => $conditions,
        ];
    }

    private function controller(array $grants): ObjectScopedCrudController
    {
        return (new ObjectScopedCrudController())->withGrants($grants);
    }

    // ── The regression ────────────────────────────────────────────────────────

    /**
     * THE regression: a grant on one record says nothing about the collection.
     *
     * Before the fix this returned true, and `list` handed back every invoice in the
     * table to somebody who had been given exactly one.
     */
    public function testAGrantOnOneRecordDoesNotAuthoriseTheCollection(): void
    {
        // Arrange
        $controller = $this->controller([$this->grant('read', '42')]);

        // Act
        $verdict = $controller->askEndpoint('read');

        // Assert — no opinion, not an allow. Callers read null as "no rule", which
        // leaves the endpoint exactly as it behaves for a project that granted
        // nothing; what it must not do is report an allow.
        $this->assertNull(
            $verdict,
            'A grant naming one record authorised the whole resource.'
        );
    }

    /**
     * And the same grant does authorise that record.
     *
     * Without this the fix could be "ignore object_id grants entirely", which would
     * pass the test above and make the store's only per-record mechanism useless.
     */
    public function testAGrantOnOneRecordAuthorisesThatRecord(): void
    {
        // Arrange
        $controller = $this->controller([$this->grant('read', '42')]);

        // Act + Assert
        $this->assertTrue($controller->askObject('read', '42'));
    }

    /** And says nothing about a different record. */
    public function testAGrantOnOneRecordSaysNothingAboutAnother(): void
    {
        // Arrange
        $controller = $this->controller([$this->grant('read', '42')]);

        // Act + Assert
        $this->assertNull($controller->askObject('read', '43'));
    }

    // ── Blanket grants ────────────────────────────────────────────────────────

    /**
     * A grant with no object named still authorises the endpoint — the ordinary case,
     * and the one that must not have broken.
     */
    public function testABlanketGrantAuthorisesTheCollection(): void
    {
        // Arrange
        $controller = $this->controller([$this->grant('read', null)]);

        // Act + Assert
        $this->assertTrue($controller->askEndpoint('read'));
    }

    /**
     * `*` means the same as NULL. The column is documented as accepting the wildcard
     * and the schema allows NULL, so both spellings of "everything" have to work or
     * an administrator's grant silently does nothing.
     */
    public function testTheWildcardObjectIdIsTreatedAsBlanket(): void
    {
        // Arrange
        $controller = $this->controller([$this->grant('read', '*')]);

        // Act + Assert
        $this->assertTrue($controller->askEndpoint('read'));
        $this->assertTrue($controller->askObject('read', '42'));
    }

    /**
     * A blanket grant covers any individual record: "read invoices" includes invoice
     * 42, and a record-level check must not demand a row of its own.
     */
    public function testABlanketGrantCoversAnIndividualRecord(): void
    {
        // Arrange
        $controller = $this->controller([$this->grant('read', null)]);

        // Act + Assert
        $this->assertTrue($controller->askObject('read', '42'));
    }

    // ── Denies ────────────────────────────────────────────────────────────────

    /**
     * A deny on one record does not close the endpoint.
     *
     * The mirror of the regression, and the reason to fix the matching rather than
     * special-case allows: under the old loop, denying one invoice denied the list
     * for everybody. Both directions were wrong, in opposite ways.
     */
    public function testADenyOnOneRecordDoesNotCloseTheCollection(): void
    {
        // Arrange
        $controller = $this->controller([
            $this->grant('read', null),
            $this->grant('read', '42', 'deny'),
        ]);

        // Act + Assert
        $this->assertTrue($controller->askEndpoint('read'));
    }

    /** But it does close that record. */
    public function testADenyOnOneRecordClosesThatRecord(): void
    {
        // Arrange
        $controller = $this->controller([
            $this->grant('read', null),
            $this->grant('read', '42', 'deny'),
        ]);

        // Act + Assert
        $this->assertFalse($controller->askObject('read', '42'));
        $this->assertTrue($controller->askObject('read', '43'));
    }

    /** A blanket deny closes everything, record-level checks included. */
    public function testABlanketDenyClosesTheCollectionAndItsRecords(): void
    {
        // Arrange
        $controller = $this->controller([$this->grant('read', null, 'deny')]);

        // Act + Assert
        $this->assertFalse($controller->askEndpoint('read'));
        $this->assertFalse($controller->askObject('read', '42'));
    }

    // ── The other matching rules, unchanged ───────────────────────────────────

    /** A grant for another resource is not consulted. */
    public function testAGrantForAnotherResourceIsIgnored(): void
    {
        // Arrange
        $controller = $this->controller([
            $this->grant('read', null, 'allow', 'payment'),
        ]);

        // Act + Assert
        $this->assertNull($controller->askEndpoint('read'));
    }

    /** A grant for another action is not consulted. */
    public function testAGrantForAnotherActionIsIgnored(): void
    {
        // Arrange
        $controller = $this->controller([$this->grant('update', null)]);

        // Act + Assert
        $this->assertNull($controller->askEndpoint('read'));
    }

    /** The action wildcard still covers every action. */
    public function testTheActionWildcardCoversEveryAction(): void
    {
        // Arrange
        $controller = $this->controller([$this->grant('*', null)]);

        // Act + Assert
        $this->assertTrue($controller->askEndpoint('read'));
        $this->assertTrue($controller->askEndpoint('delete'));
    }

    /**
     * A conditional grant is still skipped, at both levels.
     *
     * The resolver passes ABAC conditions through for the application to evaluate
     * against its own request context, and this class has none of it. Treating an
     * unevaluated condition as satisfied would grant more than was written.
     */
    public function testAConditionalGrantIsSkipped(): void
    {
        // Arrange
        $controller = $this->controller([
            $this->grant('read', null, 'allow', 'invoice', ['location_id' => [1, 2]]),
        ]);

        // Act + Assert
        $this->assertNull($controller->askEndpoint('read'));
        $this->assertNull($controller->askObject('read', '42'));
    }

    /**
     * A resolver that throws is not a decision.
     *
     * An installation with no authserver schema must keep working, and a broken query
     * must not read as a denial that locks an administrator out of their own screens.
     */
    public function testAFailingResolverYieldsNoOpinion(): void
    {
        // Arrange
        $controller = new class extends ApiCrudController {
            public function __construct() {}

            protected function permissionResolver(): PermissionResolverInterface
            {
                throw new \RuntimeException('no authserver schema here');
            }

            protected function resourceName(): string
            {
                return 'invoice';
            }

            public function askEndpoint(string $action): ?bool
            {
                return $this->askPermissionResolver(7, $action);
            }
        };

        // Act + Assert
        $this->assertNull($controller->askEndpoint('read'));
    }
}
