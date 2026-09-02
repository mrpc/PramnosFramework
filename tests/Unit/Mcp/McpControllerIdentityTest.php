<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Mcp\Controllers\McpController;

/**
 * Who an MCP call is from, and which actions the controller answers.
 *
 * Six statements across two methods, neither ever executed.
 *
 * `authenticatedUser()` asks the framework who the request turned out to be rather than parsing the
 * bearer header a second time — the middleware has already validated it, and a second reading is a
 * second opinion. The guard is `userid > 0`, so a `User` that loaded nothing is `null` rather than
 * an empty object: an empty `User` is truthy, and every caller checking `if ($user)` would treat an
 * unauthenticated call as authenticated.
 *
 * The constructor registers exactly one action. `POST /mcp` is a single JSON-RPC endpoint, so
 * anything else reaching this controller is a 404 rather than a method somebody can call — which
 * is what stops a URL from addressing a helper on a controller that speaks JSON-RPC.
 */
#[CoversClass(McpController::class)]
class McpControllerIdentityTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['logged'], $_SESSION['uid']);
        Application::getInstance()->currentUser = null;
        parent::tearDown();
    }

    private function controller(): object
    {
        return new class extends McpController {
            public function exposeAuthenticatedUser(): ?\Pramnos\User\User
            {
                return $this->authenticatedUser();
            }

            /** @return list<string> */
            public function exposeActions(): array
            {
                return $this->actions ?? [];
            }
        };
    }

    /**
     * Only `display` is registered.
     *
     * One endpoint, one action. A controller that exposed its helpers as actions would let a URL
     * address them — and the helpers here read tokens and scopes.
     */
    public function testOnlyTheOneEndpointIsAnAction(): void
    {
        // Act
        $actions = $this->controller()->exposeActions();

        // Assert
        $this->assertContains('display', $actions);
        $this->assertNotContains('authenticatedUser', $actions);
        $this->assertNotContains('scopesOf', $actions);
    }

    /**
     * With nobody signed in, the answer is `null`.
     *
     * Not an empty `User`. That object is truthy, so every `if ($user)` downstream would run the
     * authenticated path for a call that presented nothing.
     */
    public function testWithNobodySignedInTheAnswerIsNull(): void
    {
        // Arrange
        Application::getInstance()->currentUser = null;
        unset($_SESSION['logged'], $_SESSION['uid']);

        // Act + Assert
        $this->assertNull($this->controller()->exposeAuthenticatedUser());
    }

    /**
     * A user whose id is `0` is nobody.
     *
     * The state a `User` is in after a lookup that found nothing — which is what a revoked or
     * mistyped token produces. The guard is on the id for exactly this reason.
     */
    public function testAUserWithIdZeroIsNobody(): void
    {
        // Arrange — an object that exists and holds nothing
        $empty = new \Pramnos\User\User();
        $empty->userid = 0;
        Application::getInstance()->currentUser = $empty;
        $_SESSION['logged'] = true;
        $_SESSION['uid']    = 0;

        // Act + Assert
        $this->assertNull(
            $this->controller()->exposeAuthenticatedUser(),
            'a User that loaded nothing was treated as an authenticated caller'
        );
    }

    /**
     * A real account comes back as itself.
     *
     * The control: without it, an `authenticatedUser()` that always answered `null` would satisfy
     * every test above and no MCP call would ever be authorised.
     */
    public function testARealAccountComesBackAsItself(): void
    {
        // Arrange
        $user = new \Pramnos\User\User();
        $user->userid = 4242;
        Application::getInstance()->currentUser = $user;
        $_SESSION['logged'] = true;
        $_SESSION['uid']    = 4242;

        // Act
        $answer = $this->controller()->exposeAuthenticatedUser();

        // Assert
        $this->assertSame($user, $answer);
    }
}
