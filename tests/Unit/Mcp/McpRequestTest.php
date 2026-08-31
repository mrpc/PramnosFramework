<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Mcp\Controllers\McpController;
use Pramnos\Mcp\PublicRegistry;

/**
 * The request path itself — what `display()` answers, not what its helpers return.
 *
 * The first tests for this class exercised `server()` and `scopesOf()` and a hand-written stand-in
 * for the refusal. They covered the pieces and left the request uncovered, which is the half that
 * matters: every branch a caller can actually reach lives in `display()`.
 */
#[CoversClass(McpController::class)]
class McpRequestTest extends TestCase
{
    protected function setUp(): void
    {
        PublicRegistry::reset();
        $_SESSION = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function tearDown(): void
    {
        PublicRegistry::reset();
        $_SESSION = [];
    }

    /**
     * A `GET` is told what this endpoint is, not given a stack trace.
     *
     * Somebody opening the URL in a browser, or a client probing for the streaming transport this
     * does not implement. Both are better served by an answer than by an empty page — and the
     * answer names the discovery document, so a probing client has somewhere to go.
     */
    public function testAGetIsAnsweredRatherThanBroken(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'GET';

        // Act
        $answer = $this->controller('')->display();

        // Assert
        $this->assertSame(405, $answer['status']);
        $this->assertSame('method_not_allowed', $answer['body']['error']);
        $this->assertStringContainsString('oauth-protected-resource', $answer['body']['resource']);
    }

    /**
     * An unauthenticated call is refused with the address of the document that says how.
     *
     * That header is the discovery mechanism: a client calls blind, is refused, reads what it is
     * pointed at, and comes back with a token. A bare 401 ends the conversation.
     */
    public function testAnUnauthenticatedCallIsPointedAtTheMetadata(): void
    {
        // Act
        $answer = $this->controller('{"jsonrpc":"2.0","id":1,"method":"tools/list"}', authenticated: false)
            ->display();

        // Assert
        $this->assertSame(401, $answer['status']);
        $this->assertSame('Authentication required', $answer['body']['error']['message']);
        $this->assertStringContainsString(
            'oauth-protected-resource',
            $answer['body']['error']['data']['resource_metadata']
        );
    }

    /**
     * A body that is not JSON gets JSON-RPC's own parse error.
     *
     * With a null id, because there is no message to read one from.
     */
    public function testAnUnparseableBodyIsAParseError(): void
    {
        // Act
        $answer = $this->controller('not json at all')->display();

        // Assert
        $this->assertSame(400, $answer['status']);
        $this->assertSame(-32700, $answer['body']['error']['code']);
        $this->assertNull($answer['body']['id']);
    }

    /**
     * A notification — a message with no id — gets no reply, by specification.
     *
     * `202` says it arrived without inventing a body the client would try to parse.
     */
    public function testANotificationGetsNoBody(): void
    {
        // Act
        $answer = $this->controller('{"jsonrpc":"2.0","method":"notifications/initialized"}')->display();

        // Assert
        $this->assertSame(202, $answer['status']);
        $this->assertNull($answer['body']);
    }

    /**
     * A real call gets a real answer, and the tool list is the caller's.
     *
     * The end-to-end shape: authenticated, parsed, dispatched, answered — with the scopes on the
     * token deciding what is in the list.
     */
    public function testAToolsListReturnsWhatTheScopesReach(): void
    {
        // Arrange
        PublicRegistry::offer(
            name: 'mine', scope: 'user', description: 'Mine.',
            input: [], handler: static fn (): int => 1,
        );
        PublicRegistry::offer(
            name: 'theirs', scope: 'admin', description: 'Theirs.',
            input: [], handler: static fn (): int => 1,
        );

        $_SESSION['usertoken'] = (object) ['scope' => ['user']];

        // Act
        $answer = $this->controller('{"jsonrpc":"2.0","id":7,"method":"tools/list"}')->display();

        // Assert
        $names = array_column($answer['body']['result']['tools'] ?? [], 'name');
        $this->assertSame(['mine'], $names);
        $this->assertSame(7, $answer['body']['id']);
    }

    /**
     * And a tool the scopes do not reach is unknown, not forbidden.
     *
     * The server is built per request holding only what the caller may use, so naming another one
     * directly cannot invoke it — and the refusal does not confirm that it exists.
     */
    public function testAToolOutsideTheScopesIsUnknown(): void
    {
        // Arrange
        PublicRegistry::offer(
            name: 'theirs', scope: 'admin', description: 'Theirs.',
            input: [], handler: static fn (): int => 1,
        );

        $_SESSION['usertoken'] = (object) ['scope' => ['user']];

        // Act
        $answer = $this->controller(
            '{"jsonrpc":"2.0","id":9,"method":"tools/call","params":{"name":"theirs"}}'
        )->display();

        // Assert
        $this->assertArrayHasKey('error', $answer['body']);
        $this->assertStringNotContainsString('forbidden', strtolower(json_encode($answer['body'])));
    }

    /**
     * A controller whose body, identity and JSON writer are all given.
     */
    private function controller(string $body, bool $authenticated = true): object
    {
        return new class ($body, $authenticated) extends McpController {
            public function __construct(private string $body, private bool $authenticated)
            {
                // Deliberately not parent::__construct(): it registers actions against an
                // application this test does not have.
            }

            protected function requestBody(): string
            {
                return $this->body;
            }

            protected function authenticatedUser(): ?\Pramnos\User\User
            {
                if (!$this->authenticated) {
                    return null;
                }

                $user = new \Pramnos\User\User();
                $user->userid = 42;

                return $user;
            }

            protected function serverName(): string
            {
                return 'test';
            }

            /** Capture instead of writing, so the answer can be asserted on. */
            protected function json(mixed $body, int $status = 200): mixed
            {
                return ['status' => $status, 'body' => $body];
            }
        };
    }
}
