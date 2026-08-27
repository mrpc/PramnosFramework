<?php

declare(strict_types=1);

namespace TestApp {
    class Application extends \Pramnos\Application\Application {
        public $applicationInfo = ['namespace' => 'TestApp'];
        public $appName = '';
        public function init($settingsFile = '') {}
    }
}

namespace Pramnos\Tests\Unit\Console {

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\MakeCommandBase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Helper\HelperSet;

class DummyGeneratorCommand extends MakeCommandBase
{
    protected function configure() {}
    protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output) { return 0; }
    
    // Expose wizard for testing
    public function triggerMigrationWizard($input, $output) {
        return $this->runMigrationWizard($input, $output);
    }
    
    public function exposeLookupModel($name, $forceSingular = true) {
        return $this->lookupModel($name, $forceSingular);
    }
    
    public function exposeCreateView($name, $full = false) {
        return $this->createView($name, $full);
    }
    
    public function exposeSetDbTable($table) {
        $this->dbtable = $table;
    }
    public function exposeCreateModel($entityName, $columns = [], $foreignKeys = []) {
        return $this->createModel($entityName, $columns, $foreignKeys);
    }
    
    public function exposeCreateController($entityName, $web = true, $columns = [], $foreignKeys = []) {
        return $this->createController($entityName, $web, $columns, $foreignKeys);
    }
    
    public function exposeCreateApi($entityName) {
        return $this->createApi($entityName);
    }
    
    public function exposeCreateCrud($name) {
        return $this->createCrud($name);
    }

    // Expose the FK form-field builder so both Select2-on (AJAX remote) and
    // Select2-off (native eager) branches can be asserted deterministically,
    // without depending on the shared Document singleton's script registry.
    public function exposeBuildWizardFormFields($columns, $fkByColumn, $primaryKey, $themeKey, $useSelect2, $className = '') {
        return $this->buildWizardFormFields($columns, $fkByColumn, $primaryKey, $themeKey, $useSelect2, $className);
    }
}

class MakeCommandGeneratorsTest extends TestCase
{
    private DummyGeneratorCommand $command;
    private \Pramnos\Console\Application $app;
    private array $filesToCleanup = [];
    private $dbMock;
    private $originalDb;

    protected function setUp(): void
    {
        $this->app = new \Pramnos\Console\Application();
        $this->app->internalApplication = new \TestApp\Application();
        
        $this->command = new DummyGeneratorCommand();
        $this->command->setApplication($this->app);
        
        // Ensure clean state for seeders and other generated files
        $toClean = [
            APP_PATH . '/seeders/TestItemSeeder.php',
            APP_PATH . '/seeders/EmptyItemSeeder.php',
            ROOT . '/src/Middleware/TestMiddleware.php',
            ROOT . '/src/Events/TestEvent.php',
            ROOT . '/src/Listeners/TestListener.php',
            ROOT . '/src/Models/TestEntity.php',
            ROOT . '/src/Controllers/Testentities.php',
            ROOT . '/src/Api/Controllers/Testentities.php',
            ROOT . '/src/Models/TestCrudEntity.php',
            ROOT . '/src/Controllers/Testcrudentities.php',
            ROOT . '/src/Views/testbasicview/testbasicview.html.php',
            ROOT . '/src/Views/testbasicview/edit.html.php',
            ROOT . '/src/Views/testbasicview/show.html.php',
        ];
        foreach ($toClean as $f) {
            if (file_exists($f)) unlink($f);
        }
        
        $this->removeDirRecursive(ROOT . '/src/Views/testbasicview');
        $this->removeDirRecursive(ROOT . '/src/Views/testentity');
        $this->removeDirRecursive(ROOT . '/src/Views/testcrudentity');
        $this->removeDirRecursive(ROOT . '/src/Views/testfullview');
        $this->removeDirRecursive(ROOT . '/src/Views/notableview');

        // Prevent mkdir warning
        if (!is_dir(ROOT . '/src/Api')) {
            mkdir(ROOT . '/src/Api');
        }
        
        $this->dbMock = $this->createMock(\Pramnos\Database\Database::class);
        $this->dbMock->method('tableExists')->willReturn(true);
        $this->dbMock->method('getColumns')->willReturn(new class {
            private $data = [
                ['Field' => 'id', 'Type' => 'int(11)', 'Null' => 'NO', 'Key' => 'PRI', 'Comment' => 'Primary Key', 'PrimaryKey' => true, 'ForeignKey' => false],
                ['Field' => 'title', 'Type' => 'varchar(255)', 'Null' => 'NO', 'Key' => '', 'Comment' => 'The title', 'PrimaryKey' => false, 'ForeignKey' => false],
                ['Field' => 'amount', 'Type' => 'float', 'Null' => 'YES', 'Key' => '', 'Comment' => 'The amount', 'PrimaryKey' => false, 'ForeignKey' => false],
                ['Field' => 'status', 'Type' => 'tinyint(1)', 'Null' => 'NO', 'Key' => '', 'Comment' => 'Status', 'PrimaryKey' => false, 'ForeignKey' => false],
                ['Field' => 'description', 'Type' => 'text', 'Null' => 'YES', 'Key' => '', 'Comment' => 'Desc', 'PrimaryKey' => false, 'ForeignKey' => false],
                ['Field' => 'created_at', 'Type' => 'datetime', 'Null' => 'NO', 'Key' => '', 'Comment' => '', 'PrimaryKey' => false, 'ForeignKey' => false],
                ['Field' => 'start_date', 'Type' => 'date', 'Null' => 'YES', 'Key' => '', 'Comment' => '', 'PrimaryKey' => false, 'ForeignKey' => false],
                ['Field' => 'category_id', 'Type' => 'int(11)', 'Null' => 'YES', 'Key' => 'MUL', 'Comment' => '', 'PrimaryKey' => false, 'ForeignKey' => true, 'ForeignTable' => 'categories', 'ForeignSchema' => '', 'ForeignColumn' => 'id'],
                ['Field' => 'userid', 'Type' => 'int(11)', 'Null' => 'YES', 'Key' => 'MUL', 'Comment' => '', 'PrimaryKey' => false, 'ForeignKey' => true, 'ForeignTable' => 'users', 'ForeignSchema' => '', 'ForeignColumn' => 'userid'],
            ];
            private $index = 0;
            public $fields = [];
            public function fetch() {
                if ($this->index < count($this->data)) {
                    $this->fields = $this->data[$this->index++];
                    return true;
                }
                return false;
            }
        });
        $this->dbMock->connected = true;
        $this->dbMock->type = 'mysql';
        $this->dbMock->prefix = 'pr_';

        $dbRef = &\Pramnos\Database\Database::getInstance();
        $this->originalDb = clone $dbRef;
        $dbRef = $this->dbMock;
    }

    protected function tearDown(): void
    {
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $dbRef = $this->originalDb;
        
        foreach ($this->filesToCleanup as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        
        if (file_exists(ROOT . '/src/Api/routes.php')) {
            unlink(ROOT . '/src/Api/routes.php');
        }
        if (is_dir(ROOT . '/src/Api/Controllers')) {
            $files = glob(ROOT . '/src/Api/Controllers/*');
            foreach ($files as $file) {
                if (is_file($file)) unlink($file);
            }
            rmdir(ROOT . '/src/Api/Controllers');
        }
        if (is_dir(ROOT . '/src/Api')) {
            rmdir(ROOT . '/src/Api');
        }

        $this->removeDirRecursive(ROOT . '/src/Views/testbasicview');
        $this->removeDirRecursive(ROOT . '/src/Views/testentity');
        $this->removeDirRecursive(ROOT . '/src/Views/testcrudentity');
        $this->removeDirRecursive(ROOT . '/src/Views/testfullview');
        $this->removeDirRecursive(ROOT . '/src/Views/notableview');

        // Clean up empty generated parent directories
        $emptyDirs = [
            ROOT . '/src/Controllers',
            ROOT . '/src/Models',
            ROOT . '/src/Views',
            ROOT . '/src/Middleware',
            ROOT . '/src/Events',
            ROOT . '/src/Listeners',
        ];
        foreach ($emptyDirs as $dir) {
            if (is_dir($dir)) {
                $files = glob($dir . '/*');
                if ($files === false || empty($files)) {
                    rmdir($dir);
                }
            }
        }
        
        // Wipe generated test files: create:model/controller now emit schema-aware
        // tests into tests/Unit/Models/<E>Test.php and tests/Feature/<C>Test.php.
        // These dirs hold no tracked framework tests (tests/Feature has only a
        // .gitkeep), so any *Test.php here is generator cruft — remove it so it
        // does not leak into the framework's own suite (breaking it with a
        // "Class Tests\\BaseTestCase not found" on the generated integration base).
        foreach ([ROOT . '/tests/Unit/Models', ROOT . '/tests/Feature'] as $genTestDir) {
            foreach ((array) glob($genTestDir . '/*Test.php') as $genTest) {
                if (is_file($genTest)) {
                    @unlink($genTest);
                }
            }
        }
        if (is_dir(ROOT . '/tests/Unit/Models')) {
            $rest = glob(ROOT . '/tests/Unit/Models/*');
            if ($rest === false || empty($rest)) {
                @rmdir(ROOT . '/tests/Unit/Models');
            }
        }

        // Also wipe any migrations created during this test by wildcard
        $migrationFiles = glob(APP_PATH . '/migrations/*_create_test_items_table.php');
        if ($migrationFiles !== false) {
            foreach ($migrationFiles as $file) {
                unlink($file);
            }
        }
        $migrationFiles = glob(APP_PATH . '/migrations/*_create_products_table.php');
        if ($migrationFiles !== false) {
            foreach ($migrationFiles as $file) {
                unlink($file);
            }
        }

        $this->cleanModelRegistry();
    }

    /**
     * Remove this class' entries from ROOT/app/model-registry.json.
     *
     * createModel() calls MakeCommandBase::registerModelInRegistry(), which
     * writes (and mkdirs) ROOT/app/model-registry.json — inside the framework
     * checkout when running under PHPUnit. Without this cleanup the generated
     * models accumulated there permanently and the root-owned app/ directory
     * showed up forever as untracked in git.
     *
     * Only the class names this test generates are filtered out, mirroring
     * MakeCommandBaseExtendedTest, so a real project's registry survives. The
     * file (and the app/ directory, when we created it) is removed once no
     * entries are left.
     *
     * @return void
     */
    private function cleanModelRegistry(): void
    {
        $appDir       = ROOT . DIRECTORY_SEPARATOR . 'app';
        $registryFile = $appDir . DIRECTORY_SEPARATOR . 'model-registry.json';
        if (!file_exists($registryFile)) {
            return;
        }

        $ours = ['TestEntity', 'IntroModelEntity', 'SchemaModel', 'TestCrudEntity'];
        $data = json_decode((string) file_get_contents($registryFile), true);
        if (!is_array($data)) {
            // Corrupt or unexpected content: it can only have come from a
            // generator run, so drop the file wholesale.
            @unlink($registryFile);
            @rmdir($appDir);
            return;
        }

        $kept = array_values(array_filter(
            $data,
            fn($entry) => !in_array($entry['className'] ?? '', $ours, true)
        ));

        if (empty($kept)) {
            @unlink($registryFile);
            // Only succeeds while app/ holds nothing else (e.g. app/keys).
            @rmdir($appDir);
            return;
        }

        file_put_contents($registryFile, json_encode($kept, JSON_PRETTY_PRINT));
    }

    private function removeDirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = glob($dir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_dir($file)) {
                    $this->removeDirRecursive($file);
                } else {
                    unlink($file);
                }
            }
        }
        rmdir($dir);
    }

    private function addCleanup(string $file): void
    {
        $this->filesToCleanup[] = $file;
    }

    public function testCreateMiddleware(): void
    {
        $output = $this->command->createMiddleware('TestMiddleware');
        $this->assertStringContainsString('TestMiddleware', $output);

        $srcFile = ROOT . '/src/Middleware/TestMiddleware.php';
        $testFile = ROOT . '/tests/Unit/TestMiddlewareMiddlewareTest.php';

        $this->assertFileExists($srcFile);
        $this->assertFileExists($testFile);

        $this->addCleanup($srcFile);
        $this->addCleanup($testFile);
    }

    public function testCreateService(): void
    {
        $srcFile  = ROOT . '/src/Services/TestThingService.php';
        $testFile = ROOT . '/tests/Unit/TestThingServiceTest.php';
        // Register cleanup up front so a failed assertion still removes the files.
        $this->addCleanup($srcFile);
        $this->addCleanup($testFile);

        $output = $this->command->createService('TestThingService');
        $this->assertStringContainsString('TestThingService', $output);

        $this->assertFileExists($srcFile);
        $this->assertFileExists($testFile);

        $contents = (string) file_get_contents($srcFile);
        // Namespace ends in \Services (the app prefix depends on the run context).
        $this->assertMatchesRegularExpression('/namespace \S+\\\\Services;/', $contents);
        $this->assertStringContainsString('class TestThingService', $contents);
        // The generated service extends the framework base rather than wiring a
        // Database by hand. That inheritance is what makes it appear in the debug
        // toolbar's Domain tab — a plain class has no seam to record from.
        $this->assertStringContainsString('use Pramnos\Application\Service;', $contents);
        $this->assertStringContainsString('extends Service', $contents);
        // The best-effort table guess strips "Service" and snake_cases the rest.
        $this->assertStringContainsString("'test_thing'", $contents);
    }

    public function testCreateEvent(): void
    {
        $output = $this->command->createEvent('TestEvent');
        $this->assertStringContainsString('TestEvent', $output);
        
        $srcFile = ROOT . '/src/Events/TestEvent.php';
        $testFile = ROOT . '/tests/Unit/TestEventEventTest.php';
        
        $this->assertFileExists($srcFile);
        $this->assertFileExists($testFile);
        
        $this->addCleanup($srcFile);
        $this->addCleanup($testFile);
    }

    public function testCreateListener(): void
    {
        $output = $this->command->createListener('TestListener');
        $this->assertStringContainsString('TestListener', $output);
        
        $srcFile = ROOT . '/src/Listeners/TestListener.php';
        $testFile = ROOT . '/tests/Unit/TestListenerListenerTest.php';
        
        $this->assertFileExists($srcFile);
        $this->assertFileExists($testFile);
        
        $this->addCleanup($srcFile);
        $this->addCleanup($testFile);
    }

    public function testCreateMigration(): void
    {
        $output = $this->command->createMigration('create test items table');
        $this->assertStringContainsString('CreateTestItemsTable', $output);
        
        $files = glob(APP_PATH . '/migrations/*_create_test_items_table.php');
        $this->assertCount(1, $files);
    }

    public function testCreateSeeder(): void
    {
        $columns = [
            ['name' => 'title', 'type' => 'string', 'options' => [], 'nullable' => false, 'default' => '', 'unique' => false, 'comment' => '', 'unsigned' => false]
        ];
        $output = $this->command->createSeeder('TestItem', $columns, '#PREFIX#test_items');
        $this->assertStringContainsString('TestItemSeeder', $output);
        
        $srcFile = APP_PATH . '/seeders/TestItemSeeder.php';
        $testFile = ROOT . '/tests/Unit/TestItemSeederTest.php';
        
        $this->assertFileExists($srcFile);
        $this->assertFileExists($testFile);
        
        $this->addCleanup($srcFile);
        $this->addCleanup($testFile);
    }

    public function testCreateSeederEmptyColumns(): void
    {
        $output = $this->command->createSeeder('EmptyItem', [], '');
        $this->assertStringContainsString('EmptyItemSeeder', $output);
        
        $srcFile = APP_PATH . '/seeders/EmptyItemSeeder.php';
        $testFile = ROOT . '/tests/Unit/EmptyItemSeederTest.php';
        
        $this->assertFileExists($srcFile);
        $this->assertFileExists($testFile);
        
        $this->addCleanup($srcFile);
        $this->addCleanup($testFile);
    }

    public function testRunMigrationWizard(): void
    {
        $helper = $this->createMock(QuestionHelper::class);
        $answers = [
            'create products table', // Description
            'products', // Table name
            true, // PK yes
            'title', // col name
            'string  (VARCHAR — variable length text)', // col type label
            255, // length
            false, // nullable
            '', // default
            '', // comment
            false, // unique
            '', // Enter to finish
            true, // Timestamps yes
            false, // Soft deletes no
            false, // Add a foreign key?
            false, // Add another table?
            false, // Run this migration now?
            'Product', // Class name
            false, // Create model?
            false, // Create Web Controller?
            false, // Create API Controller?
            false, // Create Seeder?
            false, false, false, false
        ];
        $callCount = 0;
        $helper->method('getName')->willReturn('question');
        $helper->method('ask')->willReturnCallback(function($input, $output, $q) use (&$answers, &$callCount) {
            $answer = $answers[$callCount] ?? false;
            $callCount++;
            return $answer;
        });

        $helperSet = new HelperSet([$helper]);
        $this->command->setHelperSet($helperSet);

        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $result = $this->command->triggerMigrationWizard($input, $output);
        
        $this->assertStringContainsString('create_products_table.php', $result);
        
        $files = glob(APP_PATH . '/migrations/*_create_products_table.php');
        $this->assertCount(1, $files);
    }
    public function testCreateModel(): void
    {
        $columns = [
            ['name' => 'title', 'type' => 'string', 'options' => [], 'nullable' => false, 'default' => '', 'unique' => false, 'comment' => '', 'unsigned' => false]
        ];
        $output = $this->command->exposeCreateModel('TestEntity', $columns);
        $this->assertStringContainsString('TestEntity', $output);
        
        $srcFile = ROOT . '/src/Models/TestEntity.php';
        $testFile = ROOT . '/tests/Unit/Models/TestEntityTest.php';
        
        $this->assertFileExists($srcFile);

        $this->addCleanup($srcFile);
        $this->addCleanup($testFile);
    }

    /**
     * The DB-introspection model path (no wizard columns) must converge on the
     * SAME schema-first generator as the migration-wizard path: createModel()
     * normalises the live table (the $dbMock getColumns() set: id PK, title
     * varchar, amount float, status tinyint(1), description text, created_at
     * datetime, start_date date, category_id FK, userid FK) into wizard-shaped
     * column/FK arrays and delegates to buildModelFromWizardColumns(), which
     * renders scaffolding/templates/crud-model.stub.
     *
     * This proves the two model generators are unified: there is no longer a
     * divergent inline heredoc for introspected tables. We assert the file is
     * valid PHP, declares typed properties for every non-PK column, and carries
     * the crud-model.stub load/save/getData/getApiList shape — including the
     * stub-only `$fields = []` getApiList signature, which the retired
     * introspection heredoc emitted as `$fields = array()`.
     */
    public function testCreateModelFromDbIntrospectionUsesCrudModelStub(): void
    {
        // Arrange — no wizard columns, so createModel() introspects $dbMock.
        $srcFile  = ROOT . '/src/Models/IntroModelEntity.php';
        $testFile = ROOT . '/tests/Unit/IntroModelEntityTest.php';

        try {
            // Act — empty wizard columns forces the DB-introspection branch.
            $output = $this->command->exposeCreateModel('IntroModelEntity');

            // Assert — file was generated at the conventional location.
            $this->assertStringContainsString('IntroModelEntity', $output);
            $this->assertFileExists($srcFile);

            $content = (string) file_get_contents($srcFile);

            // Assert — output is syntactically valid PHP (php -l).
            $lint = shell_exec(
                'php -l ' . escapeshellarg($srcFile) . ' 2>&1'
            );
            $this->assertStringContainsString(
                'No syntax errors detected',
                (string) $lint,
                'Generated introspection model must be valid PHP'
            );

            // Assert — extends the framework Model base, like the wizard path.
            $this->assertStringContainsString(
                'extends \Pramnos\Application\Model',
                $content
            );

            // Assert — a typed property is declared for every non-PK column,
            // with the PHP type derived from the SQL type (float/bool/int/…).
            $this->assertStringContainsString('public $title;', $content);
            $this->assertStringContainsString('public $amount;', $content);
            $this->assertStringContainsString('public $status;', $content);
            $this->assertStringContainsString('public $description;', $content);
            $this->assertStringContainsString('public $created_at;', $content);
            $this->assertStringContainsString('public $start_date;', $content);
            $this->assertStringContainsString('public $category_id;', $content);
            $this->assertStringContainsString('public $userid;', $content);
            // Type mapping: float column → @var float, tinyint(1) → @var bool,
            // FK int column → @var int. Proves mapSqlTypeToLogical drives types.
            $this->assertStringContainsString("* @var float\n     */\n    public \$amount;", $content);
            $this->assertStringContainsString("* @var bool\n     */\n    public \$status;", $content);
            $this->assertStringContainsString("* @var int\n     */\n    public \$category_id;", $content);

            // Assert — the crud-model.stub method surface is present, i.e. both
            // paths converge on one template.
            $this->assertStringContainsString('public function load(', $content);
            $this->assertStringContainsString('public function save(', $content);
            $this->assertStringContainsString('public function delete(', $content);
            $this->assertStringContainsString('public function getData(', $content);
            $this->assertStringContainsString('public function getApiList(', $content);
            // The stub uses `$fields = []`; the retired heredoc used
            // `$fields = array()`. This assertion is what proves the unified
            // (stub) generator ran, not the old introspection heredoc.
            $this->assertStringContainsString("public function getApiList(\$fields = [], \$search = ''", $content);
            $this->assertStringNotContainsString('$fields = array()', $content);
        } finally {
            // Clean up every artifact this test may have created.
            if (file_exists($srcFile)) {
                unlink($srcFile);
            }
            if (file_exists($testFile)) {
                unlink($testFile);
            }
            $this->removeDirRecursive(ROOT . '/src/Views/intromodelentity');
        }
    }

    public function testCreateController(): void
    {
        // Mock output property for createController which uses $this->output->writeln()
        $refl = new \ReflectionProperty(\Pramnos\Console\Commands\MakeCommandBase::class, 'output');
        $refl->setValue($this->command, new BufferedOutput());

        $columns = [
            ['name' => 'title', 'type' => 'string', 'options' => [], 'nullable' => false, 'default' => '', 'unique' => false, 'comment' => '', 'unsigned' => false]
        ];
        $output = $this->command->exposeCreateController('TestEntity', true, $columns);
        $this->assertStringContainsString('Testentities', $output);
        // The summary ends with a ready-to-use test URL for the new controller.
        $this->assertStringContainsString('Test it now:', $output);
        $this->assertStringContainsString('Testentities', $output);

        $srcFile = ROOT . '/src/Controllers/Testentities.php';
        $testFile = ROOT . '/tests/Feature/TestentitiesTest.php';
        
        $this->assertFileExists($srcFile);

        $this->addCleanup($srcFile);
        $this->addCleanup($testFile);
    }

    /**
     * create:controller now ALWAYS generates a full CRUD controller from the
     * live table schema — the "simple skeleton" path was removed. When the
     * table does not exist (and no wizard columns are supplied) generation must
     * fail loudly with a clear message pointing to create:migration, rather than
     * silently emitting a schema-less stub.
     */
    public function testControllerWithoutTableThrowsCreateMigrationError(): void
    {
        // Arrange — output sink + a DB mock whose table does NOT exist. The
        // shared setUp() mock returns tableExists()=true, so swap in a dedicated
        // mock for this one assertion (tearDown restores the original instance).
        $refl = new \ReflectionProperty(\Pramnos\Console\Commands\MakeCommandBase::class, 'output');
        $refl->setValue($this->command, new BufferedOutput());

        $noTableDb = $this->createMock(\Pramnos\Database\Database::class);
        $noTableDb->method('tableExists')->willReturn(false);
        $noTableDb->connected = true;
        $noTableDb->type = 'mysql';
        $noTableDb->prefix = 'pr_';
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $dbRef = $noTableDb;

        // Assert — a clear error pointing to create:migration is thrown.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Create it first with `create:migration`');

        // Act — full controller, no wizard columns, table missing → must throw.
        $this->command->exposeCreateController('NoTableEntity', true);
    }

    /**
     * The generated CRUD web controller must use the framework's established
     * server-side DataTable pattern (matching \Pramnos\Auth\Controllers\
     * ApplicationsController): display() builds a \Pramnos\Html\Datatable whose
     * rows are streamed over AJAX from a data() action via
     * \Pramnos\Html\Datatable\Datasource::getList(). It must NOT emit the old
     * client-side approach (PramnosDataTable adapter enqueues, guarded
     * isScriptRegistered() blocks, enqueueStyle('datatables')) nor a web-side
     * getApiList endpoint.
     */
    public function testWizardControllerUsesServerSideDatatable(): void
    {
        // Arrange — output sink for createController()'s writeln() calls.
        $refl = new \ReflectionProperty(\Pramnos\Console\Commands\MakeCommandBase::class, 'output');
        $refl->setValue($this->command, new BufferedOutput());

        $columns = [
            ['name' => 'title', 'type' => 'string', 'options' => [], 'nullable' => false, 'default' => '', 'unique' => false, 'comment' => '', 'unsigned' => false]
        ];

        $ctrlFile = ROOT . '/src/Controllers/Gridentities.php';
        try {
            // Act
            $this->command->exposeCreateController('GridEntity', true, $columns);
            $this->assertFileExists($ctrlFile);
            $content = (string) file_get_contents($ctrlFile);

            // Assert — display() builds a server-side DataTable and hands it to
            // the view instead of loading a full list into the template.
            $this->assertStringContainsString('new \Pramnos\Html\Datatable(', $content,
                'display() must build a server-side DataTable');
            $this->assertStringContainsString('$dt->source = sURL', $content,
                'the DataTable must point at the data() AJAX endpoint');
            $this->assertStringContainsString("Gridentities/data", $content,
                'the DataTable source must be the controller data() action');
            $this->assertStringContainsString('$view->datatable = $dt;', $content,
                'the built DataTable must be passed to the view');
            $this->assertStringContainsString("->addColumn('Title', true, true, true, '', '', true, 'left', true)", $content,
                'each non-PK column becomes a DataTable column labelled from the schema, '
                . 'with its own search box under it');
            $this->assertStringContainsString('$dt->footerTextSearch = true;', $content,
                'a generated list gets per-column filters, like the framework\'s own admin lists');
            $this->assertStringContainsString('\Pramnos\Html\Icon::link(', $content,
                'row actions are labelled icons rather than three words repeated per row');
            $this->assertStringContainsString("->addColumn('Actions', true, false, false, 'html')", $content,
                'the chain must finish with a non-sortable HTML Actions column');
            $this->assertStringContainsString('$dt->bootstrap = false;', $content,
                'bootstrap flag must be false when scaffold_theme is not bootstrap');

            // Assert — a JSON data() action mirrors ApplicationsController: it
            // switches the document to json and streams rows via Datasource.
            $this->assertStringContainsString('public function data(): void', $content,
                'the controller must expose a data() AJAX action');
            $this->assertStringContainsString("getDocument('json')", $content,
                'data() must switch the document to JSON so the theme is not rendered');
            $this->assertStringContainsString('\Pramnos\Html\Datatable\Datasource::getList(', $content,
                'data() must stream rows through the DataTable Datasource');
            $this->assertStringContainsString('echo json_encode($result);', $content,
                'data() must echo the JSON payload');
            $this->assertStringContainsString('$this->terminate();', $content,
                'data() must terminate the request after emitting JSON');

            // Assert — `data` is a registered public action so exec() dispatches
            // it (auth does not block it); writes stay login-gated.
            $this->assertStringContainsString("addaction(['show', 'data'])", $content,
                'the data action must be public or exec() falls back to display()');
            $this->assertStringContainsString("addAuthAction(['edit', 'save', 'delete'])", $content,
                'the create/edit form and mutations must require login');

            // Assert — the retired client-side approach is entirely gone.
            $this->assertStringNotContainsString('getApiList', $content,
                'the web controller must no longer expose getApiList');
            $this->assertStringNotContainsString('pramnos-datatable', $content,
                'the client-side PramnosDataTable adapter must be gone');
            $this->assertStringNotContainsString("enqueueStyle('datatables')", $content,
                'the client-side DataTables stylesheet enqueue must be gone');
            $this->assertStringNotContainsString('isScriptRegistered', $content,
                'the guarded client-side script enqueues must be gone');

            // Assert — the generated controller is valid PHP.
            $lint = shell_exec(PHP_BINARY . ' -l ' . escapeshellarg($ctrlFile) . ' 2>&1');
            $this->assertMatchesRegularExpression('/No syntax errors/', (string) $lint,
                'generated controller must be valid PHP');
        } finally {
            // Cleanup — generated files + the wizard-written views/<entity>/ dir
            // so nothing lingers as untracked cruft in the framework repo.
            $this->addCleanup($ctrlFile);
            $this->addCleanup(ROOT . '/tests/Feature/GridentitiesTest.php');
            $viewDir = ROOT . '/src/Views/gridentity';
            if (is_dir($viewDir)) {
                foreach ((array) glob($viewDir . '/*') as $vf) {
                    if (is_file($vf)) {
                        @unlink($vf);
                    }
                }
                @rmdir($viewDir);
            }
        }
    }

    /**
     * The scaffolded CRUD *views* must match the framework's per-theme admin
     * views and render the list through the controller's server-side DataTable
     * (`$this->datatable->render()`), NOT the retired client-side markup.
     *
     * The TestApp\Application in this suite declares no `scaffold_theme`, so
     * detectUiSetup() resolves to the default 'plain-css' theme — we therefore
     * assert against the plain-css stub set (outer `.page-section` wrapper,
     * inline-style flash blocks, a flex header with an `<h2>` + themed "+ New"
     * button, the generic app-breadcrumb via renderBreadcrumbs()). The edit view
     * must carry a real `<form>` posting to the controller's save() action with
     * one field per non-PK column; the show view a field→value table driven by
     * the model's getData(). None of the three may leak the old client-side
     * DataTable shell (`data-dt-api`, `PramnosDataTable`).
     */
    public function testWizardViewsMatchAdminThemeAndUseServerSideDatatable(): void
    {
        // Arrange — output sink for createController()'s writeln() calls and a
        // column set exercising text / boolean / plain string field variants.
        $refl = new \ReflectionProperty(\Pramnos\Console\Commands\MakeCommandBase::class, 'output');
        $refl->setValue($this->command, new BufferedOutput());

        $columns = [
            ['name' => 'title',  'type' => 'string',  'options' => [], 'nullable' => false, 'default' => '', 'unique' => false, 'comment' => '', 'unsigned' => false],
            ['name' => 'active', 'type' => 'boolean', 'options' => [], 'nullable' => true,  'default' => '', 'unique' => false, 'comment' => '', 'unsigned' => false],
            ['name' => 'notes',  'type' => 'text',    'options' => [], 'nullable' => true,  'default' => '', 'unique' => false, 'comment' => '', 'unsigned' => false],
        ];

        $ctrlFile = ROOT . '/src/Controllers/Viewentities.php';
        $viewDir  = ROOT . '/src/Views/viewentity';
        $listFile = $viewDir . '/viewentity.html.php';
        $editFile = $viewDir . '/edit.html.php';
        $showFile = $viewDir . '/show.html.php';

        try {
            // Act — generate the controller + the three per-theme view files.
            $this->command->exposeCreateController('ViewEntity', true, $columns);

            // Assert — all three view files were written to views/<entity>/.
            $this->assertFileExists($listFile, 'list view must be generated');
            $this->assertFileExists($editFile, 'edit view must be generated');
            $this->assertFileExists($showFile, 'show view must be generated');

            $list = (string) file_get_contents($listFile);
            $edit = (string) file_get_contents($editFile);
            $show = (string) file_get_contents($showFile);

            // Assert — LIST: renders the server-side DataTable widget, wrapped in
            // the plain-css theme shell, with the generic app breadcrumb.
            $this->assertStringContainsString('$this->datatable->render()', $list,
                'list view must render the controller-provided server-side DataTable');
            $this->assertStringContainsString('class="page-section"', $list,
                'list view must use the plain-css outer wrapper (matches admin users.html.php)');
            $this->assertStringContainsString('renderBreadcrumbs()', $list,
                'list view must render the controller-populated app breadcrumbs generically');
            $this->assertStringContainsString('+ New', $list,
                'list view must offer a themed "+ New" action');

            // Assert — LIST: the retired client-side DataTable shell is gone.
            $this->assertStringNotContainsString('data-dt-api', $list,
                'the client-side data-dt-api table markup must be gone');
            $this->assertStringNotContainsString('PramnosDataTable', $list,
                'the client-side PramnosDataTable.init script must be gone');

            // Assert — EDIT: a real form posting to save() with one field per
            // non-PK column (theme-correct plain-css control markup).
            $this->assertStringContainsString('<form method="post"', $edit,
                'edit view must contain a real form');
            $this->assertStringContainsString('Viewentities/save/', $edit,
                'edit form must post to the controller save() action');
            $this->assertStringContainsString('name="title"', $edit,
                'edit form must render an input for each non-PK column');
            $this->assertStringContainsString('name="notes"', $edit,
                'text columns must render a textarea field');
            $this->assertStringContainsString('<textarea', $edit,
                'text columns must render as a textarea');
            $this->assertStringContainsString('type="checkbox"', $edit,
                'boolean columns must render as a checkbox');
            $this->assertStringNotContainsString('data-dt-api', $edit);
            $this->assertStringNotContainsString('PramnosDataTable', $edit);

            // Assert — SHOW: a field→value table driven by the model getData().
            $this->assertStringContainsString('$this->model->getData()', $show,
                'show view must iterate the model getData() field map');
            $this->assertStringContainsString('/delete/', $show,
                'show view must expose a Delete action');
            $this->assertStringContainsString('/edit/', $show,
                'show view must expose an Edit action');
            $this->assertStringNotContainsString('data-dt-api', $show);
            $this->assertStringNotContainsString('PramnosDataTable', $show);

            // Assert — every generated view file is valid PHP.
            foreach ([$listFile, $editFile, $showFile] as $vf) {
                $lint = shell_exec(PHP_BINARY . ' -l ' . escapeshellarg($vf) . ' 2>&1');
                $this->assertMatchesRegularExpression('/No syntax errors/', (string) $lint,
                    "generated view must be valid PHP: {$vf}");
            }
        } finally {
            // Cleanup — never leave untracked scaffolding cruft in the repo.
            $this->addCleanup($ctrlFile);
            $this->addCleanup(ROOT . '/tests/Feature/ViewentitiesTest.php');
            $this->removeDirRecursive($viewDir);
        }
    }

    /**
     * A FULL controller requested WITHOUT wizard columns must be generated by
     * introspecting the live table and delegating to the shared CRUD generator
     * (crud-controller.stub) — the same path the migration wizard uses.
     *
     * Why this matters: the old DB-first path built the controller from a
     * separate inline heredoc that fataled at runtime. This test proves the
     * introspected controller instead comes from crud-controller.stub — i.e. it
     * uses the server-side DataTable pattern (a data() action streaming rows via
     * Datasource::getList()) and never the retired getApiList endpoint.
     */
    public function testFullControllerFromDbIntrospectionUsesCrudStub(): void
    {
        // Arrange — createController() writes to $this->output; the DB mock from
        // setUp() already returns tableExists()=true and a typed column set
        // (PK + string/float/tinyint(1)/text/datetime/date + FK columns), which
        // exercises the SQL→logical type mapping and FK extraction.
        $refl = new \ReflectionProperty(\Pramnos\Console\Commands\MakeCommandBase::class, 'output');
        $refl->setValue($this->command, new BufferedOutput());

        $ctrlFile = ROOT . '/src/Controllers/Introentities.php';
        $viewDir  = ROOT . '/src/Views/introentity';

        try {
            // Act — full controller, NO wizard columns → DB-introspection path.
            $output = $this->command->exposeCreateController('IntroEntity', true);

            // Assert — the controller file was written.
            $this->assertFileExists($ctrlFile);
            $content = (string) file_get_contents($ctrlFile);

            // Assert — it came from crud-controller.stub: the server-side
            // DataTable data() action streaming rows through the Datasource.
            $this->assertStringContainsString('public function data(): void', $content,
                'introspected full controller must expose the DataTable data() action from crud-controller.stub');
            $this->assertStringContainsString('\Pramnos\Html\Datatable\Datasource::getList(', $content,
                'data() must stream rows through the DataTable Datasource');
            $this->assertStringContainsString('new \Pramnos\Html\Datatable(', $content,
                'display() must build a server-side DataTable');

            // Assert — the removed DB-first heredoc endpoint (a public getApiList()
            // action calling parent::_getApiList(), which does not exist on
            // Controller) stays gone.
            $this->assertStringNotContainsString('public function getApiList(', $content,
                'the web controller must no longer expose a getApiList() action');
            $this->assertStringNotContainsString('parent::_getApiList(', $content,
                'the removed DB-first heredoc called parent::_getApiList() which does not exist on Controller');

            // Assert — the FK-aware fkOptions() AJAX action IS emitted (the mocked
            // table has FK columns): it looks a field up in $fkMap and REUSES the
            // related model's _getApiList() instead of a bespoke query, and it is
            // registered as a public action so exec() dispatches it.
            $this->assertStringContainsString('public function fkOptions(): void', $content,
                'a controller with FK columns must expose the fkOptions() AJAX action');
            $this->assertStringContainsString('$fkMap = [', $content,
                'fkOptions() must carry the generated field => [class, pk] map');
            $this->assertStringContainsString('$model->_getApiList(', $content,
                'fkOptions() must reuse the related model _getApiList() pipeline');
            $this->assertStringContainsString("addaction(['show', 'data', 'fkOptions'])", $content,
                'fkOptions must be registered as a public action');
            // Both the user FK and a regular FK land in $fkMap. User now has its
            // own _getApiList() (added to \Pramnos\User\User), so the former
            // direct-users-query special-case is gone: user FKs flow through the
            // same generic $model->_getApiList() path asserted above.
            $this->assertStringContainsString("'\\\\Pramnos\\\\User\\\\User', 'userid'", $content,
                'the user FK must map to \\Pramnos\\User\\User / userid');
            $this->assertStringNotContainsString("->table('users')->select(['userid', 'username'])", $content,
                'the user FK special-case direct query must be gone — User has its own _getApiList()');

            // Assert — the generated controller is valid PHP.
            $lint = shell_exec(PHP_BINARY . ' -l ' . escapeshellarg($ctrlFile) . ' 2>&1');
            $this->assertMatchesRegularExpression('/No syntax errors/', (string) $lint,
                'generated controller must be valid PHP');
        } finally {
            // Cleanup — never leave untracked scaffolding cruft in the repo.
            $this->addCleanup($ctrlFile);
            $this->addCleanup(ROOT . '/tests/Feature/IntroentitiesTest.php');
            $this->removeDirRecursive($viewDir);
        }
    }

    /**
     * The FK form-field builder must switch between two rendering strategies:
     *
     *  - Select2 ON  → an AJAX-remote <select> that loads options lazily from the
     *    controller's fkOptions() action (url contains "fkOptions?field="),
     *    pre-rendering ONLY the currently-selected option (no eager foreach over a
     *    full list variable) so a FK to a huge table cannot bloat/break the form.
     *  - Select2 OFF → the native eager <select> populated by iterating the
     *    controller-provided list variable (the small-table fallback), unchanged.
     */
    public function testFkFieldSelect2UsesAjaxRemoteAndEagerFallback(): void
    {
        // Arrange — one regular FK column (category_id → categories).
        $columns = [
            ['name' => 'category_id', 'type' => 'biginteger', 'options' => [], 'nullable' => false, 'default' => null, 'unique' => false, 'comment' => 'Category', 'unsigned' => true],
        ];
        $fkByColumn = [
            'category_id' => ['column' => 'category_id', 'references' => 'id', 'on' => 'categories', 'onDelete' => 'RESTRICT', 'onUpdate' => 'RESTRICT'],
        ];

        // Act — render both variants of the same FK field.
        $withSelect2 = $this->command->exposeBuildWizardFormFields(
            $columns, $fkByColumn, 'id', 'bootstrap', true, 'Items'
        );
        $withoutSelect2 = $this->command->exposeBuildWizardFormFields(
            $columns, $fkByColumn, 'id', 'bootstrap', false, 'Items'
        );

        // Assert — Select2 ON: AJAX remote wired to the fkOptions() action.
        $this->assertStringContainsString('.select2({ ajax:', $withSelect2,
            'Select2 FK must be initialised with an ajax remote source');
        $this->assertStringContainsString('Items/fkOptions?field=category_id', $withSelect2,
            'the ajax url must point at the controller fkOptions() action for this field');
        $this->assertStringContainsString('processResults:', $withSelect2,
            'the ajax config must map the {results, pagination} envelope');
        // Only the selected option is pre-rendered — no eager foreach of a list.
        $this->assertStringContainsString('category_idSelectedText', $withSelect2,
            'the selected option text must come from the controller-resolved SelectedText');
        $this->assertStringNotContainsString('foreach ($this->categoryList', $withSelect2,
            'Select2 FK must NOT iterate the full option list');

        // Assert — Select2 OFF: native eager <select> over the list variable.
        $this->assertStringContainsString('foreach ($this->categoryList', $withoutSelect2,
            'without Select2 the FK must eagerly iterate the controller list variable');
        $this->assertStringNotContainsString('ajax:', $withoutSelect2,
            'the native fallback must not emit an ajax remote config');
    }

    /**
     * The model generator must now emit a SCHEMA-AWARE integration test (via
     * buildModelTest → crud-model-test.stub), not the old trivial
     * assertTrue(true) placeholder.
     *
     * We generate a model from a mixed column set and assert the generated test
     * file: (1) lives under tests/Unit/Models and extends the project's
     * Tests\BaseTestCase; (2) references the real entity columns by name in a
     * save→load→getData→delete round-trip; (3) sets a typed sample per scalar
     * column and asserts each persisted; (4) checks the getApiList envelope; and
     * (5) is valid PHP (php -l). Foreign-key columns are asserted as properties
     * but excluded from the round-trip (no parent-row dependency).
     */
    public function testModelTestIsSchemaAware(): void
    {
        // Arrange — a column set spanning string / int / float / bool + one FK.
        $columns = [
            ['name' => 'title',       'type' => 'string',     'options' => [], 'nullable' => false, 'default' => '', 'unique' => false, 'comment' => '', 'unsigned' => false],
            ['name' => 'amount',      'type' => 'float',       'options' => [], 'nullable' => true,  'default' => '', 'unique' => false, 'comment' => '', 'unsigned' => false],
            ['name' => 'active',      'type' => 'boolean',     'options' => [], 'nullable' => false, 'default' => '', 'unique' => false, 'comment' => '', 'unsigned' => false],
            ['name' => 'qty',         'type' => 'integer',     'options' => [], 'nullable' => false, 'default' => '', 'unique' => false, 'comment' => '', 'unsigned' => false],
        ];
        $foreignKeys = [
            ['column' => 'category_id', 'references' => 'id', 'on' => 'categories', 'onDelete' => 'SET NULL', 'onUpdate' => 'RESTRICT'],
        ];
        // Ensure category_id is a real column so property assertions include it.
        $columns[] = ['name' => 'category_id', 'type' => 'biginteger', 'options' => [], 'nullable' => true, 'default' => null, 'unique' => false, 'comment' => '', 'unsigned' => true];

        $srcFile  = ROOT . '/src/Models/SchemaModel.php';
        $testFile = ROOT . '/tests/Unit/Models/SchemaModelTest.php';

        try {
            // Act
            $this->command->exposeCreateModel('SchemaModel', $columns, $foreignKeys);

            // Assert — the schema-aware test file was written to tests/Unit/Models.
            $this->assertFileExists($testFile, 'model generator must emit a test under tests/Unit/Models');
            $content = (string) file_get_contents($testFile);

            // Assert — it extends the project's scaffolded base, not raw TestCase.
            $this->assertStringContainsString('use Tests\BaseTestCase;', $content);
            $this->assertStringContainsString('extends BaseTestCase', $content);

            // Assert — extends-Model + typed-property assertions per column.
            $this->assertStringContainsString('\Pramnos\Application\Model::class', $content);
            $this->assertStringContainsString("property_exists(\$model, 'title')", $content);
            $this->assertStringContainsString("property_exists(\$model, 'amount')", $content);
            $this->assertStringContainsString("property_exists(\$model, 'active')", $content);
            $this->assertStringContainsString("property_exists(\$model, 'qty')", $content);
            $this->assertStringContainsString("property_exists(\$model, 'category_id')", $content);

            // Assert — the round-trip sets a typed sample per scalar column and
            // verifies persistence with the correct cast per type.
            $this->assertStringContainsString('public function testSaveLoadGetDataDeleteRoundTrip', $content);
            $this->assertStringContainsString("\$model->title = 'sample text';", $content);
            $this->assertStringContainsString('$model->amount = 3.5;', $content);
            $this->assertStringContainsString('$model->active = 1;', $content);
            $this->assertStringContainsString('$model->qty = 42;', $content);
            $this->assertStringContainsString("assertEquals('sample text', (string) \$reloaded->title)", $content);
            $this->assertStringContainsString('assertEqualsWithDelta(3.5, (float) $reloaded->amount', $content);
            $this->assertStringContainsString('$model->save();', $content);
            $this->assertStringContainsString('$reloaded->load($id);', $content);
            $this->assertStringContainsString('$cleanup->delete($id);', $content);

            // Assert — FK column is a property but NOT set in the round-trip.
            $this->assertStringNotContainsString('$model->category_id =', $content,
                'FK columns must be left unset so the round-trip needs no parent row');

            // Assert — getData keys + getApiList envelope.
            $this->assertStringContainsString("assertArrayHasKey('title', \$data)", $content);
            $this->assertStringContainsString('public function testGetApiListReturnsEnvelope', $content);
            $this->assertStringContainsString("assertArrayHasKey('data', \$list)", $content);
            $this->assertStringContainsString("assertArrayHasKey('pagination', \$list)", $content);

            // Assert — no trivial placeholder survives.
            $this->assertStringNotContainsString('assertTrue(true)', $content);

            // Assert — the generated test is valid PHP.
            $lint = shell_exec(PHP_BINARY . ' -l ' . escapeshellarg($testFile) . ' 2>&1');
            $this->assertMatchesRegularExpression('/No syntax errors/', (string) $lint,
                'generated model test must be valid PHP');
        } finally {
            if (file_exists($srcFile))  { unlink($srcFile); }
            if (file_exists($testFile)) { unlink($testFile); }
            $this->removeDirRecursive(ROOT . '/src/Views/schemamodel');
            if (is_dir(ROOT . '/tests/Unit/Models')
                && count((array) glob(ROOT . '/tests/Unit/Models/*')) === 0) {
                @rmdir(ROOT . '/tests/Unit/Models');
            }
        }
    }

    /**
     * The controller generator must now emit a SCHEMA-AWARE Feature test (via
     * buildControllerTest → crud-controller-test.stub) that reflects on the
     * registered actions and dispatches through the framework TestClient — not
     * the old trivial placeholder.
     *
     * We generate a controller and assert the generated test file: (1) lives
     * under tests/Feature, extends Tests\BaseTestCase and uses TestClient;
     * (2) asserts the controller extends \Pramnos\Application\Controller;
     * (3) reflects on the public $actions (show, data) and $actions_auth (edit,
     * save, delete) arrays; (4) dispatches the list route and the data() JSON
     * endpoint (terminate() mocked, payload decoded); and (5) is valid PHP.
     */
    public function testControllerTestIsSchemaAware(): void
    {
        // Arrange — output sink for createController()'s writeln() calls.
        $refl = new \ReflectionProperty(\Pramnos\Console\Commands\MakeCommandBase::class, 'output');
        $refl->setValue($this->command, new BufferedOutput());

        $columns = [
            ['name' => 'title', 'type' => 'string', 'options' => [], 'nullable' => false, 'default' => '', 'unique' => false, 'comment' => '', 'unsigned' => false],
        ];

        $ctrlFile = ROOT . '/src/Controllers/Schemactrls.php';
        $testFile = ROOT . '/tests/Feature/SchemactrlsTest.php';
        $viewDir  = ROOT . '/src/Views/schemactrl';

        try {
            // Act
            $output = $this->command->exposeCreateController('SchemaCtrl', true, $columns);
            // The controller class is pluralised by the generator; confirm the
            // test path we assert against matches the reported class name.
            $this->assertStringContainsString('Schemactrls', $output);

            // Assert — the Feature test file was written.
            $this->assertFileExists($testFile, 'controller generator must emit a test under tests/Feature');
            $content = (string) file_get_contents($testFile);

            // Assert — project base + in-memory HTTP client.
            $this->assertStringContainsString('use Tests\BaseTestCase;', $content);
            $this->assertStringContainsString('extends BaseTestCase', $content);
            $this->assertStringContainsString('use Pramnos\Testing\TestClient;', $content);

            // Assert — extends framework Controller.
            $this->assertStringContainsString('\Pramnos\Application\Controller::class', $content);

            // Assert — action-registration reflection over the public arrays.
            $this->assertStringContainsString('public function testRegistersPublicAndAuthActions', $content);
            $this->assertStringContainsString("assertContains('show', \$controller->actions", $content);
            $this->assertStringContainsString("assertContains('data', \$controller->actions", $content);
            $this->assertStringContainsString("assertContains('edit', \$controller->actions_auth", $content);
            $this->assertStringContainsString("assertContains('save', \$controller->actions_auth", $content);
            $this->assertStringContainsString("assertContains('delete', \$controller->actions_auth", $content);

            // Assert — dispatch checks: list route + JSON data endpoint.
            $this->assertStringContainsString("\$client->get('/schemactrls')", $content);
            $this->assertStringContainsString('$response->assertSuccessful();', $content);
            $this->assertStringContainsString('public function testDataActionReturnsJson', $content);
            $this->assertStringContainsString("onlyMethods(['terminate'])", $content);
            $this->assertStringContainsString('$controller->data();', $content);
            $this->assertStringContainsString("array_key_exists('data', \$decoded) || array_key_exists('aaData', \$decoded)", $content);

            // Assert — no trivial placeholder survives.
            $this->assertStringNotContainsString('assertTrue(true)', $content);

            // Assert — the generated test is valid PHP.
            $lint = shell_exec(PHP_BINARY . ' -l ' . escapeshellarg($testFile) . ' 2>&1');
            $this->assertMatchesRegularExpression('/No syntax errors/', (string) $lint,
                'generated controller test must be valid PHP');
        } finally {
            $this->addCleanup($ctrlFile);
            $this->addCleanup($testFile);
            $this->removeDirRecursive($viewDir);
        }
    }

    public function testCreateApi(): void
    {
        file_put_contents(ROOT . '/src/Api/routes.php', '<?php');
        $output = $this->command->exposeCreateApi('TestEntity');
        $this->assertStringContainsString('TestEntity', $output);
        
        $srcFile = ROOT . '/src/Api/Controllers/TestEntity.php';
        $testFile = ROOT . '/tests/Feature/ApiTestentitiesTest.php';
        
        $this->assertFileExists($srcFile);
        
        $this->addCleanup($srcFile);
        $this->addCleanup($testFile);
    }

    public function testCreateCrud(): void
    {
        $refl = new \ReflectionProperty(\Pramnos\Console\Commands\MakeCommandBase::class, 'output');
        $refl->setValue($this->command, new BufferedOutput());

        $this->dbMock->method('tableExists')->willReturn(true);

        $output = $this->command->exposeCreateCrud('TestCrudEntity');
        $this->assertStringContainsString('Creating Model: OK', $output);
        $this->assertStringContainsString('Creating Controller: OK', $output);
        $this->assertStringContainsString('Creating View: OK', $output);
        
        $modelFile = ROOT . '/src/Models/TestCrudEntity.php';
        $ctrlFile = ROOT . '/src/Controllers/Testcrudentities.php';
        $viewDir = ROOT . '/src/Views/testcrudentity';
        
        $this->assertFileExists($modelFile);
        $this->assertFileExists($ctrlFile);
        $this->assertDirectoryExists($viewDir);
        
        $this->addCleanup($modelFile);
        $this->addCleanup($ctrlFile);
        $this->addCleanup(ROOT . '/tests/Unit/Models/TestCrudEntityTest.php');
        $this->addCleanup(ROOT . '/tests/Feature/TestcrudentitiesTest.php');
        
        if (is_dir($viewDir)) {
            $files = glob($viewDir . '/*');
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($viewDir);
        }
    }

    public function testLookupModelByConvention(): void
    {
        $result = $this->command->exposeLookupModel('user');
        $this->assertIsArray($result);
        $this->assertEquals('User', $result['className']);
        $this->assertEquals('\Pramnos\Models\User', $result['fullClassName']);
    }

    /**
     * The simple (non --full) view path now emits a SINGLE bare placeholder
     * view file, rendered from scaffolding/templates/simple-view.stub rather
     * than the retired inline heredoc. It must NOT require a table and must NOT
     * be a CRUD view: no edit.html.php / show.html.php, no admin datatable
     * markup — just a themed placeholder the developer fills in.
     */
    public function testCreateViewBasic(): void
    {
        // Act — simple view, no table involved.
        $this->command->exposeCreateView('TestBasicView', false);
        $viewDir = ROOT . '/src/Views/testbasicview';
        $indexFile = $viewDir . '/testbasicview.html.php';

        // Assert — exactly one placeholder file is generated (no CRUD siblings).
        $this->assertFileExists($indexFile);
        $this->assertFileDoesNotExist($viewDir . '/edit.html.php',
            'the simple view path must not scaffold a CRUD edit view');
        $this->assertFileDoesNotExist($viewDir . '/show.html.php',
            'the simple view path must not scaffold a CRUD show view');

        // Assert — it is the bare placeholder from simple-view.stub, not the
        // old admin CRUD list (no server-side datatable render call).
        $content = (string) file_get_contents($indexFile);
        $this->assertStringContainsString('placeholder view', $content,
            'simple view must be the bare placeholder rendered from simple-view.stub');
        $this->assertStringNotContainsString('$this->datatable->render()', $content,
            'the simple view must not carry the admin CRUD datatable markup');

        // Assert — valid PHP.
        $lint = shell_exec(PHP_BINARY . ' -l ' . escapeshellarg($indexFile) . ' 2>&1');
        $this->assertMatchesRegularExpression('/No syntax errors/', (string) $lint);

        unlink($indexFile);
        rmdir($viewDir);
    }

    /**
     * `create:view <name> --full` is now unified onto the SAME admin-style
     * per-theme CRUD view generator used by the wizard/CRUD path (mirroring the
     * already-unified createController()/createModel()). Given a live table
     * (the setUp() $dbMock returns a column set + tableExists()=true), the full
     * path introspects the table, builds wizard-shaped columns/FKs and delegates
     * to createViewsFromWizard(), which renders crud-view-*.stub per theme.
     *
     * The TestApp\Application declares no scaffold_theme, so detectUiSetup()
     * resolves to the default plain-css stub set. We assert the three admin-style
     * files exist and carry the unified markup — the list view's server-side
     * `$this->datatable->render()`, the plain-css `.page-section` theme wrapper
     * and the generic renderBreadcrumbs() call, a real edit <form> posting to
     * save(), and the show view's getData() field table — and that none of the
     * retired inline-heredoc markup survives.
     */
    public function testCreateViewFullUsesAdminStyleWizardViews(): void
    {
        // Arrange — full view generation from the mocked live table.
        $viewDir  = ROOT . '/src/Views/testfullview';
        $listFile = $viewDir . '/testfullview.html.php';
        $editFile = $viewDir . '/edit.html.php';
        $showFile = $viewDir . '/show.html.php';

        try {
            // Act — --full path (no wizard columns) → DB introspection + delegate.
            $output = $this->command->exposeCreateView('TestFullView', true);

            // Assert — the admin-style list/edit/show trio was generated.
            $this->assertStringContainsString('Views:', $output,
                'full view must return the createViewsFromWizard() summary shape');
            $this->assertFileExists($listFile, 'full path must generate the list view');
            $this->assertFileExists($editFile, 'full path must generate the edit view');
            $this->assertFileExists($showFile, 'full path must generate the show view');

            $list = (string) file_get_contents($listFile);
            $edit = (string) file_get_contents($editFile);
            $show = (string) file_get_contents($showFile);

            // Assert — LIST: server-side DataTable + plain-css theme wrapper +
            // generic app breadcrumb (the admin-style markup), not the retired
            // heredoc's inline `new \Pramnos\Html\Datatable(...)` in the view.
            $this->assertStringContainsString('$this->datatable->render()', $list,
                'list view must render the controller-provided server-side DataTable');
            $this->assertStringContainsString('class="page-section"', $list,
                'list view must use the admin plain-css theme wrapper');
            $this->assertStringContainsString('renderBreadcrumbs()', $list,
                'list view must render the app breadcrumbs generically');
            $this->assertStringNotContainsString('new \Pramnos\Html\Datatable(', $list,
                'the retired inline heredoc built the Datatable in the view itself');

            // Assert — EDIT: a real form posting to save() with fields for the
            // introspected columns (title → text input, description → textarea).
            $this->assertStringContainsString('<form method="post"', $edit,
                'edit view must contain a real form');
            $this->assertStringContainsString('Testfullviews/save/', $edit,
                'edit form must post to the controller save() action');
            $this->assertStringContainsString('name="title"', $edit,
                'edit form must render an input for each non-PK column');
            $this->assertStringContainsString('<textarea', $edit,
                'text columns must render as a textarea');

            // Assert — SHOW: a getData()-driven field table with edit/delete.
            $this->assertStringContainsString('$this->model->getData()', $show,
                'show view must iterate the model getData() field map');
            $this->assertStringContainsString('/edit/', $show);
            $this->assertStringContainsString('/delete/', $show);

            // Assert — every generated view is valid PHP.
            foreach ([$listFile, $editFile, $showFile] as $vf) {
                $lint = shell_exec(PHP_BINARY . ' -l ' . escapeshellarg($vf) . ' 2>&1');
                $this->assertMatchesRegularExpression('/No syntax errors/', (string) $lint,
                    "generated view must be valid PHP: {$vf}");
            }
        } finally {
            $this->removeDirRecursive($viewDir);
        }
    }

    /**
     * `create:view <name> --full` against a table that does not exist (and with
     * no wizard columns) must fail loudly with the SAME clear message the
     * controller/model generators throw — pointing the developer at
     * create:migration — instead of silently emitting a schema-less view.
     */
    public function testCreateViewFullWithoutTableThrowsCreateMigrationError(): void
    {
        // Arrange — swap in a DB mock whose table does NOT exist (setUp()'s mock
        // returns tableExists()=true). tearDown() restores the original instance.
        $noTableDb = $this->createMock(\Pramnos\Database\Database::class);
        $noTableDb->method('tableExists')->willReturn(false);
        $noTableDb->connected = true;
        $noTableDb->type = 'mysql';
        $noTableDb->prefix = 'pr_';
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $dbRef = $noTableDb;

        // Assert — a clear error pointing to create:migration is thrown.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Create it first with `create:migration`');

        try {
            // Act — full view, no wizard columns, table missing → must throw.
            $this->command->exposeCreateView('NoTableView', true);
        } finally {
            // The (empty) view dir is created before the table check; clean it up.
            $this->removeDirRecursive(ROOT . '/src/Views/notableview');
        }
    }
}

}
