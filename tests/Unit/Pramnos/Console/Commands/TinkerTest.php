<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Tinker;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

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
}
