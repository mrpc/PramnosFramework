<?php

declare(strict_types=1);

namespace SpaDoorsTestApp {
    /** A Svelte SPA project. */
    class Application extends \Pramnos\Application\Application
    {
        public $applicationInfo = [
            'namespace' => 'SpaDoorsTestApp',
            'app_style' => 'spa',
            'spa_stack' => 'svelte',
        ];
        public $appName = '';
        public function init($settingsFile = '') {}
    }
}

namespace Pramnos\Tests\Unit\Console {

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * **The two capabilities that had no door.**
 *
 * `createSpaScreen()` existed and was reachable only through `create:crud`, so
 * the documented way to add a dashboard was to generate a CRUD for a table you
 * did not want and delete two thirds of it. There was no `create:component` at
 * all, which is why components in scaffolded projects have no tests: the lever
 * that gives services theirs is that `create:service` writes one.
 *
 * `MakeView` exists on the MVC side for exactly the first reason. These are its
 * counterparts.
 *
 * A command that is written and not registered is a command that does not
 * exist, and nothing else in the suite would notice — which is what this file
 * is for.
 */
class SpaMakeCommandsAreRegisteredTest extends TestCase
{
    private \Pramnos\Console\Application $app;

    protected function setUp(): void
    {
        $this->app = new \Pramnos\Console\Application();
        $internal  = new \SpaDoorsTestApp\Application();
        $internal->applicationInfo = [
            'namespace' => 'SpaDoorsTestApp',
            'app_style' => 'spa',
            'spa_stack' => 'svelte',
        ];
        $this->app->internalApplication = $internal;
    }

    protected function tearDown(): void
    {
        $this->rmdir(ROOT . '/frontend');
    }

    private function rmdir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->rmdir($full) : unlink($full);
        }
        rmdir($path);
    }

    /**
     * Both commands answer to their names.
     *
     * Asserted through the application's own registry rather than by
     * instantiating the classes: `new MakeScreen()` proves the file parses, and
     * nothing else.
     */
    public function testBothCommandsAreRegistered(): void
    {
        // Assert
        $this->assertTrue($this->app->has('create:screen'));
        $this->assertTrue($this->app->has('create:component'));
    }

    /**
     * They describe themselves, so `list` is useful.
     *
     * A registered command with no description is one somebody has to read the
     * source of to discover.
     */
    public function testBothCommandsDescribeThemselves(): void
    {
        // Assert
        $this->assertNotSame(
            '', $this->app->find('create:screen')->getDescription()
        );
        $this->assertNotSame(
            '', $this->app->find('create:component')->getDescription()
        );
    }

    /**
     * `create:screen --blank` writes a screen through the command, not only
     * through the method underneath it.
     *
     * The wiring between the two is where a command can be registered, parse
     * its options and still do nothing.
     */
    public function testCreateScreenBlankWritesAScreen(): void
    {
        // Arrange
        $command = $this->app->find('create:screen');
        $output  = new BufferedOutput();

        // Act
        $status = $command->run(
            new ArrayInput(['name' => 'Dashboard', '--blank' => true]),
            $output
        );

        // Assert
        $this->assertSame(0, $status);
        $this->assertFileExists(ROOT . '/frontend/screens/Dashboard.svelte');
        $this->assertStringContainsString('OK', $output->fetch());
    }

    /**
     * `create:component` writes the component and its test, through the
     * command.
     *
     * The name is preserved as typed. It used to go through
     * `getProperClassName($name, false)`, which pluralises and flattens the
     * rest of the name to lower case — so this would have written
     * `Statusbadges.svelte`, which nothing imports, under a name nobody asked
     * for. A screen is not a database table.
     */
    public function testCreateComponentWritesBothFilesUnderTheNameGiven(): void
    {
        // Arrange
        $command = $this->app->find('create:component');
        $output  = new BufferedOutput();

        // Act
        $status = $command->run(
            new ArrayInput(['name' => 'StatusBadge']), $output
        );

        // Assert
        $this->assertSame(0, $status);
        $this->assertFileExists(ROOT . '/frontend/components/StatusBadge.svelte');
        $this->assertFileExists(ROOT . '/frontend/__tests__/StatusBadge.test.js');
        // Not Statusbadges.
        $this->assertFileDoesNotExist(
            ROOT . '/frontend/components/Statusbadges.svelte'
        );
    }

    /**
     * A second run reports the files as existing rather than overwriting them.
     *
     * A generator that silently replaced a component somebody had written would
     * be worse than one that refused.
     */
    public function testASecondRunDoesNotOverwrite(): void
    {
        // Arrange
        $command = $this->app->find('create:component');
        $command->run(new ArrayInput(['name' => 'StatusBadge']), new BufferedOutput());
        $path = ROOT . '/frontend/components/StatusBadge.svelte';
        file_put_contents($path, "// mine\n");

        // Act
        $output = new BufferedOutput();
        $command->run(new ArrayInput(['name' => 'StatusBadge']), $output);

        // Assert
        $this->assertSame("// mine\n", (string) file_get_contents($path));
        $this->assertStringContainsString('SKIPPED', $output->fetch());
    }

    /**
     * In a project with no SPA, `create:screen` says so and succeeds.
     *
     * An `mvc` application has no screens, and saying that is more useful than
     * an error about a directory that was never going to exist. Exit code 0
     * because nothing failed — the answer is "there is nothing here to do".
     */
    public function testCreateScreenExplainsItselfInAnMvcProject(): void
    {
        // Arrange
        $this->app->internalApplication->applicationInfo = [
            'namespace' => 'SpaDoorsTestApp',
            'app_style' => 'mvc',
        ];
        $command = $this->app->find('create:screen');
        $output  = new BufferedOutput();

        // Act
        $status = $command->run(new ArrayInput(['name' => 'Dashboard']), $output);

        // Assert
        $this->assertSame(0, $status);
        $this->assertStringContainsString('scaffold:spa', $output->fetch(),
            'it must name the command that would make this possible');
        $this->assertFileDoesNotExist(ROOT . '/frontend/screens/Dashboard.svelte');
    }

    /**
     * `create:component` is for the Svelte stack, and says so on the others.
     *
     * A component library for a build-less stack is a different project; the
     * non-goal is stated in the command rather than discovered by finding a
     * `.svelte` file in a project with no compiler.
     */
    public function testCreateComponentExplainsItselfOnANonSvelteStack(): void
    {
        // Arrange
        $this->app->internalApplication->applicationInfo = [
            'namespace' => 'SpaDoorsTestApp',
            'app_style' => 'spa',
            'spa_stack' => 'vanilla',
        ];
        $command = $this->app->find('create:component');
        $output  = new BufferedOutput();

        // Act
        $status = $command->run(new ArrayInput(['name' => 'StatusBadge']), $output);

        // Assert
        $this->assertSame(0, $status);
        $this->assertStringContainsString('svelte', $output->fetch());
        $this->assertFileDoesNotExist(
            ROOT . '/frontend/components/StatusBadge.svelte'
        );
    }
}

}
