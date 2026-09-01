<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Console\Commands\MakeCommandBase;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** A concrete command exposing the model generator. */
class GeneratedModelDummyCommand extends MakeCommandBase
{
    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return 0;
    }

    public function callCreateModel(string $name, array $columns = [], array $fks = []): string
    {
        return (string) $this->createModel($name, $columns, $fks);
    }

    public function setDbTable(?string $table): void
    {
        $this->dbtable = $table;
    }
}

/**
 * `create:model` against a live table, and against a table that does not exist yet.
 *
 * Two entry points into one generator, and the second is the interesting one. A model is *always* a
 * full CRUD artifact built from the schema — the old schema-less skeleton was removed — so with no
 * live table there are exactly two possibilities: wizard column definitions were supplied, in which
 * case the migration exists but has not run yet, or they were not, in which case there is nothing to
 * generate from. **The second fails loudly**, and that is the decision worth pinning: emitting a
 * model with no fields would be a class that loads, saves nothing, and looks like the generator
 * worked.
 *
 * The rest is derivation from the column report, and the failures are the quiet kind — a model whose
 * primary key is wrong saves a new row every time instead of updating, which reads as "the edit form
 * does not work".
 *
 * Generates into the repository and removes it afterwards, including the test stub it emits and the
 * registry entry it records. Both backends: {@see GeneratedModelPostgreSQLTest} — the column report
 * this reads is the driver's, and the primary key comes out of it.
 */
#[CoversClass(MakeCommandBase::class)]
class GeneratedModelTest extends BaseTestCase
{
    private $db;

    private GeneratedModelDummyCommand $command;

    private string $table = '';

    private array $written = [];

    private ?string $savedRegistry = null;

    private bool $hadRegistry = false;

    private const ENTITY = 'ModelProbe';

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

        $this->table = 'modelprobe_' . bin2hex(random_bytes(4));
        $this->createProbeTable();

        // The registry is a real file in this repository; put it back exactly as it was.
        $registry = ROOT . DS . 'app' . DS . 'model-registry.json';
        $this->hadRegistry = is_file($registry);
        $this->savedRegistry = $this->hadRegistry ? (string) file_get_contents($registry) : null;

        $this->command = new GeneratedModelDummyCommand();
        $this->command->setDbTable($this->table);

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
        $this->command->setApplication($consoleApp);
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    private function createProbeTable(): void
    {
        $quote = $this->db->type === 'postgresql' ? '"' : '`';
        $q = static fn (string $name): string => $quote . $name . $quote;

        if ($this->db->type === 'postgresql') {
            $this->db->query(
                'CREATE TABLE ' . $q($this->table) . ' ('
                . $q('probeid') . ' SERIAL PRIMARY KEY, '
                . $q('title') . ' VARCHAR(255) NOT NULL, '
                . $q('quantity') . ' INTEGER NULL, '
                . $q('notes') . ' TEXT NULL)'
            );
        } else {
            $this->db->query(
                'CREATE TABLE ' . $q($this->table) . ' ('
                . $q('probeid') . ' INT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . $q('title') . ' VARCHAR(255) NOT NULL, '
                . $q('quantity') . ' INT NULL, '
                . $q('notes') . ' TEXT NULL) ENGINE=InnoDB'
            );
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }
        $this->written = [];

        /*
         * The emitted test stub, wherever it landed.
         *
         * `create:model` writes a schema-aware PHPUnit test alongside the model, and it extends the
         * *scaffolded project's* base test case — so leaving one in this repository breaks the
         * framework's own suite on the next run, in a file nobody wrote.
         */
        foreach ((array) glob(ROOT . '/tests/Unit/Models/*Test.php') as $stub) {
            @unlink($stub);
        }
        if (is_dir(ROOT . '/tests/Unit/Models') && (glob(ROOT . '/tests/Unit/Models/*') ?: []) === []) {
            @rmdir(ROOT . '/tests/Unit/Models');
        }

        $registry = ROOT . DS . 'app' . DS . 'model-registry.json';
        if ($this->hadRegistry && $this->savedRegistry !== null) {
            file_put_contents($registry, $this->savedRegistry);
        } elseif (is_file($registry)) {
            @unlink($registry);
        }

        if ($this->table !== '') {
            $quote = $this->db->type === 'postgresql' ? '"' : '`';
            try {
                $this->db->query('DROP TABLE IF EXISTS ' . $quote . $this->table . $quote);
            } catch (\Throwable) {
                // Nothing to drop.
            }
        }

        parent::tearDown();
    }

    private function expectedFile(string $entity): string
    {
        $dir  = ROOT . DS . INCLUDES . DS . 'Models';
        $file = $dir . DS . MakeCommandBase::getProperClassName($entity, true) . '.php';

        $this->written = [$file, $dir];

        return $file;
    }

    // ── From a live table ─────────────────────────────────────────────────────

    /**
     * A model is generated from the table, naming its table and its key.
     *
     * The two values everything else depends on: the wrong table is a model that queries something
     * else, and the wrong primary key is a model that inserts a new row on every save instead of
     * updating — which reads as "the edit form does not work" rather than as a generator bug.
     */
    public function testAModelIsGeneratedFromTheTable(): void
    {
        // Arrange
        $file = $this->expectedFile(self::ENTITY);

        // Act
        $summary = $this->command->callCreateModel(self::ENTITY);
        $content = (string) file_get_contents($file);

        // Assert
        $this->assertFileExists($file, 'the generator reported success and wrote no file');
        $this->assertStringContainsString('Model created', $summary);
        $this->assertStringContainsString('namespace App\\Models;', $content);
        $this->assertStringContainsString($this->table, $content, 'the model names another table');
        $this->assertStringContainsString(
            '$_primaryKey = "probeid"',
            $content,
            "the model declares a primary key the table does not have, so it can never load a row"
        );
    }

    /**
     * The primary key is read from the table, not guessed from its name.
     *
     * This is the fix this test found. The generator derived the key by convention — singular table
     * name plus `id` — which is right for a table the toolchain generated and wrong for most legacy
     * schemas: `customers` keyed on `customer_id` produced a model declaring `customerid`, and a
     * model whose primary key is not a column loads nothing and inserts a new row on every save,
     * which presents as «the edit form does not work».
     *
     * `createApi()` had always read the key out of the column report, so the two generators
     * disagreed about the same table — the controller addressing one column and the model another.
     * The probe table here deliberately breaks the convention, which is the only way to tell the two
     * behaviours apart.
     */
    public function testThePrimaryKeyIsReadFromTheTableNotGuessedFromItsName(): void
    {
        // Arrange — the table is `modelprobe_<hex>`, so the convention would say
        // `modelprobe_<hex>id`. Its actual key is `probeid`.
        $file = $this->expectedFile(self::ENTITY);
        $conventional = strtolower($this->table) . 'id';

        // Act
        $this->command->callCreateModel(self::ENTITY);
        $content = (string) file_get_contents($file);

        // Assert
        $this->assertStringContainsString('$_primaryKey = "probeid"', $content);
        $this->assertStringNotContainsString(
            $conventional,
            $content,
            'the key was guessed from the table name, so the model addresses a column that does not exist'
        );
    }

    /**
     * Every column reaches the generated class.
     *
     * A column the model does not know about is one a save silently drops — the form posts it, the
     * model ignores it, and the value is gone with no error anywhere.
     */
    public function testEveryColumnReachesTheModel(): void
    {
        // Arrange
        $file = $this->expectedFile(self::ENTITY);

        // Act
        $this->command->callCreateModel(self::ENTITY);
        $content = (string) file_get_contents($file);

        // Assert
        foreach (['title', 'quantity', 'notes'] as $column) {
            $this->assertStringContainsString($column, $content, $column . ' was dropped');
        }
    }

    /**
     * The generated model is valid PHP.
     *
     * The assertion that makes the others worth making: a syntax error here is a fatal on the first
     * autoload, and the developer's first sight of their new model is a parse error.
     */
    public function testTheGeneratedModelIsValidPhp(): void
    {
        // Arrange
        $file = $this->expectedFile(self::ENTITY);

        // Act
        $this->command->callCreateModel(self::ENTITY);

        $output = [];
        $status = 0;
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);

        // Assert
        $this->assertSame(0, $status, 'the generated model does not parse: ' . implode("\n", $output));
    }

    /**
     * It is registered, so a later `create:api` can find its namespace.
     *
     * The registry is how the generators agree with each other: without the entry, a controller
     * generated afterwards guesses the model's namespace, and a wrong guess is a `class not found`
     * on the endpoint's first request.
     */
    public function testTheModelIsRecordedInTheRegistry(): void
    {
        // Arrange
        $this->expectedFile(self::ENTITY);

        // Act
        $this->command->callCreateModel(self::ENTITY);
        $registry = (string) @file_get_contents(ROOT . DS . 'app' . DS . 'model-registry.json');

        // Assert
        $this->assertStringContainsString('ModelProbe', $registry, 'the model was not registered');
        $this->assertStringContainsString($this->table, $registry);
    }

    // ── Without one ───────────────────────────────────────────────────────────

    /**
     * With no table and no wizard columns, it refuses and says what to run.
     *
     * The decision this branch exists for. A model is always built from a schema, so with neither
     * source there is nothing to generate — and emitting a fieldless class would be a model that
     * loads, saves nothing, and looks exactly like a generator that worked. The message names
     * `create:migration`, because that is the actual next step and the person reading it is in the
     * middle of something else.
     */
    public function testWithNoTableAndNoColumnsItRefusesAndSaysWhatToRun(): void
    {
        // Arrange
        $this->command->setDbTable('no_such_model_table');

        // Act & Assert
        try {
            $this->command->callCreateModel('AbsentProbe');
            $this->fail('a model was generated with no schema behind it');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('no_such_model_table', $exception->getMessage());
            $this->assertStringContainsString('create:migration', $exception->getMessage());
        }
    }

    /**
     * With wizard columns it generates without a live table — schema first.
     *
     * The migration wizard writes the migration and generates the model in one go, before anything
     * has run, so this path has to work with the database knowing nothing about the table yet.
     * Requiring the table would make the wizard a two-step process with a failure in the middle.
     */
    public function testWizardColumnsGenerateWithoutALiveTable(): void
    {
        // Arrange
        $this->command->setDbTable('not_created_yet_probe');
        $file = $this->expectedFile('WizardProbe');

        $columns = [
            ['name' => 'wizardprobeid', 'type' => 'integer', 'nullable' => false, 'primary' => true],
            ['name' => 'label', 'type' => 'string', 'nullable' => false],
            ['name' => 'amount', 'type' => 'integer', 'nullable' => true],
        ];

        // Act
        $summary = $this->command->callCreateModel('WizardProbe', $columns, []);

        // Assert
        $this->assertFileExists($file, 'the wizard path needs a live table, which it must not');
        $content = (string) file_get_contents($file);
        $this->assertStringContainsString('not_created_yet_probe', $content);
        $this->assertStringContainsString('label', $content);
        $this->assertStringContainsString('Model created', $summary);
    }
}
