<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\SpaBuild;
use Pramnos\Console\Commands\SpaCommandBase;
use Pramnos\Console\Commands\SpaDev;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The seams both command doubles need.
 *
 * A trait rather than a shared base class: `spa:dev` and `spa:build` differ in
 * their options and Symfony reads those from the concrete class, so each double
 * has to extend its own command.
 */
trait SpaCommandSeams
{
    /** @var list<string> Commands that would have been run. */
    public array $ran = [];

    public bool $fakeInContainer = true;
    public bool $fakeHasNpm = true;

    /** @var list<int> Exit codes to hand back, in call order. */
    public array $fakeExitCodes = [];

    protected function inContainer(): bool
    {
        return $this->fakeInContainer;
    }

    protected function hasNpm(): bool
    {
        return $this->fakeHasNpm;
    }

    protected function passthru(string $command): int
    {
        $this->ran[] = $command;

        return (int) (array_shift($this->fakeExitCodes) ?? 0);
    }
}

/**
 * The `spa:dev` and `spa:build` shortcuts.
 *
 * Serving and building the front end were npm commands with no presence in
 * `pramnos list`, so they had to be remembered from the docs rather than found
 * in the CLI the rest of the project uses.
 *
 * What is worth testing is not "npm was called" but the three decisions around
 * it, each of which produced a wrong answer in practice: *where* npm comes from
 * (the scaffolded CLI already runs inside the container, so delegating to
 * `./dockernpm` there asks Docker to exec into Docker), *whether the project can
 * be built at all* (a build-less stack has nothing to build), and *what happens
 * when dependencies are missing* (npm's own error names a binary, not an action).
 *
 * The commands are driven through doubles that record the shell command instead
 * of running it — the seam `Serve` uses for the same reason.
 */
#[CoversClass(SpaCommandBase::class)]
#[CoversClass(SpaDev::class)]
#[CoversClass(SpaBuild::class)]
class SpaCommandsTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/pramnos_spa_cmd_' . bin2hex(random_bytes(4));
        mkdir($this->projectDir . '/app', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->projectDir);
    }

    /**
     * Write the project's app.php.
     *
     * @param string $appStyle mvc | spa | hybrid
     * @param string $spaStack svelte | vanilla-vite | vanilla | ''
     */
    private function seedConfig(string $appStyle, string $spaStack = ''): void
    {
        $stack = $spaStack === '' ? '' : "'spa_stack' => '$spaStack', ";
        file_put_contents(
            $this->projectDir . '/app/app.php',
            "<?php\nreturn ['name' => 'Acme', 'app_style' => '$appStyle', $stack];\n"
        );
    }

    /** Pretend the dependencies are installed. */
    private function seedNodeModules(): void
    {
        mkdir($this->projectDir . '/node_modules', 0777, true);
    }

    /**
     * A double of one of the commands, with its side effects captured.
     *
     * @param class-string $class SpaDev::class or SpaBuild::class
     */
    private function doubleOf(
        string $class,
        bool $inContainer = true,
        bool $hasNpm = true,
        array $exitCodes = []
    ): object {
        $double = $class === SpaBuild::class
            ? new class extends SpaBuild { use SpaCommandSeams; }
            : new class extends SpaDev { use SpaCommandSeams; };

        $double->projectRoot     = $this->projectDir;
        $double->fakeInContainer = $inContainer;
        $double->fakeHasNpm      = $hasNpm;
        $double->fakeExitCodes   = $exitCodes;

        // Commands need an application before CommandTester will run them.
        (new Application())->add($double);

        return $double;
    }

    /** Both commands register under their documented names. */
    public function testCommandsAreNamedAsDocumented(): void
    {
        // Act
        $dev   = new SpaDev();
        $build = new SpaBuild();

        // Assert
        $this->assertSame('spa:dev', $dev->getName());
        $this->assertSame('spa:build', $build->getName());
        // `serve` is the word people reach for, and `serve` itself is taken by
        // the PHP server — so the SPA equivalent answers to both.
        $this->assertContains('spa:serve', $dev->getAliases());
        $this->assertNotEmpty($dev->getDescription());
        $this->assertNotEmpty($build->getDescription());
    }

    /**
     * Inside the container npm is run directly, never through ./dockernpm.
     *
     * The scaffolded CLI wrapper is `docker-compose exec -u www-data app php
     * <cli>.php`, so this is the normal case — and the reported failure: the
     * command printed "The app container is not running" from inside that very
     * container, because it had shelled out to a script whose whole job is to
     * enter it.
     */
    public function testInsideTheContainerNpmRunsDirectly(): void
    {
        // Arrange — dockernpm exists and must still be ignored
        $this->seedConfig('spa', 'svelte');
        $this->seedNodeModules();
        file_put_contents($this->projectDir . '/dockernpm', "#!/bin/sh\n");
        $build = $this->doubleOf(SpaBuild::class, inContainer: true);

        // Act
        $tester = new CommandTester($build);
        $exit   = $tester->execute([]);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertCount(1, $build->ran);
        $this->assertStringContainsString('npm run build', $build->ran[0]);
        $this->assertStringNotContainsString('dockernpm', $build->ran[0]);
        // HOME must be writable or npm cannot write its cache: www-data's is not.
        $this->assertStringContainsString('HOME=/tmp', $build->ran[0]);
        // And it runs from the project root, where package.json is.
        $this->assertStringContainsString('cd ' . escapeshellarg($this->projectDir), $build->ran[0]);
    }

    /** On the host, ./dockernpm is preferred: the toolchain lives in the image. */
    public function testOnTheHostDockernpmIsUsedWhenPresent(): void
    {
        // Arrange
        $this->seedConfig('spa', 'svelte');
        $this->seedNodeModules();
        file_put_contents($this->projectDir . '/dockernpm', "#!/bin/sh\n");
        $dev = $this->doubleOf(SpaDev::class, inContainer: false);

        // Act
        (new CommandTester($dev))->execute([]);

        // Assert
        $this->assertStringContainsString('./dockernpm run dev', $dev->ran[0]);
    }

    /** With no dockernpm and no npm anywhere, say so instead of failing obscurely. */
    public function testNoNpmAnywhereIsReportedNotAttempted(): void
    {
        // Arrange
        $this->seedConfig('spa', 'svelte');
        $this->seedNodeModules();
        $build = $this->doubleOf(SpaBuild::class, inContainer: false, hasNpm: false);

        // Act
        $tester = new CommandTester($build);
        $exit   = $tester->execute([]);

        // Assert
        $this->assertSame(1, $exit);
        $this->assertSame([], $build->ran, 'nothing is run');
        $this->assertStringContainsString('docker-compose up -d', $tester->getDisplay());
    }

    /** A container built without node is a rebuild, and the message says which. */
    public function testContainerWithoutNpmPointsAtRebuilding(): void
    {
        // Arrange
        $this->seedConfig('spa', 'svelte');
        $this->seedNodeModules();
        $build = $this->doubleOf(SpaBuild::class, inContainer: true, hasNpm: false);

        // Act
        $tester = new CommandTester($build);
        $exit   = $tester->execute([]);

        // Assert
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('docker-compose build app', $tester->getDisplay());
    }

    /**
     * Missing dependencies are installed, not reported.
     *
     * npm's own error for an absent node_modules names a missing binary several
     * lines down and says nothing about what to do. Installing is what the reader
     * would do next, and it is idempotent.
     */
    public function testMissingDependenciesAreInstalledFirst(): void
    {
        // Arrange — no node_modules
        $this->seedConfig('spa', 'svelte');
        $build = $this->doubleOf(SpaBuild::class);

        // Act
        $tester = new CommandTester($build);
        $exit   = $tester->execute([]);

        // Assert — install, then build, in that order
        $this->assertSame(0, $exit);
        $this->assertCount(2, $build->ran);
        $this->assertStringContainsString('npm install', $build->ran[0]);
        $this->assertStringContainsString('npm run build', $build->ran[1]);
        $this->assertStringContainsString('node_modules is missing', $tester->getDisplay());
    }

    /**
     * A failed install stops there.
     *
     * Building on top of a broken install produces a second, less relevant error
     * about whatever the missing dependency was needed for.
     */
    public function testAFailedInstallAbortsBeforeBuilding(): void
    {
        // Arrange — install returns 1
        $this->seedConfig('spa', 'svelte');
        $build = $this->doubleOf(SpaBuild::class, exitCodes: [1]);

        // Act
        $exit = (new CommandTester($build))->execute([]);

        // Assert
        $this->assertSame(1, $exit);
        $this->assertCount(1, $build->ran, 'the build is not attempted');
    }

    /** The npm exit code is the command's exit code, so CI can see a failure. */
    public function testTheBuildsExitCodeIsReturned(): void
    {
        // Arrange
        $this->seedConfig('spa', 'svelte');
        $this->seedNodeModules();
        $build = $this->doubleOf(SpaBuild::class, exitCodes: [2]);

        // Act / Assert
        $this->assertSame(2, (new CommandTester($build))->execute([]));
    }

    /** --watch passes the flag to Vite rather than to npm, which would eat it. */
    public function testWatchPassesTheFlagThrough(): void
    {
        // Arrange
        $this->seedConfig('spa', 'svelte');
        $this->seedNodeModules();
        $build = $this->doubleOf(SpaBuild::class);

        // Act
        $tester = new CommandTester($build);
        $tester->execute(['--watch' => true]);

        // Assert
        $this->assertStringContainsString('npm run build -- --watch', $build->ran[0]);
        $this->assertStringContainsString('rebuilding on every change', $tester->getDisplay());
    }

    /** An MVC project has no front end to build, and is told that. */
    public function testMvcProjectIsRefusedWithAReason(): void
    {
        // Arrange
        $this->seedConfig('mvc');
        $build = $this->doubleOf(SpaBuild::class);

        // Act
        $tester = new CommandTester($build);
        $exit   = $tester->execute([]);

        // Assert
        $this->assertSame(1, $exit);
        $this->assertSame([], $build->ran);
        $this->assertStringContainsString('no SPA front end', $tester->getDisplay());
    }

    /**
     * A build-less SPA is refused too, with the reason that applies to it.
     *
     * Its sources under www/assets/js/ are served exactly as written, so there is
     * nothing to build and nothing for a dev server to supply. A generic "not
     * supported" would leave the reader unsure which of the two cases they hit.
     */
    public function testBuildlessStackIsRefusedWithItsOwnReason(): void
    {
        // Arrange — app_style spa, but the stack has no build step
        $this->seedConfig('spa', 'vanilla');
        $dev = $this->doubleOf(SpaDev::class);

        // Act
        $tester = new CommandTester($dev);
        $exit   = $tester->execute([]);

        // Assert
        $this->assertSame(1, $exit);
        $this->assertSame([], $dev->ran);
        $this->assertStringContainsString('no build step', $tester->getDisplay());
        $this->assertStringContainsString('www/assets/js/', $tester->getDisplay());
    }

    /** Run from somewhere that is not a project, it says so rather than guessing. */
    public function testOutsideAProjectItSaysSo(): void
    {
        // Arrange — no app/app.php at all
        $build = $this->doubleOf(SpaBuild::class);

        // Act
        $tester = new CommandTester($build);
        $exit   = $tester->execute([]);

        // Assert
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No app/app.php', $tester->getDisplay());
    }

    /**
     * `spa:dev` prints the URL to open, from the port docker-compose publishes.
     *
     * The one thing about this workflow that cannot be guessed is that the Vite
     * port serves no HTML — opening it yields nothing, which reads as a broken dev
     * server. Naming the URL that *does* work is what makes that concrete.
     */
    public function testDevPrintsTheApplicationUrl(): void
    {
        // Arrange
        $this->seedConfig('spa', 'svelte');
        $this->seedNodeModules();
        file_put_contents(
            $this->projectDir . '/docker-compose.yml',
            "services:\n  app:\n    ports:\n      - \"8082:80\"\n      - \"5173:5173\"\n"
        );
        $dev = $this->doubleOf(SpaDev::class);

        // Act
        $tester = new CommandTester($dev);
        $tester->execute([]);

        // Assert
        $display = $tester->getDisplay();
        $this->assertStringContainsString('http://localhost:8082', $display);
        $this->assertStringContainsString('not the Vite port', $display);
    }

    /** A hybrid project mounts the SPA under /app, so that is the URL offered. */
    public function testDevUrlFollowsAHybridMountPoint(): void
    {
        // Arrange
        $this->seedConfig('hybrid', 'svelte');
        $this->seedNodeModules();
        file_put_contents(
            $this->projectDir . '/docker-compose.yml',
            "services:\n  app:\n    ports:\n      - \"8082:80\"\n"
        );
        $dev = $this->doubleOf(SpaDev::class);

        // Act
        $tester = new CommandTester($dev);
        $tester->execute([]);

        // Assert
        $this->assertStringContainsString('http://localhost:8082/app', $tester->getDisplay());
    }

    /**
     * With no port to read, the instruction still has to stand on its own.
     *
     * Inventing a URL would send somebody to a port nothing listens on, which is
     * worse than telling them which one not to use.
     */
    public function testDevWithoutAComposeFileStillWarnsAboutTheVitePort(): void
    {
        // Arrange — no docker-compose.yml
        $this->seedConfig('spa', 'svelte');
        $this->seedNodeModules();
        $dev = $this->doubleOf(SpaDev::class);

        // Act
        $tester = new CommandTester($dev);
        $tester->execute([]);

        // Assert
        $display = $tester->getDisplay();
        $this->assertStringContainsString('not the Vite port', $display);
        $this->assertStringNotContainsString('http://localhost:', $display);
    }

    /** A compose file with no published web port is treated as no URL at all. */
    public function testDevWithoutAPublishedWebPortOffersNoUrl(): void
    {
        // Arrange — a compose file, but nothing mapped to port 80
        $this->seedConfig('spa', 'svelte');
        $this->seedNodeModules();
        file_put_contents(
            $this->projectDir . '/docker-compose.yml',
            "services:\n  app:\n    expose:\n      - \"80\"\n"
        );
        $dev = $this->doubleOf(SpaDev::class);

        // Act
        $tester = new CommandTester($dev);
        $tester->execute([]);

        // Assert
        $this->assertStringNotContainsString('http://localhost:', $tester->getDisplay());
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
