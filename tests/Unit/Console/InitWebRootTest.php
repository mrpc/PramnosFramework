<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `pramnos init --web-root`.
 *
 * `www` was hardcoded in 38 places, and a consumer reported it through the one that hurt: the
 * SPA build wrote to `www/assets/spa` whatever the project's real document root was. A project
 * served from anywhere else built its front end into a directory nothing serves — and the
 * symptom is a **blank page**, not an error, because the manifest is simply not where the shell
 * looks for it.
 *
 * The reason this class exists rather than one assertion about `outDir`: a half-applied option
 * is worse than no option. If the directory moves and the `.htaccess`, the favicons, the API
 * entry point or the Docker `DocumentRoot` stay behind, the result is a project that looks
 * configured and is broken in a way the configuration appears to explain. So these tests
 * scaffold with a non-default root and assert on the **tree**, not on the flag.
 */
class InitWebRootTest extends TestCase
{
    /** @var string Temporary project root */
    private string $tmpDir;

    /** @var Init The command under test */
    private Init $command;

    /**
     * Creates an isolated project root.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pramnos_webroot_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));

        $this->command                 = new Init();
        $this->command->targetBaseDir  = $this->tmpDir;
        $this->command->skipDockerRun  = true;
        $this->command->scaffoldingDir = dirname(__DIR__, 3) . '/scaffolding';
    }

    /**
     * Removes the temporary tree.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmpDir));
    }

    /**
     * Scaffolds a project.
     *
     * @param array<string, mixed> $options Merged over the non-interactive set
     * @return CommandTester The tester
     */
    private function scaffold(array $options = []): CommandTester
    {
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        $tester->execute(array_merge([
            '--app-name'      => 'RootApp',
            '--namespace'     => 'RootApp',
            '--features'      => '',
            '--ui-system'     => 'plain-css',
            '--docker'        => 'y',
            '--docker-port'   => '8080',
            '--cache-system'  => 'none',
            '--libraries'     => '',
            '--db-type'       => 'mysql',
            '--db-host'       => 'db',
            '--db-name'       => 'root_db',
            '--db-user'       => 'root',
            '--db-pass'       => 'secret',
            '--db-prefix'     => '',
            '--rest-api'      => 'y',
            '--api-docs'      => 'n',
            '--webhook'       => 'y',
            '--app-style'     => 'spa',
            '--spa-stack'     => 'svelte',
            '--no-install'    => true,
            '--no-download'   => true,
            '--no-migrations' => true,
        ], $options));

        return $tester;
    }

    /**
     * The default is unchanged: `www`.
     *
     * Asserted first, because the option is only safe if it changes nothing when absent.
     */
    public function testTheDefaultWebRootIsStillWww(): void
    {
        // Act
        $this->scaffold();

        // Assert
        $this->assertFileExists($this->tmpDir . '/www/index.php');
        $this->assertDirectoryDoesNotExist($this->tmpDir . '/public');
    }

    /**
     * With `--web-root=public`, every scaffolded path moves — and nothing is left behind.
     *
     * The list is deliberately broad. Each entry is a file that a half-applied option would
     * leave in `www/` while the server looked in `public/`, and each would fail differently:
     * a missing front controller is a 404 on everything, a missing `.htaccess` is a 404 on every
     * deep link only, missing favicons are silent, and the SPA build output is a blank page.
     */
    public function testEveryScaffoldedPathFollowsTheWebRoot(): void
    {
        // Act
        $this->scaffold(['--web-root' => 'public']);

        // Assert — the new root has what the server needs
        foreach ([
            '/public/index.php',
            '/public/.htaccess',
            '/public/assets/css',
            '/public/assets/js',
            '/public/api/index.php',
            '/public/api/.htaccess',
            '/public/webhook.php',
        ] as $path) {
            $this->assertFileExists(
                $this->tmpDir . $path,
                $path . ' should follow --web-root; a path left behind is a silent 404.'
            );
        }

        // Assert — and nothing was scaffolded into the old default
        $this->assertDirectoryDoesNotExist(
            $this->tmpDir . '/www',
            'A partially applied --web-root is worse than none: the project looks configured.'
        );
    }

    /**
     * The SPA build output follows it too — the finding that prompted the option.
     *
     * `outDir` in `vite.config.js` is where the front end is written, and the manifest path is
     * where the shell looks for it. If those disagree with the document root the page is blank
     * with nothing in any log.
     */
    public function testTheSpaBuildOutputFollowsTheWebRoot(): void
    {
        // Act
        $this->scaffold(['--web-root' => 'public']);

        // Assert
        $vite = (string) file_get_contents($this->tmpDir . '/vite.config.js');
        $this->assertStringContainsString(
            'public/assets/spa',
            $vite,
            'outDir must point inside the configured document root.'
        );
        $this->assertStringNotContainsString('www/assets/spa', $vite);
    }

    /**
     * The Docker document root follows it.
     *
     * Otherwise the container serves a directory the scaffold never created, which presents as
     * a working `docker-compose up` and a 403 or a directory listing.
     */
    public function testTheDockerDocumentRootFollowsTheWebRoot(): void
    {
        // Act
        $this->scaffold(['--web-root' => 'public']);

        // Assert
        $dockerfile = (string) file_get_contents($this->tmpDir . '/Dockerfile');
        $this->assertStringContainsString('/var/www/html/public', $dockerfile);
        $this->assertStringNotContainsString('/var/www/html/www', $dockerfile);
    }

    /**
     * `.gitignore` ignores build output at the new path.
     *
     * A generated directory that is not ignored gets committed, and the next person to pull gets
     * somebody else's build.
     */
    public function testGitignoreFollowsTheWebRoot(): void
    {
        // Act
        $this->scaffold(['--web-root' => 'public']);

        // Assert
        $gitignore = (string) file_get_contents($this->tmpDir . '/.gitignore');
        $this->assertStringContainsString('public/assets/spa', $gitignore);
    }

    /**
     * A root given with slashes or whitespace is normalised rather than refused.
     *
     * `--web-root=/public/` is what somebody types, and turning that into `//public//index.php`
     * would be a worse answer than either accepting it or rejecting it.
     */
    public function testTheWebRootIsNormalised(): void
    {
        // Act
        $this->scaffold(['--web-root' => '  /public/  ']);

        // Assert
        $this->assertFileExists($this->tmpDir . '/public/index.php');
    }

    /**
     * An empty value falls back to the default rather than scaffolding into the project root.
     *
     * `--web-root=` is easy to produce from a shell variable that did not expand, and writing a
     * front controller and an `.htaccess` into the repository root would be difficult to undo.
     */
    public function testAnEmptyWebRootFallsBackToTheDefault(): void
    {
        // Act
        $this->scaffold(['--web-root' => '']);

        // Assert
        $this->assertFileExists($this->tmpDir . '/www/index.php');
        $this->assertFileDoesNotExist($this->tmpDir . '/index.php');
    }
}
