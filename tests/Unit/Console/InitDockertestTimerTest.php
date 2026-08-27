<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The bash `timeout` fallback must not hold a command substitution open.
 *
 * `timeout` is GNU coreutils and macOS does not ship it, so the scaffolded runner
 * falls back to a bash implementation: it starts the command in the background and
 * a timer subshell alongside it, then waits for whichever finishes first.
 *
 * The timer inherited stdout. A command substitution — and the runner's preflight
 * uses one, `ps_out=$(timeout … docker-compose ps)` — does not return when its
 * command exits; it returns when every process holding the write end of the pipe
 * has let go. The timer holds it, and the timer sleeps for the whole budget. So a
 * `docker-compose ps` that answered in 90 ms cost the full 45 seconds, and a run
 * that executed no tests at all took 45.9 s before PHPUnit was even started.
 *
 * The redirection that was there sent the timer's *stderr* to /dev/null, which
 * silences its noise and leaves open the descriptor that actually matters.
 */
class InitDockertestTimerTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pf-timer-' . bin2hex(random_bytes(6));
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
     * The scaffolded runner's timer sends both streams away.
     */
    public function testTheScaffoldedTimerReleasesStdout(): void
    {
        // Arrange
        $application = new Application();
        $application->add(new Init());
        $command = $application->find('init');
        $command->targetBaseDir = $this->tempDir;
        $command->skipDockerRun = true;
        $tester = new CommandTester($command);
        $tester->setInputs(['TimerApp', 'TimerApp', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '']);
        $tester->execute([], ['interactive' => false]);

        // Act
        $script = (string) @file_get_contents($this->tempDir . '/dockertest');
        $this->assertNotSame('', $script, 'init must scaffold a dockertest');

        // Assert
        $this->assertStringContainsString('>/dev/null 2>&1 &', $script,
            "the timer must release stdout, or a command substitution waits for the whole budget");
        $this->assertStringNotContainsString(") 2>/dev/null &", $script,
            'stderr-only redirection leaves the descriptor that matters open');
    }

    /**
     * And so does this framework's own runner.
     *
     * The scaffolded one is a copy of it; fixing one and not the other is how the
     * bug came back the first time.
     */
    public function testThisFrameworksOwnRunnerReleasesStdout(): void
    {
        // Arrange
        $path = dirname(__DIR__, 3) . '/dockertest';

        // Act
        $script = (string) file_get_contents($path);

        // Assert
        $this->assertStringContainsString('>/dev/null 2>&1 &', $script);
        $this->assertStringNotContainsString(") 2>/dev/null &", $script);
    }

    /**
     * The behaviour itself: a fast command inside a command substitution returns
     * fast.
     *
     * The assertions above are on text; this one runs the code. A 20-second budget
     * against a command that answers instantly — if the timer holds the pipe, this
     * takes 20 seconds and fails.
     */
    public function testAFastCommandInACommandSubstitutionReturnsFast(): void
    {
        if (!function_exists('shell_exec')) {
            $this->markTestSkipped('shell_exec is disabled');
        }

        // Arrange — the fallback, lifted from this framework's own runner
        $runner = dirname(__DIR__, 3) . '/dockertest';
        $script = $this->tempDir . '/probe.sh';
        file_put_contents($script, <<<SH
        #!/usr/bin/env bash
        eval "\$(sed -n '/^_bash_timeout()/,/^}/p' '$runner')"
        timeout() { _bash_timeout "\$@"; }
        out=\$(timeout 20 echo hello)
        echo "\$out"
        SH);

        // Act
        $start = microtime(true);
        $output = trim((string) shell_exec('bash ' . escapeshellarg($script) . ' 2>/dev/null'));
        $elapsed = microtime(true) - $start;

        // Assert
        $this->assertSame('hello', $output, 'the command substitution must capture the output');
        $this->assertLessThan(
            5.0,
            $elapsed,
            sprintf('took %.1f s against a 20 s budget — the timer is holding the pipe', $elapsed)
        );
    }
}
