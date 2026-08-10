<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\ApiCrudController;

/**
 * A controller whose permission lookup is fed by the test rather than a database.
 */
class ProbeCrudController extends ApiCrudController
{
    /** @var bool|null What the permission store "says" for the next question. */
    public ?bool $decision = null;

    /** @var list<string> Actions the store was asked about. */
    public array $asked = [];

    public function __construct()
    {
        // Deliberately not calling the parent constructor: this exercises the
        // authorisation logic, which needs no application, database or request.
    }

    protected function permissionFor(string $action): ?bool
    {
        $this->asked[] = $action;

        return $this->decision;
    }

    /** Expose the guard for assertions. */
    public function check(string $action): ?array
    {
        return $this->guard($action);
    }

    /** Expose the derived resource name. */
    public function resource(): string
    {
        return $this->resourceName();
    }
}

/**
 * A controller with the real permission lookup, over a store that can be
 * declared present or absent.
 */
class StoreAwareCrudController extends ApiCrudController
{
    /** @var bool Whether a permission store exists for this test. */
    public bool $storePresent = true;

    /** @var bool Whether the store was reached. */
    public bool $consulted = false;

    public function __construct() {}

    /** @var bool|null What the ACL "says" when it is consulted. */
    public ?bool $storeDecision = null;

    /** Stand in for the permission systems, recording that they were asked. */
    protected function askPermissionStore(string $action): ?bool
    {
        if (!$this->storePresent) {
            return null;
        }
        $this->consulted = true;

        return $this->storeDecision;
    }

    public function check(string $action): ?array
    {
        return $this->guard($action);
    }
}

/**
 * A controller that declares its resource explicitly.
 */
class NamedCrudController extends ApiCrudController
{
    protected string $resource = 'invoices';

    public function __construct() {}

    public function resource(): string
    {
        return $this->resourceName();
    }
}


/**
 * A user shaped like the reference production application's: named permission
 * flags, a registry that declares them, and hasPermission() reading the flag.
 */
class ApplicationUser
{
    public int $userid = 5;
    public int $usertype = 10;
    public int $viewCustomer = 1;
    public int $deleteCustomer = 0;

    /** @param string $permission */
    public function hasPermission($permission): bool
    {
        if ($this->usertype > 89 || $this->usertype === 1) {
            return true;
        }

        return isset($this->$permission) && $this->$permission === 1;
    }

    /** @return array<string, array<string, string>> */
    public static function getAllPermissions(): array
    {
        return ['Actions' => [
            'viewCustomer'   => 'View Customer',
            'editCustomer'   => 'Edit Customer',
            'createCustomer' => 'Create Customer',
            'deleteCustomer' => 'Delete Customer',
        ]];
    }
}

/**
 * A user with no permission scheme of its own.
 */
class PlainUser
{
    public int $userid = 5;
}

/**
 * A controller guarding the resource that application declares.
 */
class CustomerCrudController extends ApiCrudController
{
    protected string $resource = 'customer';

    public function __construct() {}

    /** The framework systems are silent in these tests. */
    protected function askPermissionResolver(int $userId, string $action): ?bool
    {
        return null;
    }

    protected function legacyAclExists(): bool
    {
        return false;
    }

    public function check(string $action): ?array
    {
        return $this->guard($action);
    }
}

/**
 * The same controller for a resource the application never heard of.
 */
class UnknownEntityCrudController extends CustomerCrudController
{
    protected string $resource = 'thing';
}

/**
 * Covers authorisation for generated API CRUD controllers.
 *
 * The generated actions used to repeat "is there a session user with id >= 2"
 * — authentication, not authorisation. Every signed-in user could list, edit
 * and delete every record of every entity, and `delete` carried **no check at
 * all**: an API key alone was enough to destroy data. The decision is now one
 * overridable method per action, backed by the permission store.
 */
#[CoversClass(ApiCrudController::class)]
class ApiCrudControllerTest extends TestCase
{
    /** @var array<string, mixed> Session as it was before the test */
    private array $originalSession = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalSession = $_SESSION ?? [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->originalSession;
        parent::tearDown();
    }

    /**
     * Sign a user in for the duration of a test.
     */
    private function signIn(int $userid): void
    {
        $user = new \stdClass();
        $user->userid = $userid;
        $_SESSION['user'] = $user;
    }

    /**
     * An anonymous request is refused with 401 — "sign in", not "you may not".
     */
    public function testAnonymousRequestsAreUnauthenticated(): void
    {
        // Arrange
        $controller = new ProbeCrudController();

        // Act
        $result = $controller->check('list');

        // Assert
        $this->assertSame(401, $result['status']);
        $this->assertSame('not_authenticated', $result['error']);
        $this->assertSame([], $controller->asked, 'permissions are not consulted for an anonymous user');
    }

    /**
     * User 1 is the anonymous/system account, so it is not a signed-in user —
     * the same rule the generated controllers applied inline.
     */
    public function testTheSystemUserDoesNotCountAsSignedIn(): void
    {
        // Arrange
        $this->signIn(1);
        $controller = new ProbeCrudController();

        // Act + Assert
        $this->assertSame(401, $controller->check('list')['status']);
    }

    /**
     * With no permission rule at all, a signed-in user is allowed.
     *
     * This is the compatibility guarantee: a project that has granted nothing
     * behaves exactly as it did before this class existed. Anything stricter
     * would lock every existing project out of its own API.
     */
    public function testNoRuleMeansAuthenticationIsEnough(): void
    {
        // Arrange
        $this->signIn(7);
        $controller = new ProbeCrudController();
        $controller->decision = null;

        // Act + Assert
        $this->assertNull($controller->check('list'), 'no rule must not mean "denied"');
    }

    /**
     * An explicit deny is honoured, and answered with 403.
     *
     * 401 and 403 are different answers: a client that gets 401 tries to sign
     * in again, which for a permission problem loops forever.
     */
    public function testAnExplicitDenyIsForbiddenNotUnauthenticated(): void
    {
        // Arrange
        $this->signIn(7);
        $controller = new ProbeCrudController();
        $controller->decision = false;

        // Act
        $result = $controller->check('delete');

        // Assert
        $this->assertSame(403, $result['status']);
        $this->assertSame('forbidden', $result['error']);
    }

    /**
     * An explicit allow passes.
     */
    public function testAnExplicitAllowPasses(): void
    {
        // Arrange
        $this->signIn(7);
        $controller = new ProbeCrudController();
        $controller->decision = true;

        // Act + Assert
        $this->assertNull($controller->check('update'));
    }

    /**
     * Each action asks about itself, so a project can grant read without
     * granting delete — the entire point of moving the check per action.
     */
    public function testEachActionIsAskedAboutSeparately(): void
    {
        // Arrange
        $this->signIn(7);
        $controller = new ProbeCrudController();

        // Act
        foreach (['list', 'read', 'create', 'update', 'delete'] as $action) {
            $controller->check($action);
        }

        // Assert
        $this->assertSame(['list', 'read', 'create', 'update', 'delete'], $controller->asked);
    }


    /**
     * With no permission store, a signed-in user is allowed — and the store is
     * not consulted at all.
     *
     * This is the regression that made a fresh project unusable. The legacy ACL
     * reports a failed lookup as `false`, which is indistinguishable from a
     * deny; a project that never enabled permissions has no table, so every
     * lookup failed, so every action was refused. A brand-new project's admin
     * screen told its own administrator "You do not have permission to see
     * this".
     */
    public function testAMissingPermissionStoreMeansNoOpinionNotDenial(): void
    {
        // Arrange
        $this->signIn(7);
        $controller = new StoreAwareCrudController();
        $controller->storePresent = false;

        // Act
        $result = $controller->check('users');

        // Assert
        $this->assertNull($result, 'a project without permissions must keep working');
        $this->assertFalse($controller->consulted, 'a store that cannot answer is not asked');
    }

    /**
     * With a store present but no rule, the answer is still "allowed" — but the
     * store IS consulted, which is what makes adding a rule take effect.
     */
    public function testAPresentStoreIsConsultedAndSilenceStillAllows(): void
    {
        // Arrange
        $this->signIn(7);
        $controller = new StoreAwareCrudController();
        $controller->storePresent  = true;
        $controller->storeDecision = null;

        // Act
        $result = $controller->check('users');

        // Assert
        $this->assertNull($result);
        $this->assertTrue($controller->consulted);
    }

    /**
     * A store that says no is obeyed — the whole point of consulting it.
     */
    public function testAPresentStoreCanStillDeny(): void
    {
        // Arrange
        $this->signIn(7);
        $controller = new StoreAwareCrudController();
        $controller->storePresent  = true;
        $controller->storeDecision = false;

        // Act
        $result = $controller->check('users');

        // Assert
        $this->assertSame(403, $result['status']);
    }


    /**
     * An application's own permission scheme is honoured.
     *
     * The reference production application declares named flags on the user
     * (`viewCustomer`, `editCustomer`, …), lists them in
     * `User::getAllPermissions()` and asks `hasPermission()`. A generated
     * endpoint that ignored that would be a hole in an otherwise guarded
     * application, so it is consulted first — and CRUD actions map onto the
     * verbs such schemes use: list and read are "view", update is "edit".
     */
    public function testTheApplicationsOwnPermissionSchemeIsHonoured(): void
    {
        // Arrange — this user may view customers and nothing else
        $_SESSION['user'] = new ApplicationUser();
        $controller = new CustomerCrudController();

        // Act + Assert
        $this->assertNull($controller->check('list'), 'viewCustomer is granted');
        $this->assertNull($controller->check('read'), 'read maps to view');
        $this->assertSame(403, $controller->check('update')['status'], 'update maps to edit');
        $this->assertSame(403, $controller->check('create')['status']);
        $this->assertSame(403, $controller->check('delete')['status']);
    }

    /**
     * A resource the application never declared gets no opinion, not a denial.
     *
     * Schemes like this read an undefined flag as "no", so asking about an
     * entity the application has never heard of would refuse every generated
     * CRUD until somebody added a column — turning a new feature into a silent
     * 403. Only declared permissions are asked about.
     */
    public function testAnUndeclaredResourceFallsThroughInsteadOfBeingDenied(): void
    {
        // Arrange
        $_SESSION['user'] = new ApplicationUser();
        $controller = new UnknownEntityCrudController();

        // Act + Assert
        $this->assertNull($controller->check('list'), 'an unknown entity must not be denied by accident');
    }

    /**
     * A user class with no scheme of its own is not interrogated at all.
     */
    public function testAUserWithoutAPermissionSchemeIsNotAsked(): void
    {
        // Arrange
        $_SESSION['user'] = new PlainUser();
        $controller = new CustomerCrudController();

        // Act + Assert
        $this->assertNull($controller->check('delete'));
    }

    /**
     * The resource name defaults to the controller's own name, which is what
     * the generated controllers rely on, and can be declared explicitly.
     */
    public function testResourceNameDefaultsToTheControllerName(): void
    {
        // Act + Assert
        $this->assertSame('probecrudcontroller', (new ProbeCrudController())->resource());
        $this->assertSame('invoices', (new NamedCrudController())->resource());
    }
}
