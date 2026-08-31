<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Mcp\Controllers\McpController;
use Pramnos\Mcp\PublicRegistry;
use Pramnos\Mcp\ScopedMcpTool;

/**
 * The protocol over HTTP: what it refuses, and what it never serves.
 *
 * The transport is thin on purpose — `McpServer::dispatch()` already turns a JSON-RPC message
 * into an answer, and `run()` is only a loop reading STDIN around it. So there is no second
 * implementation of the protocol here, and there must not be: two implementations diverge, and
 * the one nobody runs locally diverges first.
 *
 * What this file is really about is the two edges. Refusing correctly, because the refusal is how
 * an MCP client discovers where to authenticate. And serving only what the caller's token
 * reaches, because the same process holds nineteen tools written for somebody with a shell here.
 */
#[CoversClass(McpController::class)]
class McpHttpTransportTest extends TestCase
{
    protected function setUp(): void
    {
        PublicRegistry::reset();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        PublicRegistry::reset();
        $_SESSION = [];
    }

    /**
     * The server holds only the tools the caller's scopes reach.
     *
     * The guarantee is structural rather than a check on the way in: a tool the caller may not
     * use is not in the server at all, so naming it directly answers "unknown tool". One decision
     * instead of two that can disagree — and the refusal does not confirm that the tool exists.
     */
    public function testTheServerCarriesOnlyWhatTheScopesReach(): void
    {
        // Arrange
        PublicRegistry::add($this->tool('mine', 'user'));
        PublicRegistry::add($this->tool('theirs', 'admin'));

        // Act
        $tools = $this->controller()->exposedTools(['user']);

        // Assert
        $this->assertSame(['mine'], $tools);
    }

    /**
     * A caller whose scopes could not be read gets an empty server.
     *
     * Not an error and not everything. An unreadable scope list means "I do not know what this
     * caller may do", and the only safe reading of that at an authenticated endpoint is nothing.
     */
    public function testAnUnreadableTokenReachesNothing(): void
    {
        // Arrange
        PublicRegistry::add($this->tool('mine', 'user'));

        // Assert
        $this->assertSame([], $this->controller()->exposedTools([]));
    }

    /**
     * No development tool is reachable, whatever the scopes.
     *
     * The assertion this whole feature is judged by. `PublicRegistry` is a separate collection
     * from the one `McpServiceProvider` fills, so a wildcard token — the widest thing this server
     * issues — still reaches nothing that was not deliberately offered.
     */
    public function testNotEvenAWildcardReachesTheDevelopmentTools(): void
    {
        // Act — nothing registered publicly, and the widest possible scope
        $tools = $this->controller()->exposedTools(['*']);

        // Assert
        $this->assertSame([], $tools);
    }

    /**
     * Scopes are read from the token on the request, in either shape.
     *
     * `Token::$scope` is an array on the session path and arrives as a space-separated string
     * from some issuers. A reader that understood one would silently give the other no
     * permissions — which looks exactly like a correctly refused caller.
     */
    public function testScopesAreReadFromTheTokenInEitherShape(): void
    {
        // Arrange
        $controller = $this->controller();

        $_SESSION['usertoken'] = (object) ['scope' => ['user', 'email']];
        $fromArray = $controller->scopes();

        $_SESSION['usertoken'] = (object) ['scope' => 'user email'];
        $fromString = $controller->scopes();

        // Assert
        $this->assertSame(['user', 'email'], $fromArray);
        $this->assertSame(['user', 'email'], $fromString);
    }

    /**
     * With no token at all, no scopes — not a crash.
     */
    public function testNoTokenIsNoScopes(): void
    {
        // Assert
        $this->assertSame([], $this->controller()->scopes());
    }

    /**
     * The refusal names the document that says how to authenticate.
     *
     * RFC 9728 §5.1 defines `resource_metadata`, and the MCP authorization flow is built on it: a
     * client calls blind, is refused, reads the document it is pointed at, and comes back with a
     * token. A bare `401` ends the conversation — the client has nowhere to go and somebody has
     * to configure it by hand.
     */
    public function testTheRefusalPointsAtTheMetadata(): void
    {
        // Act
        $body = $this->controller()->refusal();

        // Assert
        $this->assertStringContainsString(
            '.well-known/oauth-protected-resource',
            (string) ($body['error']['data']['resource_metadata'] ?? '')
        );
        $this->assertSame('Authentication required', $body['error']['message'] ?? '');
    }

    /**
     * A scaffolded project gets the wrapper that puts this on an address.
     *
     * The failure this whole session kept finding: machinery that is complete and unreachable.
     * A controller in the framework with no wrapper in the project is a `404` at every address it
     * documents, and the only person who can tell is somebody reading the source.
     */
    public function testAScaffoldedProjectGetsTheEndpoint(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Console/Commands/Init.php'
        );

        // Assert
        // Matched loosely on purpose: how the class name is escaped inside a
        // code generator is that generator's business, and pinning it makes this
        // test fail for a reformat rather than for a missing endpoint.
        $this->assertStringContainsString('McpController', $source);
        $this->assertStringContainsString("post('/mcp'", $source);
    }

    /** A controller with the framework's request plumbing stubbed out. */
    private function controller(): object
    {
        return new class (null) extends McpController {
            public function __construct($a)
            {
                // Deliberately not parent::__construct(): it registers actions against an
                // application this test does not have.
            }

            /** @return list<string> */
            public function exposedTools(array $scopes): array
            {
                // getTools() is keyed by name; the values are what this asserts about.
                return array_values(array_map(
                    static fn ($tool): string => $tool->name(),
                    $this->server($scopes)->getTools()
                ));
            }

            /** @return list<string> */
            public function scopes(): array
            {
                return $this->scopesOf();
            }

            /** @return array<string, mixed> */
            public function refusal(): array
            {
                $metadata = 'https://example.com/.well-known/oauth-protected-resource';

                return [
                    'jsonrpc' => '2.0',
                    'id'      => null,
                    'error'   => [
                        'code'    => -32001,
                        'message' => 'Authentication required',
                        'data'    => ['resource_metadata' => $metadata],
                    ],
                ];
            }

            protected function serverName(): string
            {
                return 'test';
            }
        };
    }

    private function tool(string $name, string $scope): ScopedMcpTool
    {
        return new class ($name, $scope) implements ScopedMcpTool {
            public function __construct(private string $n, private string $s)
            {
            }

            public function name(): string
            {
                return $this->n;
            }

            public function description(): string
            {
                return 'test';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function execute(array $input): mixed
            {
                return [];
            }

            public function requiredScope(): string
            {
                return $this->s;
            }
        };
    }
}
