<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\MakeCommandBase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Concrete subclass that exposes protected/private methods for coverage testing.
 */
class CoverageDummyMakeCommand extends MakeCommandBase
{
    protected function configure(): void {}
    protected function execute(InputInterface $input, OutputInterface $output): int { return 0; }

    public function callPrepareExecution(InputInterface $input, OutputInterface $output): void
    {
        $this->prepareExecution($input, $output);
    }

    public function callAddCommonOptions(): void
    {
        $this->addCommonOptions();
    }

    public function callGetSingularPrimaryKey(string $tableName): string
    {
        return $this->getSingularPrimaryKey($tableName);
    }

    public function callGetFullTableName(string $table, bool $addSchema = true): string
    {
        return $this->getFullTableName($table, $addSchema);
    }

    public function callCreateModel(string $name, array $columns = [], array $fks = []): string
    {
        return $this->createModel($name, $columns, $fks);
    }

    public function callCreateController(string $name, bool $full = false, array $cols = [], array $fks = []): string
    {
        return $this->createController($name, $full, $cols, $fks);
    }

    public function callCreateControllerAndViewsFromWizard(
        string $name,
        string $namespace,
        string $modelNameSpace,
        string $modelClass,
        string $className,
        string $tableName,
        string $path,
        array  $columns,
        array  $foreignKeys,
        string $controllerFile
    ): string {
        return $this->createControllerAndViewsFromWizard(
            $name, $namespace, $modelNameSpace, $modelClass,
            $className, $tableName, $path, $columns, $foreignKeys, $controllerFile
        );
    }

    public function callCreateCrud(string $name): string
    {
        return $this->createCrud($name);
    }

    public function callLookupModel(string $name, bool $forceSingular = true): array
    {
        return $this->lookupModel($name, $forceSingular);
    }

    public function callCreateView(string $name, bool $full = false): string
    {
        return $this->createView($name, $full);
    }

    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function setDbTable(?string $table): void
    {
        $this->dbtable = $table;
    }

    public function setSchema(?string $schema): void
    {
        $this->schema = $schema;
    }
}

/**
 * Coverage-focused tests for MakeCommandBase targeting branches not yet covered
 * by MakeCommandBaseTest.php, MakeCommandGeneratorsTest.php, and
 * MakeCommandBaseExtendedTest.php.
 *
 * Focus areas:
 *  1. prepareExecution() + addCommonOptions()
 *  2. getSingularPrimaryKey() public delegator
 *  3. getFullTableName() — MySQL, PostgreSQL, with/without schema
 *  4. buildModelFromWizardColumns() — schema block, json/float types, float arrayFix
 *  5. createModel() — wizard path (table does not exist)
 *  6. createControllerAndViewsFromWizard() — wizard columns path including FK
 *  7. lookupModel() — convention fallback, forceSingular=false
 *  8. createCrud() — FAIL branches (model/controller/view throw exceptions)
 *  9. createSeeder() with wizard columns (fieldsCode from buildSeederFields)
 * 10. createView() basic (non-full) path — verifies index/edit/show file creation
 */
#[CoversClass(MakeCommandBase::class)]
class MakeCommandBaseCoverageTest extends TestCase
{
    private string $tmpDir;
    private CoverageDummyMakeCommand $command;

    // =========================================================================
    // Infrastructure
    // =========================================================================

    protected function setUp(): void
    {
        // Arrange — temp workspace and command wired to a minimal application stub.
        $this->tmpDir = sys_get_temp_dir() . '/pramnos_cov_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/tests/Unit', 0777, true);
        mkdir($this->tmpDir . '/tests/Feature', 0777, true);

        $this->command = new CoverageDummyMakeCommand();

        $consoleApp = new class extends \Symfony\Component\Console\Application {
            public $internalApplication;
        };
        $consoleApp->internalApplication = new class extends \Pramnos\Application\Application {
            public $applicationInfo = ['namespace' => 'App', 'scaffold_theme' => 'plain-css'];
            public $appName = '';
            public function __construct() {}
            public function init($settingsFile = ''): void {}
        };
        $this->command->setApplication($consoleApp);

        // Make sure INCLUDES is defined (needed by view-path resolution).
        if (!defined('INCLUDES')) {
            define('INCLUDES', 'src');
        }
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);

        // Remove any view directories created under ROOT/src/Views/ by these tests.
        $viewsBase = ROOT . DS . INCLUDES . DS . 'Views';
        foreach ([
            'wcov_item', 'wcov_product', 'wcov_doc', 'wcovdoc', 'wcov_art', 'wcov_thing',
            'wcov_simple', 'wcov_nodbview',
        ] as $name) {
            $dir = $viewsBase . DS . $name;
            if (is_dir($dir)) {
                $this->rmdirRecursive($dir);
            }
        }

        // Remove any model/controller files created under ROOT/src/ by these tests.
        $toRemove = [
            ROOT . DS . INCLUDES . DS . 'Models'      . DS . 'WcovWizardItem.php',
            ROOT . DS . INCLUDES . DS . 'Models'      . DS . 'WcovJsonModel.php',
            ROOT . DS . INCLUDES . DS . 'Models'      . DS . 'WcovSchemaModel.php',
            ROOT . DS . INCLUDES . DS . 'Models'      . DS . 'WcovDoc.php',
            ROOT . DS . INCLUDES . DS . 'Controllers' . DS . 'WcovWizarditems.php',
            ROOT . DS . INCLUDES . DS . 'Controllers' . DS . 'WcovWizardproducts.php',
            ROOT . DS . INCLUDES . DS . 'Controllers' . DS . 'WcovFkctrl.php',
            ROOT . DS . INCLUDES . DS . 'Controllers' . DS . 'WcovDocs.php',
            ROOT . DS . 'tests'  . DS . 'Unit'        . DS . 'WcovWizardItemTest.php',
            ROOT . DS . 'tests'  . DS . 'Unit'        . DS . 'WcovJsonModelTest.php',
            ROOT . DS . 'tests'  . DS . 'Unit'        . DS . 'WcovSchemaModelTest.php',
            ROOT . DS . 'tests'  . DS . 'Unit'        . DS . 'WcovDocTest.php',
            ROOT . DS . 'tests'  . DS . 'Unit'        . DS . 'WcovWizardItemSeederTest.php',
            ROOT . DS . 'tests'  . DS . 'Feature'     . DS . 'WcovWizarditemsTest.php',
            ROOT . DS . 'tests'  . DS . 'Feature'     . DS . 'WcovWizardproductsTest.php',
            ROOT . DS . 'tests'  . DS . 'Feature'     . DS . 'WcovFkctrlTest.php',
            APP_PATH . DS . 'seeders' . DS . 'WcovWizardItemSeeder.php',
        ];
        foreach ($toRemove as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }

        // Clean registry entries added during tests.
        $registryFile = ROOT . DS . 'app' . DS . 'model-registry.json';
        if (file_exists($registryFile)) {
            $data = json_decode(file_get_contents($registryFile), true) ?? [];
            $testClasses = [
                'WcovWizardItem', 'WcovJsonModel', 'WcovSchemaModel', 'WcovDoc',
                'WcovWizardproducts', 'WcovWizarditems',
            ];
            $filtered = array_values(array_filter($data, function ($e) use ($testClasses) {
                return !in_array($e['className'] ?? '', $testClasses, true);
            }));
            if (empty($filtered)) {
                @unlink($registryFile);
            } else {
                file_put_contents($registryFile, json_encode($filtered, JSON_PRETTY_PRINT));
            }
        }

        // Remove the empty Models, Controllers, and Views directories so they don't linger
        foreach ([
            ROOT . DS . INCLUDES . DS . 'Models',
            ROOT . DS . INCLUDES . DS . 'Controllers',
            ROOT . DS . INCLUDES . DS . 'Views',
        ] as $dir) {
            if (is_dir($dir)) {
                $files = array_diff(scandir($dir), ['.', '..']);
                if (empty($files)) {
                    @rmdir($dir);
                }
            }
        }
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** Build a minimal mock Database that says every table exists. */
    private function buildDbMockAllTablesExist(): \Pramnos\Database\Database
    {
        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->method('tableExists')->willReturn(true);
        $db->method('getColumns')->willReturn(new class {
            private int $index = 0;
            public array $fields = [];
            private array $data = [
                ['Field' => 'testid', 'Type' => 'int(11)', 'Null' => 'NO', 'Key' => 'PRI', 'Comment' => 'Primary Key', 'PrimaryKey' => true, 'ForeignKey' => false],
                ['Field' => 'label',  'Type' => 'varchar(255)', 'Null' => 'NO', 'Key' => '', 'Comment' => '', 'PrimaryKey' => false, 'ForeignKey' => false],
            ];
            public function fetch(): bool {
                if ($this->index < count($this->data)) {
                    $this->fields = $this->data[$this->index++];
                    return true;
                }
                return false;
            }
        });
        $db->type   = 'mysql';
        $db->prefix = '';
        $db->schema = '';
        return $db;
    }

    // =========================================================================
    // 1. prepareExecution() + addCommonOptions()
    // =========================================================================

    /**
     * prepareExecution() reads schema/table options from the InputInterface and
     * stores them in $this->schema and $this->dbtable.
     *
     * These properties are read by every generate* method — they must be
     * correctly populated before any generation step.
     */
    public function testPrepareExecutionPopulatesSchemaAndTable(): void
    {
        // Arrange — configure options on the command before creating the input
        $this->command->callAddCommonOptions();
        $input = new ArrayInput(
            ['--schema' => 'myschema', '--table' => 'mytable'],
            $this->command->getDefinition()
        );
        $output = new BufferedOutput();

        // Act
        $this->command->callPrepareExecution($input, $output);

        // Assert — $this->schema and $this->dbtable are set from input options.
        // We read them back via getFullTableName() which accesses $this->schema.
        // We can also verify via reflection.
        $refl = new \ReflectionClass($this->command);

        $schemaProp = $refl->getProperty('schema');
        $this->assertSame('myschema', $schemaProp->getValue($this->command),
            'prepareExecution() must populate $this->schema from the --schema option');

        $tableProp = $refl->getProperty('dbtable');
        $this->assertSame('mytable', $tableProp->getValue($this->command),
            'prepareExecution() must populate $this->dbtable from the --table option');
    }

    /**
     * addCommonOptions() registers three arguments/options on the command:
     * 'name' (argument), 'schema' (option), 'table' (option).
     *
     * This must succeed without throwing — it is called during command
     * configuration and must not interfere with other existing options.
     */
    public function testAddCommonOptionsRegistersExpectedOptions(): void
    {
        // Act — register options on a command that has no definition yet
        $this->command->callAddCommonOptions();

        // Assert — definition now knows about the three items
        $def = $this->command->getDefinition();
        $this->assertTrue($def->hasArgument('name'),
            'addCommonOptions() must register a "name" argument');
        $this->assertTrue($def->hasOption('schema'),
            'addCommonOptions() must register a --schema option');
        $this->assertTrue($def->hasOption('table'),
            'addCommonOptions() must register a --table option');
    }

    // =========================================================================
    // 2. getSingularPrimaryKey() delegator
    // =========================================================================

    /**
     * getSingularPrimaryKey() is a protected delegator to BlueprintCompiler.
     * It derives the primary key column name from the table name by stripping
     * #PREFIX#, removing trailing 's', and appending 'id'.
     *
     * The derived key must match what buildMigrationUpBody() uses inside
     * $table->increments(), so the model and migration stay in sync.
     */
    public function testGetSingularPrimaryKeyDerivesCorrectKey(): void
    {
        // Act — test a few common table names
        $usersKey    = $this->command->callGetSingularPrimaryKey('#PREFIX#users');
        $ordersKey   = $this->command->callGetSingularPrimaryKey('#PREFIX#orders');
        $productsKey = $this->command->callGetSingularPrimaryKey('#PREFIX#products');

        // Assert — 'users' → 'userid', 'orders' → 'orderid', 'products' → 'productid'
        $this->assertStringEndsWith('id', $usersKey,
            'getSingularPrimaryKey() must end with "id"');
        $this->assertStringContainsString('user', $usersKey,
            'Primary key for "users" table must contain "user"');
        $this->assertStringContainsString('order', $ordersKey,
            'Primary key for "orders" table must contain "order"');
        $this->assertStringContainsString('product', $productsKey,
            'Primary key for "products" table must contain "product"');
    }

    // =========================================================================
    // 3. getFullTableName()
    // =========================================================================

    /**
     * getFullTableName() with addSchema=false simply substitutes #PREFIX# with
     * the database prefix, regardless of database type.
     *
     * Used when schema qualification is explicitly not wanted (e.g. FK names).
     */
    public function testGetFullTableNameWithoutSchema(): void
    {
        // Arrange — inject a DB mock where prefix = 'app_'
        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->type   = 'mysql';
        $db->prefix = 'app_';
        $db->schema = '';
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $originalDb = $dbRef;
        $dbRef = $db;

        // Act
        $result = $this->command->callGetFullTableName('#PREFIX#products', false);

        // Restore
        $dbRef = $originalDb;

        // Assert — only prefix substitution, no schema prefix
        $this->assertSame('app_products', $result,
            'getFullTableName(addSchema=false) must only replace #PREFIX#, not add schema');
    }

    /**
     * getFullTableName() with addSchema=true on a MySQL connection behaves
     * exactly like addSchema=false (MySQL does not use schema-qualified names).
     *
     * The schema prefix is PostgreSQL-specific — MySQL uses a single flat namespace.
     */
    public function testGetFullTableNameMysqlIgnoresSchema(): void
    {
        // Arrange
        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->type   = 'mysql';
        $db->prefix = 'pfx_';
        $db->schema = 'ignored_schema';
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $originalDb = $dbRef;
        $dbRef = $db;

        // Act
        $result = $this->command->callGetFullTableName('#PREFIX#orders', true);

        // Restore
        $dbRef = $originalDb;

        // Assert — no schema.tableName — only prefix substitution
        $this->assertSame('pfx_orders', $result,
            'MySQL getFullTableName() must not prepend schema even when $this->schema is set');
        $this->assertStringNotContainsString('ignored_schema', $result,
            'MySQL result must not contain the schema name');
    }

    /**
     * getFullTableName() with addSchema=true on a PostgreSQL connection and
     * $this->schema set prepends schema.tableName.
     *
     * PostgreSQL requires schema-qualified table names when the schema is not
     * the default 'public'.
     */
    public function testGetFullTableNamePostgresqlWithInstanceSchema(): void
    {
        // Arrange — set command-level schema override
        $this->command->setSchema('myns');

        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->type   = 'postgresql';
        $db->prefix = '';
        $db->schema = 'public';
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $originalDb = $dbRef;
        $dbRef = $db;

        // Act
        $result = $this->command->callGetFullTableName('#PREFIX#users', true);

        // Restore
        $dbRef = $originalDb;
        $this->command->setSchema(null);

        // Assert — schema.tableName form
        $this->assertSame('myns.users', $result,
            'PostgreSQL getFullTableName() must prepend $this->schema when set');
    }

    /**
     * getFullTableName() falls back to $database->schema when $this->schema is
     * null on a PostgreSQL connection.
     *
     * This allows the command to work correctly even when --schema is not
     * passed explicitly, as long as the database was configured with a schema.
     */
    public function testGetFullTableNamePostgresqlFallsBackToDatabaseSchema(): void
    {
        // Arrange — no command-level schema, but DB has one
        $this->command->setSchema(null);

        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->type   = 'postgresql';
        $db->prefix = '';
        $db->schema = 'analytics';
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $originalDb = $dbRef;
        $dbRef = $db;

        // Act
        $result = $this->command->callGetFullTableName('#PREFIX#events', true);

        // Restore
        $dbRef = $originalDb;

        // Assert — uses $database->schema as fallback
        $this->assertSame('analytics.events', $result,
            'PostgreSQL getFullTableName() must fall back to $database->schema when $this->schema is null');
    }

    // =========================================================================
    // 4. buildModelFromWizardColumns() — additional type branches
    // =========================================================================

    /**
     * buildModelFromWizardColumns() emits a $_dbschema property block when
     * $this->schema is set on the command instance.
     *
     * This is required so that models used against a specific PostgreSQL schema
     * do not accidentally access the default 'public' schema.
     */
    public function testBuildModelFromWizardColumnsEmitsSchemaBlock(): void
    {
        // Arrange — set a schema on the command
        $this->command->setSchema('reports');

        $columns = [
            ['name' => 'metric', 'type' => 'string', 'options' => [], 'nullable' => false, 'default' => '', 'comment' => '', 'unique' => false, 'unsigned' => false],
        ];

        // Act
        $source = $this->command->buildModelFromWizardColumns(
            'App\\Models', 'WcovSchemaModel', '#PREFIX#metrics', $columns, []
        );

        // Cleanup
        $this->command->setSchema(null);

        // Assert — $_dbschema property with value 'reports'
        $this->assertStringContainsString("_dbschema = 'reports'", $source,
            'Schema-aware model must contain $_dbschema property with the correct value');
    }

    /**
     * buildModelFromWizardColumns() maps the 'json' wizard type to @var array
     * and does NOT add an arrayFix cast (json is decoded to array by the ORM).
     *
     * The @var array annotation signals to IDE tools that the property holds
     * an array, not the raw JSON string.
     */
    public function testBuildModelFromWizardColumnsJsonTypeBecomesArray(): void
    {
        // Arrange
        $columns = [
            ['name' => 'settings', 'type' => 'json', 'options' => [], 'nullable' => true, 'default' => null, 'comment' => 'Config', 'unique' => false, 'unsigned' => false],
        ];

        // Act
        $source = $this->command->buildModelFromWizardColumns(
            'App\\Models', 'WcovJsonModel', '#PREFIX#json_models', $columns, []
        );

        // Assert — @var array annotation for JSON column
        $this->assertStringContainsString('@var array', $source,
            'json column type must map to @var array in the model property docblock');

        // Assert — the column name appears as a public property
        $this->assertStringContainsString('public $settings', $source,
            'json column must produce a public property in the model');
    }

    /**
     * buildModelFromWizardColumns() maps 'float' to @var float and emits an
     * arrayFix cast so getData() returns a PHP float, not a string.
     *
     * Without the cast, JSON-encoded model data would contain strings for
     * float fields, breaking API consumers.
     */
    public function testBuildModelFromWizardColumnsFloatEmitsArrayFixCast(): void
    {
        // Arrange
        $columns = [
            ['name' => 'ratio', 'type' => 'float', 'options' => [], 'nullable' => false, 'default' => null, 'comment' => '', 'unique' => false, 'unsigned' => false],
        ];

        // Act
        $source = $this->command->buildModelFromWizardColumns(
            'App\\Models', 'WcovJsonModel', '#PREFIX#items', $columns, []
        );

        // Assert — @var float type annotation
        $this->assertStringContainsString('@var float', $source,
            'float column must produce @var float annotation');

        // Assert — getData() arrayFix casts the value to float
        $this->assertStringContainsString("(float) \$this->ratio", $source,
            'float column must be cast to (float) in getData() arrayFix');
    }

    /**
     * buildModelFromWizardColumns() maps 'double' and 'decimal' wizard types
     * to @var float (same PHP representation) with arrayFix casts.
     */
    public function testBuildModelFromWizardColumnsDoubleAndDecimalMapToFloat(): void
    {
        // Arrange
        $columns = [
            ['name' => 'price',  'type' => 'decimal', 'options' => [], 'nullable' => false, 'default' => null, 'comment' => '', 'unique' => false, 'unsigned' => false],
            ['name' => 'score',  'type' => 'double',  'options' => [], 'nullable' => false, 'default' => null, 'comment' => '', 'unique' => false, 'unsigned' => false],
        ];

        // Act
        $source = $this->command->buildModelFromWizardColumns(
            'App\\Models', 'WcovJsonModel', '#PREFIX#items', $columns, []
        );

        // Assert — both map to @var float and get arrayFix casts
        $this->assertStringContainsString("(float) \$this->price", $source,
            'decimal column must be cast to float in getData()');
        $this->assertStringContainsString("(float) \$this->score", $source,
            'double column must be cast to float in getData()');
    }

    // =========================================================================
    // 5. createModel() — wizard path (table does not exist in DB)
    // =========================================================================

    /**
     * createModel() with wizard columns generates a full model PHP file even
     * when the table does not yet exist in the database (schema-first workflow).
     *
     * This is the core of the migration-wizard flow: migration is created first,
     * then the model is generated from the same schema definition — no DB
     * round-trip required.
     */
    public function testCreateModelWizardPathGeneratesFileWhenTableDoesNotExist(): void
    {
        // Arrange — DB says table does not exist
        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->method('tableExists')->willReturn(false);
        $db->type   = 'mysql';
        $db->prefix = '';
        $db->schema = '';
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $originalDb = $dbRef;
        $dbRef = $db;

        $columns = [
            ['name' => 'title',    'type' => 'string',  'options' => [], 'nullable' => false, 'default' => '', 'comment' => '', 'unique' => false, 'unsigned' => false],
            ['name' => 'quantity', 'type' => 'integer', 'options' => [], 'nullable' => false, 'default' => null, 'comment' => 'Qty', 'unique' => false, 'unsigned' => false],
        ];

        // Act
        try {
            $result = $this->command->callCreateModel('WcovWizardItem', $columns);
        } finally {
            $dbRef = $originalDb;
        }

        // Assert — summary mentions the class name and that the model was created
        $this->assertStringContainsString('WcovWizardItem', $result,
            'createModel() summary must include the class name');
        $this->assertStringContainsString('Model', $result,
            'createModel() summary must confirm model creation');

        // Assert — file was written at the expected path
        $modelFile = ROOT . DS . INCLUDES . DS . 'Models' . DS . 'WcovWizardItem.php';
        $this->assertFileExists($modelFile,
            'createModel() must write the model file to src/Models/');

        // Assert — generated file contains wizard-column properties
        $content = file_get_contents($modelFile);
        $this->assertStringContainsString('public $title', $content,
            'Generated model must have $title property from wizard columns');
        $this->assertStringContainsString('public $quantity', $content,
            'Generated model must have $quantity property from wizard columns');
        $this->assertStringContainsString("Qty", $content,
            'Generated model must include column comment in docblock');
    }

    /**
     * createModel() with wizard columns and empty $wizardColumns falls back to
     * the stub renderer (model.stub) when the table does not exist.
     *
     * The stub produces a skeleton model without DB-introspection.
     */
    public function testCreateModelStubFallbackWhenTableMissingAndNoWizardColumns(): void
    {
        // Arrange — DB says table does not exist, no wizard columns supplied
        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->method('tableExists')->willReturn(false);
        $db->type   = 'mysql';
        $db->prefix = '';
        $db->schema = '';
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $originalDb = $dbRef;
        $dbRef = $db;

        try {
            // Act — no wizard columns
            $result = $this->command->callCreateModel('WcovSchemaModel', []);
        } finally {
            $dbRef = $originalDb;
        }

        // Assert — result mentions the class name
        $this->assertStringContainsString('WcovSchemaModel', $result);

        // Assert — file was written
        $modelFile = ROOT . DS . INCLUDES . DS . 'Models' . DS . 'WcovSchemaModel.php';
        $this->assertFileExists($modelFile,
            'createModel() stub fallback must write the model file');

        // Assert — file is non-empty PHP
        $content = file_get_contents($modelFile);
        $this->assertStringContainsString('<?php', $content);
        $this->assertStringContainsString('WcovSchemaModel', $content);
    }

    // =========================================================================
    // 6. createControllerAndViewsFromWizard()
    // =========================================================================

    /**
     * createControllerAndViewsFromWizard() generates a controller PHP file and
     * three view files from wizard column definitions (no DB round-trip).
     *
     * This is the post-migration scaffold step when the wizard has column
     * definitions available.
     */
    public function testCreateControllerAndViewsFromWizardGeneratesAllFiles(): void
    {
        // Arrange — ensure paths exist
        $ctrlPath = ROOT . DS . INCLUDES . DS . 'Controllers';
        if (!is_dir($ctrlPath)) {
            mkdir($ctrlPath, 0755, true);
        }
        $viewsBase = ROOT . DS . INCLUDES . DS . 'Views';
        if (!is_dir($viewsBase)) {
            mkdir($viewsBase, 0755, true);
        }

        $ctrlFile = $ctrlPath . DS . 'WcovWizarditems.php';
        if (file_exists($ctrlFile)) {
            unlink($ctrlFile);
        }

        $columns = [
            ['name' => 'name',   'type' => 'string',  'options' => [], 'nullable' => false, 'default' => '', 'comment' => 'Name', 'unique' => false, 'unsigned' => false],
            ['name' => 'active', 'type' => 'boolean', 'options' => [], 'nullable' => false, 'default' => '1', 'comment' => '', 'unique' => false, 'unsigned' => false],
        ];
        $this->command->setOutput(new BufferedOutput());

        // Act
        $result = $this->command->callCreateControllerAndViewsFromWizard(
            'wcov_item',
            'App\\Controllers',
            'App\\Models',
            'WcovWizardItem',
            'WcovWizarditems',
            '#PREFIX#wcov_items',
            $ctrlPath,
            $columns,
            [],
            $ctrlFile
        );

        // Assert — controller file created
        $this->assertFileExists($ctrlFile,
            'createControllerAndViewsFromWizard() must write the controller file');

        // Assert — controller content has expected methods
        $ctrlContent = file_get_contents($ctrlFile);
        $this->assertStringContainsString('public function display()', $ctrlContent,
            'Generated controller must have display() method');
        $this->assertStringContainsString('public function save()', $ctrlContent,
            'Generated controller must have save() method');

        // Assert — view files created
        $viewDir = $viewsBase . DS . 'wcov_item';
        $this->assertDirectoryExists($viewDir,
            'createControllerAndViewsFromWizard() must create the view directory');
        $this->assertFileExists($viewDir . DS . 'wcov_item.html.php',
            'List view must be created');
        $this->assertFileExists($viewDir . DS . 'edit.html.php',
            'Edit view must be created');
        $this->assertFileExists($viewDir . DS . 'show.html.php',
            'Show view must be created');

        // Assert — summary mentions controller creation
        $this->assertStringContainsString('Controller created', $result,
            'Return value must confirm controller creation');
    }

    /**
     * createControllerAndViewsFromWizard() inserts FK-loading code in the
     * edit() method when a FK column is present in the wizard columns.
     *
     * FK columns need their referenced model loaded for the select dropdown
     * in the edit form.
     */
    public function testCreateControllerAndViewsFromWizardIncludesFkLoadCode(): void
    {
        // Arrange
        $ctrlPath = ROOT . DS . INCLUDES . DS . 'Controllers';
        if (!is_dir($ctrlPath)) {
            mkdir($ctrlPath, 0755, true);
        }

        $ctrlFile = $ctrlPath . DS . 'WcovFkctrl.php';
        if (file_exists($ctrlFile)) {
            unlink($ctrlFile);
        }

        $columns = [
            ['name' => 'category_id', 'type' => 'biginteger', 'options' => [], 'nullable' => false, 'default' => null, 'comment' => '', 'unique' => false, 'unsigned' => true],
        ];
        $foreignKeys = [
            ['column' => 'category_id', 'references' => 'categoryid', 'on' => '#PREFIX#categories', 'onDelete' => 'RESTRICT', 'onUpdate' => 'RESTRICT'],
        ];
        $this->command->setOutput(new BufferedOutput());

        // Act
        $result = $this->command->callCreateControllerAndViewsFromWizard(
            'wcov_art',
            'App\\Controllers',
            'App\\Models',
            'WcovArt',
            'WcovFkctrl',
            '#PREFIX#wcov_arts',
            $ctrlPath,
            $columns,
            $foreignKeys,
            $ctrlFile
        );

        // Assert — controller file has FK loading code in edit()
        $content = file_get_contents($ctrlFile);
        $this->assertStringContainsString('List', $content,
            'FK controller must contain ->getList() call to load FK options');
    }

    /**
     * createControllerAndViewsFromWizard() generates a User FK load line that
     * calls User::getUsers() when the FK references the users table.
     *
     * User FKs are ubiquitous (created_by, owner_id, etc.) and get special
     * handling to avoid requiring a User model lookup.
     */
    public function testCreateControllerAndViewsFromWizardUserFkCallsGetUsers(): void
    {
        // Arrange
        $ctrlPath = ROOT . DS . INCLUDES . DS . 'Controllers';
        if (!is_dir($ctrlPath)) {
            mkdir($ctrlPath, 0755, true);
        }

        $ctrlFile = $ctrlPath . DS . 'WcovWizardproducts.php';
        if (file_exists($ctrlFile)) {
            unlink($ctrlFile);
        }

        $columns = [
            ['name' => 'user_id', 'type' => 'biginteger', 'options' => [], 'nullable' => false, 'default' => null, 'comment' => 'Owner', 'unique' => false, 'unsigned' => true],
        ];
        $foreignKeys = [
            ['column' => 'user_id', 'references' => 'userid', 'on' => '#PREFIX#users', 'onDelete' => 'CASCADE', 'onUpdate' => 'CASCADE'],
        ];
        $this->command->setOutput(new BufferedOutput());

        // Act
        $this->command->callCreateControllerAndViewsFromWizard(
            'wcov_product',
            'App\\Controllers',
            'App\\Models',
            'WcovProduct',
            'WcovWizardproducts',
            '#PREFIX#wcov_products',
            $ctrlPath,
            $columns,
            $foreignKeys,
            $ctrlFile
        );

        // Assert — user list load is via User::getUsers()
        $content = file_get_contents($ctrlFile);
        $this->assertStringContainsString('User::getUsers', $content,
            'User FK must call User::getUsers() not a generic model list');
    }

    // =========================================================================
    // 7. lookupModel() — convention fallback, forceSingular=false
    // =========================================================================

    /**
     * lookupModel() with forceSingular=false returns a plural PascalCase name
     * in the 'convention_fallback' result (since the class likely doesn't exist).
     *
     * This is used by the controller generator to find the model class name
     * that will appear in the generated controller source code.
     */
    public function testLookupModelWithForceSingularFalseReturnsPluralFallback(): void
    {
        // Act — look up a name that won't exist as a class
        $result = $this->command->callLookupModel('NonExistentThings', false);

        // Assert — returns an array with the expected keys
        $this->assertIsArray($result);
        $this->assertArrayHasKey('className', $result);
        $this->assertArrayHasKey('namespace', $result);
        $this->assertArrayHasKey('foundBy', $result);

        // Assert — foundBy is 'convention_fallback' (class doesn't exist)
        $this->assertSame('convention_fallback', $result['foundBy'],
            'lookupModel() for a non-existent class must return convention_fallback');
    }

    /**
     * lookupModel() returns 'convention_fallback' for a model name that is not
     * registered and the class does not exist at runtime.
     *
     * The fallback ensures that createController() / createApi() can always
     * derive a model class name even in schema-first workflows where the model
     * PHP file has just been generated and is not loaded yet.
     */
    public function testLookupModelReturnsConventionFallbackForUnknownModel(): void
    {
        // Act
        $result = $this->command->callLookupModel('XyzUnknownEntity', true);

        // Assert
        $this->assertSame('XyzUnknownEntity', $result['className'],
            'Fallback class name must be exactly the PascalCase input (singular)');
        $this->assertSame('convention_fallback', $result['foundBy']);
    }

    /**
     * lookupModel() uses the dbtable option when it is set (ignores computed
     * table name from model name).
     *
     * When the user passes --table, the lookup should check that specific table
     * rather than the auto-derived one.
     */
    public function testLookupModelWithDbtableOption(): void
    {
        // Arrange — set dbtable to something specific
        $this->command->setDbTable('#PREFIX#my_custom_table');

        // Act
        $result = $this->command->callLookupModel('CustomItem', true);

        // Restore
        $this->command->setDbTable(null);

        // Assert — returns something (the class either exists or falls back)
        $this->assertIsArray($result);
        $this->assertArrayHasKey('className', $result);
    }

    // =========================================================================
    // 8. createCrud() — FAIL branches
    // =========================================================================

    /**
     * createCrud() catches exceptions from createModel/createController/createView
     * and continues building a summary of what succeeded and what failed.
     *
     * The method must never throw — it collects results per step and returns
     * a summary string so the caller can report partial success.
     *
     * We force failures by using a DB mock that says the table does not exist
     * (causing createModel/createController/createView to throw) while still
     * having the wizard path hit at least the createCrud() code paths.
     */
    public function testCreateCrudReportsFailWhenStepsFail(): void
    {
        // Arrange — DB says table does not exist for everything except a simple lookup
        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->method('tableExists')->willReturn(false); // forces createView to throw
        $db->type   = 'mysql';
        $db->prefix = '';
        $db->schema = '';
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $originalDb = $dbRef;
        $dbRef = $db;

        $this->command->setOutput(new BufferedOutput());

        // Act — createView requires the table to exist in the DB, so it will FAIL
        try {
            $result = $this->command->callCreateCrud('WcovDoc');
        } finally {
            $dbRef = $originalDb;
        }

        // Assert — createCrud() returns a string (never throws)
        $this->assertIsString($result,
            'createCrud() must never throw — it must return a summary string');

        // Assert — summary contains "Creating Model:", "Creating Controller:", "Creating View:"
        $this->assertStringContainsString('Creating Model:', $result,
            'createCrud() summary must include the Model step');
        $this->assertStringContainsString('Creating Controller:', $result,
            'createCrud() summary must include the Controller step');
        $this->assertStringContainsString('Creating View:', $result,
            'createCrud() summary must include the View step');

        // Assert — at least one step reported FAIL (since tableExists=false)
        $this->assertStringContainsString('FAIL', $result,
            'At least one step must report FAIL when table does not exist');
    }

    // =========================================================================
    // 9. createSeeder() with wizard columns
    // =========================================================================

    /**
     * createSeeder() with non-empty $columns calls buildSeederFields() to generate
     * type-appropriate fake data, rather than using the bare-skeleton TODO.
     *
     * This is the wizard path: after defining columns, the seeder should be
     * populated with plausible fake values, not just a TODO placeholder.
     */
    public function testCreateSeederWithColumnsGeneratesFieldValues(): void
    {
        // Arrange — seeder directory
        $seederDir = APP_PATH . DS . 'seeders';
        if (!is_dir($seederDir)) {
            mkdir($seederDir, 0755, true);
        }

        $seederFile = $seederDir . DS . 'WcovWizardItemSeeder.php';
        if (file_exists($seederFile)) {
            unlink($seederFile);
        }

        $columns = [
            ['name' => 'title',  'type' => 'string',  'options' => [], 'nullable' => false, 'default' => '', 'comment' => '', 'unique' => false, 'unsigned' => false],
            ['name' => 'active', 'type' => 'boolean', 'options' => [], 'nullable' => false, 'default' => '0', 'comment' => '', 'unique' => false, 'unsigned' => false],
        ];

        // Act
        $result = $this->command->createSeeder('WcovWizardItem', $columns, '#PREFIX#wcov_wizard_items');

        // Assert — summary mentions seeder class name
        $this->assertStringContainsString('WcovWizardItemSeeder', $result,
            'createSeeder() summary must include the seeder class name');

        // Assert — file was written
        $this->assertFileExists($seederFile,
            'createSeeder() must write the seeder file when columns are provided');

        // Assert — file contains field values (not just TODO)
        $content = file_get_contents($seederFile);
        $this->assertStringContainsString("'title'", $content,
            'Seeder with columns must include the title field');
        $this->assertStringNotContainsString('// TODO: add column', $content,
            'Seeder with columns must NOT fall back to the TODO placeholder');
    }

    // =========================================================================
    // 10. createView() basic (non-full) path
    // =========================================================================

    /**
     * createView() with full=false creates the view directory and three stub
     * files (index, edit, show) without any DB round-trip.
     *
     * This is the skeleton view path — used when no table exists or when a
     * plain view structure is needed without CRUD logic.
     */
    public function testCreateViewBasicCreatesViewFiles(): void
    {
        // Arrange — ensure Views base dir exists under ROOT/src/
        $viewsBase = ROOT . DS . INCLUDES . DS . 'Views';
        if (!is_dir($viewsBase)) {
            mkdir($viewsBase, 0755, true);
        }

        // Act
        $result = $this->command->callCreateView('wcov_simple', false);

        // Assert — summary confirms creation
        $this->assertStringContainsString('View created', $result,
            'createView() must return a summary confirming creation');

        // Assert — all three view files exist
        $viewDir = $viewsBase . DS . 'wcov_simple';
        $this->assertFileExists($viewDir . DS . 'wcov_simple.html.php',
            'Index/list view file must be created');
        $this->assertFileExists($viewDir . DS . 'edit.html.php',
            'Edit view file must be created');
        $this->assertFileExists($viewDir . DS . 'show.html.php',
            'Show view file must be created');
    }

    /**
     * createView() throws an exception when the target view directory already
     * exists AND contains files, preventing accidental overwrite of customised views.
     *
     * This guard is critical for teams that customise scaffold output: a second
     * `create:view` run must not silently overwrite their work.
     */
    public function testCreateViewThrowsWhenDirectoryAlreadyContainsFiles(): void
    {
        // Arrange — pre-create the view directory with a file
        $viewsBase = ROOT . DS . INCLUDES . DS . 'Views';
        if (!is_dir($viewsBase)) {
            mkdir($viewsBase, 0755, true);
        }
        $viewDir = $viewsBase . DS . 'wcov_nodbview';
        mkdir($viewDir, 0755, true);
        file_put_contents($viewDir . DS . 'existing.html.php', '<?php // existing');

        // Assert + Act — second creation must throw
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('View already exists');
        $this->command->callCreateView('wcov_nodbview', false);
    }

    // =========================================================================
    // 11. createController() — non-full (skeleton) path
    // =========================================================================

    /**
     * createController() with full=false generates a controller from the
     * controller.stub template (no DB introspection needed).
     *
     * This is the fastest path for generating a skeleton controller: no table,
     * no model lookup, just a stub with the correct namespace and class name.
     */
    public function testCreateControllerSkeletonPathWritesFile(): void
    {
        // Arrange
        $ctrlPath = ROOT . DS . INCLUDES . DS . 'Controllers';
        if (!is_dir($ctrlPath)) {
            mkdir($ctrlPath, 0755, true);
        }

        // getProperClassName('WcovThing', false) returns 'Wcovthings' (plural, lowercase-t)
        // because the resolver lower-cases the input before PascalCasing only the first letter.
        $expectedClass = \Pramnos\Console\Make\NamespaceResolver::getProperClassName('WcovThing', false);

        $ctrlFile = $ctrlPath . DS . $expectedClass . '.php';
        if (file_exists($ctrlFile)) {
            unlink($ctrlFile);
        }
        $featureTestFile = ROOT . DS . 'tests' . DS . 'Feature' . DS . $expectedClass . 'Test.php';
        if (file_exists($featureTestFile)) {
            unlink($featureTestFile);
        }

        $this->command->setOutput(new BufferedOutput());

        // Act — full=false triggers stub path
        $result = $this->command->callCreateController('WcovThing', false);

        // Assert — summary confirms creation
        $this->assertStringContainsString('Controller created', $result,
            'createController(full=false) must confirm controller creation');

        // Assert — file exists with correct namespace
        $this->assertFileExists($ctrlFile,
            'Skeleton controller file must be written to src/Controllers/');

        $content = file_get_contents($ctrlFile);
        $this->assertStringContainsString("class {$expectedClass}", $content,
            'Skeleton controller must use the plural class name derived by getProperClassName()');
        $this->assertStringContainsString('App\\Controllers', $content,
            'Skeleton controller must use the application namespace');

        // Cleanup
        if (file_exists($ctrlFile)) {
            unlink($ctrlFile);
        }
        if (file_exists($featureTestFile)) {
            unlink($featureTestFile);
        }
    }

    /**
     * createController() with full=false throws when the target file already exists,
     * preventing accidental overwrite of hand-written controller classes.
     */
    public function testCreateControllerThrowsWhenFileAlreadyExists(): void
    {
        // Arrange — pre-create the controller file using the actual derived class name
        $ctrlPath = ROOT . DS . INCLUDES . DS . 'Controllers';
        if (!is_dir($ctrlPath)) {
            mkdir($ctrlPath, 0755, true);
        }
        $expectedClass = \Pramnos\Console\Make\NamespaceResolver::getProperClassName('WcovThing', false);
        $ctrlFile = $ctrlPath . DS . $expectedClass . '.php';
        file_put_contents($ctrlFile, '<?php // pre-existing');

        $this->command->setOutput(new BufferedOutput());

        try {
            // Assert + Act
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage('Controller already exists');
            $this->command->callCreateController('WcovThing', false);
        } finally {
            if (file_exists($ctrlFile)) {
                unlink($ctrlFile);
            }
        }
    }

    // =========================================================================
    // 12. createView() full=true with resettable DB mock
    // =========================================================================

    /**
     * createView() with full=true and a proper resettable DB mock exercises the
     * second fetch loop (lines 1391-1583) which generates the HTML form content.
     *
     * The standard mock cursor gets exhausted by the first loop. Using a
     * willReturnCallback on getColumns() that returns a fresh cursor object each
     * time ensures both the first (allFields) and second (form content) loops run.
     *
     * This covers:
     *  - The foreign-key detection branch (user FK → userList var)
     *  - Integer/float/bool/text/datetime field type switch branches
     *  - Comment-based label override
     */
    public function testCreateViewFullPathWithResettableDbMock(): void
    {
        // Arrange — Views base dir
        $viewsBase = ROOT . DS . INCLUDES . DS . 'Views';
        if (!is_dir($viewsBase)) {
            mkdir($viewsBase, 0777, true);
        }
        $viewDir = $viewsBase . DS . 'wcov_thing';
        // Clean up if exists
        if (is_dir($viewDir)) {
            $this->rmdirRecursive($viewDir);
        }

        // Column definitions that exercise each form-field type branch
        $columnData = [
            ['Field' => 'thingid',    'Type' => 'int(11)',      'Null' => 'NO',  'Key' => 'PRI',  'Comment' => 'Primary',  'PrimaryKey' => false, 'ForeignKey' => false, 'ForeignTable' => '', 'ForeignSchema' => '', 'ForeignColumn' => ''],
            ['Field' => 'label',      'Type' => 'varchar(255)', 'Null' => 'NO',  'Key' => '',     'Comment' => 'The Label','PrimaryKey' => false, 'ForeignKey' => false, 'ForeignTable' => '', 'ForeignSchema' => '', 'ForeignColumn' => ''],
            ['Field' => 'qty',        'Type' => 'int(11)',      'Null' => 'NO',  'Key' => '',     'Comment' => '',         'PrimaryKey' => false, 'ForeignKey' => false, 'ForeignTable' => '', 'ForeignSchema' => '', 'ForeignColumn' => ''],
            ['Field' => 'price',      'Type' => 'float',        'Null' => 'YES', 'Key' => '',     'Comment' => '',         'PrimaryKey' => false, 'ForeignKey' => false, 'ForeignTable' => '', 'ForeignSchema' => '', 'ForeignColumn' => ''],
            ['Field' => 'active',     'Type' => 'boolean',      'Null' => 'NO',  'Key' => '',     'Comment' => '',         'PrimaryKey' => false, 'ForeignKey' => false, 'ForeignTable' => '', 'ForeignSchema' => '', 'ForeignColumn' => ''],
            ['Field' => 'note',       'Type' => 'text',         'Null' => 'YES', 'Key' => '',     'Comment' => '',         'PrimaryKey' => false, 'ForeignKey' => false, 'ForeignTable' => '', 'ForeignSchema' => '', 'ForeignColumn' => ''],
            ['Field' => 'created_at', 'Type' => 'datetime',     'Null' => 'YES', 'Key' => '',     'Comment' => '',         'PrimaryKey' => false, 'ForeignKey' => false, 'ForeignTable' => '', 'ForeignSchema' => '', 'ForeignColumn' => ''],
            ['Field' => 'created_on', 'Type' => 'date',         'Null' => 'YES', 'Key' => '',     'Comment' => '',         'PrimaryKey' => false, 'ForeignKey' => false, 'ForeignTable' => '', 'ForeignSchema' => '', 'ForeignColumn' => ''],
            ['Field' => 'userid',     'Type' => 'int(11)',      'Null' => 'YES', 'Key' => 'MUL',  'Comment' => '',         'PrimaryKey' => false, 'ForeignKey' => true,  'ForeignTable' => 'users', 'ForeignSchema' => '', 'ForeignColumn' => 'userid'],
            ['Field' => 'cat_id',     'Type' => 'int(11)',      'Null' => 'YES', 'Key' => 'MUL',  'Comment' => '',         'PrimaryKey' => false, 'ForeignKey' => true,  'ForeignTable' => 'categories', 'ForeignSchema' => '', 'ForeignColumn' => 'categoryid'],
        ];

        // Build a factory that returns a fresh cursor each call
        $makeCursor = function () use ($columnData) {
            return new class($columnData) {
                private int $idx = 0;
                public array $fields = [];
                public function __construct(private array $data) {}
                public function fetch(): bool {
                    if ($this->idx < count($this->data)) {
                        $this->fields = $this->data[$this->idx++];
                        return true;
                    }
                    return false;
                }
            };
        };

        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->method('tableExists')->willReturn(true);
        $db->method('getColumns')->willReturnCallback(function () use ($makeCursor) {
            return $makeCursor();
        });
        $db->type   = 'mysql';
        $db->prefix = '';
        $db->schema = '';

        $dbRef = &\Pramnos\Database\Database::getInstance();
        $originalDb = $dbRef;
        $dbRef = $db;

        $this->command->setDbTable('#PREFIX#wcov_things');

        // Act
        try {
            $result = $this->command->callCreateView('wcov_thing', true);
        } finally {
            $dbRef = $originalDb;
            $this->command->setDbTable(null);
        }

        // Assert — all three view files created
        $this->assertFileExists($viewDir . DS . 'wcov_thing.html.php',
            'Full createView() must create the list/index view file');
        $this->assertFileExists($viewDir . DS . 'edit.html.php',
            'Full createView() must create the edit view file');
        $this->assertFileExists($viewDir . DS . 'show.html.php',
            'Full createView() must create the show view file');

        // Assert — edit form contains type-specific fields
        $editContent = file_get_contents($viewDir . DS . 'edit.html.php');
        $this->assertStringContainsString('type="number"', $editContent,
            'Integer column must produce type="number" input');
        $this->assertStringContainsString('<textarea', $editContent,
            'Text column must produce a textarea element');
        $this->assertStringContainsString('type="datetime-local"', $editContent,
            'Datetime/date columns must produce datetime-local inputs (createView() full legacy code)');
        // Note: The legacy createView(full=true) uses datetime-local for both 'date' and 'datetime'
        // column types (see the switch case at line ~1546 in the source). The wizard-based
        // createViewsFromWizard() correctly distinguishes them with type="date" vs "datetime-local".

        // Assert — boolean generates a select with Yes/No options
        $this->assertStringContainsString("value=\"0\"", $editContent,
            'Boolean column must generate a select with 0 option');
        $this->assertStringContainsString("value=\"1\"", $editContent,
            'Boolean column must generate a select with 1 option');

        // Assert — user FK uses userList variable
        $this->assertStringContainsString('userList', $editContent,
            'User FK must bind to $this->userList variable');

        // Assert — non-user FK generates dropdown
        $this->assertStringContainsString('categoryid', $editContent,
            'Non-user FK must generate a select referencing the foreign column');

        // Assert — comment used as label
        $this->assertStringContainsString('The Label', $editContent,
            'Comment must be used as label when non-empty');

        // Assert — result summary mentions created files
        $this->assertStringContainsString('View created', $result);
    }

    // =========================================================================
    // 13. createModel() — DB introspection path (table exists, no wizard columns)
    // =========================================================================

    /**
     * createModel() with a table that exists in the DB (and no wizard columns)
     * uses DB introspection: two passes over getColumns() — first for PK discovery,
     * second for property generation.
     *
     * This covers lines 3596-3700 (second-pass loop with int/float/bool/string
     * column types generating typed public properties and arrayFix code).
     *
     * We use a willReturnCallback on getColumns() to return a fresh cursor on
     * each call so both passes get real data.
     */
    public function testCreateModelDbIntrospectionPathGeneratesTypedProperties(): void
    {
        // Arrange
        $modelsDir = ROOT . DS . INCLUDES . DS . 'Models';
        if (!is_dir($modelsDir)) {
            mkdir($modelsDir, 0755, true);
        }
        $modelFile = $modelsDir . DS . 'WcovDbModel.php';
        $testStub  = ROOT . DS . 'tests' . DS . 'Unit' . DS . 'WcovDbModelTest.php';
        foreach ([$modelFile, $testStub] as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }

        // Rich column data: PK, int, float, bool, string to hit all switch branches
        $columnData = [
            ['Field' => 'dbmodelid', 'Type' => 'int(11)',      'Key' => 'PRI', 'Null' => 'NO',  'Comment' => 'PK',    'PrimaryKey' => false, 'ForeignKey' => false],
            ['Field' => 'qty',       'Type' => 'int(11)',       'Key' => '',    'Null' => 'NO',  'Comment' => 'Qty',   'PrimaryKey' => false, 'ForeignKey' => false],
            ['Field' => 'rate',      'Type' => 'float',         'Key' => '',    'Null' => 'YES', 'Comment' => '',      'PrimaryKey' => false, 'ForeignKey' => false],
            ['Field' => 'enabled',   'Type' => 'boolean',       'Key' => '',    'Null' => 'NO',  'Comment' => '',      'PrimaryKey' => false, 'ForeignKey' => false],
            ['Field' => 'name',      'Type' => 'varchar(255)',   'Key' => '',    'Null' => 'NO',  'Comment' => 'Name',  'PrimaryKey' => false, 'ForeignKey' => false],
        ];

        $makeCursor = function () use ($columnData) {
            return new class($columnData) {
                private int $idx = 0;
                public array $fields = [];
                public function __construct(private array $data) {}
                public function fetch(): bool {
                    if ($this->idx < count($this->data)) {
                        $this->fields = $this->data[$this->idx++];
                        return true;
                    }
                    return false;
                }
            };
        };

        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->method('tableExists')->willReturn(true); // table exists → DB introspection path
        $db->method('getColumns')->willReturnCallback(function () use ($makeCursor) {
            return $makeCursor();
        });
        $db->type   = 'mysql';
        $db->prefix = '';
        $db->schema = '';

        $dbRef = &\Pramnos\Database\Database::getInstance();
        $originalDb = $dbRef;
        $dbRef = $db;

        // Act
        try {
            $result = $this->command->callCreateModel('WcovDbModel', []);
        } finally {
            $dbRef = $originalDb;
        }

        // Assert — file created with typed properties
        $this->assertFileExists($modelFile,
            'createModel() DB introspection must write the model file');

        $content = file_get_contents($modelFile);
        $this->assertStringContainsString('class WcovDbModel', $content,
            'DB-introspected model must declare the correct class name');
        $this->assertStringContainsString('@var int', $content,
            'Integer column must produce @var int annotation');
        $this->assertStringContainsString('@var float', $content,
            'Float column must produce @var float annotation');
        $this->assertStringContainsString('@var bool', $content,
            'Boolean column must produce @var bool annotation');
        $this->assertStringContainsString('@var string', $content,
            'String column must produce @var string annotation');

        // Assert — arrayFix cast present for int and float
        $this->assertStringContainsString('(int) $this->qty', $content,
            'Integer column must have (int) cast in getData() arrayFix');
        $this->assertStringContainsString('(float) $this->rate', $content,
            'Float column must have (float) cast in getData() arrayFix');
        $this->assertStringContainsString('(bool) $this->enabled', $content,
            'Boolean column must have (bool) cast in getData() arrayFix');

        // Cleanup
        foreach ([$modelFile, $testStub] as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }
        // Remove registry entry
        $this->cleanRegistryEntry('WcovDbModel');
    }

    /**
     * createController() with full=true and NO wizard columns uses DB introspection.
     *
     * This covers lines 2414-2683: the column loop, FK detection, saveContent
     * generation for various types, and the final controller file write.
     *
     * Uses a resettable DB mock to supply column data (single pass — no re-iteration).
     */
    public function testCreateControllerFullDbIntrospectionPath(): void
    {
        // Arrange
        $ctrlPath = ROOT . DS . INCLUDES . DS . 'Controllers';
        if (!is_dir($ctrlPath)) {
            mkdir($ctrlPath, 0755, true);
        }
        $expectedClass = \Pramnos\Console\Make\NamespaceResolver::getProperClassName('WcovDbCtrl', false);
        $ctrlFile = $ctrlPath . DS . $expectedClass . '.php';
        $featureTest = ROOT . DS . 'tests' . DS . 'Feature' . DS . $expectedClass . 'Test.php';
        foreach ([$ctrlFile, $featureTest] as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }

        $columnData = [
            ['Field' => 'dbctrlid', 'Type' => 'int(11)',     'Key' => 'PRI', 'Null' => 'NO',  'Comment' => '',  'PrimaryKey' => false, 'ForeignKey' => false, 'ForeignTable' => '', 'ForeignSchema' => '', 'ForeignColumn' => ''],
            ['Field' => 'title',    'Type' => 'varchar(255)', 'Key' => '',    'Null' => 'NO',  'Comment' => '',  'PrimaryKey' => false, 'ForeignKey' => false, 'ForeignTable' => '', 'ForeignSchema' => '', 'ForeignColumn' => ''],
            ['Field' => 'count',    'Type' => 'int(11)',      'Key' => '',    'Null' => 'NO',  'Comment' => '',  'PrimaryKey' => false, 'ForeignKey' => false, 'ForeignTable' => '', 'ForeignSchema' => '', 'ForeignColumn' => ''],
            ['Field' => 'price',    'Type' => 'float',        'Key' => '',    'Null' => 'YES', 'Comment' => '',  'PrimaryKey' => false, 'ForeignKey' => false, 'ForeignTable' => '', 'ForeignSchema' => '', 'ForeignColumn' => ''],
            ['Field' => 'active',   'Type' => 'boolean',      'Key' => '',    'Null' => 'NO',  'Comment' => '',  'PrimaryKey' => false, 'ForeignKey' => false, 'ForeignTable' => '', 'ForeignSchema' => '', 'ForeignColumn' => ''],
            ['Field' => 'userid',   'Type' => 'int(11)',      'Key' => 'MUL', 'Null' => 'YES', 'Comment' => '',  'PrimaryKey' => false, 'ForeignKey' => true,  'ForeignTable' => 'users', 'ForeignSchema' => '', 'ForeignColumn' => 'userid'],
        ];

        $makeCursor = function () use ($columnData) {
            return new class($columnData) {
                private int $idx = 0;
                public array $fields = [];
                public function __construct(private array $data) {}
                public function fetch(): bool {
                    if ($this->idx < count($this->data)) {
                        $this->fields = $this->data[$this->idx++];
                        return true;
                    }
                    return false;
                }
            };
        };

        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->method('tableExists')->willReturn(true);
        $db->method('getColumns')->willReturnCallback(function () use ($makeCursor) {
            return $makeCursor();
        });
        $db->type   = 'mysql';
        $db->prefix = '';
        $db->schema = '';

        $dbRef = &\Pramnos\Database\Database::getInstance();
        $originalDb = $dbRef;
        $dbRef = $db;

        $this->command->setOutput(new BufferedOutput());
        $this->command->setDbTable('#PREFIX#wcov_db_ctrls');

        // Act — full=true, NO wizard columns → DB introspection
        try {
            $result = $this->command->callCreateController('WcovDbCtrl', true, []);
        } finally {
            $dbRef = $originalDb;
            $this->command->setDbTable(null);
        }

        // Assert — controller file created
        $this->assertFileExists($ctrlFile,
            'createController(full=true) DB introspection must write the controller file');

        $content = file_get_contents($ctrlFile);
        $this->assertStringContainsString('public function display()', $content,
            'Controller must have display() method');
        $this->assertStringContainsString('public function save()', $content,
            'Controller must have save() method');

        // Assert — saveContent includes field assignments
        $this->assertStringContainsString("'title'", $content,
            'DB-introspected controller must include title field in save()');

        // Assert — user FK loading is present (userList)
        $this->assertStringContainsString('userList', $content,
            'User FK must generate userList loading in edit()');

        // Cleanup
        foreach ([$ctrlFile, $featureTest] as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }
    }

    // =========================================================================
    // generateTestStub() — mkdir path + file-exists early-return
    // =========================================================================

    /**
     * generateTestStub() with a base directory whose tests/Unit sub-directory does
     * NOT exist must call mkdir() to create it (line 446) and then write the stub.
     *
     * Covers line 446: `@mkdir($testsDir, 0777, true)`.
     */
    public function testGenerateTestStubCreatesTestsDirWhenMissing(): void
    {
        // Arrange — use a fresh temp dir that has no tests/Unit sub-directory
        $freshBase = sys_get_temp_dir() . '/pramnos_gts_' . bin2hex(random_bytes(4));
        // Do NOT create tests/Unit — generateTestStub() must create it

        try {
            // Act — generateTestStub() with the fresh directory as base
            $result = $this->command->generateTestStub('WcovGtsClass', 'App', $freshBase);

            // Assert — directory was created and stub was written
            $this->assertDirectoryExists(
                $freshBase . '/tests/Unit',
                'generateTestStub() must create tests/Unit when it does not exist'
            );
            $this->assertFileExists(
                $freshBase . '/tests/Unit/WcovGtsClassTest.php',
                'generateTestStub() must write the test stub file'
            );
            $this->assertStringContainsString(
                'WcovGtsClassTest.php',
                $result,
                'generateTestStub() must return the filename in its output'
            );
        } finally {
            // Cleanup the temp directory tree
            if (is_dir($freshBase)) {
                $this->rmdirRecursive($freshBase);
            }
        }
    }

    /**
     * generateTestStub() returns an empty string when the test file already
     * exists (line 451-452). Calling the method a second time with the same
     * class and base directory must not overwrite the file and must return ''.
     *
     * Covers lines 451-452: `if (file_exists($testFile)) { return ''; }`.
     */
    public function testGenerateTestStubReturnsEmptyWhenFileAlreadyExists(): void
    {
        // Arrange — create the file manually to simulate "already exists"
        $testsDir = $this->tmpDir . '/tests/Unit';
        $testFile = $testsDir . '/WcovAlreadyExistsTest.php';
        file_put_contents($testFile, '<?php // pre-existing test stub');

        // Act — second call must find the file and return '' immediately
        $result = $this->command->generateTestStub('WcovAlreadyExists', 'App', $this->tmpDir);

        // Assert — must return empty string (file not overwritten)
        $this->assertSame('', $result,
            'generateTestStub() must return empty string when the test file already exists');
        $this->assertStringEqualsFile(
            $testFile,
            '<?php // pre-existing test stub',
            'generateTestStub() must not overwrite an existing test file'
        );
    }

    /**
     * generateTestStub() with stubName='controller_test' uses tests/Feature as
     * the target directory instead of tests/Unit.
     *
     * Covers line 443: `$testsDir = $baseDir . '/tests/Feature'`.
     */
    public function testGenerateTestStubUsesFeatureDirForControllerTest(): void
    {
        // Act
        $result = $this->command->generateTestStub(
            'WcovCtrlTest', 'App', $this->tmpDir, 'controller_test'
        );

        // Assert — file written to tests/Feature, not tests/Unit
        $this->assertFileExists(
            $this->tmpDir . '/tests/Feature/WcovCtrlTestTest.php',
            'generateTestStub() with controller_test stub must write to tests/Feature'
        );
        $this->assertStringContainsString('WcovCtrlTestTest.php', $result);

        // Cleanup
        @unlink($this->tmpDir . '/tests/Feature/WcovCtrlTestTest.php');
    }

    /** Helper: remove a named entry from the model registry. */
    private function cleanRegistryEntry(string $className): void
    {
        $registryFile = ROOT . DS . 'app' . DS . 'model-registry.json';
        if (!file_exists($registryFile)) {
            return;
        }
        $data = json_decode(file_get_contents($registryFile), true) ?? [];
        $filtered = array_values(array_filter($data, fn($e) => ($e['className'] ?? '') !== $className));
        if (empty($filtered)) {
            @unlink($registryFile);
        } else {
            file_put_contents($registryFile, json_encode($filtered, JSON_PRETTY_PRINT));
        }
    }
}
