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
