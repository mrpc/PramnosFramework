<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The test runner's exit status must be PHPUnit's, not the last thing it did.
 *
 * The scaffolded `dockertest` ends with an `if` that opens the coverage report,
 * and **an `if` whose condition is false exits 0**. PHPUnit's status was never
 * captured, so the script inherited the browser block's — and a suite printing
 * `FAILURES! Tests: 1799 … Failures: 1` answered `echo $?` with `0`.
 *
 * Everything that reads the summary on screen was fine. Everything that does not
 * — a `&&` chain, a git hook, a CI step, an agent running the suite before it
 * commits — was told the run was green. A project on this scaffold shipped a
 * commit that way.
 *
 * The invariant is checked structurally rather than by running the script, which
 * would need Docker: **every** branch that invokes PHPUnit captures `$?` on the
 * very next line, and the script's last statement is `exit $phpunit_status`. A
 * fourth branch added later without a capture fails this.
 */
class InitDockertestExitStatusTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pf-exit-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->tempDir . '/*') as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->tempDir);
    }

    /**
     * The runner `init` writes into every new project.
     */
    public function testTheScaffoldedRunnerExitsWithPhpunitsStatus(): void
    {
        // Arrange
        $application = new Application();
        $application->add(new Init());
        $command = $application->find('init');
        $command->targetBaseDir = $this->tempDir;
        $command->skipDockerRun = true;
        $tester = new CommandTester($command);
        $tester->setInputs(['ExitApp', 'ExitApp', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '']);
        $tester->execute([], ['interactive' => false]);

        // Act
        $script = (string) @file_get_contents($this->tempDir . '/dockertest');

        // Assert
        $this->assertNotSame('', $script, 'init must scaffold a dockertest');
        $this->assertEveryPhpunitCallIsFollowedByACapture($script);
        $this->assertLastStatementIsTheCapturedStatus($script);
    }

    /**
     * And this framework's own, which the scaffolded one is a copy of.
     *
     * Fixing one and not the other is how a runner bug comes back: the next
     * project is generated from whichever copy was missed.
     */
    public function testThisFrameworksOwnRunnerExitsWithPhpunitsStatus(): void
    {
        // Arrange / Act
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/dockertest');

        // Assert
        $this->assertEveryPhpunitCallIsFollowedByACapture($script);
        $this->assertLastStatementIsTheCapturedStatus($script);
    }

    /**
     * Each line that runs PHPUnit is followed by `phpunit_status=$?`.
     *
     * `$?` is the status of the *previous* command, so anything between the two —
     * an `echo`, a `fi`, another command — silently replaces the answer. Hence
     * "the next line", not "somewhere after".
     *
     * Backslash continuations are joined first, so a call split over two lines is
     * one call for this purpose.
     */
    private function assertEveryPhpunitCallIsFollowedByACapture(string $script): void
    {
        $joined = preg_replace('/\\\\\R\s*/', ' ', $script);
        $lines  = preg_split('/\R/', (string) $joined) ?: [];

        $calls = 0;
        foreach ($lines as $i => $line) {
            if (!str_contains($line, 'vendor/bin/phpunit')) {
                continue;
            }
            // Checking that the binary is there is not running it.
            if (str_contains($line, 'test -f') || str_contains($line, '! -f')) {
                continue;
            }

            $calls++;
            $next = trim($lines[$i + 1] ?? '');
            $this->assertSame(
                'phpunit_status=$?',
                $next,
                'a PHPUnit invocation whose status is not captured on the next line: ' . trim($line)
            );
        }

        // Proves the loop above actually looked at something — a script that stopped
        // mentioning phpunit would otherwise pass every assertion in it.
        $this->assertGreaterThanOrEqual(3, $calls, 'expected one invocation per coverage mode');
    }

    /**
     * The script's last statement is the captured status.
     */
    private function assertLastStatementIsTheCapturedStatus(string $script): void
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/', $script) ?: []),
            static fn(string $l): bool => $l !== '' && !str_starts_with($l, '#')
        ));

        $this->assertSame('exit $phpunit_status', end($lines));
    }
}
