<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\MakeCommandBase;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/** A concrete command exposing the two protected methods under test. */
class SearchRegistrationDummyCommand extends MakeCommandBase
{
    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return 0;
    }

    public function callOfferSearchRegistration(
        InputInterface $input,
        OutputInterface $output,
        string $name
    ): string {
        return $this->offerSearchRegistration($input, $output, $name);
    }

    public function callEnsureTargetDirectory(string $path): void
    {
        $this->ensureTargetDirectory($path);
    }
}

/**
 * The offer to make a generated entity findable, and the directory a generator writes into.
 *
 * `offerSearchRegistration()` was 24 uncovered statements and the reason is worth saying: it does
 * nothing at all unless `app/search.php` exists, and the framework's own repository has no such
 * file. So every existing test took the first `return ''` and the other twenty-two statements had
 * never run. The file is created here and removed afterwards — and skipped rather than clobbered
 * if a checkout has one, because appending to somebody's real registrations is exactly the mistake
 * this method's own duplicate guard exists to prevent.
 *
 * The behaviour worth pinning is all about *not* being pushy. A generator that edits a file the
 * developer owns has to be right about when it may: only when that file exists (which is the
 * project saying it uses the search registry at all), only once per entity, and only when asked.
 *
 * `ensureTargetDirectory()` is here because it was found by the same search. Three generators used
 * a bare `mkdir($path)` where nineteen siblings pass `true`, and the failure was silent: the
 * directory was not created, `file_put_contents()` failed, and the command printed «created» with
 * the path of a file that did not exist.
 */
#[CoversClass(MakeCommandBase::class)]
class MakeSearchRegistrationTest extends TestCase
{
    private SearchRegistrationDummyCommand $command;

    private BufferedOutput $output;

    private string $searchFile = '';

    /** Directories this test made, deepest first, for tearDown. */
    private array $made = [];

    /** Whether the `app/search.php` in place is this test's, and so safe to remove. */
    private bool $madeSearchFile = false;

    protected function setUp(): void
    {
        $this->command = new SearchRegistrationDummyCommand();
        $this->output  = new BufferedOutput();

        $consoleApp = new class extends \Symfony\Component\Console\Application {
            public $internalApplication;
        };
        $consoleApp->internalApplication = new class extends \Pramnos\Application\Application {
            public $applicationInfo = ['namespace' => 'App'];

            public $appName = '';

            public function __construct()
            {
            }

            public function init($settingsFile = ''): void
            {
            }
        };
        $this->command->setApplication($consoleApp);
        $this->command->setHelperSet(new HelperSet(['question' => new QuestionHelper()]));

        $this->searchFile = ROOT . DS . 'app' . DS . 'search.php';
    }

    protected function tearDown(): void
    {
        if ($this->searchFile !== '' && is_file($this->searchFile) && $this->madeSearchFile) {
            @unlink($this->searchFile);
        }

        foreach ($this->made as $dir) {
            @rmdir($dir);
        }
        $this->made = [];
    }

    /**
     * Put a `app/search.php` in place, or skip.
     *
     * Skipped rather than overwritten: a checkout that has one has real registrations in it, and
     * this method appends.
     */
    private function giveTheProjectASearchFile(string $contents = "<?php\n"): void
    {
        if (is_file($this->searchFile)) {
            $this->markTestSkipped('This checkout has its own app/search.php.');
        }

        file_put_contents($this->searchFile, $contents);
        $this->madeSearchFile = true;
    }

    /** An input that answers questions from a string, the way a terminal would. */
    private function interactive(string $answers = ''): ArrayInput
    {
        $input = new ArrayInput([]);
        $input->setInteractive(true);

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $answers);
        rewind($stream);
        $input->setStream($stream);

        return $input;
    }

    private function nonInteractive(): ArrayInput
    {
        $input = new ArrayInput([]);
        $input->setInteractive(false);

        return $input;
    }

    // ── When the offer is not made at all ─────────────────────────────────────

    /**
     * With no `app/search.php`, nothing is said and nothing is written.
     *
     * The file's existence is the project saying it uses the search registry. Creating it would be
     * a generator deciding an application has a feature — and a mention of it in the output of
     * every `create:crud` would be noise on a project that will never want one.
     */
    public function testWithNoSearchFileNothingIsOffered(): void
    {
        // Arrange — the framework's own repository has none; skip if this checkout does
        if (is_file($this->searchFile)) {
            $this->markTestSkipped('This checkout has its own app/search.php.');
        }

        // Act
        $line = $this->command->callOfferSearchRegistration(
            $this->interactive("yes\n"),
            $this->output,
            'Device'
        );

        // Assert
        $this->assertSame('', $line);
        $this->assertFileDoesNotExist($this->searchFile, 'the generator created a file it does not own');
    }

    /**
     * With no namespace configured, the offer is skipped rather than guessed.
     *
     * The registration names a model class, and without a namespace there is no name to write.
     * A block referring to a class that does not exist would break the search registry on its next
     * boot — for a convenience nobody asked for.
     */
    public function testWithNoNamespaceNothingIsOffered(): void
    {
        // Arrange
        $this->giveTheProjectASearchFile();
        $consoleApp = new class extends \Symfony\Component\Console\Application {
            public $internalApplication;
        };
        $consoleApp->internalApplication = new class extends \Pramnos\Application\Application {
            public $applicationInfo = ['namespace' => '   '];

            public $appName = '';

            public function __construct()
            {
            }

            public function init($settingsFile = ''): void
            {
            }
        };
        $this->command->setApplication($consoleApp);

        // Act
        $line = $this->command->callOfferSearchRegistration(
            $this->interactive("yes\n"),
            $this->output,
            'Device'
        );

        // Assert
        $this->assertSame('', $line);
        $this->assertSame("<?php\n", file_get_contents($this->searchFile), 'the file was written to');
    }

    /**
     * An entity already registered is not registered twice.
     *
     * `create:crud` for the same entity is an ordinary thing to run again — after changing the
     * table, or because the first run was interrupted. A second block searching the same table
     * gives every result twice, which reads as a bug in the search rather than in a generated file
     * nobody looks at.
     */
    public function testAnEntityAlreadyRegisteredIsNotRegisteredAgain(): void
    {
        // Arrange — the guard matches on the model class, which is what the block contains
        $existing = "<?php\nRegistry::register('Device', \\App\\Models\\Device::class, []);\n";
        $this->giveTheProjectASearchFile($existing);

        // Act
        $line = $this->command->callOfferSearchRegistration(
            $this->interactive("yes\n"),
            $this->output,
            'Device'
        );

        // Assert
        $this->assertSame('', $line);
        $this->assertSame($existing, file_get_contents($this->searchFile), 'a duplicate was appended');
    }

    // ── When it is made ───────────────────────────────────────────────────────

    /**
     * A non-interactive run says how to do it by hand instead of doing it.
     *
     * This is CI, or a scripted scaffold. Editing a file the developer owns without being asked is
     * the wrong default there — and saying nothing would leave the entity quietly missing from the
     * search box with no clue why.
     */
    public function testANonInteractiveRunExplainsRatherThanWrites(): void
    {
        // Arrange
        $this->giveTheProjectASearchFile();

        // Act
        $line = $this->command->callOfferSearchRegistration(
            $this->nonInteractive(),
            $this->output,
            'Device'
        );

        // Assert
        $this->assertStringContainsString('Not registered for admin search', $line);
        $this->assertStringContainsString('app/search.php', $line, 'the reader is not told where');
        $this->assertStringContainsString('Device', $line);
        $this->assertSame("<?php\n", file_get_contents($this->searchFile), 'it wrote anyway');
    }

    /**
     * Declining writes nothing and says nothing.
     *
     * The developer has already answered the question; repeating it as a line of output is the
     * generator arguing.
     */
    public function testDecliningWritesNothing(): void
    {
        // Arrange
        $this->giveTheProjectASearchFile();

        // Act
        $line = $this->command->callOfferSearchRegistration(
            $this->interactive("no\n"),
            $this->output,
            'Device'
        );

        // Assert
        $this->assertSame('', $line);
        $this->assertSame("<?php\n", file_get_contents($this->searchFile));
    }

    /**
     * Accepting appends a registration that names the class, the URL and its own gap.
     *
     * The display columns are a guess read off the table, and when the table cannot be read the
     * block carries a `TODO` naming what the first column means — because a registration with the
     * wrong title column is a search box whose results are all blank, and that is a puzzle from
     * the outside. The returned line says the same thing, so it is visible without opening the
     * file.
     */
    public function testAcceptingAppendsARegistration(): void
    {
        // Arrange
        $this->giveTheProjectASearchFile();

        // Act — an empty answer takes the question's default, which is yes
        $line = $this->command->callOfferSearchRegistration(
            $this->interactive("\n"),
            $this->output,
            'Device'
        );
        $written = (string) file_get_contents($this->searchFile);

        // Assert
        $this->assertStringContainsString('Registered', $line);
        $this->assertStringContainsString('Device', $line);

        $this->assertStringContainsString("Registry::register('Device'", $written);
        $this->assertStringContainsString('\\App\\Models\\Device::class', $written);
        $this->assertStringContainsString("'url'     => '/device/edit/:id'", $written);
        $this->assertStringContainsString('<?php', $written, 'the existing contents were replaced');

        /*
         * Either the columns were guessed from a live table, or the block carries the TODO. Both
         * are correct answers and which one depends on whether this environment has the table, so
         * the assertion is that the block is never silently short of a `display` key.
         */
        $this->assertStringContainsString("'display' => [", $written);

        if (str_contains($written, 'TODO')) {
            $this->assertStringContainsString(
                'the first is the result title',
                $written,
                'the gap is left without saying what fills it'
            );
            $this->assertStringContainsString('display', $line);
        }
    }

    // ── The directory a generator writes into ─────────────────────────────────

    /**
     * A directory two levels down is created, parents and all.
     *
     * `Api/Controllers` is two below the application root, so on a project adding its first API
     * controller the parent does not exist either. The bare `mkdir()` this replaces created
     * nothing, and the generator went on to report a file it had not written.
     */
    public function testANestedDirectoryIsCreatedWithItsParents(): void
    {
        // Arrange
        $base = sys_get_temp_dir() . DS . 'pramnos-mkdir-' . bin2hex(random_bytes(6));
        $deep = $base . DS . 'Api' . DS . 'Controllers';
        $this->made = [$deep, $base . DS . 'Api', $base];

        // Act
        $this->command->callEnsureTargetDirectory($deep);

        // Assert
        $this->assertDirectoryExists($deep);
        $this->assertTrue(is_writable($deep), 'a directory a generator cannot write into');
    }

    /**
     * Called again on a directory that is already there, it does nothing and does not complain.
     *
     * Every generator calls this before writing, and most of them run against a tree that already
     * exists. Losing a race to a concurrent generator is not a failure either, which is why the
     * check is `mkdir() || is_dir()` rather than the return value alone.
     */
    public function testAnExistingDirectoryIsLeftAlone(): void
    {
        // Arrange
        $base = sys_get_temp_dir() . DS . 'pramnos-mkdir-' . bin2hex(random_bytes(6));
        mkdir($base, 0777, true);
        $this->made = [$base];
        file_put_contents($base . DS . 'keep.txt', 'kept');

        // Act
        $this->command->callEnsureTargetDirectory($base);

        // Assert
        $this->assertFileExists($base . DS . 'keep.txt', 'the directory was recreated');

        unlink($base . DS . 'keep.txt');
    }

    /**
     * A directory that cannot be created is an exception naming the path.
     *
     * The alternative is what this replaced: a PHP warning nobody sees in a command's output,
     * followed by «created» and the path of a file that is not there. The path is in the message
     * because the cause is almost always a permission on one of its parents, and the developer
     * needs to know which tree to look at.
     */
    public function testADirectoryThatCannotBeCreatedThrows(): void
    {
        // Arrange — a child of a regular file can never be a directory
        $file = tempnam(sys_get_temp_dir(), 'pramnos-notadir');
        $this->assertIsString($file);
        $target = $file . DS . 'Controllers';

        try {
            // Act
            $this->command->callEnsureTargetDirectory($target);
            $this->fail('a directory that cannot exist was reported as created');
        } catch (\Exception $exception) {
            // Assert
            $this->assertStringContainsString('Could not create the directory', $exception->getMessage());
            $this->assertStringContainsString($target, $exception->getMessage());
        } finally {
            @unlink($file);
        }
    }
}
