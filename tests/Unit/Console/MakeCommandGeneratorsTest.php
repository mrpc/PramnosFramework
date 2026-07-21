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
     * The simple (skeleton) controller — rendered from controller.stub — must
     * register EVERY action it defines. `show` was previously missing from the
     * constructor, so Controller::exec() silently fell back to display() for
     * /entity/show/:id. It must be registered (public read) alongside the
     * login-gated edit/save/delete.
     */
    public function testSimpleControllerRegistersShowAction(): void
    {
        // Arrange
        $refl = new \ReflectionProperty(\Pramnos\Console\Commands\MakeCommandBase::class, 'output');
        $refl->setValue($this->command, new BufferedOutput());

        // Act — simple path ($full = false), no columns → controller.stub
        $this->command->exposeCreateController('SkelEntity', false);
        $srcFile = ROOT . '/src/Controllers/Skelentities.php';
        $this->addCleanup($srcFile);
        $this->addCleanup(ROOT . '/tests/Feature/SkelentitiesTest.php');
        $this->assertFileExists($srcFile);
        $content = (string) file_get_contents($srcFile);

        // Assert — show registered (public), writes login-gated, valid PHP
        $this->assertStringContainsString("addaction(['show'])", $content,
            'show must be registered or exec() falls back to display()');
        $this->assertStringContainsString("addAuthAction(['edit', 'save', 'delete'])", $content);
        $lint = shell_exec(PHP_BINARY . ' -l ' . escapeshellarg($srcFile) . ' 2>&1');
        $this->assertMatchesRegularExpression('/No syntax errors/', (string) $lint);
    }

    /**
     * When DataTables is installed, the generated CRUD controller must enqueue
     * the Pramnos REST adapter under the `pramnos-datatable` handle — guarded by
     * isScriptRegistered() — and must NOT enqueue the old bundle handle
     * `pramnos-adapters`, which was never registered and fataled the page
     * ("Cannot find script: pramnos-adapters").
     */
    public function testWizardControllerEnqueuesGuardedDatatableAdapter(): void
    {
        // Arrange — output sink + a fake installed DataTables vendor dir so
        // detectUiSetup() reports datatables=true (drives the enqueue block).
        $refl = new \ReflectionProperty(\Pramnos\Console\Commands\MakeCommandBase::class, 'output');
        $refl->setValue($this->command, new BufferedOutput());

        $vendorDt = ROOT . '/www/assets/vendor/datatables';
        $createdDirs = [];
        foreach ([ROOT . '/www', ROOT . '/www/assets', ROOT . '/www/assets/vendor', $vendorDt] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir);
                $createdDirs[] = $dir; // remember only what we created, to clean up
            }
        }

        $columns = [
            ['name' => 'title', 'type' => 'string', 'options' => [], 'nullable' => false, 'default' => '', 'unique' => false, 'comment' => '', 'unsigned' => false]
        ];

        $ctrlFile = ROOT . '/src/Controllers/Gridentities.php';
        try {
            // Act
            $this->command->exposeCreateController('GridEntity', true, $columns);
            $this->assertFileExists($ctrlFile);
            $content = (string) file_get_contents($ctrlFile);

            // Assert — guarded adapter enqueue, old bundle handle gone
            $this->assertStringContainsString("enqueueScript('pramnos-datatable')", $content,
                'controller must enqueue the pramnos-datatable adapter handle');
            $this->assertStringContainsString("isScriptRegistered('pramnos-datatable')", $content,
                'the adapter enqueue must be guarded so a missing handle never fatals');
            $this->assertStringNotContainsString("enqueueScript('pramnos-adapters')", $content,
                'the unregistered bundle handle must no longer be enqueued');

            // Assert — getApiList returns JSON (a Response), reads the adapter's
            // query params from the request, and no longer wraps its payload in
            // the HTML theme (the "Invalid JSON response" regression).
            $this->assertStringContainsString('Pramnos\Http\Response::json(', $content,
                'getApiList must return a JSON Response so the theme is not rendered');
            $this->assertStringContainsString('new \Pramnos\Http\Request()', $content,
                'getApiList must read DataTables params from the request, not method args');
            $this->assertStringContainsString("\$request->get('page'", $content);
            $this->assertStringNotContainsString("getDocument('json')", $content,
                'the old getDocument(json)+return-array pattern must be gone');

            // Assert — every action is registered so Controller::exec() dispatches
            // getApiList/show instead of falling back to display(); writes
            // (edit form + save/delete) are login-gated.
            $this->assertStringContainsString("addaction(['show', 'getApiList'])", $content,
                'public read/JSON actions must be registered or exec() falls back to display()');
            $this->assertStringContainsString("addAuthAction(['edit', 'save', 'delete'])", $content,
                'the create/edit form and mutations must require login');

            // Assert — the DataTables stylesheet is enqueued (guarded) so the
            // list controls are styled, not bare HTML.
            $this->assertStringContainsString("isStyleRegistered('datatables')", $content);
            $this->assertStringContainsString("enqueueStyle('datatables')", $content);

            // Assert — the generated controller is valid PHP.
            $lint = shell_exec(PHP_BINARY . ' -l ' . escapeshellarg($ctrlFile) . ' 2>&1');
            $this->assertMatchesRegularExpression('/No syntax errors/', (string) $lint,
                'generated controller must be valid PHP');
        } finally {
            // Cleanup — generated files + only the dirs we created (deepest first)
            $this->addCleanup($ctrlFile);
            $this->addCleanup(ROOT . '/tests/Feature/GridentitiesTest.php');
            // The wizard also writes a views/<entity>/ directory — remove it so it
            // does not linger as untracked cruft in the framework repo.
            $viewDir = ROOT . '/src/Views/gridentity';
            if (is_dir($viewDir)) {
                foreach ((array) glob($viewDir . '/*') as $vf) {
                    if (is_file($vf)) {
                        @unlink($vf);
                    }
                }
                @rmdir($viewDir);
            }
            foreach (array_reverse($createdDirs) as $dir) {
                if (is_dir($dir)) {
                    rmdir($dir);
                }
            }
        }
    }

    /**
     * A FULL controller requested WITHOUT wizard columns must be generated by
     * introspecting the live table and delegating to the shared CRUD generator
     * (crud-controller.stub) — the same path the migration wizard uses.
     *
     * Why this matters: the old DB-first path built the controller from a
     * separate inline heredoc whose getApiList() called `parent::_getApiList()`
     * — a method that does NOT exist on \Pramnos\Application\Controller, so the
     * generated controller fataled at runtime. This test proves the introspected
     * controller instead contains the WORKING getApiList (JSON Response +
     * $model->_getApiList) that only crud-controller.stub emits, and never the
     * broken `parent::_getApiList(` call.
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

            // Assert — it came from crud-controller.stub: JSON Response +
            // the model's _getApiList (the working getApiList signature).
            $this->assertStringContainsString('\Pramnos\Http\Response::json(', $content,
                'introspected full controller must emit the JSON Response getApiList from crud-controller.stub');
            $this->assertStringContainsString('$model->_getApiList(', $content,
                'getApiList must delegate to the model, not to a non-existent Controller method');

            // Assert — the broken path-3 call is gone for good.
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
