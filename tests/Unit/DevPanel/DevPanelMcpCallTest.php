<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\DevPanel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\FeatureRegistry;
use Pramnos\DevPanel\DevPanelController;
use Pramnos\Framework\Factory;
use Pramnos\Mcp\McpServer;
use Pramnos\Mcp\McpToolInterface;

/**
 * `terminate()` without the `exit`, so the endpoint can be called at all.
 *
 * Each of `handleMcpCall()`'s refusals ends in `terminate()`, and the `return` after every one
 * carries the comment «terminate() may be a no-op in tests — never fall through». That comment is
 * a contract, and this class is the other half of it: counting the calls asserts the endpoint
 * stopped where it said it would, which is the difference between a refusal and a refusal followed
 * by the call it refused.
 */
class NonTerminatingDevPanel extends DevPanelController
{
    public int $terminated = 0;

    protected function terminate(): void
    {
        $this->terminated++;
    }
}

/**
 * The MCP tab's AJAX endpoint and its page — 81 statements, never executed.
 *
 * `DevPanelMcpTest` covers what the schema-driven form renders. What had never run is the half
 * that *does* something: the POST that dispatches a tool, and the page that frames it. Which is
 * the half where being wrong matters, because this endpoint runs whatever a project registered —
 * and a project is free to register a tool that writes.
 *
 * Three properties are asserted here that no amount of reading the page can tell you:
 *
 * - **Every refusal stops.** A stale token, arguments that are not JSON, a protocol layer that
 *   threw: each answers and ends. A refusal that fell through to the dispatch below it would be
 *   the CSRF check as decoration.
 * - **The request is returned as faithfully as the response.** Half the value of this screen is
 *   seeing what the form built — `{"limit": "5"}` and `{"limit": 5}` are different calls, and a
 *   schema that rejected the first is otherwise a mystery. The subtle one: PHP decodes `{}` to an
 *   empty *array*, which re-encodes as `[]`, so a page whose whole job is showing what was sent
 *   would show `"arguments": []` for a call that sent an object.
 * - **A tool that threw is not shown as the answer.** `dispatch()` reports a throwing tool as a
 *   *successful* JSON-RPC response whose content is the exception message. Unless that is said out
 *   loud, the page renders a stack trace as though it were the tool's output.
 */
#[CoversClass(DevPanelController::class)]
class DevPanelMcpCallTest extends TestCase
{
    private ?array $savedFeatures = null;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        unset($_SESSION['csrf_token'], $_SESSION['token']);

        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        FeatureRegistry::reset();
        FeatureRegistry::loadFromConfig(['devpanel', 'mcp']);
    }

    protected function tearDown(): void
    {
        FeatureRegistry::reset();
        $_POST = [];
        unset($_SERVER['REQUEST_METHOD']);
    }

    /** A tool the test owns, so nothing here depends on what the framework registers. */
    private function tool(string $name, ?\Throwable $throws = null): McpToolInterface
    {
        return new class ($name, $throws) implements McpToolInterface {
            public function __construct(private string $toolName, private ?\Throwable $throws)
            {
            }

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return 'A tool this test owns.';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']]];
            }

            public function execute(array $input): mixed
            {
                if ($this->throws !== null) {
                    throw $this->throws;
                }

                return ['echoed' => $input];
            }
        };
    }

    /**
     * A controller whose container hands back the given server.
     *
     * `mcpServer()` looks in the container first — the same resolution `mcp:serve` and `mcp:call`
     * do, so that a tool registered in a service provider is the tool this screen calls. Binding
     * one here is also what keeps these tests off whatever the framework's defaults happen to be.
     */
    private function controller(?McpServer $server = null): NonTerminatingDevPanel
    {
        $controller = new NonTerminatingDevPanel();

        if ($server !== null) {
            $container = new class ($server) {
                public function __construct(private McpServer $server)
                {
                }

                public function has(string $id): bool
                {
                    return $id === 'mcp.server';
                }

                public function get(string $id): McpServer
                {
                    return $this->server;
                }
            };

            $controller->application = new class ($container) {
                public function __construct(private object $container)
                {
                }

                public function getContainer(): object
                {
                    return $this->container;
                }
            };
        }

        return $controller;
    }

    /** Run the private endpoint and hand back the decoded body. */
    private function post(NonTerminatingDevPanel $controller): array
    {
        ob_start();
        (new \ReflectionMethod(DevPanelController::class, 'handleMcpCall'))->invoke($controller);
        $body = (string) ob_get_clean();

        $decoded = json_decode($body, true);

        $this->assertIsArray($decoded, 'the endpoint did not answer JSON: ' . substr($body, 0, 200));

        return $decoded;
    }

    private function validToken(): string
    {
        return Factory::getSession()->getCsrfToken();
    }

    // ── The refusals ──────────────────────────────────────────────────────────

    /**
     * A stale token is refused, and nothing is dispatched.
     *
     * A POST that *executes* gets a token even behind the panel's own gate: the other endpoints
     * here read, and this one runs whatever a project registered. The assertion that matters is
     * the absence of `request` — a refusal that answered and then went on to dispatch would look
     * identical to this one from the client's side, and the tool would have run.
     */
    public function testAStaleTokenIsRefusedAndNothingRuns(): void
    {
        // Arrange
        $server = (new McpServer('Test', '1.0'))->addTool($this->tool('probe'));
        $controller = $this->controller($server);
        $_POST = ['csrf' => 'not-the-token', 'tool' => 'probe', 'arguments' => '{}'];

        // Act
        $answer = $this->post($controller);

        // Assert
        $this->assertFalse($answer['ok']);
        $this->assertStringContainsString('reload', strtolower($answer['error']));
        $this->assertArrayNotHasKey('request', $answer, 'the tool ran anyway');
        $this->assertArrayNotHasKey('response', $answer);
        $this->assertSame(1, $controller->terminated, 'the refusal fell through');
    }

    /**
     * A missing token is a stale token, not a special case.
     *
     * The form always sends one, so an absent field means the page was cached, opened from
     * history, or built by something else — and all three want the same answer.
     */
    public function testAMissingTokenIsRefusedToo(): void
    {
        // Arrange
        $controller = $this->controller(new McpServer('Test', '1.0'));
        $_POST = ['tool' => 'probe'];

        // Act
        $answer = $this->post($controller);

        // Assert
        $this->assertFalse($answer['ok']);
        $this->assertSame(1, $controller->terminated);
    }

    /**
     * Arguments that are not JSON are named, with the parser's own message.
     *
     * The arguments arrive as a string from a textarea somebody has been editing, so this is the
     * failure that happens most and the one where a generic "bad request" costs the most time —
     * `json_last_error_msg()` says *where*, and a trailing comma is invisible on a re-read.
     */
    public function testArgumentsThatAreNotJsonAreNamed(): void
    {
        // Arrange
        $controller = $this->controller(new McpServer('Test', '1.0'));
        $_POST = [
            'csrf' => $this->validToken(),
            'tool' => 'probe',
            'arguments' => '{"limit": 5,}',
        ];

        // Act
        $answer = $this->post($controller);

        // Assert
        $this->assertFalse($answer['ok']);
        $this->assertStringContainsString('not a JSON object', $answer['error']);
        $this->assertStringContainsString('Syntax error', $answer['error'], "the parser's own words");
        $this->assertSame(1, $controller->terminated);
    }

    /**
     * A JSON scalar is refused as well, and that is not pedantry.
     *
     * `"5"` parses, so a check for a decode failure alone would let it through — and
     * `params.arguments` has to be an object for the call to be a call at all. The tool would
     * receive `5` where it expected a shape and fail somewhere far less legible.
     */
    public function testAJsonScalarIsRefused(): void
    {
        // Arrange
        $controller = $this->controller(new McpServer('Test', '1.0'));
        $_POST = ['csrf' => $this->validToken(), 'tool' => 'probe', 'arguments' => '"five"'];

        // Act
        $answer = $this->post($controller);

        // Assert
        $this->assertFalse($answer['ok']);
        $this->assertStringContainsString('not a JSON object', $answer['error']);
    }

    /**
     * A protocol layer that throws says so, and hands back the request that broke it.
     *
     * `dispatch()` catches a throwing *tool* and reports it as `isError`, so reaching this branch
     * means the protocol itself broke. Showing that as an empty result is the worst of the
     * available answers: the screen exists to show what happened, and "nothing" is the one thing
     * that never happened.
     */
    public function testAProtocolFailureIsSaidPlainly(): void
    {
        // Arrange
        $server = new class ('Test', '1.0') extends McpServer {
            public function dispatch(array $message): ?array
            {
                throw new \RuntimeException('the envelope was malformed');
            }
        };
        $controller = $this->controller($server);
        $_POST = ['csrf' => $this->validToken(), 'tool' => 'probe', 'arguments' => '{}'];

        // Act
        $answer = $this->post($controller);

        // Assert
        $this->assertFalse($answer['ok']);
        $this->assertStringContainsString('server itself threw', $answer['error']);
        $this->assertStringContainsString('the envelope was malformed', $answer['error']);
        $this->assertSame(
            'probe',
            $answer['request']['params']['name'],
            'the request that broke it is the only clue there was'
        );
        $this->assertSame(1, $controller->terminated);
    }

    // ── The successful exchange ───────────────────────────────────────────────

    /**
     * A call comes back with both halves and how long it took.
     *
     * The request is returned as well as the response, deliberately: `{"limit": "5"}` and
     * `{"limit": 5}` are different calls, and a schema that rejected the first is a mystery
     * without seeing which one went.
     */
    public function testACallReturnsBothHalvesOfTheExchange(): void
    {
        // Arrange
        $server = (new McpServer('Test', '1.0'))->addTool($this->tool('probe'));
        $controller = $this->controller($server);
        $_POST = [
            'csrf' => $this->validToken(),
            'tool' => 'probe',
            'arguments' => '{"limit": 5}',
        ];

        // Act
        $answer = $this->post($controller);

        // Assert
        $this->assertTrue($answer['ok'], json_encode($answer));
        $this->assertFalse($answer['failed']);
        $this->assertSame('tools/call', $answer['request']['method']);
        $this->assertSame(['limit' => 5], $answer['request']['params']['arguments']);
        $this->assertArrayHasKey('response', $answer);
        $this->assertIsFloat($answer['ms'] + 0.0);
        $this->assertSame(1, $controller->terminated);
    }

    /**
     * A call with no arguments shows `{}`, not `[]`.
     *
     * PHP decodes `{}` to an empty *array*, which `json_encode()` turns back into `[]`. On a
     * screen whose entire job is showing what was sent, `"arguments": []` for a call that sent an
     * object is the screen lying about the one thing it is for — and the substitution has to
     * happen *after* dispatch, because the server needs the array.
     */
    public function testACallWithNoArgumentsShowsAnObject(): void
    {
        // Arrange
        $server = (new McpServer('Test', '1.0'))->addTool($this->tool('probe'));
        $controller = $this->controller($server);
        $_POST = ['csrf' => $this->validToken(), 'tool' => 'probe', 'arguments' => '{}'];

        // Act — the raw body, because the distinction is lost by decoding it
        ob_start();
        (new \ReflectionMethod(DevPanelController::class, 'handleMcpCall'))->invoke($controller);
        $body = (string) ob_get_clean();

        // Assert
        $this->assertStringContainsString('"arguments":{}', $body, 'shown as an empty list');
        $this->assertStringNotContainsString('"arguments":[]', $body);
    }

    /**
     * A tool that threw is reported as failed, not as output.
     *
     * The trap the flag exists for: `dispatch()` turns a throwing tool into a *successful*
     * JSON-RPC response whose content is the exception message, so `ok` is true and the page would
     * render a stack trace in the position where the answer goes.
     */
    public function testAToolThatThrewIsFlaggedAsFailed(): void
    {
        // Arrange
        $server = (new McpServer('Test', '1.0'))
            ->addTool($this->tool('boom', new \RuntimeException('the tool gave up')));
        $controller = $this->controller($server);
        $_POST = ['csrf' => $this->validToken(), 'tool' => 'boom', 'arguments' => '{}'];

        // Act
        $answer = $this->post($controller);

        // Assert
        $this->assertTrue($answer['ok'], 'the exchange itself completed');
        $this->assertTrue($answer['failed'], 'a thrown exception was shown as the answer');
    }

    /**
     * An unknown tool is a failure too, and by the other route.
     *
     * `dispatch()` answers a name it does not know with a JSON-RPC `error` rather than an
     * `isError` result, so the flag has to read both — and a typo in a tool name is the most
     * ordinary thing that happens on this screen.
     */
    public function testAnUnknownToolIsFlaggedAsFailed(): void
    {
        // Arrange
        $server = (new McpServer('Test', '1.0'))->addTool($this->tool('probe'));
        $controller = $this->controller($server);
        $_POST = ['csrf' => $this->validToken(), 'tool' => 'porbe', 'arguments' => '{}'];

        // Act
        $answer = $this->post($controller);

        // Assert
        $this->assertTrue($answer['failed'], 'an unknown tool looked like a successful call');
    }

    // ── Which server the screen is looking at ─────────────────────────────────

    /**
     * The container's server wins, because it is the only one an application can add to.
     *
     * The same resolution `mcp:serve` and `mcp:call` perform. If this screen built its own
     * instead, it would show the framework's defaults while a client saw the project's tools —
     * a debugger describing a different server than the one being debugged.
     */
    public function testTheContainersServerIsTheOneShown(): void
    {
        // Arrange
        $server = (new McpServer('The Project', '2.0'))->addTool($this->tool('project-tool'));

        // Act
        $resolved = (new \ReflectionMethod(DevPanelController::class, 'mcpServer'))
            ->invoke($this->controller($server));

        // Assert
        $this->assertSame($server, $resolved);
        $this->assertSame('The Project', $resolved->getName());
    }

    /**
     * With no binding it builds one from the framework's defaults.
     *
     * Which is what makes the panel work with the `mcp` feature switched off — and that is
     * precisely when somebody is looking at this screen wondering why a client cannot see
     * anything. A blank page there would answer the question wrongly.
     */
    public function testWithNoBindingItBuildsTheDefaultServer(): void
    {
        // Act
        $resolved = (new \ReflectionMethod(DevPanelController::class, 'mcpServer'))
            ->invoke($this->controller());

        // Assert
        $this->assertInstanceOf(McpServer::class, $resolved);
        $this->assertNotSame([], $resolved->getTools(), 'the built-in tools were not registered');
    }

    // ── The page ──────────────────────────────────────────────────────────────

    private function render(NonTerminatingDevPanel $controller): string
    {
        return (string) (new \ReflectionMethod(DevPanelController::class, 'renderMcp'))
            ->invoke($controller);
    }

    /**
     * The page names the server and counts what it holds.
     *
     * The counts are the first thing to read when a client sees nothing: zero tools is a
     * registration problem and nine is a client problem, and they are different afternoons.
     */
    public function testThePageNamesTheServerAndCountsItsTools(): void
    {
        // Arrange
        $server = (new McpServer('The Project', '2.0'))
            ->addTool($this->tool('one'))
            ->addTool($this->tool('two'));

        // Act
        $html = $this->render($this->controller($server));

        // Assert
        $this->assertStringContainsString('The Project', $html);
        $this->assertStringContainsString('<td>2</td>', $html, 'the tool count is not on the page');
        $this->assertStringContainsString('one', $html);
        $this->assertStringContainsString('two', $html);
    }

    /**
     * With the feature off, the warning is about *your* tools — not about whether anything serves.
     *
     * The distinction that catches people out. `mcp:serve` works either way: with no
     * container-bound server it builds one from the framework's defaults, so a client sees the
     * built-in tools regardless. What the feature adds is the binding, which is the only place an
     * application can register a tool of its own. A warning that said "no client is being served"
     * would send somebody to debug a client that is working.
     */
    public function testWithTheFeatureOffTheWarningIsAboutTheApplicationsOwnTools(): void
    {
        // Arrange
        FeatureRegistry::reset();
        FeatureRegistry::loadFromConfig(['devpanel']);
        $server = (new McpServer('Test', '1.0'))->addTool($this->tool('probe'));

        // Act
        $html = $this->render($this->controller($server));

        // Assert
        $this->assertStringContainsString('mcp', $html);
        $this->assertStringContainsString('A client still gets the built-in', $html);
        $this->assertStringNotContainsString(
            'no client is being served',
            $html,
            'the warning sends the reader after a working client'
        );
    }

    /** With the feature on, there is no warning to read past. */
    public function testWithTheFeatureOnThereIsNoWarning(): void
    {
        // Arrange
        $server = (new McpServer('Test', '1.0'))->addTool($this->tool('probe'));

        // Act
        $html = $this->render($this->controller($server));

        // Assert
        $this->assertStringNotContainsString('is not in', $html);
    }

    /**
     * A server with no tools says so and stops.
     *
     * It returns before the form-and-script machinery, which is right — a page of scaffolding
     * around nothing reads as a broken screen rather than an empty one — and it is the branch
     * that makes "zero tools" legible instead of blank.
     */
    public function testAServerWithNoToolsSaysSoAndStops(): void
    {
        // Act
        $html = $this->render($this->controller(new McpServer('Test', '1.0')));

        // Assert
        $this->assertStringContainsString('No tools are registered.', $html);
        $this->assertStringNotContainsString('<script', $html, 'scaffolding around nothing');
    }

    /**
     * With tools, the page carries the token the endpoint above demands.
     *
     * The two halves have to agree: the endpoint refuses a POST without a valid token, so a page
     * that rendered without one would give a screen where every call answers "reload" and
     * reloading does not help.
     */
    public function testThePageCarriesTheTokenTheEndpointDemands(): void
    {
        // Arrange
        $server = (new McpServer('Test', '1.0'))->addTool($this->tool('probe'));

        // Act
        $html = $this->render($this->controller($server));

        // Assert
        $this->assertStringContainsString('<script', $html);
        $this->assertStringContainsString(
            htmlspecialchars(Factory::getSession()->getCsrfToken(), ENT_QUOTES),
            $html
        );
    }
}
