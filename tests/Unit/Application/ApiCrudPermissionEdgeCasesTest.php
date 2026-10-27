<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\ApiCrudController;
use Pramnos\Auth\PermissionResolverInterface;

/**
 * Edge cases of the permission chain (`permissionFor()`, `askPermissionStore()`,
 * `askApplicationUser()`, `applicationDeclaresPermission()`, and the organisation-scoped
 * resolver call) that the happy-path tests elsewhere in this neighbourhood do not reach:
 * an anonymous request, and every point where a method the request's user object happens to
 * carry — `hasPermission()`, `getAllPermissions()` — can throw or answer with something that
 * is not what it promised. None of these are hypothetical: `$user` here is whatever the
 * application's own class is, and a generated endpoint cannot assume it behaves.
 */
#[CoversClass(ApiCrudController::class)]
class ApiCrudPermissionEdgeCasesTest extends TestCase
{
    /** @param list<array<string, mixed>> $grants */
    private function controller(?object $user, array $grants = []): object
    {
        return new class ($user, $grants) extends ApiCrudController {
            public function __construct(private readonly ?object $user, private readonly array $grants)
            {
            }

            protected function requestUser(): ?object
            {
                return $this->user;
            }

            protected function resourceName(): string
            {
                return 'invoice';
            }

            protected function permissionResolver(): PermissionResolverInterface
            {
                return new class ($this->grants) implements PermissionResolverInterface {
                    public function __construct(private array $grants) {}

                    public function resolve(int $userId, ?int $appId): array
                    {
                        return ['permissions' => $this->grants];
                    }
                };
            }

            public function exposePermissionFor(string $action): ?bool
            {
                return $this->permissionFor($action);
            }

            public function exposeAskPermissionStore(string $action): ?bool
            {
                return $this->askPermissionStore($action);
            }

            public function exposeAskApplicationUser(string $action): ?bool
            {
                return $this->askApplicationUser($this->user, $action);
            }
        };
    }

    /** An anonymous request has no opinion from `permissionFor()` — nobody to ask about. */
    public function testPermissionForWithNoUserIsNoOpinion(): void
    {
        // Act / Assert
        $this->assertNull($this->controller(null)->exposePermissionFor('read'));
    }

    /** Same guard, one layer down — `askPermissionStore()` itself refuses to ask about nobody. */
    public function testAskPermissionStoreWithNoUserIsNoOpinion(): void
    {
        // Act / Assert
        $this->assertNull($this->controller(null)->exposeAskPermissionStore('read'));
    }

    /** A user object with no `hasPermission()` method at all has nothing to say. */
    public function testAUserWithNoHasPermissionMethodIsNoOpinion(): void
    {
        // Arrange
        $user = (object) ['userid' => 7];

        // Act / Assert
        $this->assertNull($this->controller($user)->exposeAskApplicationUser('read'));
    }

    /** `getAllPermissions()` missing: the permission cannot be confirmed as declared, so nothing is asked. */
    public function testAUserWithNoPermissionRegistryIsNoOpinion(): void
    {
        // Arrange
        $user = new class {
            public int $userid = 7;

            public function hasPermission(string $permission): bool
            {
                return true;
            }
        };

        // Act / Assert
        $this->assertNull($this->controller($user)->exposeAskApplicationUser('read'));
    }

    /** `getAllPermissions()` throwing is treated the same as it not existing: no opinion. */
    public function testAThrowingPermissionRegistryIsNoOpinion(): void
    {
        // Arrange
        $user = new class {
            public int $userid = 7;

            public function hasPermission(string $permission): bool
            {
                return true;
            }

            public static function getAllPermissions(): array
            {
                throw new \RuntimeException('registry unavailable');
            }
        };

        // Act / Assert
        $this->assertNull($this->controller($user)->exposeAskApplicationUser('read'));
    }

    /** `getAllPermissions()` answering with something other than an array is not a declared permission. */
    public function testANonArrayPermissionRegistryIsNoOpinion(): void
    {
        // Arrange
        $user = new class {
            public int $userid = 7;

            public function hasPermission(string $permission): bool
            {
                return true;
            }

            public static function getAllPermissions(): string
            {
                return 'not a list of permissions';
            }
        };

        // Act / Assert
        $this->assertNull($this->controller($user)->exposeAskApplicationUser('read'));
    }

    /** The permission is declared, but asking the user object about it throws: still no opinion. */
    public function testAThrowingHasPermissionIsNoOpinion(): void
    {
        // Arrange
        $user = new class {
            public int $userid = 7;

            public function hasPermission(string $permission): bool
            {
                throw new \RuntimeException('cannot evaluate this permission right now');
            }

            public static function getAllPermissions(): array
            {
                return ['viewInvoice' => 'View an invoice'];
            }
        };

        // Act / Assert
        $this->assertNull($this->controller($user)->exposeAskApplicationUser('read'));
    }

    /**
     * With no application opinion and no modern resolver answer, a legacy ACL table that
     * exists is actually consulted — the installations `legacyAclExists()` exists for.
     *
     * `Permissions::getInstance()` here reads the shared `Factory::getDatabase()` connection,
     * which carries neither `authserver.permissions` nor a `#PREFIX#permissions` table in this
     * suite, so its own real "no store provisioned" path answers null — exercised here rather
     * than a resolver the test writes, on purpose: this path is only reachable with the real
     * class, and only when `legacyAclExists()` says yes.
     */
    public function testALegacyAclTableThatExistsIsActuallyAsked(): void
    {
        // Arrange
        $controller = new class extends ApiCrudController {
            public function __construct() {}

            protected function requestUser(): ?object
            {
                return (object) ['userid' => 7];
            }

            protected function resourceName(): string
            {
                return 'invoice';
            }

            protected function permissionResolver(): PermissionResolverInterface
            {
                return new class implements PermissionResolverInterface {
                    public function resolve(int $userId, ?int $appId): array
                    {
                        return ['permissions' => []];
                    }
                };
            }

            protected function legacyAclExists(): bool
            {
                return true;
            }

            public function exposeAskPermissionStore(string $action): ?bool
            {
                return $this->askPermissionStore($action);
            }
        };

        // Act / Assert
        $this->assertNull($controller->exposeAskPermissionStore('read'));
    }

    /**
     * A resolver that is built successfully but whose `resolve()` call throws is not a
     * decision — distinct from {@see \Pramnos\Tests\Unit\Application\ApiCrudObjectScopedGrantsTest}'s
     * case of `permissionResolver()` itself throwing while being constructed.
     */
    public function testAResolverThatThrowsWhileResolvingIsNoOpinion(): void
    {
        // Arrange
        $controller = new class extends ApiCrudController {
            public function __construct() {}

            protected function requestUser(): ?object
            {
                return (object) ['userid' => 7];
            }

            protected function resourceName(): string
            {
                return 'invoice';
            }

            protected function permissionResolver(): PermissionResolverInterface
            {
                return new class implements PermissionResolverInterface {
                    public function resolve(int $userId, ?int $appId): array
                    {
                        throw new \RuntimeException('authserver schema is unreadable');
                    }
                };
            }

            public function exposeAskPermissionResolver(string $action): ?bool
            {
                return $this->askPermissionResolver(7, $action);
            }
        };

        // Act / Assert
        $this->assertNull($controller->exposeAskPermissionResolver('read'));
    }
}
