<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Mcp\Controllers\McpController;
use Pramnos\Mcp\McpToolInterface;
use Pramnos\Mcp\PublicRegistry;
use Pramnos\Mcp\ScopedMcpTool;
use Pramnos\Mcp\ScopedTool;
use Pramnos\User\Roles;

/**
 * Who sees a public MCP tool, beside its scope, what runs around every call, and what a caller
 * holding the wrong scope is told (FW-158, points 1 and 2).
 *
 * The registry decided by the token's scopes alone, so an application where the role decides had
 * to rebuild the endpoint's server, and wrap every tool for redaction and audit itself. And a
 * tool whose scope was not granted answered "Unknown tool", which tells an assistant nothing it
 * can act on — where "this needs mcp:people" tells it to reconnect.
 */
#[CoversClass(PublicRegistry::class)]
#[CoversClass(ScopedTool::class)]
#[CoversClass(McpController::class)]
class PublicRegistryGateTest extends TestCase
{
    protected function setUp(): void
    {
        PublicRegistry::reset();
    }

    protected function tearDown(): void
    {
        PublicRegistry::reset();
    }

    /** A user of a role: what `Roles::atLeast()` reads. */
    private static function user(int $usertype): object
    {
        return (object) ['userid' => 7, 'usertype' => $usertype];
    }

    /** A plain tool that answers with what it is given. */
    private static function tool(string $name): McpToolInterface
    {
        return new class ($name) implements McpToolInterface {
            public function __construct(private string $toolName)
            {
            }

            /** Its name. */
            public function name(): string
            {
                return $this->toolName;
            }

            /** What it does. */
            public function description(): string
            {
                return 'Answers with its input.';
            }

            /** What it takes. */
            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            /** Its input, and an address to redact. */
            public function execute(array $input): mixed
            {
                return ['said' => 'write to maria@example.com', 'input' => $input];
            }
        };
    }

    /** The names of a list of tools. */
    private static function names(array $tools): array
    {
        return array_map(static fn (ScopedMcpTool $tool): string => $tool->name(), $tools);
    }

    /**
     * A tool wrapped with `minRole:` is seen only from that role up, and a gate decides beside
     * the scope; the scope still decides among what the gates allow.
     */
    public function testRolesAndGatesDecideBesideTheScope(): void
    {
        // Arrange
        PublicRegistry::add(ScopedTool::wrap(self::tool('people'), 'mcp:people', minRole: Roles::ROOT));
        PublicRegistry::add(ScopedTool::wrap(self::tool('visits'), 'mcp'));
        PublicRegistry::add(ScopedTool::wrap(self::tool('beta'), 'mcp'));
        PublicRegistry::gate(static fn (?object $user, ScopedMcpTool $tool): bool => $tool->name() !== 'beta');
        $admin = self::user(90);
        $root  = self::user(100);

        // Act + Assert
        $this->assertSame(['visits'], self::names(PublicRegistry::reachableBy($admin)), 'below root, and the gated tool hidden');
        $this->assertSame(['people', 'visits'], self::names(PublicRegistry::reachableBy($root)));
        $this->assertSame(['visits'], self::names(PublicRegistry::availableTo(['mcp'], $root)), 'root, without the scope');
        $this->assertSame(['people', 'visits'], self::names(PublicRegistry::availableTo(['mcp', 'mcp:people'], $root)));
        $this->assertSame(['visits'], self::names(PublicRegistry::availableTo(['mcp', 'mcp:people'], null)), 'no user passes no role: only the tool that asks none');
    }

    /**
     * The scope a reachable tool lacks is named; one the user may not reach is not, nor one the
     * scopes already permit, nor one that does not exist.
     */
    public function testTheMissingScopeOfAReachableTool(): void
    {
        // Arrange
        PublicRegistry::add(ScopedTool::wrap(self::tool('people'), 'mcp:people', minRole: Roles::ROOT));
        PublicRegistry::add(ScopedTool::wrap(self::tool('visits'), 'mcp'));

        // Act + Assert
        $this->assertSame('mcp:people', PublicRegistry::missingScope('people', ['mcp'], self::user(100)));
        $this->assertNull(PublicRegistry::missingScope('people', ['mcp'], self::user(90)), 'not reachable: not disclosed');
        $this->assertNull(PublicRegistry::missingScope('visits', ['mcp'], self::user(90)));
        $this->assertNull(PublicRegistry::missingScope('nothing', ['mcp'], self::user(100)));
    }

    /**
     * Hooks run around every call, the first added outermost, and see the tool, the input and
     * the user; `redacting()` cleans every string of the result.
     */
    public function testHooksRunAroundEveryCall(): void
    {
        // Arrange
        $seen = [];
        PublicRegistry::add(ScopedTool::wrap(self::tool('visits'), 'mcp'));
        PublicRegistry::around(static function (ScopedMcpTool $tool, array $input, callable $next, ?object $user) use (&$seen): mixed {
            $seen[] = 'outer:' . $tool->name() . ':' . $user?->userid;

            return $next($input + ['outer' => true]);
        });
        PublicRegistry::around(PublicRegistry::redacting());

        // Act
        $result = PublicRegistry::availableTo(['mcp'], self::user(90))[0]->execute(['q' => 1]);

        // Assert
        $this->assertSame(['outer:visits:7'], $seen);
        $this->assertSame(['q' => 1, 'outer' => true], $result['input'], 'the outer hook ran first and passed on');
        $this->assertStringNotContainsString('maria@example.com', $result['said']);
        $this->assertStringContainsString('m***@', $result['said']);
    }

    /**
     * Through the endpoint: a call to a tool the user may reach but the token's scopes do not
     * cover is a `403` naming the scope, not "Unknown tool"; a tool the user may not reach
     * answers as no tool at all, so its existence is not disclosed.
     */
    public function testTheEndpointNamesTheMissingScope(): void
    {
        // Arrange
        PublicRegistry::add(ScopedTool::wrap(self::tool('people'), 'mcp:people', minRole: Roles::ROOT));
        $call = static fn (object $user): object => new class ($user) extends McpController {
            public mixed $body = null;
            public int $status = 0;

            public function __construct(private object $caller)
            {
            }

            /** The signed-in user. */
            protected function authenticatedUser(): ?\Pramnos\User\User
            {
                $user = new \Pramnos\User\User(0);
                $user->userid   = (int) $this->caller->userid;
                $user->usertype = (int) $this->caller->usertype;

                return $user;
            }

            /** An access token, for this endpoint, granted `mcp` only. */
            protected function bearerIsAnAccessToken(): bool
            {
                return true;
            }

            /** Issued for this endpoint. */
            protected function tokenIsForThisEndpoint(): bool
            {
                return true;
            }

            /** @return list<string> */
            protected function scopesOf(): array
            {
                return ['mcp'];
            }

            /** A call to `people`. */
            protected function requestBody(): string
            {
                return (string) json_encode(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'people', 'arguments' => []]]);
            }

            /** The answer, kept. */
            protected function json(mixed $body, int $status = 200): mixed
            {
                $this->body   = $body;
                $this->status = $status;

                return null;
            }
        };
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/api/1.0/mcp';

        // Act
        $root = $call(self::user(100));
        $root->display();
        $admin = $call(self::user(90));
        $admin->display();

        // Assert
        $this->assertSame(403, $root->status);
        $this->assertSame(['error' => 'insufficient_scope', 'scope' => 'mcp:people'], $root->body['error']['data']);
        $this->assertSame(4, $root->body['id']);
        $this->assertSame(200, $admin->status);
        $this->assertStringContainsString('Unknown tool', (string) ($admin->body['error']['message'] ?? ''), 'not reachable: as if there were none');
    }
}
