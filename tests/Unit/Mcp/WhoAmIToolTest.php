<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Mcp\PublicRegistry;
use Pramnos\Mcp\Tools\WhoAmITool;

/**
 * The public MCP endpoint's smoke test, and the fact that it has one.
 *
 * The endpoint shipped with an empty tool list, and an empty tool list is
 * indistinguishable from a broken one: a client that connects, authenticates and
 * receives nothing cannot tell whether the wiring works, the token is right, or
 * the scopes are what somebody intended. This tool answers all three and exposes
 * nothing the caller did not already present.
 */
#[CoversClass(WhoAmITool::class)]
class WhoAmIToolTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['logged'], $_SESSION['uid'], $_SESSION['usertoken']);
        Application::getInstance()->currentUser = null;
        PublicRegistry::reset();
        parent::tearDown();
    }

    /**
     * The scope is one every authenticated caller holds.
     *
     * A smoke test guarded by a scope nobody has is not a smoke test — it would
     * be absent from exactly the tool list somebody is trying to debug.
     */
    public function testItAsksForTheBaseScope(): void
    {
        // Act
        $tool = new WhoAmITool();

        // Assert
        $this->assertSame('whoami', $tool->name());
        $this->assertSame('mcp', $tool->requiredScope());
    }

    /**
     * It says what it is for, and takes no input.
     *
     * The description is the entire basis on which a model decides to call a tool,
     * and an empty-properties object rather than an empty array is what keeps the
     * schema encoding as `{}` instead of `[]` — which some clients reject.
     */
    public function testItDescribesItselfAndTakesNoInput(): void
    {
        // Act
        $tool   = new WhoAmITool();
        $schema = $tool->inputSchema();

        // Assert
        $this->assertStringContainsString('scopes', $tool->description());
        $this->assertSame('object', $schema['type']);
        $this->assertInstanceOf(\stdClass::class, $schema['properties']);
        $this->assertSame('{}', json_encode($schema['properties']));
    }

    /**
     * It is registrable on the public endpoint, which the development tools are
     * not — `PublicRegistry` refuses anything that is not a `ScopedMcpTool`, and
     * this is the first thing that satisfies it.
     */
    public function testItIsAcceptedByThePublicRegistry(): void
    {
        // Act
        PublicRegistry::add(new WhoAmITool());

        // Assert
        $visible = PublicRegistry::visibleTo(array('mcp'));
        $this->assertCount(1, $visible);
        $this->assertSame('whoami', $visible[0]->name());
        // and a caller without the scope sees nothing
        $this->assertSame(array(), PublicRegistry::visibleTo(array('something.else')));
    }

    /**
     * A signed-in caller gets their id, their scopes and the fact of being
     * authenticated.
     */
    public function testItReportsTheIdentityAndScopesOnTheToken(): void
    {
        // Arrange
        $user = new \Pramnos\User\User();
        $user->userid = 4242;
        Application::getInstance()->currentUser = $user;
        $_SESSION['logged']    = true;
        $_SESSION['uid']       = 4242;
        $_SESSION['usertoken'] = (object) array('scope' => 'mcp mcp:db_read');

        // Act
        $answer = (new WhoAmITool())->execute(array());

        // Assert
        $this->assertSame(4242, $answer['user_id']);
        $this->assertTrue($answer['authenticated']);
        $this->assertSame(array('mcp', 'mcp:db_read'), $answer['scopes']);
    }

    /**
     * A token carrying its scopes as an array answers the same as one carrying a
     * space-separated string.
     *
     * Both shapes are in use — the League OAuth2 entities hand back objects, and
     * the session copy is a string — and a diagnostic that only understood one of
     * them would report «no scopes» to exactly the caller trying to find out why
     * a tool is missing.
     */
    public function testScopesAsAnArrayAreUnderstoodToo(): void
    {
        // Arrange
        $_SESSION['usertoken'] = (object) array('scope' => array('mcp', 'mcp:db_read'));

        // Act
        $answer = (new WhoAmITool())->execute(array());

        // Assert
        $this->assertSame(array('mcp', 'mcp:db_read'), $answer['scopes']);
    }

    /**
     * With no token in the session the answer is empty rather than an error.
     *
     * "Authenticated, no readable scopes" is a real state and it is the one worth
     * reporting plainly — it is what a caller sees when the middleware validated
     * a credential that carries no scope list.
     */
    public function testNoTokenIsAnEmptyScopeListNotAFailure(): void
    {
        // Arrange
        unset($_SESSION['usertoken']);
        Application::getInstance()->currentUser = null;
        unset($_SESSION['logged'], $_SESSION['uid']);

        // Act
        $answer = (new WhoAmITool())->execute(array());

        // Assert
        $this->assertSame(array(), $answer['scopes']);
        $this->assertFalse($answer['authenticated']);
        $this->assertSame(0, $answer['user_id']);
    }

    /**
     * It answers with no name and no email address.
     *
     * This is a production endpoint whose replies travel, and the caller already
     * knows who they are. An id identifies a row in a log; the rest would be
     * personal data handed out for no purpose — which is the same argument
     * {@see \Pramnos\Security\PersonalDataRegistry} makes about `db-inspect`.
     */
    public function testItRevealsNoPersonalData(): void
    {
        // Arrange
        $user = new \Pramnos\User\User();
        $user->userid = 4242;
        $user->email  = 'someone@example.com';
        $user->username = 'someone';
        Application::getInstance()->currentUser = $user;
        $_SESSION['logged'] = true;
        $_SESSION['uid']    = 4242;

        // Act
        $encoded = json_encode((new WhoAmITool())->execute(array()));

        // Assert
        $this->assertStringNotContainsString('someone@example.com', $encoded);
        $this->assertStringNotContainsString('someone', $encoded);
    }
}
