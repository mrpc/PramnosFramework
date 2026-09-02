<?php

declare(strict_types=1);

namespace App\Models {
    /**
     * A model that already exists, as far as `class_exists()` is concerned.
     *
     * Declared here rather than autoloaded because that is the condition the branch under test turns
     * on: `createModel()` treats a model as existing when the class is loadable **and** the file is
     * there. In this repository a generated model is never autoloadable, which is the whole reason the
     * update path had never executed.
     */
    class RegenProbe extends \Pramnos\Application\Model
    {
        public $regenprobeid;

        public $title;

        /** The hand-written method the update must not destroy. */
        public function businessRule(): string
        {
            return 'this took someone an afternoon';
        }
    }
}

namespace Pramnos\Tests\Integration\Console {

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Console\Commands\MakeCommandBase;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** A concrete command exposing the model generator. */
class RegeneratedModelDummyCommand extends MakeCommandBase
{
    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return 0;
    }

    public function callCreateModel(string $name): string
    {
        return (string) $this->createModel($name);
    }

    public function setDbTable(?string $table): void
    {
        $this->dbtable = $table;
    }
}

/**
 * Re-running `create:model` on a model that already exists.
 *
 * The branch had never executed, and the reason is the same one that made it wrong: it turns on
 * `class_exists()`, and a generated model in this repository is not autoloadable — so nothing could
 * reach it, and nothing noticed what it did.
 *
 * What it did was destroy work. The branch says «Model already exists — left untouched» and sets a
 * flag, and then an unconditional `file_put_contents()` a few statements further down regenerated the
 * file from the schema. Every hand-written method in it went, and the report said «Model updated.»
 *
 * Re-running the generator after adding a column is the normal thing to do — it is what the command is
 * *for* — so this is not an edge case reached by misuse. It is the second time this path has been
 * wrong: before the message it called `updateModel()`, which does not exist anywhere in the framework,
 * and died with a fatal error instead.
 *
 * Both engines, because the update path reads the live schema through `getColumns()` before it decides
 * anything, and «what the table looks like» is answered by two different drivers.
 */
#[CoversClass(MakeCommandBase::class)]
class RegeneratedModelTest extends BaseTestCase
{
    private $db;

    private string $table = '';

    private string $modelFile = '';

    private const ENTITY = 'RegenProbe';

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        if (!defined('INCLUDES')) {
            define('INCLUDES', 'src');
        }
        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        $this->table = 'regenprobe_' . bin2hex(random_bytes(4));
        $this->createProbeTable();

        $directory = ROOT . DS . INCLUDES . DS . 'Models';
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $this->modelFile = $directory . DS . self::ENTITY . '.php';
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    private function createProbeTable(): void
    {
        $q = $this->db->type === 'postgresql' ? '"' : '`';

        $this->db->query(
            'CREATE TABLE ' . $q . $this->table . $q . ' ('
            . ($this->db->type === 'postgresql'
                ? $q . 'regenprobeid' . $q . ' SERIAL PRIMARY KEY, '
                : $q . 'regenprobeid' . $q . ' INT NOT NULL AUTO_INCREMENT PRIMARY KEY, ')
            . $q . 'title' . $q . ' VARCHAR(255) NOT NULL'
            . ')'
        );
    }

    protected function tearDown(): void
    {
        if ($this->modelFile !== '' && is_file($this->modelFile)) {
            @unlink($this->modelFile);
        }

        // An update writes neither, but a regression that started writing them again would leave the
        // framework's own checkout dirty rather than failing.
        foreach ([
            ROOT . DS . 'tests' . DS . 'Unit' . DS . self::ENTITY . 'Test.php',
            ROOT . DS . 'tests' . DS . 'Unit' . DS . 'Models' . DS . self::ENTITY . 'Test.php',
        ] as $generated) {
            if (is_file($generated)) {
                @unlink($generated);
            }
        }

        if ($this->table !== '') {
            $q = $this->db->type === 'postgresql' ? '"' : '`';
            try {
                $this->db->query('DROP TABLE IF EXISTS ' . $q . $this->table . $q);
            } catch (\Throwable) {
                // Nothing to drop.
            }
        }

        parent::tearDown();
    }

    /** A model file with a method nobody generated. */
    private function writeExistingModel(bool $withApiList = false): void
    {
        $apiList = $withApiList
            ? "\n    public function getApiList(\$fields = array()) { return array(); }\n"
            : '';

        file_put_contents(
            $this->modelFile,
            "<?php\n\nnamespace App\\Models;\n\n"
            . "class " . self::ENTITY . " extends \\Pramnos\\Application\\Model\n{\n"
            . "    public \$regenprobeid;\n\n"
            . "    public \$title;\n\n"
            . "    public function businessRule(): string\n"
            . "    {\n        return 'this took someone an afternoon';\n    }\n"
            . $apiList
            . "}\n"
        );
    }

    private function command(): RegeneratedModelDummyCommand
    {
        $command = new RegeneratedModelDummyCommand();
        $command->setDbTable($this->table);

        $consoleApp = new class extends \Symfony\Component\Console\Application {
            public $internalApplication;
        };
        $consoleApp->internalApplication = new class extends Application {
            public $applicationInfo = ['namespace' => 'App'];

            public $appName = '';

            public function __construct()
            {
            }

            public function init($settingsFile = ''): void
            {
            }
        };
        $command->setApplication($consoleApp);

        return $command;
    }

    /**
     * A hand-written method survives a regeneration.
     *
     * The assertion this file exists for. Without it the command is a quiet way to lose an afternoon's
     * work: the developer adds a column, runs the generator that put the model there in the first
     * place, and it reports «Model updated.» while the model's own code is gone. Nothing in the output
     * distinguishes that from the harmless outcome, and the loss is only visible when something that
     * called the method breaks.
     */
    public function testAHandWrittenMethodSurvives(): void
    {
        // Arrange
        $this->writeExistingModel();
        $before = (string) file_get_contents($this->modelFile);

        // Act
        $summary = $this->command()->callCreateModel(self::ENTITY);

        // Assert
        $this->assertStringContainsString('Model updated.', $summary);

        $after = (string) file_get_contents($this->modelFile);
        $this->assertStringContainsString(
            'this took someone an afternoon',
            $after,
            'the generator destroyed a hand-written method'
        );
        $this->assertStringContainsString('function businessRule', $after);
        $this->assertStringContainsString('$title', $after, 'the declared properties went too');
        $this->assertNotSame('', $before);
    }

    /**
     * `getApiList()` is added when the file predates it, and that is the one change an update makes.
     *
     * Additive, so it cannot lose anything — which is why it is the only thing this branch is allowed
     * to do to a file it did not write. Inserted before the last closing brace rather than appended,
     * because appending after the brace puts a method outside its class and the file stops parsing.
     */
    public function testTheApiListMethodIsAddedWhenItIsMissing(): void
    {
        // Arrange
        $this->writeExistingModel();
        $this->assertStringNotContainsString(
            'function getApiList(',
            (string) file_get_contents($this->modelFile)
        );

        // Act
        $this->command()->callCreateModel(self::ENTITY);

        // Assert
        $after = (string) file_get_contents($this->modelFile);
        $this->assertStringContainsString('function getApiList(', $after);
        $this->assertStringContainsString('_getApiList(', $after, 'the body does not delegate');

        // …and the file still parses, which is what «before the last brace» is about
        $lint = [];
        exec('php -l ' . escapeshellarg($this->modelFile) . ' 2>&1', $lint, $status);
        $this->assertSame(0, $status, 'the inserted method left the file unparsable: '
            . implode("\n", $lint));
    }

    /**
     * A model that already has it is not given a second copy.
     *
     * Two methods of the same name in one class is a fatal error, so this is not a tidiness question:
     * the check is what stops the second run of the command breaking the file the first run fixed.
     */
    public function testAModelThatAlreadyHasItIsNotGivenASecondCopy(): void
    {
        // Arrange
        $this->writeExistingModel(true);

        // Act
        $this->command()->callCreateModel(self::ENTITY);

        // Assert
        $after = (string) file_get_contents($this->modelFile);
        $this->assertSame(
            1,
            substr_count($after, 'function getApiList('),
            'the model was given a second getApiList(), which is a fatal error'
        );
    }

    /**
     * An update writes no test file and registers nothing.
     *
     * Both are `!$isUpdate` guards, and both matter for the same reason: a generated test overwrites
     * whatever the developer had written in its place, and a second registry entry for one model is a
     * lookup that answers twice.
     */
    public function testAnUpdateWritesNoTestAndRegistersNothing(): void
    {
        // Arrange
        $this->writeExistingModel();

        $registryFile = ROOT . DS . 'app' . DS . 'model-registry.json';
        $registryBefore = is_file($registryFile) ? (string) file_get_contents($registryFile) : null;

        // Act
        $summary = $this->command()->callCreateModel(self::ENTITY);

        // Assert
        $this->assertStringNotContainsString('Test:', $summary, 'a test file was generated');
        $this->assertFileDoesNotExist(ROOT . DS . 'tests' . DS . 'Unit' . DS . self::ENTITY . 'Test.php');

        $registryAfter = is_file($registryFile) ? (string) file_get_contents($registryFile) : null;
        $this->assertSame($registryBefore, $registryAfter, 'the update added a registry entry');
    }
}

}
