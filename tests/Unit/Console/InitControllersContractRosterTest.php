<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The generated ControllersContractTest names the controllers that exist.
 *
 * `ControllersContractTest.php` is the first thing a developer runs in a new project,
 * and it was generated from a list written beside the code that writes the controllers
 * rather than from the controllers. The two drifted apart in both directions at once:
 * it named twelve classes under `App\Controllers` that the scaffold writes to
 * `App\Admin\Controllers`, and it named none of the four it had written outside its
 * list. So a freshly scaffolded project opened with 24 errors — the worst possible
 * first impression, and every one of them false.
 *
 * The assertions here are about the roster, not about the controllers: that every row
 * names a class the scaffold wrote, that every class the scaffold wrote has a row, and
 * that the parents are resolved through the imports the wrappers actually use.
 */
#[CoversClass(Init::class)]
class InitControllersContractRosterTest extends TestCase
{
    private string $tmpDir = '';
    private Init $command;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pramnos-roster-' . bin2hex(random_bytes(6));
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

    /** Scaffold with the feature set that writes the most controllers. */
    private function scaffold(string $features = 'auth,authserver,queue'): void
    {
        $app = new Application();
        $app->add($this->command);
        (new CommandTester($this->command))->execute([
            '--app-name'    => 'Roster App',
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace'   => 'RosterApp',
            '--features'    => $features,
            '--ui-system'   => 'plain-css',
            '--docker'      => 'n',
            '--libraries'   => '',
            '--db-type'     => 'postgresql',
            '--db-host'     => 'localhost',
            '--db-name'     => 'roster_db',
            '--db-user'     => 'roster',
            '--db-pass'     => 'pass',
            '--db-prefix'   => '',
        ], ['interactive' => false]);
    }

    /**
     * Every class name in the roster is a file the scaffold wrote.
     *
     * The direction that produced the 24 errors. A row for a class that does not exist
     * is not a gap in coverage — it is a failing test in a project that has done
     * nothing wrong yet.
     */
    public function testEveryRowNamesAControllerThatWasWritten(): void
    {
        // Arrange
        $this->scaffold();
        $roster = $this->rosterRows();

        // Assert
        $this->assertNotEmpty($roster, 'a project with auth, authserver and queue has controllers');
        foreach ($roster as $class => $parent) {
            $this->assertFileExists(
                $this->fileFor($class),
                $class . ' is in the roster but the scaffold never wrote it'
            );
        }
    }

    /**
     * Every controller the scaffold wrote has a row.
     *
     * The other direction, which was silent: four controllers were outside the
     * hand-written list, so the contract nobody had thought to add was the contract
     * nobody was checking.
     */
    public function testEveryWrittenControllerHasARow(): void
    {
        // Arrange
        $this->scaffold();
        $roster = array_keys($this->rosterRows());

        // Act — what is actually on disk, by namespace and class name.
        $onDisk = [];
        foreach (['src/Controllers' => '', 'src/Admin/Controllers' => 'Admin\\'] as $dir => $segment) {
            foreach ((array) glob($this->tmpDir . '/' . $dir . '/*.php') as $file) {
                $onDisk[] = 'RosterApp\\' . $segment . 'Controllers\\'
                    . basename((string) $file, '.php');
            }
        }

        // Assert
        sort($onDisk);
        sort($roster);
        $this->assertSame($onDisk, $roster);
    }

    /**
     * The administration controllers are named under `Admin\`, where they live.
     *
     * The specific mistake: `Dashboard`, `Users`, `Settings`, `Logs`, `Services`,
     * `Organizations`, `Emails`, `TokenActions`, `Tokens`, `Applications`,
     * `Permissions` and `Queue` are written to `src/Admin/Controllers`, and the roster
     * named all twelve under `App\Controllers`.
     */
    public function testAdminControllersAreNamedWhereTheyLive(): void
    {
        // Arrange
        $this->scaffold();
        $roster = $this->rosterRows();

        // Assert
        $this->assertArrayHasKey('RosterApp\\Admin\\Controllers\\Dashboard', $roster);
        $this->assertArrayNotHasKey('RosterApp\\Controllers\\Dashboard', $roster);
    }

    /**
     * An aliased import resolves to the class it imports, not to the alias.
     *
     * Every thin wrapper is written as `use Pramnos\Auth\Controllers\Account as
     * FrameworkAccount; class Login extends FrameworkAccount` — so reading the `extends`
     * token alone would produce `RosterApp\Controllers\FrameworkAccount`, a class that
     * exists nowhere, and the assertion would fail on every wrapper in the project.
     */
    public function testAnAliasedParentResolvesToTheImportedClass(): void
    {
        // Arrange
        $this->scaffold();
        $roster = $this->rosterRows();

        // Assert
        $this->assertSame(
            'Pramnos\\Auth\\Controllers\\Account',
            $roster['RosterApp\\Controllers\\Login'] ?? null
        );
    }

    /**
     * Nothing is claimed for a feature that was not enabled.
     *
     * The counterpart to reading the disk: with no features there are fewer
     * controllers, and the roster has to shrink with them rather than keep rows the
     * flags used to justify.
     */
    public function testAProjectWithoutFeaturesGetsASmallerRoster(): void
    {
        // Act
        $this->scaffold('');
        $roster = $this->rosterRows();

        // Assert — the auth wrappers are gone, and what is left still exists.
        $this->assertArrayNotHasKey('RosterApp\\Controllers\\Login', $roster);
        foreach (array_keys($roster) as $class) {
            $this->assertFileExists($this->fileFor($class));
        }
    }

    /**
     * The generated test passes in the project it was generated for.
     *
     * The roster being right on paper is not the claim that matters — the claim is that
     * a developer's first `./dockertest` in a new project is green. So this does what
     * the generated test does: autoload each class out of the scaffolded `src/`,
     * construct it with a null application, and check the parent. Running the emitted
     * file itself would need a composer install in a temporary project; this is the same
     * two assertions without one.
     */
    public function testTheGeneratedContractHoldsForEveryRow(): void
    {
        // Arrange — the scaffolded project's own PSR-4 mapping, for this test only.
        $this->scaffold();
        $loader = function (string $class): void {
            if (!str_starts_with($class, 'RosterApp\\')) {
                return;
            }
            $file = $this->fileFor($class);
            if (is_file($file)) {
                require_once $file;
            }
        };
        spl_autoload_register($loader);

        try {
            // Act & Assert
            // A sweep over nothing passes. The collection is asserted non-empty first, because a
            // moved directory or a renamed helper turns this guard into a no-op that still reports
            // success — which is how a guard stops guarding without anybody noticing.
            $this->assertNotEmpty($this->rosterRows(), 'the sweep found nothing to check');
            foreach ($this->rosterRows() as $class => $parent) {
                $this->assertTrue(
                    class_exists($class),
                    $class . ' is in the roster but does not load — this is the error a new project saw'
                );
                $this->assertInstanceOf($parent, new $class(null));
            }
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    /**
     * Every form an `extends` clause takes in this scaffold, and one it does not.
     *
     * Driven off files written by hand rather than by `init`, because the generator only
     * ever emits the aliased form — and the three other branches are what would break
     * silently if somebody changed a template. A parent resolved to the wrong name does
     * not fail here; it fails in the project, once, as `assertInstanceOf` against a class
     * that does not exist.
     */
    public function testEveryFormOfAnExtendsClauseResolves(): void
    {
        // Arrange — a project skeleton with one controller per form.
        mkdir($this->tmpDir . '/src/Controllers', 0777, true);
        $write = function (string $name, string $body): void {
            file_put_contents($this->tmpDir . '/src/Controllers/' . $name . '.php', $body);
        };

        // Aliased import — what every generated wrapper looks like.
        $write('Aliased', "<?php\nnamespace App\\Controllers;\n"
            . "use Pramnos\\Auth\\Controllers\\Account as FrameworkAccount;\n"
            . "class Aliased extends FrameworkAccount\n{\n}\n");
        // Plain import, no alias.
        $write('Imported', "<?php\nnamespace App\\Controllers;\n"
            . "use Pramnos\\Application\\Controller;\n"
            . "class Imported extends Controller\n{\n}\n");
        // Already fully qualified in the clause itself.
        $write('Absolute', "<?php\nnamespace App\\Controllers;\n"
            . "class Absolute extends \\Pramnos\\Application\\Controller\n{\n}\n");
        // Neither: a sibling in the same namespace.
        $write('Sibling', "<?php\nnamespace App\\Controllers;\n"
            . "class Sibling extends Absolute\n{\n}\n");
        // Not a controller at all — a trait file, and an interface with no parent.
        $write('Helpers', "<?php\nnamespace App\\Controllers;\ntrait Helpers\n{\n}\n");
        $write('Bare', "<?php\nnamespace App\\Controllers;\nclass Bare\n{\n}\n");

        // Act
        $roster = $this->roster();

        // Assert — one entry per form, resolved to the class actually named.
        $this->assertSame([
            'App\\Controllers\\Absolute' => 'Pramnos\\Application\\Controller',
            'App\\Controllers\\Aliased'  => 'Pramnos\\Auth\\Controllers\\Account',
            'App\\Controllers\\Imported' => 'Pramnos\\Application\\Controller',
            'App\\Controllers\\Sibling'  => 'App\\Controllers\\Absolute',
        ], $roster);
    }

    /**
     * A project with no administration directory is read without complaint.
     *
     * `scaffold:spa` and the `project:` commands run against projects that were not
     * scaffolded here, and a missing directory is a normal shape rather than an error —
     * an exception at this point would abort writing the test file over a condition that
     * means "this project has no admin screens".
     */
    public function testAMissingDirectoryIsSkipped(): void
    {
        // Arrange — only the public-facing directory exists.
        mkdir($this->tmpDir . '/src/Controllers', 0777, true);
        file_put_contents(
            $this->tmpDir . '/src/Controllers/Home.php',
            "<?php\nnamespace App\\Controllers;\n"
            . "class Home extends \\Pramnos\\Application\\Controller\n{\n}\n"
        );

        // Act & Assert
        $this->assertSame(
            ['App\\Controllers\\Home' => 'Pramnos\\Application\\Controller'],
            $this->roster()
        );
    }

    /** The private roster helper, which has no caller outside the generator. */
    private function roster(): array
    {
        $method = new \ReflectionMethod($this->command, 'scaffoldedControllers');

        return $method->invoke($this->command);
    }

    /**
     * The generated roster, as class FQN => expected parent FQN.
     *
     * Parsed out of the emitted provider rather than re-derived, so what is asserted is
     * the text a project receives.
     *
     * @return array<string, string>
     */
    private function rosterRows(): array
    {
        $test = (string) file_get_contents(
            $this->tmpDir . '/tests/Unit/Controllers/ControllersContractTest.php'
        );

        preg_match_all("/=> \['([^']+)', '([^']+)'\]/", $test, $matches, PREG_SET_ORDER);

        $rows = [];
        foreach ($matches as $match) {
            $rows[stripslashes($match[1])] = stripslashes($match[2]);
        }

        return $rows;
    }

    /** The file a scaffolded controller class name should be in. */
    private function fileFor(string $class): string
    {
        return $this->tmpDir . '/src/'
            . str_replace('\\', '/', substr($class, strlen('RosterApp\\')))
            . '.php';
    }
}
