<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\ProjectSetup;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `project:setup` brings a cloned checkout up without touching a tracked file.
 *
 * The command exists because of a change made in the same session: the database
 * credentials moved out of three committed files and into `.env`, which is not
 * committed. That is right, and it created a new question — *"I have just cloned this,
 * what do I have to create by hand?"* — that nothing answered. A clone with no `.env`
 * starts a database with no password and cannot connect to it.
 *
 * Every process is stubbed through `skipProcesses`, so these tests assert the two things
 * that are actually this command's own: the file it writes, and the order and conditions
 * of the steps it would run. Whether `docker-compose up` works is Docker's business.
 */
#[CoversClass(ProjectSetup::class)]
class ProjectSetupTest extends TestCase
{
    private string $tmpDir = '';
    private ProjectSetup $command;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pramnos-setup-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir . '/app/config', 0777, true);

        $this->command = new ProjectSetup();
        $this->command->targetBaseDir = $this->tmpDir;
        $this->command->skipProcesses = true;
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->tmpDir);
    }

    /** A checkout as git would leave it: everything but `.env`. */
    private function giveMeACheckout(bool $withDocker = true): void
    {
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'app/thing']));
        file_put_contents($this->tmpDir . '/app/config/settings.php', "<?php\nreturn [];\n");
        file_put_contents($this->tmpDir . '/.env.example', implode("\n", [
            'APP_DEBUG=false',
            'APP_DB_TYPE=postgresql',
            'APP_DB_HOST=db',
            'APP_DB_NAME=thing_db',
            'APP_DB_USER=thing',
            'APP_DB_PASSWORD=',
            'APP_DB_PREFIX=',
            'UID=1000',
            'GID=1000',
        ]));

        if ($withDocker) {
            file_put_contents(
                $this->tmpDir . '/docker-compose.yml',
                "services:\n  app:\n    ports:\n      - \"8099:80\"\n"
            );
        }
    }

    /**
     * Execute the command. Named `invoke`, not `run`: `TestCase::run()` is final, and
     * overriding it is a fatal at class-load time — the same collision `ProjectSetup`
     * hit with `Command::run()`, in the same session.
     *
     * @param array<string,mixed> $args
     * @param list<string>        $answers
     */
    private function invoke(array $args = [], array $answers = []): CommandTester
    {
        $app = new Application();
        $app->add($this->command);

        $tester = new CommandTester($this->command);
        if ($answers !== []) {
            $tester->setInputs($answers);
        }
        $tester->execute($args, $answers === [] ? ['interactive' => false] : []);

        return $tester;
    }

    // ── Preconditions ───────────────────────────────────────────────────────

    /**
     * It refuses a directory that is not a project, and says what to run instead.
     *
     * Every step after this either starts containers or execs into them. In the wrong
     * directory that is not merely useless — `docker-compose up` there either fails
     * confusingly or starts somebody else's environment.
     */
    public function testItRefusesADirectoryThatIsNotAProject(): void
    {
        // Act — nothing scaffolded.
        $tester = $this->invoke();

        // Assert
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('does not look like a Pramnos project', $tester->getDisplay());
        $this->assertStringContainsString('init', $tester->getDisplay(),
            'the message has to name the command that does apply');
    }

    /**
     * A project with no `.env.example` is told why this cannot help it.
     *
     * That is a project scaffolded before the credentials moved: its settings are still
     * in `app/config/settings.php`, so there is no file for this step to create and
     * nothing for it to prompt about. Saying so beats writing an empty `.env` that
     * changes nothing.
     */
    public function testAProjectWithoutAnExampleIsToldWhy(): void
    {
        // Arrange
        $this->giveMeACheckout();
        unlink($this->tmpDir . '/.env.example');

        // Act
        $tester = $this->invoke();

        // Assert
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('app/config/settings.php', $tester->getDisplay());
    }

    // ── .env ────────────────────────────────────────────────────────────────

    /**
     * `.env` is created from the example, with the password filled in.
     *
     * The one value only the operator knows. Everything else is copied, because
     * everything else is already correct in the committed example.
     */
    public function testItWritesEnvWithTheGivenPassword(): void
    {
        // Arrange
        $this->giveMeACheckout();

        // Act
        $this->invoke(['--db-pass' => 'sekrit', '--no-migrations' => true]);

        // Assert
        $env = (string) file_get_contents($this->tmpDir . '/.env');
        $this->assertStringContainsString('APP_DB_PASSWORD=sekrit', $env);
        $this->assertStringContainsString('APP_DB_NAME=thing_db', $env, 'non-secret values are copied through');
    }

    /**
     * The host user ids are read from this machine, not copied from the example.
     *
     * The example carries `1000` — the first non-root user on a Debian host, and wrong
     * on plenty of others. Copying it means everything the container writes into the
     * bind mount is owned by somebody who is not you, which is exactly the state the
     * scaffolder went to trouble to avoid.
     */
    public function testItFillsInThisMachinesUserIds(): void
    {
        // Arrange
        $this->giveMeACheckout();
        $expected = \Pramnos\Console\Commands\Init::hostUserIds();

        // Act
        $this->invoke(['--db-pass' => 'x', '--no-migrations' => true]);

        // Assert
        $env = (string) file_get_contents($this->tmpDir . '/.env');
        $this->assertStringContainsString('UID=' . $expected['UID'], $env);
        $this->assertStringContainsString('GID=' . $expected['GID'], $env);
    }

    /**
     * An existing `.env` is never overwritten without being asked.
     *
     * It is the one file in the project that is not in version control, so this is the
     * only edit here that `git checkout` cannot undo.
     */
    public function testAnExistingEnvIsLeftAlone(): void
    {
        // Arrange
        $this->giveMeACheckout();
        file_put_contents($this->tmpDir . '/.env', "APP_DB_PASSWORD=mine\n");

        // Act
        $tester = $this->invoke(['--no-migrations' => true]);

        // Assert
        $this->assertSame("APP_DB_PASSWORD=mine\n", (string) file_get_contents($this->tmpDir . '/.env'));
        $this->assertStringContainsString('left alone', $tester->getDisplay());
        $this->assertStringContainsString('--force-env', $tester->getDisplay(),
            'and it has to say how to override, or the escape hatch is undiscoverable');
    }

    /**
     * `--force-env` rewrites it.
     */
    public function testForceEnvRewritesIt(): void
    {
        // Arrange
        $this->giveMeACheckout();
        file_put_contents($this->tmpDir . '/.env', "APP_DB_PASSWORD=stale\n");

        // Act
        $this->invoke(['--force-env' => true, '--db-pass' => 'fresh', '--no-migrations' => true]);

        // Assert
        $env = (string) file_get_contents($this->tmpDir . '/.env');
        $this->assertStringContainsString('APP_DB_PASSWORD=fresh', $env);
        $this->assertStringNotContainsString('stale', $env);
    }

    /**
     * With no password given, it says the file still needs one.
     *
     * The one thing that can be wrong after this command reports success. Without the
     * warning the next symptom is "authentication failed", which points at the database
     * rather than at a file nobody mentioned.
     */
    public function testItWarnsWhenThePasswordIsStillBlank(): void
    {
        // Arrange
        $this->giveMeACheckout();

        // Act — non-interactive, so the question returns nothing.
        $tester = $this->invoke(['--no-migrations' => true]);

        // Assert
        $this->assertStringContainsString('fill in .env', $tester->getDisplay());
    }

    // ── The steps ───────────────────────────────────────────────────────────

    /**
     * The environment steps run in the order that makes them work.
     *
     * The order is the substance of the command, and two of the four gaps matter:
     * dependencies before migrations, because migrations run the project's own CLI and
     * need an autoloader; and the database wait before them too, because
     * `docker-compose up -d` returns as soon as containers are *created* and a fresh
     * volume takes seconds more to accept a connection. Migrating into that window fails
     * with a connection error that reads like a configuration mistake.
     */
    public function testTheStepsRunInAnOrderThatWorks(): void
    {
        // Arrange
        $this->giveMeACheckout();

        // Act
        $display = $this->invoke(['--db-pass' => 'x', '--no-admin' => true])->getDisplay();

        // Assert — each step is present…
        foreach (['docker-compose up -d --build', 'composer install', 'migrate --scope=framework'] as $step) {
            $this->assertStringContainsString($step, $display);
        }

        // …and in this order, with the wait in between.
        $this->assertLessThan(
            strpos($display, 'composer install'),
            strpos($display, 'docker-compose up -d --build')
        );
        $this->assertLessThan(
            strpos($display, 'migrate --scope=framework'),
            strpos($display, 'composer install')
        );
        $this->assertLessThan(
            strpos($display, 'migrate --scope=framework'),
            strpos($display, 'the database to accept connections')
        );
    }

    /**
     * The migration runs the project's own CLI, found by reading the checkout.
     *
     * Every project names its entry point after itself, so it cannot be assumed — and a
     * wrong guess turns this step into "file not found", which reads as a broken
     * framework rather than a wrong name.
     */
    public function testItFindsTheProjectsOwnCliName(): void
    {
        // Arrange — an entry point identifiable the way the real ones are.
        $this->giveMeACheckout();
        file_put_contents(
            $this->tmpDir . '/thing.php',
            "<?php\n\$consoleApp->internalApplication->init(ROOT . '/app/config/settings.php');\n"
        );

        // Act
        $display = $this->invoke(['--db-pass' => 'x', '--no-admin' => true])->getDisplay();

        // Assert
        $this->assertStringContainsString('php thing.php migrate --scope=framework', $display);
    }

    /**
     * `--no-docker` skips everything that needs a container, and says so.
     *
     * For a host running its own stack. Silently doing nothing would be the same output
     * as success.
     */
    public function testNoDockerSkipsTheContainerSteps(): void
    {
        // Arrange
        $this->giveMeACheckout();

        // Act
        $display = $this->invoke(['--no-docker' => true, '--db-pass' => 'x'])->getDisplay();

        // Assert
        $this->assertStringContainsString('Skipping Docker', $display);
        $this->assertStringNotContainsString('docker-compose up', $display);
        $this->assertStringNotContainsString('migrate --scope=framework', $display);
    }

    /**
     * A project with no `docker-compose.yml` is treated the same way.
     *
     * Not every project is a Docker one, and the absence of the file is a clearer signal
     * than any flag.
     */
    public function testAProjectWithNoComposeFileSkipsDocker(): void
    {
        // Arrange
        $this->giveMeACheckout(withDocker: false);

        // Act
        $display = $this->invoke(['--db-pass' => 'x'])->getDisplay();

        // Assert
        $this->assertStringContainsString('Skipping Docker', $display);
    }

    /**
     * The front end is built only when there is one.
     *
     * An `npm install` in a project with no `package.json` is a confusing failure in the
     * middle of an otherwise successful run.
     */
    public function testTheFrontEndIsBuiltOnlyWhenThereIsOne(): void
    {
        // Arrange
        $this->giveMeACheckout();

        // Act — no package.json yet.
        $without = $this->invoke(['--db-pass' => 'x', '--no-admin' => true])->getDisplay();

        // Assert
        $this->assertStringNotContainsString('npm install', $without);

        // Arrange — now there is one, with a build script.
        unlink($this->tmpDir . '/.env');
        file_put_contents(
            $this->tmpDir . '/package.json',
            json_encode(['scripts' => ['build' => 'vite build']])
        );

        // Act
        $this->command = new ProjectSetup();
        $this->command->targetBaseDir = $this->tmpDir;
        $this->command->skipProcesses = true;
        $with = $this->invoke(['--db-pass' => 'x', '--no-admin' => true])->getDisplay();

        // Assert
        $this->assertStringContainsString('npm install', $with);
        $this->assertStringContainsString('npm run build', $with);
    }

    /**
     * The closing summary names the URL the project is actually served at.
     *
     * Read out of `docker-compose.yml`, because the port is per-project and "it is up"
     * without an address is a small cruelty.
     */
    public function testItReportsTheUrlFromTheComposeFile(): void
    {
        // Arrange
        $this->giveMeACheckout();

        // Act
        $display = $this->invoke(['--db-pass' => 'x', '--no-admin' => true])->getDisplay();

        // Assert
        $this->assertStringContainsString('http://localhost:8099', $display);
        $this->assertStringContainsString('.env', $display, 'and where the configuration lives');
    }

    /**
     * `--dry-run` changes nothing on disk.
     *
     * Including `.env`, which is the only file this command ever writes — so a dry run
     * that created it would be the one thing a dry run must not do.
     */
    public function testDryRunWritesNothing(): void
    {
        // Arrange
        $this->giveMeACheckout();

        // Act
        $tester = $this->invoke(['--dry-run' => true, '--db-pass' => 'x']);

        // Assert
        $this->assertFileDoesNotExist($this->tmpDir . '/.env');
        $this->assertStringContainsString('would write', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }
}
