<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A scaffolded project can reach the administration screens it was given.
 *
 * `init` writes sixteen controllers into `src/Admin/Controllers/` and wrote nothing that
 * mounted them. `Application::enterAdminAreaIfRequested()` returns immediately when
 * `applicationInfo['admin']` is absent, and two things follow from that: the prefix stays
 * `''`, so `adminUrl('Users')` renders `/Users`; and `src/Admin/` is never put in
 * controller scope, so `<Ns>\Admin\Controllers\Users` is not a class anybody can route to.
 *
 * So **every administration screen in a fresh project answered 404** — while the menu
 * listed them all, because the navigation is built from the registry rather than from
 * routes. The area looked complete and nothing in it opened.
 *
 * The assertions pair the two halves deliberately: a block without controllers and
 * controllers without a block are each half of what shipped, and each would pass a test
 * that only looked at its own side.
 */
#[CoversClass(Init::class)]
class InitAdminAreaTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pramnos-admin-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0777, true);
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
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

    private function scaffold(): void
    {
        $command = new Init();
        $command->targetBaseDir = $this->tmpDir;
        $command->skipDockerRun = true;

        $app = new Application();
        $app->add($command);
        (new CommandTester($command))->execute([
            '--app-name'    => 'Admin App',
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace'   => 'AdminApp',
            '--features'    => 'auth,authserver,queue',
            '--ui-system'   => 'plain-css',
            '--docker'      => 'n',
            '--libraries'   => '',
            '--db-type'     => 'mysql',
            '--db-host'     => 'localhost',
            '--db-name'     => 'admin_db',
            '--db-user'     => 'admin',
            '--db-pass'     => 'pass',
            '--db-prefix'   => '',
        ], ['interactive' => false]);
    }

    /**
     * The generated `app.php` mounts the area, in the shape the framework reads.
     *
     * Asserted by loading the config and reading the keys `enterAdminAreaIfRequested()`
     * actually consults, rather than by matching the text — a block with the right words
     * under the wrong key would satisfy a string search and mount nothing.
     */
    public function testTheGeneratedConfigMountsTheAdminArea(): void
    {
        // Act
        $this->scaffold();

        // Assert
        $config = require $this->tmpDir . '/app/app.php';

        $this->assertIsArray($config['admin'] ?? null, 'without this key the area never activates');
        $this->assertSame('admin', $config['admin']['prefix']);
        $this->assertSame('Dashboard', $config['admin']['default_controller']);

        /*
         * 80, because that is the lowest any bundled admin controller requires — nine
         * declare 80 and four declare 90. A gate above the lowest would lock out the
         * screens that only need 80, and each controller still enforces its own.
         */
        $this->assertSame(80, $config['admin']['min_usertype']);
    }

    /**
     * And the controllers the block mounts are the ones on disk.
     *
     * The other half. A prefix that mounts an empty directory is as useless as sixteen
     * controllers nobody mounted, and this is the pairing that shipped broken.
     */
    public function testTheMountedDirectoryIsWhereTheControllersWere(): void
    {
        // Act
        $this->scaffold();

        // Assert — the area defaults to `Admin`, which is where init writes them.
        $config = require $this->tmpDir . '/app/app.php';
        $area   = $config['admin']['area'] ?? 'Admin';

        $directory = $this->tmpDir . '/src/' . $area . '/Controllers';
        $this->assertDirectoryExists($directory, 'the mounted area must be where the controllers are');

        $controllers = glob($directory . '/*.php') ?: [];
        $this->assertGreaterThan(10, count($controllers), 'init writes the administration screens here');

        // The default controller has to exist, or the bare `/admin` is a 404.
        $this->assertFileExists(
            $directory . '/' . $config['admin']['default_controller'] . '.php'
        );
    }
}
