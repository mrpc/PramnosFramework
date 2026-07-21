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
            $this->assertStringContainsString("->addColumn('Title')", $content,
                'each non-PK column must become a DataTable column labelled from the schema');
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

            // Assert — the retired getApiList endpoint / broken heredoc call are gone.
            $this->assertStringNotContainsString('getApiList', $content,
                'the web controller must no longer expose getApiList');
            $this->assertStringNotContainsString('parent::_getApiList(', $content,
                'the removed DB-first heredoc called parent::_getApiList() which does not exist on Controller');

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

    public function testCreateViewBasic(): void
    {
        $this->command->exposeCreateView('TestBasicView', false);
        $viewDir = ROOT . '/src/Views/testbasicview';
        $indexFile = $viewDir . '/testbasicview.html.php';
        
        $this->assertFileExists($indexFile);
        
        unlink($indexFile);
        unlink($viewDir . '/edit.html.php');
        unlink($viewDir . '/show.html.php');
        rmdir($viewDir);
    }
}

}
