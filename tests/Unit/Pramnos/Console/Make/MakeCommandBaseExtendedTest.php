<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\MakeCommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Concrete subclass of MakeCommandBase that satisfies the abstract contract.
 *
 * We also expose private/protected helpers via public delegators so tests can
 * call them directly without reflection.
 */
class ExtDummyMakeCommand extends MakeCommandBase
{
    protected function configure(): void {}
    protected function execute(InputInterface $input, OutputInterface $output): int { return 0; }

    /** Expose detectUiSetup() publicly for testing. */
    public function callDetectUiSetup(): array
    {
        return $this->detectUiSetup();
    }

    /**
     * Expose createViewsFromWizard() publicly so tests can call it directly
     * without going through the full wizard pipeline.
     */
    public function callCreateViewsFromWizard(
        string $name,
        array  $columns,
        array  $foreignKeys,
        string $primaryKey,
        array  $ui
    ): string {
        return $this->createViewsFromWizard($name, $columns, $foreignKeys, $primaryKey, $ui);
    }

    /**
     * Expose registerModelInRegistry() publicly.
     */
    public function callRegisterModelInRegistry(array $modelInfo): bool
    {
        return $this->registerModelInRegistry($modelInfo);
    }

    /**
     * Expose addGetApiListMethod() publicly.
     */
    public function callAddGetApiListMethod(string $filename): bool
    {
        return $this->addGetApiListMethod($filename);
    }
}

/**
 * Extended tests for MakeCommandBase covering branches not exercised by
 * MakeCommandBaseTest.php.
 *
 * Focus areas:
 *  1. Static delegation helpers (getProperClassName, getModelTableName)
 *  2. generateTestStub() with the 'controller_test' stub → tests/Feature/ path
 *  3. registerModelInRegistry() — new entry, update existing, JSON creation
 *  4. addGetApiListMethod() — inserts method before closing brace
 *  5. detectUiSetup() — UI library detection from vendor directory
 *  6. createViewsFromWizard() — bootstrap, datatables, plain-CSS branches
 *     including FK fields, boolean, text, date/datetime columns
 */
#[CoversClass(MakeCommandBase::class)]
class MakeCommandBaseExtendedTest extends TestCase
{
    private string $tmpDir;
    private ExtDummyMakeCommand $command;

    // =========================================================================
    // Infrastructure
    // =========================================================================

    /** Unique suffix so parallel tests don't collide on shared paths. */
    private string $testSuffix;

    protected function setUp(): void
    {
        // Arrange — isolated temp workspace for tests that write to arbitrary paths.
        // NOTE: ROOT is already defined by the test bootstrap as the framework root.
        //       Some methods (registerModelInRegistry, createViewsFromWizard) use ROOT
        //       directly as a PHP constant, so we cannot redirect them to a tmpDir.
        //       We use a unique suffix to avoid cross-test collisions and always clean
        //       up in tearDown.
        $this->testSuffix = bin2hex(random_bytes(4));
        $this->tmpDir = sys_get_temp_dir() . '/pramnos_ext_test_' . $this->testSuffix;
        mkdir($this->tmpDir . '/tests/Unit', 0777, true);
        mkdir($this->tmpDir . '/tests/Feature', 0777, true);

        // Ensure INCLUDES is defined (Application defines it as 'src' lazily;
        // we need it available for createViewsFromWizard).
        if (!defined('INCLUDES')) {
            define('INCLUDES', 'src');
        }

        $this->command = new ExtDummyMakeCommand();

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
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tmpDir);

        // Clean up any registry entries written to ROOT/app/ by registerModelInRegistry tests
        $registryFile = ROOT . DS . 'app' . DS . 'model-registry.json';
        if (file_exists($registryFile)) {
            // Only remove entries we created in this test run (by our test suffix in the
            // class name) to avoid disturbing other tests. Read, filter, write back.
            $data = json_decode(file_get_contents($registryFile), true);
            if (is_array($data)) {
                $filtered = array_values(array_filter($data, function ($entry) {
                    // Keep only entries that were NOT written by our tests
                    $cls = $entry['className'] ?? '';
                    return !in_array($cls, ['Product', 'Order', 'Alpha', 'Beta', 'Repair'], true);
                }));
                if (empty($filtered)) {
                    unlink($registryFile);
                } else {
                    file_put_contents($registryFile, json_encode($filtered, JSON_PRETTY_PRINT));
                }
            }
        }

        // Clean up view directories created in ROOT/src/Views/ by createViewsFromWizard tests
        $viewsBase = ROOT . DS . INCLUDES . DS . 'Views';
        foreach (['post', 'customer', 'article', 'item', 'document', 'person', 'product', 'thing'] as $name) {
            $dir = $viewsBase . DS . $name;
            if (is_dir($dir)) {
                $this->rmdir($dir);
            }
        }
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // =========================================================================
    // 1. Static delegation helpers
    // =========================================================================

    /**
     * getProperClassName() delegates to NamespaceResolver and returns a
     * PascalCase singular class name.
     *
     * This is the primary naming convention for model/controller generation:
     * 'users' → 'User', 'blog_posts' → 'BlogPost'.
     */
    public function testGetProperClassNameSingulariesesTableName(): void
    {
        // Act — forceSingular = true (the default)
        $result = MakeCommandBase::getProperClassName('users', true);

        // Assert — returns singular PascalCase
        $this->assertSame('User', $result,
            'getProperClassName("users", true) must return "User"');
    }

    /**
     * getProperClassName() with forceSingular=false returns PascalCase without
     * singularising. Used for controller names which stay plural.
     */
    public function testGetProperClassNamePluralWhenForceSingularFalse(): void
    {
        // Act
        $result = MakeCommandBase::getProperClassName('products', false);

        // Assert — plural PascalCase
        $this->assertSame('Products', $result,
            'getProperClassName("products", false) must return "Products"');
    }

    /**
     * getModelTableName() returns a #PREFIX#-prefixed table name derived from
     * the model name. Used when no explicit --table option is given.
     */
    public function testGetModelTableNameAddsPrefix(): void
    {
        // Act
        $result = MakeCommandBase::getModelTableName('User');

        // Assert — table name uses #PREFIX# placeholder
        $this->assertStringStartsWith('#PREFIX#', $result,
            'getModelTableName() must prepend #PREFIX# to the table name');
        $this->assertStringContainsString('user', strtolower($result),
            'Derived table name must include the model name stem');
    }

    // =========================================================================
    // 2. generateTestStub() — controller_test → tests/Feature/ path
    // =========================================================================

    /**
     * generateTestStub() with stubName='controller_test' must write the test
     * file to tests/Feature/ (not tests/Unit/) because controllers are typically
     * tested as feature tests.
     *
     * This branch (lines 440-441 of MakeCommandBase) was not covered by the
     * existing tests which all use the default 'test' stub name.
     */
    public function testGenerateTestStubControllerTestWritesToFeatureDirectory(): void
    {
        // Arrange — Feature dir already created in setUp()
        $featureDir = $this->tmpDir . '/tests/Feature';

        // Act
        $summary = $this->command->generateTestStub(
            'ProductController',
            'App\\Controllers',
            $this->tmpDir,
            'controller_test'
        );

        // Assert — file lands in tests/Feature/, not tests/Unit/
        $expectedFile = $featureDir . '/ProductControllerTest.php';
        $this->assertFileExists($expectedFile,
            'controller_test stub must write to tests/Feature/');

        // Assert — summary mentions the test file
        $this->assertNotEmpty($summary);
        $this->assertStringContainsString('ProductControllerTest', $summary);

        // Assert — file is NOT in tests/Unit/
        $this->assertFileDoesNotExist($this->tmpDir . '/tests/Unit/ProductControllerTest.php',
            'controller_test stub must NOT write to tests/Unit/');
    }

    // =========================================================================
    // 3. registerModelInRegistry()
    // =========================================================================

    /**
     * registerModelInRegistry() creates/updates ROOT/app/model-registry.json.
     *
     * Since ROOT is a fixed constant pointing to the framework root, we write
     * to ROOT/app/model-registry.json and clean up in tearDown(). The test
     * verifies that calling the method produces a registry file with the
     * correct structure, that updates don't produce duplicates, and that
     * corrupt JSON is gracefully handled.
     *
     * NOTE: These tests share the same registry file path. They run sequentially
     * within the class, so the tearDown() between each test clears the entries
     * they create (identified by known class names: Product, Order, Alpha, Beta,
     * Repair).
     */
    public function testRegisterModelInRegistryCreatesNewRegistryFile(): void
    {
        // Arrange — registry for 'Product' must not exist yet (tearDown cleans it)
        $registryFile = ROOT . DS . 'app' . DS . 'model-registry.json';

        // Remove any pre-existing Product entry from a previous failed test
        if (file_exists($registryFile)) {
            $data = json_decode(file_get_contents($registryFile), true) ?? [];
            $data = array_values(array_filter($data, fn($e) => ($e['className'] ?? '') !== 'Product'));
            if (empty($data)) {
                unlink($registryFile);
            } else {
                file_put_contents($registryFile, json_encode($data, JSON_PRETTY_PRINT));
            }
        }

        // Act
        $result = $this->command->callRegisterModelInRegistry([
            'className'     => 'Product',
            'namespace'     => 'App\\Models',
            'fullClassName' => '\\App\\Models\\Product',
            'table'         => '#PREFIX#products',
            'schema'        => '',
            'timestamp'     => '2026-01-01 00:00:00',
        ]);

        // Assert — method reports success
        $this->assertTrue($result, 'registerModelInRegistry() must return true on success');

        // Assert — file was created/updated with valid JSON containing the new entry
        $this->assertFileExists($registryFile);
        $data = json_decode(file_get_contents($registryFile), true);
        $this->assertIsArray($data);

        // Find our entry by fullClassName
        $entry = null;
        foreach ($data as $item) {
            if (($item['fullClassName'] ?? '') === '\\App\\Models\\Product') {
                $entry = $item;
                break;
            }
        }
        $this->assertNotNull($entry, 'Product entry must be present in the registry');
        $this->assertSame('Product',      $entry['className']);
        $this->assertSame('App\\Models',  $entry['namespace']);
        $this->assertSame('#PREFIX#products', $entry['table']);

        // Assert — timestamps were added
        $this->assertArrayHasKey('createdAt', $entry);
        $this->assertArrayHasKey('updatedAt', $entry);
    }

    /**
     * registerModelInRegistry() updates an existing entry instead of
     * duplicating it when called a second time with the same fullClassName.
     *
     * This protects against duplicate model entries accumulating in the
     * registry over multiple `create:model` invocations.
     */
    public function testRegisterModelInRegistryUpdatesExistingEntry(): void
    {
        // Arrange — pre-seed with an Order entry
        $registryFile = ROOT . DS . 'app' . DS . 'model-registry.json';
        if (file_exists($registryFile)) {
            $data = json_decode(file_get_contents($registryFile), true) ?? [];
            $data = array_values(array_filter($data, fn($e) => ($e['className'] ?? '') !== 'Order'));
            file_put_contents($registryFile, json_encode($data, JSON_PRETTY_PRINT));
        }

        $info = [
            'className'     => 'Order',
            'namespace'     => 'App\\Models',
            'fullClassName' => '\\App\\Models\\Order',
            'table'         => '#PREFIX#orders',
            'schema'        => '',
            'timestamp'     => '2026-01-01 00:00:00',
        ];
        $this->command->callRegisterModelInRegistry($info);

        // Act — register again with a different table
        $info['table']     = '#PREFIX#new_orders';
        $info['timestamp'] = '2026-06-01 12:00:00';
        $this->command->callRegisterModelInRegistry($info);

        // Assert — no duplicate entries for Order
        $data    = json_decode(file_get_contents($registryFile), true);
        $orders  = array_filter($data, fn($e) => ($e['fullClassName'] ?? '') === '\\App\\Models\\Order');
        $this->assertCount(1, $orders, 'Updating must not add a second entry for the same fullClassName');

        $entry = array_values($orders)[0];
        $this->assertSame('#PREFIX#new_orders', $entry['table'],
            'Table name must be updated on the second call');
        $this->assertSame('2026-01-01 00:00:00', $entry['createdAt'],
            'createdAt must be preserved from the first registration');
        $this->assertSame('2026-06-01 12:00:00', $entry['updatedAt'],
            'updatedAt must reflect the latest registration timestamp');
    }

    /**
     * registerModelInRegistry() appends a second distinct model without
     * disturbing the first.
     *
     * The registry stores all models for the application — it must support
     * multiple entries cleanly.
     */
    public function testRegisterModelInRegistryAppendsDistinctModels(): void
    {
        // Arrange — clear Alpha and Beta from any previous run
        $registryFile = ROOT . DS . 'app' . DS . 'model-registry.json';
        if (file_exists($registryFile)) {
            $data = json_decode(file_get_contents($registryFile), true) ?? [];
            $data = array_values(array_filter($data, fn($e) => !in_array($e['className'] ?? '', ['Alpha', 'Beta'], true)));
            file_put_contents($registryFile, json_encode($data, JSON_PRETTY_PRINT));
        }

        // Act — register two distinct models
        $this->command->callRegisterModelInRegistry([
            'className'     => 'Alpha',
            'namespace'     => 'App\\Models',
            'fullClassName' => '\\App\\Models\\Alpha',
            'table'         => '#PREFIX#alphas',
            'schema'        => '',
            'timestamp'     => '2026-01-01 00:00:00',
        ]);
        $this->command->callRegisterModelInRegistry([
            'className'     => 'Beta',
            'namespace'     => 'App\\Models',
            'fullClassName' => '\\App\\Models\\Beta',
            'table'         => '#PREFIX#betas',
            'schema'        => '',
            'timestamp'     => '2026-01-02 00:00:00',
        ]);

        // Assert — both entries present
        $data       = json_decode(file_get_contents($registryFile), true);
        $classNames = array_column($data, 'fullClassName');
        $this->assertContains('\\App\\Models\\Alpha', $classNames,
            'Alpha model must be in the registry');
        $this->assertContains('\\App\\Models\\Beta', $classNames,
            'Beta model must be in the registry');
    }

    /**
     * registerModelInRegistry() gracefully handles an existing registry file
     * that contains invalid JSON by resetting the registry to an empty array.
     *
     * A corrupt registry must not crash model generation — it should be silently
     * rebuilt from the new entry.
     */
    public function testRegisterModelInRegistryHandlesCorruptRegistryFile(): void
    {
        // Arrange — write broken JSON to the registry file
        $registryFile = ROOT . DS . 'app' . DS . 'model-registry.json';
        $originalContent = file_exists($registryFile) ? file_get_contents($registryFile) : null;
        file_put_contents($registryFile, '{ this is not valid json }');

        // Act
        $result = $this->command->callRegisterModelInRegistry([
            'className'     => 'Repair',
            'namespace'     => 'App\\Models',
            'fullClassName' => '\\App\\Models\\Repair',
            'table'         => '#PREFIX#repairs',
            'schema'        => '',
            'timestamp'     => '2026-01-01 00:00:00',
        ]);

        // Assert — succeeds despite corrupt input
        $this->assertTrue($result,
            'registerModelInRegistry() must return true even when the existing file is corrupt JSON');

        // Assert — file was rebuilt and contains the new entry
        $data  = json_decode(file_get_contents($registryFile), true);
        $this->assertIsArray($data,
            'Registry must be valid JSON after rebuilding from corrupt state');
        $repairs = array_filter($data, fn($e) => ($e['className'] ?? '') === 'Repair');
        $this->assertNotEmpty($repairs,
            'Repair entry must be present after rebuilding');

        // Restore original content if there was any (clean test isolation)
        if ($originalContent !== null) {
            // Merge back original valid entries (without Repair)
            $orig = json_decode($originalContent, true) ?? [];
            $merged = array_merge($orig, array_values($repairs));
            file_put_contents($registryFile, json_encode($merged, JSON_PRETTY_PRINT));
        }
    }

    // =========================================================================
    // 4. addGetApiListMethod()
    // =========================================================================

    /**
     * addGetApiListMethod() inserts a getApiList() method just before the last
     * closing brace of a PHP class file.
     *
     * This is used by createModel() when an existing model file is missing the
     * getApiList() method that was added in a later framework version.
     */
    public function testAddGetApiListMethodInsertsMethodBeforeClosingBrace(): void
    {
        // Arrange — minimal PHP class without getApiList
        $classSource = <<<'PHP'
<?php
namespace App\Models;

class Minimal extends \Pramnos\Application\Model
{
    public $minimalid;
    protected $_primaryKey = "minimalid";
    protected $_dbtable = "#PREFIX#minimals";

    public function load($minimalid, $key = null, $debug = false)
    {
        return parent::_load($minimalid, null, $key, $debug);
    }
}
PHP;
        $filename = $this->tmpDir . '/Minimal.php';
        file_put_contents($filename, $classSource);

        // Act
        $result = $this->command->callAddGetApiListMethod($filename);

        // Assert — method reports success
        $this->assertTrue($result, 'addGetApiListMethod() must return true on success');

        // Assert — file now contains the getApiList method
        $updated = file_get_contents($filename);
        $this->assertStringContainsString('public function getApiList(', $updated,
            'getApiList() method must be present after injection');

        // Assert — parent::_getApiList() is called inside the injected method
        $this->assertStringContainsString('parent::_getApiList(', $updated);

        // Assert — the class still has its closing brace (file not truncated)
        $this->assertStringEndsWith("}\n", rtrim($updated) . "\n",
            'File must still end with a closing brace after injection');
    }

    /**
     * addGetApiListMethod() returns false when the file does not contain a
     * closing brace (i.e., strrpos returns false).
     *
     * This prevents silent data corruption when the file is empty or truncated.
     */
    public function testAddGetApiListMethodReturnsFalseForFileWithoutClosingBrace(): void
    {
        // Arrange — file with no closing brace
        $filename = $this->tmpDir . '/NoBrace.php';
        file_put_contents($filename, '<?php // no class here at all');

        // Act
        $result = $this->command->callAddGetApiListMethod($filename);

        // Assert — no brace → returns false (no insertion possible)
        $this->assertFalse($result,
            'addGetApiListMethod() must return false when there is no closing brace');
    }

    // =========================================================================
    // 5. detectUiSetup()
    // =========================================================================

    /**
     * detectUiSetup() reads the scaffold_theme from applicationInfo and checks
     * for vendor library directories under www/assets/vendor/.
     *
     * When none of the vendor directories exist, datatables/select2 should be
     * false and bootstrap should depend on the theme name.
     */
    public function testDetectUiSetupReturnsThemeAndLibraryFlags(): void
    {
        // Arrange — application configured with 'plain-css' theme (set in setUp)

        // Act
        $ui = $this->command->callDetectUiSetup();

        // Assert — returns expected keys
        $this->assertArrayHasKey('theme', $ui);
        $this->assertArrayHasKey('datatables', $ui);
        $this->assertArrayHasKey('select2', $ui);
        $this->assertArrayHasKey('bootstrap', $ui);

        // Assert — theme matches applicationInfo['scaffold_theme']
        $this->assertSame('plain-css', $ui['theme'],
            'theme must come from applicationInfo[scaffold_theme]');

        // Assert — libraries are bool
        $this->assertIsBool($ui['datatables']);
        $this->assertIsBool($ui['select2']);
        $this->assertIsBool($ui['bootstrap']);
    }

    /**
     * detectUiSetup() returns bootstrap=true when the application scaffold_theme
     * is 'bootstrap', even without the vendor/bootstrap directory present.
     *
     * This ensures the generated views use Bootstrap classes for projects that
     * ship Bootstrap as part of their own assets rather than via the vendor dir.
     */
    public function testDetectUiSetupBootstrapThemeForcesTrueBootstrap(): void
    {
        // Arrange — override applicationInfo with bootstrap theme
        $this->command->getApplication()->internalApplication->applicationInfo
            = ['namespace' => 'App', 'scaffold_theme' => 'bootstrap'];

        // Act
        $ui = $this->command->callDetectUiSetup();

        // Assert — bootstrap is true because theme === 'bootstrap'
        $this->assertTrue($ui['bootstrap'],
            "detectUiSetup() must return bootstrap=true when scaffold_theme is 'bootstrap'");
    }

    // =========================================================================
    // 6. createViewsFromWizard() — plain-CSS branch
    // =========================================================================

    /**
     * createViewsFromWizard() generates three view files (list, edit, show) in
     * the plain-CSS mode (no Bootstrap, no DataTables).
     *
     * This is the simplest rendering path. Verifies that basic HTML structure
     * for all three views is produced and that column names appear in forms.
     *
     * createViewsFromWizard() uses ROOT and INCLUDES constants to resolve the
     * view directory path. Since ROOT is fixed to the framework root in the test
     * bootstrap, we write to ROOT/src/Views/<name>/ and clean up in tearDown().
     */
    public function testCreateViewsFromWizardPlainCssGeneratesThreeFiles(): void
    {
        // Arrange — minimal columns, no FKs, plain-CSS UI
        // Ensure the Views base dir exists under ROOT/src/
        $viewsBase = ROOT . DS . INCLUDES . DS . 'Views';
        if (!is_dir($viewsBase)) {
            mkdir($viewsBase, 0777, true);
        }

        $columns = [
            ['name' => 'title',      'type' => 'string',   'options' => [], 'nullable' => false, 'default' => '', 'comment' => 'Post title', 'unique' => false, 'unsigned' => false],
            ['name' => 'body',       'type' => 'text',     'options' => [], 'nullable' => true,  'default' => null, 'comment' => '', 'unique' => false, 'unsigned' => false],
            ['name' => 'published',  'type' => 'boolean',  'options' => [], 'nullable' => false, 'default' => '0', 'comment' => '', 'unique' => false, 'unsigned' => false],
            ['name' => 'created_on', 'type' => 'datetime', 'options' => [], 'nullable' => true,  'default' => null, 'comment' => '', 'unique' => false, 'unsigned' => false],
        ];
        $ui = ['bootstrap' => false, 'datatables' => false, 'select2' => false, 'theme' => 'plain-css'];

        // Act
        $summary = $this->command->callCreateViewsFromWizard('post', $columns, [], 'postid', $ui);

        // Assert — summary references all three view files
        $viewDir = ROOT . DS . INCLUDES . DS . 'Views' . DS . 'post';
        $this->assertDirectoryExists($viewDir, 'View directory must be created');

        $listFile = $viewDir . '/post.html.php';
        $editFile = $viewDir . '/edit.html.php';
        $showFile = $viewDir . '/show.html.php';
        $this->assertFileExists($listFile,  'List view file must be created');
        $this->assertFileExists($editFile,  'Edit/create view file must be created');
        $this->assertFileExists($showFile,  'Show/detail view file must be created');

        // Assert — summary lists the generated files
        $this->assertStringContainsString('post.html.php', $summary);
        $this->assertStringContainsString('edit.html.php',  $summary);
        $this->assertStringContainsString('show.html.php',  $summary);

        // Assert — edit view contains the column names
        $editContent = file_get_contents($editFile);
        $this->assertStringContainsString('title',    $editContent, 'title field must appear in edit view');
        $this->assertStringContainsString('body',     $editContent, 'body (textarea) field must appear in edit view');
        $this->assertStringContainsString('published',$editContent, 'boolean field must appear in edit view');
        $this->assertStringContainsString('created_on',$editContent,'datetime field must appear in edit view');

        // Assert — boolean generates a checkbox input
        $this->assertStringContainsString('type="checkbox"', $editContent,
            'boolean column must produce a checkbox input');

        // Assert — text column generates a textarea
        $this->assertStringContainsString('<textarea', $editContent,
            'text column must produce a textarea element');

        // Assert — datetime column generates a datetime-local input
        $this->assertStringContainsString('datetime-local', $editContent,
            'datetime column must produce a datetime-local input');

        // Assert — list view contains a table structure
        $listContent = file_get_contents($listFile);
        $this->assertStringContainsString('<table', $listContent,
            'List view must contain an HTML table');

        // Assert — show view iterates over model data
        $showContent = file_get_contents($showFile);
        $this->assertStringContainsString('getData()', $showContent,
            'Show view must iterate over getData() output');
    }

    /**
     * createViewsFromWizard() with bootstrap=true generates Bootstrap-specific
     * CSS classes in the edit and list views.
     *
     * Verifies the $useBootstrap branch: card wrapper, form-control inputs,
     * btn-primary buttons — none of which appear in the plain-CSS branch.
     */
    public function testCreateViewsFromWizardBootstrapAddsBootstrapClasses(): void
    {
        // Arrange — views base dir under ROOT/src/
        $viewsBase = ROOT . DS . INCLUDES . DS . 'Views';
        if (!is_dir($viewsBase)) {
            mkdir($viewsBase, 0777, true);
        }

        $columns = [
            ['name' => 'name', 'type' => 'string', 'options' => ['length' => 100], 'nullable' => false, 'default' => '', 'comment' => '', 'unique' => false, 'unsigned' => false],
            ['name' => 'age',  'type' => 'integer', 'options' => [], 'nullable' => false, 'default' => null, 'comment' => '', 'unique' => false, 'unsigned' => false],
        ];
        $ui = ['bootstrap' => true, 'datatables' => false, 'select2' => false, 'theme' => 'bootstrap'];

        // Act
        $this->command->callCreateViewsFromWizard('customer', $columns, [], 'customerid', $ui);

        $customerDir = $viewsBase . DS . 'customer';
        // Assert — Bootstrap card classes appear in list view
        $listContent = file_get_contents($customerDir . DS . 'customer.html.php');
        $this->assertStringContainsString('card', $listContent,
            'Bootstrap mode must produce card wrapper in list view');
        $this->assertStringContainsString('btn-primary', $listContent,
            'Bootstrap mode must produce btn-primary in list view');

        // Assert — form-control class appears in edit view
        $editContent = file_get_contents($customerDir . DS . 'edit.html.php');
        $this->assertStringContainsString('form-control', $editContent,
            'Bootstrap mode must apply form-control class to inputs');
    }

    /**
     * createViewsFromWizard() with datatables=true generates a DataTables
     * server-side table in the list view instead of the plain HTML table.
     *
     * Verifies the $useDatatables branch: data-dt-api attribute and
     * PramnosDataTable.init() JS call.
     */
    public function testCreateViewsFromWizardDatatablesGeneratesDtTable(): void
    {
        // Arrange — views base dir
        $viewsBase = ROOT . DS . INCLUDES . DS . 'Views';
        if (!is_dir($viewsBase)) {
            mkdir($viewsBase, 0777, true);
        }

        $columns = [
            ['name' => 'title', 'type' => 'string', 'options' => [], 'nullable' => false, 'default' => '', 'comment' => 'Title', 'unique' => false, 'unsigned' => false],
        ];
        $ui = ['bootstrap' => true, 'datatables' => true, 'select2' => false, 'theme' => 'bootstrap'];

        // Act
        $this->command->callCreateViewsFromWizard('article', $columns, [], 'articleid', $ui);

        // Assert — DataTables JS is present in list view
        $articleDir  = $viewsBase . DS . 'article';
        $listContent = file_get_contents($articleDir . DS . 'article.html.php');
        $this->assertStringContainsString('PramnosDataTable', $listContent,
            'DataTables mode must include PramnosDataTable.init() in list view');
        $this->assertStringContainsString('data-dt-api', $listContent,
            'DataTables mode must include the data-dt-api attribute on the table element');
    }

    /**
     * createViewsFromWizard() emits a <select> dropdown for FK columns, and
     * when select2=true appends a Select2 initialisation script.
     *
     * FK fields require a <select> element so the edit form can display related
     * entity names rather than raw integer IDs.
     */
    public function testCreateViewsFromWizardFkColumnGeneratesSelectWithSelect2(): void
    {
        // Arrange — one FK column
        $viewsBase = ROOT . DS . INCLUDES . DS . 'Views';
        if (!is_dir($viewsBase)) {
            mkdir($viewsBase, 0777, true);
        }

        $columns = [
            ['name' => 'category_id', 'type' => 'biginteger', 'options' => [], 'nullable' => false, 'default' => null, 'comment' => 'Category', 'unique' => false, 'unsigned' => true],
        ];
        $foreignKeys = [
            ['column' => 'category_id', 'references' => 'categoryid', 'on' => '#PREFIX#categories', 'onDelete' => 'RESTRICT', 'onUpdate' => 'RESTRICT'],
        ];
        $ui = ['bootstrap' => true, 'datatables' => false, 'select2' => true, 'theme' => 'bootstrap'];

        // Act
        $this->command->callCreateViewsFromWizard('item', $columns, $foreignKeys, 'itemid', $ui);

        // Assert — select element present in edit view
        $editContent = file_get_contents($viewsBase . DS . 'item' . DS . 'edit.html.php');
        $this->assertStringContainsString('<select', $editContent,
            'FK column must generate a <select> dropdown');

        // Assert — Select2 initialisation script present
        $this->assertStringContainsString('select2()', $editContent,
            'select2=true must generate a $(...).select2() call for FK fields');
    }

    /**
     * createViewsFromWizard() treats a FK referencing 'users' or '#PREFIX#users'
     * as a special "user FK" that binds to the $this->userList variable.
     *
     * This avoids the model-lookup machinery for the ubiquitous "created_by user"
     * foreign key pattern.
     */
    public function testCreateViewsFromWizardUserFkBindsToUserListVariable(): void
    {
        // Arrange
        $viewsBase = ROOT . DS . INCLUDES . DS . 'Views';
        if (!is_dir($viewsBase)) {
            mkdir($viewsBase, 0777, true);
        }

        $columns = [
            ['name' => 'user_id', 'type' => 'biginteger', 'options' => [], 'nullable' => false, 'default' => null, 'comment' => 'Owner', 'unique' => false, 'unsigned' => true],
        ];
        $foreignKeys = [
            ['column' => 'user_id', 'references' => 'userid', 'on' => '#PREFIX#users', 'onDelete' => 'CASCADE', 'onUpdate' => 'CASCADE'],
        ];
        $ui = ['bootstrap' => false, 'datatables' => false, 'select2' => false, 'theme' => 'plain-css'];

        // Act
        $this->command->callCreateViewsFromWizard('document', $columns, $foreignKeys, 'documentid', $ui);

        // Assert — edit view references $this->userList (not a model-specific list variable)
        $editContent = file_get_contents($viewsBase . DS . 'document' . DS . 'edit.html.php');
        $this->assertStringContainsString('userList', $editContent,
            'User FK must bind to the $this->userList variable');
    }

    /**
     * createViewsFromWizard() maps the 'date' column type to a date-type
     * HTML input, and 'timestamp' to a datetime-local input.
     *
     * The type mapping must be consistent so that generated forms collect the
     * right data format.
     */
    public function testCreateViewsFromWizardDateAndTimestampInputTypes(): void
    {
        // Arrange
        $viewsBase = ROOT . DS . INCLUDES . DS . 'Views';
        if (!is_dir($viewsBase)) {
            mkdir($viewsBase, 0777, true);
        }

        $columns = [
            ['name' => 'birth_date',  'type' => 'date',      'options' => [], 'nullable' => true, 'default' => null, 'comment' => '', 'unique' => false, 'unsigned' => false],
            ['name' => 'created_at',  'type' => 'timestamp',  'options' => [], 'nullable' => true, 'default' => null, 'comment' => '', 'unique' => false, 'unsigned' => false],
        ];
        $ui = ['bootstrap' => false, 'datatables' => false, 'select2' => false, 'theme' => 'plain-css'];

        // Act
        $this->command->callCreateViewsFromWizard('person', $columns, [], 'personid', $ui);

        // Assert — date column generates type="date" input
        $editContent = file_get_contents($viewsBase . DS . 'person' . DS . 'edit.html.php');
        $this->assertStringContainsString('type="date"', $editContent,
            'date column type must generate type="date" HTML input');

        // Assert — timestamp column generates type="datetime-local" input
        $this->assertStringContainsString('datetime-local', $editContent,
            'timestamp column type must generate type="datetime-local" HTML input');
    }

    /**
     * createViewsFromWizard() maps numeric column types (integer, biginteger,
     * decimal, float, double) to type="number" inputs.
     *
     * This prevents invalid data from being submitted for numeric fields.
     */
    public function testCreateViewsFromWizardNumericColumnsGenerateNumberInputs(): void
    {
        // Arrange
        $viewsBase = ROOT . DS . INCLUDES . DS . 'Views';
        if (!is_dir($viewsBase)) {
            mkdir($viewsBase, 0777, true);
        }

        $columns = [
            ['name' => 'quantity',  'type' => 'integer',    'options' => [], 'nullable' => false, 'default' => null, 'comment' => '', 'unique' => false, 'unsigned' => false],
            ['name' => 'price',     'type' => 'decimal',    'options' => [], 'nullable' => false, 'default' => null, 'comment' => '', 'unique' => false, 'unsigned' => false],
            ['name' => 'score',     'type' => 'float',      'options' => [], 'nullable' => false, 'default' => null, 'comment' => '', 'unique' => false, 'unsigned' => false],
        ];
        $ui = ['bootstrap' => false, 'datatables' => false, 'select2' => false, 'theme' => 'plain-css'];

        // Act
        $this->command->callCreateViewsFromWizard('product', $columns, [], 'productid', $ui);

        // Assert — all numeric types produce type="number" inputs
        $editContent = file_get_contents($viewsBase . DS . 'product' . DS . 'edit.html.php');
        $this->assertStringContainsString('type="number"', $editContent,
            'numeric column types (integer, decimal, float) must produce type="number" inputs');

        // Assert — each column name appears as an input field
        $this->assertStringContainsString('name="quantity"', $editContent);
        $this->assertStringContainsString('name="price"',    $editContent);
        $this->assertStringContainsString('name="score"',    $editContent);
    }

    /**
     * createViewsFromWizard() uses the column comment as the field label when
     * a non-empty comment is present, falling back to ucfirst(str_replace) of
     * the column name otherwise.
     *
     * Label accuracy is important for usability of generated admin forms.
     */
    public function testCreateViewsFromWizardColumnCommentUsedAsLabel(): void
    {
        // Arrange
        $viewsBase = ROOT . DS . INCLUDES . DS . 'Views';
        if (!is_dir($viewsBase)) {
            mkdir($viewsBase, 0777, true);
        }

        $columns = [
            ['name' => 'desc_text', 'type' => 'string', 'options' => [], 'nullable' => false, 'default' => '', 'comment' => 'Human Friendly Label', 'unique' => false, 'unsigned' => false],
            ['name' => 'no_comment', 'type' => 'string', 'options' => [], 'nullable' => false, 'default' => '', 'comment' => '', 'unique' => false, 'unsigned' => false],
        ];
        $ui = ['bootstrap' => false, 'datatables' => false, 'select2' => false, 'theme' => 'plain-css'];

        // Act
        $this->command->callCreateViewsFromWizard('thing', $columns, [], 'thingid', $ui);

        // Assert — comment used as label
        $editContent = file_get_contents($viewsBase . DS . 'thing' . DS . 'edit.html.php');
        $this->assertStringContainsString('Human Friendly Label', $editContent,
            'Non-empty comment must be used as the field label');

        // Assert — fallback label for column without comment uses ucfirst + spaces
        $this->assertStringContainsString('No comment', $editContent,
            'Empty comment must fall back to ucfirst(str_replace("_", " ")) of column name');
    }
}
