<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\McpCall;
use Pramnos\Mcp\McpServer;
use Pramnos\Mcp\McpToolInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `mcp:call` — the only way to see what an MCP tool actually returns.
 *
 * `mcp:serve` is not something a person can debug: it speaks JSON-RPC on stdio and blocks
 * on STDIN, so answering "what does this tool return" meant hand-writing an `initialize`
 * frame and a `tools/call` frame, piping them in, and reading one very long line of JSON.
 * A mistake in the frame is indistinguishable from a broken tool, which is the worst
 * property a debugging procedure can have.
 *
 * What is asserted here is what a person reads, and the two ways the command must not
 * mislead: a tool that threw has to look like a failure, and `--arg limit=5` has to reach
 * the tool as a number.
 */
#[CoversClass(McpCall::class)]
class McpCallTest extends TestCase
{
    /**
     * Named `dispatch` rather than `run`: `TestCase::run()` is final, and overriding it is
     * a fatal error rather than a failing test.
     */
    private function dispatch(CallProbe $command, array $input): array
    {
        $output = new BufferedOutput();
        $status = $command->run(new ArrayInput($input), $output);

        return [$status, $output->fetch()];
    }

    /**
     * With no tool named, it lists every tool with the arguments each one takes.
     *
     * The schema rather than only the name: "which tools are there" is immediately
     * followed by "what does this one want", and that answer was otherwise visible only
     * to a client.
     */
    public function testWithNoToolItListsThemWithTheirArguments(): void
    {
        // Arrange
        $command = new CallProbe();

        // Act
        [$status, $text] = $this->dispatch($command, []);

        // Assert
        $this->assertSame(0, $status);
        $this->assertStringContainsString('adder', $text);
        $this->assertStringContainsString('· amount: integer', $text);
        $this->assertStringContainsString('· mode: string (fast|slow)', $text,
            'an enum is the difference between one guess and five');
        $this->assertStringContainsString('takes no arguments', $text,
            'a tool with an empty schema has to say so rather than look truncated');
    }

    /**
     * A tool is called and its output printed.
     */
    public function testItCallsATheToolAndPrintsWhatCameBack(): void
    {
        // Arrange
        $command = new CallProbe();

        // Act
        [$status, $text] = $this->dispatch($command, ['tool' => 'adder', '--arg' => ['amount=2']]);

        // Assert
        $this->assertSame(0, $status);
        $this->assertStringContainsString('"doubled": 4', $text);
    }

    /**
     * `--arg amount=2` reaches the tool as the number 2, not the string "2".
     *
     * A shell has only strings, and every schema with an integer or an array in it would
     * otherwise reject the obvious spelling. `--arg limit=5` failing validation for being
     * `"5"` is precisely the sort of thing that makes a debugging tool useless.
     */
    public function testValuesAreCoercedToWhatTheSchemaMeans(): void
    {
        // Arrange
        $command = new CallProbe();

        // Act
        $this->dispatch($command, [
            'tool'  => 'adder',
            '--arg' => ['amount=2', 'mode=fast', 'loud=true', 'nothing=null', 'files=a.log,b.log'],
        ]);

        // Assert
        $this->assertSame(2, $command->received['amount']);
        $this->assertSame('fast', $command->received['mode']);
        $this->assertTrue($command->received['loud']);
        $this->assertNull($command->received['nothing']);
        $this->assertSame(['a.log', 'b.log'], $command->received['files']);
    }

    /**
     * `--json` takes the whole arguments object, for anything nested.
     *
     * And it wins over `--arg` rather than merging: a half-merged arguments object is a
     * call nobody asked for, and saying which one lost beats guessing.
     */
    public function testJsonTakesTheWholeObjectAndWinsOverArg(): void
    {
        // Arrange
        $command = new CallProbe();

        // Act
        [$status, $text] = $this->dispatch($command, [
            'tool'   => 'adder',
            '--json' => '{"amount": 21, "nested": {"deep": true}}',
            '--arg'  => ['amount=1'],
        ]);

        // Assert
        $this->assertSame(0, $status);
        $this->assertSame(21, $command->received['amount']);
        $this->assertSame(['deep' => true], $command->received['nested']);
        $this->assertStringContainsString('--arg is ignored', $text);
    }

    /**
     * Malformed `--json` says what was wrong with it and calls nothing.
     */
    public function testMalformedJsonIsReportedRatherThanSentOn(): void
    {
        // Arrange
        $command = new CallProbe();

        // Act
        [$status, $text] = $this->dispatch($command, ['tool' => 'adder', '--json' => '{not json']);

        // Assert
        $this->assertSame(1, $status);
        $this->assertStringContainsString('not a JSON object', $text);
        $this->assertNull($command->received, 'nothing was called');
    }

    /**
     * `--arg` without an `=` is rejected, with the offending value quoted back.
     */
    public function testAnArgWithoutAValueIsRejected(): void
    {
        // Arrange
        $command = new CallProbe();

        // Act
        [$status, $text] = $this->dispatch($command, ['tool' => 'adder', '--arg' => ['amount']]);

        // Assert
        $this->assertSame(1, $status);
        $this->assertStringContainsString('key=value', $text);
        $this->assertStringContainsString('amount', $text);
    }

    /**
     * An unknown tool lists the ones that exist.
     *
     * Because the usual cause is a typo or a tool that failed to register, and both are
     * answered by the same list.
     */
    public function testAnUnknownToolNamesTheRealOnes(): void
    {
        // Arrange
        $command = new CallProbe();

        // Act
        [$status, $text] = $this->dispatch($command, ['tool' => 'addr']);

        // Assert
        $this->assertSame(1, $status);
        $this->assertStringContainsString('No tool named addr', $text);
        $this->assertStringContainsString('adder', $text);
    }

    /**
     * A tool that threw is reported as a failure, not as output.
     *
     * This is the one that matters most. An exception inside a tool comes back as a
     * **successful** JSON-RPC response whose content happens to be the exception message
     * — so without this the command would print the message like any other result and
     * exit zero, and the reader would take it for the answer.
     */
    public function testAToolThatThrewLooksLikeAFailure(): void
    {
        // Arrange
        $command = new CallProbe();

        // Act
        [$status, $text] = $this->dispatch($command, ['tool' => 'breaks']);

        // Assert
        $this->assertSame(1, $status, 'a broken tool must not exit zero');
        $this->assertStringContainsString('deliberately broken', $text);
        $this->assertStringContainsString('isError', $text);
    }

    /**
     * `--raw` prints the JSON-RPC envelope, for when the wrapper is the suspect.
     *
     * A tool that works when called directly and misbehaves through the protocol is a
     * real bug, and it is only visible in the envelope.
     */
    public function testRawPrintsTheEnvelope(): void
    {
        // Arrange
        $command = new CallProbe();

        // Act
        [$status, $text] = $this->dispatch($command, ['tool' => 'adder', '--raw' => true]);

        // Assert
        $this->assertSame(0, $status);
        $this->assertStringContainsString('"jsonrpc": "2.0"', $text);
        $this->assertStringContainsString('"result"', $text);
        $this->assertStringContainsString('"isError": false', $text);
    }
}

/**
 * `mcp:call` with a server of two known tools instead of the application's.
 */
class CallProbe extends McpCall
{
    /** @var array<string, mixed>|null What the tool was handed. */
    public ?array $received = null;

    /** The one seam: a server of known tools instead of the application's. */
    protected function server(?\Pramnos\Application\Application $app): McpServer
    {
        $server = new McpServer('Probe', '1.0.0');

        $server->addTool($this->stub(
            'adder',
            'Doubles a number, so a test can watch what reached it.',
            [
                'type' => 'object',
                'properties' => [
                    'amount' => ['type' => 'integer'],
                    'mode'   => ['type' => 'string', 'enum' => ['fast', 'slow']],
                ],
            ]
        ));
        $server->addTool($this->stub('quiet', 'Takes nothing at all.', ['type' => 'object']));
        $server->addTool($this->stub('breaks', 'Always throws.', ['type' => 'object']));

        return $server;
    }

    /** @param array<string, mixed> $schema */
    private function stub(string $name, string $description, array $schema): McpToolInterface
    {
        return new class ($name, $description, $schema, $this) implements McpToolInterface {
            /** @param array<string, mixed> $toolSchema */
            public function __construct(
                private string $toolName,
                private string $toolDescription,
                private array $toolSchema,
                private CallProbe $probe
            ) {
            }

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return $this->toolDescription;
            }

            public function inputSchema(): array
            {
                return $this->toolSchema;
            }

            public function execute(array $input): mixed
            {
                $this->probe->received = $input;

                if ($this->toolName === 'breaks') {
                    throw new \RuntimeException('deliberately broken');
                }

                return ['doubled' => 2 * (int) ($input['amount'] ?? 0)];
            }
        };
    }
}
