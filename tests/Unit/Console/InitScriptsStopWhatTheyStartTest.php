<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The helper scripts start the project's containers on demand and stop what they started.
 *
 * `./dockertest` used to start a stack that was down and leave it up for good, and the
 * front-end helpers either refused to run (`dockernpm`) or quietly used the host's node
 * (`testjs`). On a shared development machine that is a database stack holding memory for
 * days after a single test run.
 *
 * The front-end scripts are executed for real against a fake `docker-compose` on `PATH` that
 * records its calls, so the order of `up`, `exec` and `stop` is observed rather than read. The
 * test runners need a real Docker to execute, so their invariant is checked structurally — in
 * the scaffolded copy and in this framework's own, which the scaffolded one follows.
 */
#[\PHPUnit\Framework\Attributes\Group('serial')]
class InitScriptsStopWhatTheyStartTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pf-stop-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir . '/bin', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmpDir));
    }

    /**
     * testjs, with nothing of the project running: it starts the service, runs the tests in
     * it, stops the whole stack afterwards, and exits with the test run's own status.
     *
     * The status matters as much as the stop: the run is made from `||`, where errexit is off,
     * and a failing test run used to fall through to a `return 0` — a red suite reported green.
     */
    public function testTestjsStartsTheStackRunsInItStopsItAndKeepsTheFailingStatus(): void
    {
        // Arrange — nothing running; the test run inside the container fails with 3
        $script = $this->renderStub('spa-testjs', ['testCommand' => 'npm test --']);
        $this->fakeDocker(running: '', execStatus: 3);

        // Act
        [$status, $calls] = $this->runScript($script);

        // Assert
        $this->assertSame(3, $status, 'the test run\'s own status, not the fallback\'s or 0');
        $this->assertSame(['up -d app', 'exec node', 'exec test', 'stop'], $calls);
    }

    /**
     * testjs, with the stack already up: nothing is started and nothing is stopped.
     *
     * A developer who has the application open must not lose it because they ran the tests.
     */
    public function testTestjsLeavesARunningStackAlone(): void
    {
        // Arrange
        $script = $this->renderStub('spa-testjs', ['testCommand' => 'npm test --']);
        $this->fakeDocker(running: "db\napp", execStatus: 0);

        // Act
        [$status, $calls] = $this->runScript($script);

        // Assert
        $this->assertSame(0, $status);
        $this->assertSame(['exec node', 'exec test'], $calls);
    }

    /**
     * testjs, with the database up but the app down: only the app is stopped afterwards.
     *
     * The database was somebody else's — another command started it — so stopping the whole
     * stack would take away what this run did not bring.
     */
    public function testTestjsStopsOnlyTheServiceItStartedBesideARunningDatabase(): void
    {
        // Arrange
        $script = $this->renderStub('spa-testjs', ['testCommand' => 'npm test --']);
        $this->fakeDocker(running: 'db', execStatus: 0);

        // Act
        [, $calls] = $this->runScript($script);

        // Assert
        $this->assertSame(['up -d app', 'exec node', 'exec test', 'stop app'], $calls);
    }

    /**
     * dockernpm, with nothing running: it starts the app, runs npm, stops the stack, and
     * exits with npm's status.
     *
     * The final command is not `exec`'d: `exec` replaces the shell, and the trap that stops
     * the container would never run.
     */
    public function testDockernpmStartsTheAppAndStopsItAfterwards(): void
    {
        // Arrange
        $script = $this->renderStub('dockernpm', ['devPort' => '5173', 'outDir' => 'public/build']);
        $this->fakeDocker(running: '', execStatus: 7);

        // Act
        [$status, $calls] = $this->runScript($script, 'run build');

        // Assert
        $this->assertSame(7, $status, 'npm\'s own status');
        $this->assertSame(['up -d app', 'exec chown', 'exec npm', 'stop'], $calls);
    }

    /**
     * The scaffolded dockertest stops on exit what it started, unless `--keep`.
     *
     * Structural, because running it needs Docker: the flag is parsed, `started_here` is set
     * on the branch that runs `up -d` and nowhere else, and the EXIT trap is the function that
     * stops before it releases the lock.
     */
    public function testTheScaffoldedDockertestStopsWhatItStarted(): void
    {
        // Arrange
        $application = new Application();
        $application->add(new Init());
        $command = $application->find('init');
        $command->targetBaseDir = $this->tmpDir;
        $command->skipDockerRun = true;
        $tester = new CommandTester($command);
        $tester->setInputs(['StopApp', 'StopApp', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '']);
        $tester->execute([], ['interactive' => false]);

        // Act
        $script = (string) @file_get_contents($this->tmpDir . '/dockertest');

        // Assert
        $this->assertStopsWhatItStarted($script);
    }

    /**
     * And this framework's own dockertest, of which the scaffolded one is a copy.
     */
    public function testThisFrameworksOwnDockertestStopsWhatItStarted(): void
    {
        // Arrange / Act
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/dockertest');

        // Assert
        $this->assertStopsWhatItStarted($script);
    }

    /**
     * The three pieces that make a runner stop only what it started.
     */
    private function assertStopsWhatItStarted(string $script): void
    {
        $this->assertNotSame('', $script, 'a dockertest must exist');
        $this->assertStringContainsString('"--keep"', $script, 'the opt-out is parsed');
        // The flag flips immediately before the stack is brought up, so a run that found it
        // running never stops it
        $this->assertMatchesRegularExpression(
            '/Containers not running\. Starting them\.\.\."\n\s*started_here=true\n/',
            $script
        );
        $this->assertSame(1, preg_match_all('/(?<!\w)started_here=true/', $script), 'set on one branch only');
        // The EXIT trap stops, then releases the lock
        $this->assertStringContainsString("trap '_finish' EXIT", $script);
        $this->assertMatchesRegularExpression('/_finish\(\) \{.*docker-compose stop.*_release_lock\n\}/s', $script);
    }

    /**
     * Render a scaffolding stub with the tokens it needs.
     *
     * @param array<string, string> $tokens
     */
    private function renderStub(string $name, array $tokens): string
    {
        $init = new Init();
        $init->scaffoldingDir = dirname(__DIR__, 3) . '/scaffolding';
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $init->renderStub($name, $tokens + ['appName' => 'StopApp']));
        chmod($path, 0755);

        return $path;
    }

    /**
     * Put fake `docker` and `docker-compose` binaries first on PATH.
     *
     * `docker-compose` answers `ps --services --filter status=running` with $running and
     * records every other call, by its first word and — for `exec` — by what it runs, into
     * calls.log. The `exec` that runs the tests or npm exits with $execStatus.
     */
    private function fakeDocker(string $running, int $execStatus): void
    {
        $log = $this->tmpDir . '/calls.log';
        file_put_contents($this->tmpDir . '/bin/docker', "#!/bin/sh\nexit 0\n");
        file_put_contents($this->tmpDir . '/bin/docker-compose', <<<SH
#!/bin/sh
case "\$1" in
  ps) printf '%s' '$running'; [ -n '$running' ] && echo; exit 0 ;;
  up) echo "up \$2 \$3" >> '$log'; exit 0 ;;
  stop) shift; echo "stop \$*" | sed 's/ *\$//' >> '$log'; exit 0 ;;
  exec)
    case "\$*" in
      *"command -v node"*) echo "exec node" >> '$log'; exit 0 ;;
      *chown*) echo "exec chown" >> '$log'; exit 0 ;;
      *npm\ test*) echo "exec test" >> '$log'; exit $execStatus ;;
      *npm*) echo "exec npm" >> '$log'; exit $execStatus ;;
    esac ;;
esac
exit 0
SH);
        chmod($this->tmpDir . '/bin/docker', 0755);
        chmod($this->tmpDir . '/bin/docker-compose', 0755);
    }

    /**
     * Run a script with the fakes on PATH; return its status and the recorded calls.
     *
     * @return array{0: int, 1: list<string>}
     */
    private function runScript(string $script, string $args = ''): array
    {
        $cmd = 'cd ' . escapeshellarg($this->tmpDir)
            . ' && PATH=' . escapeshellarg($this->tmpDir . '/bin') . ':"$PATH" '
            . escapeshellarg($script) . ' ' . $args . ' >/dev/null 2>&1';
        exec($cmd, $out, $status);
        $calls = is_file($this->tmpDir . '/calls.log')
            ? array_values(array_filter(explode("\n", (string) file_get_contents($this->tmpDir . '/calls.log'))))
            : [];

        return [$status, $calls];
    }
}
