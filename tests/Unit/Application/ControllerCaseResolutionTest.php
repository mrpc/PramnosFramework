<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\ControllerNotFoundException;

/**
 * A controller is found from a lower-case URL segment whether or not its class has been loaded.
 *
 * `/twofactorauth` asks for `Twofactorauth`. PHP's class names ignore case, so that worked once
 * `TwoFactorAuth` was loaded by something else; before that the autoloader looked for a file named
 * `Twofactorauth.php`, which a case-sensitive filesystem does not have. Each test here uses classes
 * of its own that nothing has loaded, in a directory registered for the test, so the answer cannot
 * come from a class that happened to be loaded first.
 */
#[CoversClass(Application::class)]
final class ControllerCaseResolutionTest extends TestCase
{
    private string $dir = '';

    private ?ClassLoader $loader = null;

    /** The namespace of this run's controllers: fresh, so none of them is loaded yet. */
    private string $namespace = '';

    protected function setUp(): void
    {
        $this->namespace = 'PfCaseProbe' . bin2hex(random_bytes(4));
        $this->dir = sys_get_temp_dir() . '/' . $this->namespace;
        mkdir($this->dir . '/Controllers', 0777, true);
        mkdir($this->dir . '/Mapped', 0777, true);
        mkdir($this->dir . '/Admin/Controllers', 0777, true);
        foreach (['Controllers/TwoWordThing', 'Mapped/MappedThing', 'Admin/Controllers/AreaThing'] as $path) {
            $sub   = str_replace('/', '\\', dirname($path));
            $class = basename($path);
            file_put_contents(
                $this->dir . '/' . $path . '.php',
                "<?php namespace {$this->namespace}\\{$sub}; class {$class} { public function __construct(public mixed \$app = null, public mixed \$permissions = null) {} }"
            );
        }
        $this->loader = new ClassLoader($this->dir . '/vendor-probe');
        $this->loader->addPsr4($this->namespace . '\\Controllers\\', $this->dir . '/Controllers');
        $this->loader->addPsr4($this->namespace . '\\Admin\\Controllers\\', $this->dir . '/Admin/Controllers');
        // A class known only to the class map, as an optimised autoloader has it.
        $this->loader->addClassMap([$this->namespace . '\\Mapped\\MappedThing' => $this->dir . '/Mapped/MappedThing.php']);
        $this->loader->register();
    }

    protected function tearDown(): void
    {
        $this->loader?->unregister();
        foreach (['Controllers/TwoWordThing.php', 'Mapped/MappedThing.php', 'Admin/Controllers/AreaThing.php'] as $file) {
            @unlink($this->dir . '/' . $file);
        }
        @rmdir($this->dir . '/Admin/Controllers');
        @rmdir($this->dir . '/Admin');
        @rmdir($this->dir . '/Controllers');
        @rmdir($this->dir . '/Mapped');
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** An application whose controllers live in this run's namespace. */
    private function application(): Application
    {
        $app = new class extends Application {
            /** The real constructor reads configuration and connects; resolution needs neither. */
            public function __construct()
            {
            }

            /** The protected resolver, reachable. */
            public function resolve(string $class, bool $anyCase = true): ?string
            {
                return $this->controllerClass($class, $anyCase);
            }
        };
        $app->applicationInfo = ['namespace' => $this->namespace];

        return $app;
    }

    /**
     * A lower-case segment reaches a mixed-case controller nothing has loaded yet.
     */
    public function testALowerCaseSegmentFindsAControllerNotYetLoaded(): void
    {
        // Arrange
        $app = $this->application();
        $this->assertFalse(class_exists($this->namespace . '\\Controllers\\TwoWordThing', false), 'loaded already: the test would prove nothing');

        // Act
        $controller = $app->getController('twowordthing');

        // Assert
        $this->assertInstanceOf($this->namespace . '\\Controllers\\TwoWordThing', $controller);
        $this->assertSame($app, $controller->app, 'built as a controller: with the application');
    }

    /**
     * A class the autoloader knows only from its class map is found in any case too.
     */
    public function testAClassMapEntryIsFoundInAnyCase(): void
    {
        // Arrange
        $app = $this->application();

        // Act
        $exactOnly = $app->resolve('\\' . strtolower($this->namespace) . '\\mapped\\mappedthing', false);
        $found     = $app->resolve('\\' . strtolower($this->namespace) . '\\mapped\\mappedthing');

        // Assert
        $this->assertNull($exactOnly, 'the exact pass searched the autoloader: every area request would pay for it');
        $this->assertSame('\\' . $this->namespace . '\\Mapped\\MappedThing', $found);
    }

    /**
     * A name nothing answers to, in any case, is still not found — the 404 stays a 404.
     */
    public function testANameNothingAnswersToIsStillNotFound(): void
    {
        // Arrange
        $app = $this->application();

        // Act
        $found = $app->resolve('\\' . $this->namespace . '\\Controllers\\NoSuchThing');

        // Assert
        $this->assertNull($found);
        $this->expectException(ControllerNotFoundException::class);
        $app->getController('nosuchthing');
    }

    /**
     * Inside an area, the area's controller is found from a lower-case segment too.
     */
    public function testAnAreasControllerIsFoundInAnyCase(): void
    {
        // Arrange
        $app = $this->application();
        $app->area = 'Admin';

        // Act
        $controller = $app->getController('areathing');

        // Assert
        $this->assertInstanceOf($this->namespace . '\\Admin\\Controllers\\AreaThing', $controller);
    }

    /**
     * The exact name is taken as it is, with nothing searched — the case every existing
     * application relies on.
     */
    public function testTheExactNameIsTakenAsItIs(): void
    {
        // Arrange
        $app = $this->application();

        // Act
        $found = $app->resolve('\\' . $this->namespace . '\\Controllers\\TwoWordThing');

        // Assert
        $this->assertSame('\\' . $this->namespace . '\\Controllers\\TwoWordThing', $found);
    }
}
