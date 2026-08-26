<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A scaffolded project gets a working cross-entity search box, not the parts for one.
 *
 * The feature spans four files that have to agree: `app/search.php` declares the
 * sources, `routes.php` exposes the endpoint, the theme header renders the box, and
 * `pf-utils.js` gives it behaviour. Any one of them missing produces something that
 * looks finished — a search box that does nothing, or an endpoint nothing calls — so
 * each is asserted here rather than trusted to the diff of whichever one was edited last.
 *
 * The condition on the box is asserted too. It renders only for a user who can reach the
 * admin section and only when something is registered, because the endpoint is
 * permission-checked: a box that always answers 403 is worse than no box.
 */
#[CoversClass(Init::class)]
class InitSearchWiringTest extends TestCase
{
    private string $tmpDir = '';
    private Init $command;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pramnos-search-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0777, true);
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));

        $this->command = new Init();
        $this->command->targetBaseDir = $this->tmpDir;
        $this->command->skipDockerRun = true;
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

    /** @param array<string,mixed> $extra */
    private function scaffold(array $extra = []): void
    {
        $app = new Application();
        $app->add($this->command);
        (new CommandTester($this->command))->execute(array_merge([
            '--app-name'    => 'Search App',
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace'   => 'SearchApp',
            '--features'    => 'auth',
            '--ui-system'   => 'tailwind',
            '--docker'      => 'n',
            '--libraries'   => '',
            '--db-type'     => 'postgresql',
            '--db-host'     => 'localhost',
            '--db-name'     => 'search_db',
            '--db-user'     => 'search',
            '--db-pass'     => 'pass',
            '--db-prefix'   => '',
        ], $extra), ['interactive' => false]);
    }

    private function read(string $relative): string
    {
        return (string) @file_get_contents($this->tmpDir . '/' . $relative);
    }

    /**
     * `app/search.php` exists, is valid PHP, and registers the one entity the framework
     * owns.
     *
     * Registering `User` matters more than it looks: an omnibox that starts out searching
     * nothing at all is indistinguishable from a broken one, so the first thing anybody
     * would do is conclude the feature does not work.
     */
    public function testTheDefinitionsFileIsWrittenAndRegistersUsers(): void
    {
        // Act
        $this->scaffold();

        // Assert
        $file = $this->tmpDir . '/app/search.php';
        $this->assertFileExists($file);

        $contents = (string) file_get_contents($file);
        $this->assertStringContainsString('use Pramnos\Search\Registry;', $contents);
        $this->assertStringContainsString("Registry::register('Users'", $contents);
        $this->assertStringContainsString("'display' => ['username', 'email']", $contents);

        // Valid PHP: a definitions file that does not parse takes down every request
        // that renders the header, not only the search box.
        $this->assertSame(
            0,
            $this->lintExitCode($file),
            'app/search.php must parse: it is required on any request that renders the box'
        );
    }

    /**
     * Without the auth feature nothing is registered, and the file still parses.
     *
     * `User` is the framework's entity, so a project with no auth has no framework
     * source to offer. The file is still written, because it is where the developer adds
     * their own — and an empty registry means no box rather than an error.
     */
    public function testWithoutAuthTheFileIsWrittenButRegistersNothing(): void
    {
        // Act
        $this->scaffold(['--features' => '']);

        // Assert
        $file = $this->tmpDir . '/app/search.php';
        $this->assertFileExists($file);

        $contents = (string) file_get_contents($file);
        $this->assertStringNotContainsString("Registry::register('Users'", $contents);
        $this->assertStringContainsString('Nothing is registered yet', $contents);
        $this->assertSame(0, $this->lintExitCode($file));
    }

    /**
     * The endpoint is routed.
     *
     * A registry and a component with no route between them is the shape of a feature
     * that was assembled but never connected.
     */
    public function testTheSearchEndpointIsRouted(): void
    {
        // Act
        $this->scaffold();

        // Assert
        $routes = $this->read('src/Api/routes.php');
        $this->assertStringContainsString("'/admin/search'", $routes);
        $this->assertStringContainsString('->search();', $routes);
    }

    /**
     * The theme header renders the box, guarded on both conditions.
     */
    public function testTheThemeHeaderRendersTheBoxOnlyWhenItCanWork(): void
    {
        // Act
        $this->scaffold();

        // Assert
        $header = $this->read('app/themes/default/header.php');
        $this->assertStringContainsString('\Pramnos\Html\SearchBox()', $header);
        // Both gates: an admin-reachable user, and something registered to search.
        $this->assertStringContainsString('NavSection::Admin->value', $header);
        $this->assertStringContainsString('\Pramnos\Search\Registry::loadDefinitions()', $header);
    }

    /**
     * The behaviour ships with the page that renders the box.
     *
     * `pf-utils.js` is loaded by every scaffolded theme, which is why the handler lives
     * there rather than in a file of its own: no extra tag, no second CSP source, and it
     * cannot be forgotten by a theme.
     */
    public function testTheBehaviourAndStylesAreScaffolded(): void
    {
        // Act
        $this->scaffold();

        // Assert
        $this->assertStringContainsString('data-pf-omnibox', $this->read('www/assets/js/pf-utils.js'));
        $this->assertStringContainsString('.pf-omnibox-results', $this->read('www/assets/css/style.css'));
    }

    /**
     * The three UI systems all get the box.
     *
     * Each has its own nav markup, so each needed its own insertion — and a theme that
     * silently lacks it is the kind of gap nobody notices until they scaffold with the
     * other option.
     *
     * @param string $uiSystem
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('uiSystemProvider')]
    public function testEveryUiSystemGetsTheBox(string $uiSystem): void
    {
        // Act
        $this->scaffold(['--ui-system' => $uiSystem]);

        // Assert
        $this->assertStringContainsString(
            '\Pramnos\Html\SearchBox()',
            $this->read('app/themes/default/header.php'),
            $uiSystem . ' header must render the search box'
        );
    }

    /** @return array<string, array{string}> */
    public static function uiSystemProvider(): array
    {
        return [
            'tailwind'  => ['tailwind'],
            'bootstrap' => ['bootstrap'],
            'plain css' => ['none'],
        ];
    }

    /**
     * `php -l` on a generated file.
     *
     * Run out of process on purpose: `eval` would execute the registrations and pollute
     * the registry for every later test in the run.
     */
    private function lintExitCode(string $file): int
    {
        $code = 0;
        exec(escapeshellcmd(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);

        return $code;
    }
}
