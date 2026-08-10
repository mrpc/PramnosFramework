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
