<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\InternalPermissions;
use Pramnos\Auth\PermissionResolver;
use Pramnos\Auth\PermissionResolverInterface;
use Pramnos\Auth\WebhookService;

/**
 * A resolver double an application (e.g. one adding licensing) could inject.
 */
class LicensingResolver implements PermissionResolverInterface
{
    public function resolve(int $userId, ?int $appId): array
    {
        return ['resources' => []];
    }
}

/**
 * Exposes and overrides the InternalPermissions resolver seam.
 */
class SeamProbeController extends InternalPermissions
{
    public PermissionResolverInterface $injected;

    protected function resolver(): PermissionResolverInterface
    {
        return $this->injected;
    }

    public function callResolver(): PermissionResolverInterface
    {
        return $this->resolver();
    }
}

/**
 * Verifies the extension seams the framework promises so that an application
 * (e.g. an auth server adding licensing) can extend authorization WITHOUT
 * forking the framework:
 *
 *   1. Permission resolution is behind an interface and the internal endpoint
 *      resolves it through an overridable seam — so a licensing-aware resolver
 *      can be substituted.
 *   2. The invalidation mechanism accepts arbitrary, app-defined event types.
 *
 * These are design invariants (§0 of the auth design): if any regresses, the
 * licensing layer would need a fork.
 */
class ExtensionSeamsTest extends TestCase
{
    /**
     * The concrete resolver implements the public interface, so an application
     * can decorate/replace it behind the same contract.
     */
    public function testPermissionResolverImplementsPublicInterface(): void
    {
        $ref = new \ReflectionClass(PermissionResolver::class);
        $this->assertTrue(
            $ref->implementsInterface(PermissionResolverInterface::class),
            'PermissionResolver must implement PermissionResolverInterface so it can be swapped/decorated'
        );
    }

    /**
     * InternalPermissions resolves its resolver through an overridable seam, so
     * an application can inject a licensing-aware resolver without forking.
     */
    public function testInternalPermissionsResolverSeamIsOverridable(): void
    {
        // The seam must exist and be non-private (overridable by a subclass).
        $method = new \ReflectionMethod(InternalPermissions::class, 'resolver');
        $this->assertFalse($method->isPrivate(), 'resolver() must be overridable (protected), not private');
        $this->assertTrue(
            $method->getReturnType() instanceof \ReflectionNamedType
                && $method->getReturnType()->getName() === PermissionResolverInterface::class,
            'resolver() must return the interface type, not the concrete class'
        );

        // And overriding it actually swaps the resolver the endpoint uses.
        $probe = (new \ReflectionClass(SeamProbeController::class))->newInstanceWithoutConstructor();
        $probe->injected = new LicensingResolver();
        $this->assertInstanceOf(
            LicensingResolver::class,
            $probe->callResolver(),
            'an application must be able to substitute its own resolver via the seam'
        );
    }

    /**
     * The webhook invalidation mechanism accepts an arbitrary event-type string
     * (no enum/whitelist), so licensing changes can trigger app-defined events
     * such as `plan_changed` alongside the built-in `permissions_changed`.
     */
    public function testQueueEventAcceptsArbitraryEventType(): void
    {
        $param = (new \ReflectionMethod(WebhookService::class, 'queueEvent'))->getParameters()[0];
        $this->assertSame('eventType', $param->getName());
        $type = $param->getType();
        $this->assertTrue(
            $type instanceof \ReflectionNamedType && $type->getName() === 'string',
            'queueEvent() must accept an arbitrary event-type string (no enum), so apps can define their own events'
        );
    }
}
