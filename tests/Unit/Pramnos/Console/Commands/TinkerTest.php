<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Tinker;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Test-only subclass exposing the fallback REPL's protected building blocks so
 * the deterministic per-line logic can be exercised directly, without a TTY or
 * the blocking STDIN read loop. The overrides are thin public proxies; they add
 * no behaviour of their own.
 */
class TinkerProxy extends Tinker
{
    public function callReplLoop($stream, BufferedOutput $output): int
    {
        return $this->replLoop($stream, $output);
    }

    public function callHandleLine(string $code, BufferedOutput $output): bool
    {
        return $this->handleLine($code, $output);
    }

    public function callEvaluate(string $code, BufferedOutput $output): void
    {
        $this->evaluate($code, $output);
    }

    public function callStdinIsInteractive(): bool
    {
        return $this->stdinIsInteractive();
    }
}

/**
 * Unit tests for the `tinker` console command.
 *
 * `tinker` launches an interactive REPL, so its *interactive* behaviour cannot
 * be exercised in an automated test without a live TTY and human input. The
 * meaningful, deterministic contract we can assert is the **non-interactive
 * guard**: when the command is run without an interactive terminal it must
 *
 *   - detect the non-interactive context,
 *   - print a helpful message explaining a TTY is required, and
 *   - exit cleanly (0) *without ever reading from STDIN*.
 *
 * The last point is critical: if the guard were missing, the fallback REPL
 * would call fgets(STDIN) and the test run would hang forever. Every test here
 * runs via CommandTester with ['interactive' => false] precisely to prove the
 * command returns promptly and never blocks.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(Tinker::class)]
class TinkerTest extends TestCase
{
    private CommandTester $tester;

    /** @var string|null Original $_SERVER['PHP_SELF'] value */
    private ?string $originalPhpSelf = null;

    /**
     * Wire the command into a minimal Symfony Console Application so that
     * getApplication() is non-null and CommandTester can drive it.
     */
    protected function setUp(): void
    {
        // Symfony's DumpCompletionCommand reads $_SERVER['PHP_SELF'] in
        // configure(); ensure it is set to avoid "Undefined array key"
        // warnings on PHP 8.4.
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }

        $command = new Tinker();

        $app = new Application('test', '1.0');
        $app->add($command);
        $app->setAutoExit(false);

        $found = $app->find('tinker');
        $this->tester = new CommandTester($found);
    }

    protected function tearDown(): void
    {
        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        } else {
            $_SERVER['PHP_SELF'] = $this->originalPhpSelf;
        }
    }

    /**
     * Running the command non-interactively must exit cleanly with SUCCESS (0)
     * and must return promptly — it must NOT block waiting on STDIN.
     *
     * This is the core safety guarantee: the non-interactive guard short-circuits
     * before the REPL loop is ever entered.
     */
    public function testNonInteractiveRunExitsCleanlyWithoutBlocking(): void
    {
        // Act — the ['interactive' => false] flag makes InputInterface report
        // isInteractive() === false, exercising the guard branch. If the guard
        // were absent this call would hang on fgets() and the suite would time
        // out.
        $exitCode = $this->tester->execute([], ['interactive' => false]);

        // Assert — clean exit (proves it did not try to read input and error)
        $this->assertSame(Command::SUCCESS, $exitCode, $this->tester->getDisplay());
    }

    /**
     * The non-interactive path must explain why nothing happened, so a user who
     * pipes into `tinker` or runs it in CI gets an actionable message rather
     * than silence.
     */
    public function testNonInteractiveRunPrintsGuidance(): void
    {
        // Act
        $this->tester->execute([], ['interactive' => false]);

        // Assert — the message mentions the interactive-terminal requirement
        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('interactive terminal', $output,
            'Non-interactive run must explain that a TTY is required');
    }

    // -------------------------------------------------------------------------
    // Fallback REPL — per-line handling
    // -------------------------------------------------------------------------

    /**
     * The `exit`/`quit` keywords (with or without a trailing semicolon, in any
     * case) must request that the loop terminate — handleLine() returns false —
     * and must not attempt to evaluate the word as PHP.
     */
    public function testHandleLineExitAndQuitRequestBreak(): void
    {
        // Arrange
        $tinker = new TinkerProxy();

        // Act / Assert — each recognised termination token returns false (break)
        foreach (['exit', 'quit', 'exit;', 'quit;', 'EXIT', 'Quit;'] as $token) {
            $output = new BufferedOutput();
            $continue = $tinker->callHandleLine($token, $output);

            // false means "stop the loop"; nothing should have been evaluated
            $this->assertFalse($continue, "'$token' must break the REPL loop");
            $this->assertSame('', $output->fetch(), 'exit/quit must not evaluate');
        }
    }

    /**
     * A blank line is a no-op: the REPL simply re-prompts. handleLine() must
     * return true (continue) and write nothing.
     */
    public function testHandleLineIgnoresBlankInput(): void
    {
        // Arrange
        $tinker = new TinkerProxy();
        $output = new BufferedOutput();

        // Act
        $continue = $tinker->callHandleLine('', $output);

        // Assert — loop continues and no output was produced
        $this->assertTrue($continue, 'A blank line must keep the loop running');
        $this->assertSame('', $output->fetch());
    }

    /**
     * A non-terminating line must be evaluated and keep the loop alive:
     * handleLine() returns true and the evaluated result is printed.
     */
    public function testHandleLineEvaluatesAndContinues(): void
    {
        // Arrange
        $tinker = new TinkerProxy();
        $output = new BufferedOutput();

        // Act
        $continue = $tinker->callHandleLine('1 + 1', $output);

        // Assert — the loop continues and the expression result is shown
        $this->assertTrue($continue);
        $this->assertStringContainsString('=> 2', $output->fetch());
    }

    // -------------------------------------------------------------------------
    // Fallback REPL — evaluation of a single line
    // -------------------------------------------------------------------------

    /**
     * A scalar expression is evaluated and its value pretty-printed via
     * var_export, proving the happy-path `return (...)` branch and the scalar
     * arm of printResult().
     */
    public function testEvaluateScalarExpression(): void
    {
        // Arrange
        $tinker = new TinkerProxy();
        $output = new BufferedOutput();

        // Act
        $tinker->callEvaluate('2 * 21', $output);

        // Assert — 42 rendered by var_export
        $this->assertStringContainsString('=> 42', $output->fetch());
    }

    /**
     * A null-valued expression takes the dedicated null arm of printResult()
     * and renders as "=> null".
     */
    public function testEvaluateNullExpression(): void
    {
        // Arrange
        $tinker = new TinkerProxy();
        $output = new BufferedOutput();

        // Act
        $tinker->callEvaluate('null', $output);

        // Assert
        $this->assertStringContainsString('=> null', $output->fetch());
    }

    /**
     * An array expression takes the array/object arm of printResult(), which
     * renders via print_r (so the output contains "Array").
     */
    public function testEvaluateArrayExpressionUsesPrintR(): void
    {
        // Arrange
        $tinker = new TinkerProxy();
        $output = new BufferedOutput();

        // Act
        $tinker->callEvaluate('[1, 2, 3]', $output);

        // Assert — print_r representation of an array
        $result = $output->fetch();
        $this->assertStringContainsString('=> Array', $result);
        $this->assertStringContainsString('[0] => 1', $result);
    }

    /**
     * A statement that is NOT a valid expression (so `return (...)` raises a
     * ParseError) must fall through to being executed as a raw statement. A
     * well-formed statement must run silently without printing an error — this
     * proves the ParseError → statement fallback path.
     */
    public function testEvaluateFallsBackToStatementExecution(): void
    {
        // Arrange
        $tinker = new TinkerProxy();
        $output = new BufferedOutput();

        // Act — wrapping this in `return (...)` is a parse error, so it must be
        // executed as a plain statement instead.
        $tinker->callEvaluate('foreach ([1, 2] as $ignored) {}', $output);

        // Assert — the statement ran cleanly: no error surfaced
        $this->assertStringNotContainsString('error', strtolower($output->fetch()));
    }

    /**
     * Input that is a parse error both as an expression and as a statement must
     * be caught and reported (not fatal), proving the inner ParseError catch.
     */
    public function testEvaluateReportsParseError(): void
    {
        // Arrange
        $tinker = new TinkerProxy();
        $output = new BufferedOutput();

        // Act — invalid PHP however it is wrapped
        $tinker->callEvaluate('this is not valid php', $output);

        // Assert — a parse error is reported rather than crashing the process
        $this->assertStringContainsString('Parse error', $output->fetch());
    }

    /**
     * An expression that throws at runtime must be caught by the outer Throwable
     * handler and reported with its class name — the REPL must survive it.
     */
    public function testEvaluateCatchesThrowableFromExpression(): void
    {
        // Arrange
        $tinker = new TinkerProxy();
        $output = new BufferedOutput();

        // Act — intdiv(1, 0) throws DivisionByZeroError (a \Throwable)
        $tinker->callEvaluate('intdiv(1, 0)', $output);

        // Assert — the throwable's class is reported, proving it was caught
        $this->assertStringContainsString('DivisionByZeroError', $output->fetch());
    }

    /**
     * A statement (not an expression) that throws when executed must be caught
     * by the inner Throwable handler in the statement-fallback path and reported
     * with its class name — proving that arm too.
     */
    public function testEvaluateCatchesThrowableFromStatement(): void
    {
        // Arrange
        $tinker = new TinkerProxy();
        $output = new BufferedOutput();

        // Act — `return (if (...) ...)` is a parse error, so this runs as a
        // statement, which then throws at runtime.
        $tinker->callEvaluate('if (true) { throw new \RuntimeException("boom"); }', $output);

        // Assert — the runtime exception from the statement path is reported
        $result = $output->fetch();
        $this->assertStringContainsString('RuntimeException', $result);
        $this->assertStringContainsString('boom', $result);
    }

    // -------------------------------------------------------------------------
    // Fallback REPL — read/eval loop over an injectable stream
    // -------------------------------------------------------------------------

    /**
     * Driving the loop with an in-memory stream (never STDIN, so it cannot
     * block) must: print the fallback banner, evaluate each line, honour an
     * explicit `exit`, print the farewell and return SUCCESS.
     */
    public function testReplLoopEvaluatesLinesAndExitsOnCommand(): void
    {
        // Arrange — a scripted session ending in an explicit exit
        $tinker = new TinkerProxy();
        $output = new BufferedOutput();
        $stream = $this->streamFrom("1 + 1\nexit\n");

        // Act
        $code = $tinker->callReplLoop($stream, $output);
        fclose($stream);

        // Assert — banner, evaluated result, farewell and clean exit code
        $result = $output->fetch();
        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('minimal fallback REPL', $result);
        $this->assertStringContainsString('=> 2', $result);
        $this->assertStringContainsString('Bye.', $result);
    }

    /**
     * When the stream reaches EOF without an explicit exit command (the Ctrl+D
     * case), the loop must terminate gracefully, still printing the farewell.
     */
    public function testReplLoopStopsAtEndOfStream(): void
    {
        // Arrange — no exit/quit line; the stream simply ends
        $tinker = new TinkerProxy();
        $output = new BufferedOutput();
        $stream = $this->streamFrom("2 + 2\n");

        // Act
        $code = $tinker->callReplLoop($stream, $output);
        fclose($stream);

        // Assert — EOF ends the loop cleanly and the last result was evaluated
        $result = $output->fetch();
        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('=> 4', $result);
        $this->assertStringContainsString('Bye.', $result);
    }

    /**
     * Blank lines interleaved with real input must be skipped without error,
     * and `quit` must terminate the loop just like `exit`.
     */
    public function testReplLoopSkipsBlankLinesAndHonoursQuit(): void
    {
        // Arrange — two blank lines, one expression, then quit
        $tinker = new TinkerProxy();
        $output = new BufferedOutput();
        $stream = $this->streamFrom("\n\n3 + 3\nquit\n");

        // Act
        $code = $tinker->callReplLoop($stream, $output);
        fclose($stream);

        // Assert — only the real expression produced a result; quit ended it
        $result = $output->fetch();
        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('=> 6', $result);
        $this->assertStringContainsString('Bye.', $result);
    }

    // -------------------------------------------------------------------------
    // TTY detection helper
    // -------------------------------------------------------------------------

    /**
     * stdinIsInteractive() must always yield a boolean regardless of how STDIN
     * is wired up. This helper feeds the guard in execute(): a false result is
     * what makes the command short-circuit instead of blocking on the REPL. We
     * assert only the type — the concrete value depends on how the suite is
     * launched (piped vs. attached terminal) — while still covering the
     * stream_isatty() branch on supported PHP.
     */
    public function testStdinIsInteractiveReturnsBoolean(): void
    {
        // Arrange
        $tinker = new TinkerProxy();

        // Act
        $isTty = $tinker->callStdinIsInteractive();

        // Assert — always a bool, whatever STDIN happens to be
        $this->assertIsBool($isTty);
    }

    /**
     * Build a readable in-memory stream seeded with the given input, used to
     * drive the REPL loop without ever touching (and blocking on) real STDIN.
     *
     * @return resource
     */
    private function streamFrom(string $input)
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $input);
        rewind($stream);
        return $stream;
    }
}
