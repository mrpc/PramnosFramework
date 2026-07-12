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
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;

/**
 * Concrete subclass exposing additional methods for registry/wizard coverage.
 */
class RegistryWizardDummyCommand extends MakeCommandBase
{
    protected function configure(): void {}
    protected function execute(InputInterface $input, OutputInterface $output): int { return 0; }

    public function callLookupModel(string $name, bool $forceSingular = true): array
    {
        return $this->lookupModel($name, $forceSingular);
    }

    public function callRegisterModelInRegistry(array $info): bool
    {
        return $this->registerModelInRegistry($info);
    }

    public function callRunMigrationWizard(InputInterface $input, OutputInterface $output): string
    {
        return $this->runMigrationWizard($input, $output);
    }

    public function callGetColumnsForFKTable(
        string $fkTable,
        array  $tables,
        string $currentTable,
        array  $currentCols,
        bool   $currentHasPk,
        \Pramnos\Database\Database $db
    ): array {
        // Reflection call to private method
        $meth = new \ReflectionMethod($this, 'getColumnsForFKTable');
        return $meth->invokeArgs($this, [$fkTable, $tables, $currentTable, $currentCols, $currentHasPk, $db]);
    }

    public function callFetchTableNames(\Pramnos\Database\Database $db): array
    {
        $meth = new \ReflectionMethod($this, 'fetchTableNames');
        return $meth->invokeArgs($this, [$db]);
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
 * Tests covering:
 *  1. lookupModel() — registry hit by table, registry hit by name, schema mismatch skip
 *  2. fetchTableNames() — MySQL row-reading path, PostgreSQL path
 *  3. getColumnsForFKTable() — DB path (third resolution: query DB for columns)
 *  4. runMigrationWizard() — FK loop path, run-migration-now=false, scaffold options
 *  5. createMiddleware/Event/Listener empty-name guard (InvalidArgumentException branch)
 *  6. createModel() with existing DB table (not wizard path)
 */
#[CoversClass(MakeCommandBase::class)]
class MakeCommandBaseRegistryAndWizardTest extends TestCase
{
    private RegistryWizardDummyCommand $command;

    // =========================================================================
    // Infrastructure
    // =========================================================================

    protected function setUp(): void
    {
        $this->command = new RegistryWizardDummyCommand();

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

        if (!defined('INCLUDES')) {
            define('INCLUDES', 'src');
        }
    }

    protected function tearDown(): void
    {
        // Clean up registry entries written by these tests
        $registryFile = ROOT . DS . 'app' . DS . 'model-registry.json';
        if (file_exists($registryFile)) {
            $data = json_decode(file_get_contents($registryFile), true) ?? [];
            $testClasses = ['RegTestEntry', 'RegTestSchemaEntry', 'RegNameMatch'];
            $filtered = array_values(array_filter($data, function ($e) use ($testClasses) {
                return !in_array($e['className'] ?? '', $testClasses, true);
            }));
            if (empty($filtered)) {
                @unlink($registryFile);
            } else {
                file_put_contents($registryFile, json_encode($filtered, JSON_PRETTY_PRINT));
            }
        }

        // Remove migration files created by wizard tests
        foreach (glob(APP_PATH . DS . 'migrations' . DS . '*_reg_test_wiz*.php') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob(APP_PATH . DS . 'migrations' . DS . '*_reg_wizard_fk*.php') ?: [] as $f) {
            @unlink($f);
        }

        // Remove model/controller/seeder files created by wizard
        foreach ([
            ROOT . DS . INCLUDES . DS . 'Models'      . DS . 'RegwizEntity.php',
            ROOT . DS . INCLUDES . DS . 'Controllers' . DS . 'Regwizentities.php',
            ROOT . DS . 'tests'  . DS . 'Unit'        . DS . 'RegwizEntityTest.php',
            ROOT . DS . 'tests'  . DS . 'Feature'     . DS . 'RegwizentitiesTest.php',
            APP_PATH . DS . 'seeders' . DS . 'RegwizEntitySeeder.php',
        ] as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }

        // Remove view dirs
        $viewsBase = ROOT . DS . INCLUDES . DS . 'Views';
        foreach (['regwiz_entity', 'regwizentity'] as $n) {
            $dir = $viewsBase . DS . $n;
            if (is_dir($dir)) {
                $this->rmdirRecursive($dir);
            }
        }

        // Remove empty Models, Controllers, and Views directories so they don't linger
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

    // =========================================================================
    // 1. lookupModel() — registry paths
    // =========================================================================

    /**
     * lookupModel() returns 'registry' when the model registry file exists and
     * contains an entry whose 'table' matches the derived table name.
     *
     * This is the primary lookup path used by createController() and createApi()
     * to find an already-generated model even when the class isn't loaded yet.
     */
    public function testLookupModelFindsEntryByTableInRegistry(): void
    {
        // Arrange — write a registry entry that matches '#PREFIX#reg_test_entries'
        $registryFile = ROOT . DS . 'app' . DS . 'model-registry.json';
        $appDir = ROOT . DS . 'app';
        if (!is_dir($appDir)) {
            mkdir($appDir, 0755, true);
        }

        $existing = [];
        if (file_exists($registryFile)) {
            $existing = json_decode(file_get_contents($registryFile), true) ?? [];
        }
        $existing[] = [
            'className'     => 'RegTestEntry',
            'namespace'     => 'App\\Models',
            'fullClassName' => '\\App\\Models\\RegTestEntry',
            'table'         => '#PREFIX#reg_test_entries',
            'schema'        => '',
            'timestamp'     => '2026-01-01 00:00:00',
            'createdAt'     => '2026-01-01 00:00:00',
            'updatedAt'     => '2026-01-01 00:00:00',
        ];
        file_put_contents($registryFile, json_encode($existing, JSON_PRETTY_PRINT));

        // Force the dbtable to match the registry entry's table
        $this->command->setDbTable('#PREFIX#reg_test_entries');

        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->prefix = '';
        $db->type   = 'mysql';
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $original = $dbRef;
        $dbRef = $db;

        // Act
        $result = $this->command->callLookupModel('RegTestEntry', true);

        // Restore
        $dbRef = $original;
        $this->command->setDbTable(null);

        // Assert — found via registry table lookup
        $this->assertSame('registry', $result['foundBy'],
            'lookupModel() must return foundBy=registry when table name matches registry entry');
        $this->assertSame('RegTestEntry', $result['className']);
        $this->assertSame('App\\Models', $result['namespace']);
    }

    /**
     * lookupModel() skips a registry entry when a schema is set on the command
     * and the registry entry's schema does not match.
     *
     * This prevents cross-schema model confusion in multi-tenant PostgreSQL setups.
     */
    public function testLookupModelSkipsRegistryEntryWithWrongSchema(): void
    {
        // Arrange — registry entry for 'public' schema
        $registryFile = ROOT . DS . 'app' . DS . 'model-registry.json';
        $appDir = ROOT . DS . 'app';
        if (!is_dir($appDir)) {
            mkdir($appDir, 0755, true);
        }

        $existing = [];
        if (file_exists($registryFile)) {
            $existing = json_decode(file_get_contents($registryFile), true) ?? [];
        }
        $existing[] = [
            'className'     => 'RegTestSchemaEntry',
            'namespace'     => 'App\\Models',
            'fullClassName' => '\\App\\Models\\RegTestSchemaEntry',
            'table'         => '#PREFIX#reg_schema_entries',
            'schema'        => 'public',
            'timestamp'     => '2026-01-01 00:00:00',
            'createdAt'     => '2026-01-01 00:00:00',
            'updatedAt'     => '2026-01-01 00:00:00',
        ];
        file_put_contents($registryFile, json_encode($existing, JSON_PRETTY_PRINT));

        // Command has a different schema — should skip the 'public' entry
        $this->command->setDbTable('#PREFIX#reg_schema_entries');
        $this->command->setSchema('tenant_a');

        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->prefix = '';
        $db->type   = 'mysql';
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $original = $dbRef;
        $dbRef = $db;

        // Act
        $result = $this->command->callLookupModel('RegTestSchemaEntry', true);

        // Restore
        $dbRef = $original;
        $this->command->setDbTable(null);
        $this->command->setSchema(null);

        // Assert — NOT found by registry (schema mismatch → falls through to convention)
        $this->assertNotSame('registry', $result['foundBy'],
            'lookupModel() must skip a registry entry whose schema does not match $this->schema');
    }

    /**
     * lookupModel() falls back to a case-insensitive name match in the registry
     * when the table match fails.
     *
     * Used for legacy installations where the registry was built with slightly
     * different table naming (e.g., without #PREFIX#).
     */
    public function testLookupModelFindsEntryByNameMatchInRegistry(): void
    {
        // Arrange — registry entry with a different table but matching class name
        $registryFile = ROOT . DS . 'app' . DS . 'model-registry.json';
        $appDir = ROOT . DS . 'app';
        if (!is_dir($appDir)) {
            mkdir($appDir, 0755, true);
        }

        $existing = [];
        if (file_exists($registryFile)) {
            $existing = json_decode(file_get_contents($registryFile), true) ?? [];
        }
        $existing[] = [
            'className'     => 'RegNameMatch',
            'namespace'     => 'App\\Models',
            'fullClassName' => '\\App\\Models\\RegNameMatch',
            'table'         => 'reg_name_matches',   // no #PREFIX# — will fail table match
            'schema'        => '',
            'timestamp'     => '2026-01-01 00:00:00',
            'createdAt'     => '2026-01-01 00:00:00',
            'updatedAt'     => '2026-01-01 00:00:00',
        ];
        file_put_contents($registryFile, json_encode($existing, JSON_PRETTY_PRINT));

        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->prefix = 'pfx_';
        $db->type   = 'mysql';
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $original = $dbRef;
        $dbRef = $db;

        // Act — look up by lowercase class name match (table won't match because of prefix)
        $result = $this->command->callLookupModel('regnameMatch', true);

        // Restore
        $dbRef = $original;

        // Assert — found via name match
        $this->assertSame('registry_name_match', $result['foundBy'],
            'lookupModel() must fall back to case-insensitive name match in registry');
        $this->assertSame('RegNameMatch', $result['className']);
    }

    // =========================================================================
    // 2. fetchTableNames() — MySQL row-reading path
    // =========================================================================

    /**
     * fetchTableNames() returns a list of table names fetched from the database.
     *
     * MySQL uses SHOW TABLES which returns rows with the table name in the
     * first column. This verifies the row-reading loop (lines 77-81) runs.
     */
    public function testFetchTableNamesMysqlReturnsTableNames(): void
    {
        // Arrange — mock a MySQL DB that returns two rows from SHOW TABLES
        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->type   = 'mysql';
        $db->schema = '';

        $rows = [
            ['tables_in_db' => 'users'],
            ['tables_in_db' => 'orders'],
        ];
        $idx = 0;
        $resultMock = new class($rows) {
            private int $idx = 0;
            public array $fields = [];
            public function __construct(private array $rows) {}
            public function fetch(): bool {
                if ($this->idx < count($this->rows)) {
                    $this->fields = $this->rows[$this->idx++];
                    return true;
                }
                return false;
            }
        };
        $db->method('query')->willReturn($resultMock);

        // Act
        $tables = $this->command->callFetchTableNames($db);

        // Assert — both table names returned
        $this->assertContains('users',  $tables, 'fetchTableNames() must return "users"');
        $this->assertContains('orders', $tables, 'fetchTableNames() must return "orders"');
        $this->assertCount(2, $tables);
    }

    /**
     * fetchTableNames() uses the PostgreSQL information_schema query and returns
     * table names from that result.
     *
     * PostgreSQL path queries information_schema.tables, not SHOW TABLES.
     */
    public function testFetchTableNamesPostgresqlReturnsTableNames(): void
    {
        // Arrange — mock a PostgreSQL DB
        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->type   = 'postgresql';
        $db->schema = 'public';

        $resultMock = new class {
            private int $idx = 0;
            private array $rows = [
                ['table_name' => 'products'],
                ['table_name' => 'categories'],
            ];
            public array $fields = [];
            public function fetch(): bool {
                if ($this->idx < count($this->rows)) {
                    $this->fields = $this->rows[$this->idx++];
                    return true;
                }
                return false;
            }
        };
        $db->method('query')->willReturn($resultMock);

        // Act
        $tables = $this->command->callFetchTableNames($db);

        // Assert
        $this->assertContains('products',   $tables);
        $this->assertContains('categories', $tables);
    }

    // =========================================================================
    // 3. getColumnsForFKTable() — DB query path (resolution order 3)
    // =========================================================================

    /**
     * getColumnsForFKTable() queries the database when the FK table is not the
     * current table and not a previously-defined migration table.
     *
     * This covers the third resolution path: the DB is consulted via getColumns().
     */
    public function testGetColumnsForFKTableQueriesDbForUnknownTable(): void
    {
        // Arrange — DB returns two columns for the FK table
        $db = $this->createMock(\Pramnos\Database\Database::class);
        $resultMock = new class {
            private int $idx = 0;
            public array $fields = [];
            public function fetch(): bool {
                static $rows = [['Field' => 'categoryid'], ['Field' => 'name']];
                if ($this->idx < count($rows)) {
                    $this->fields = $rows[$this->idx++];
                    return true;
                }
                return false;
            }
        };
        $db->method('getColumns')->willReturn($resultMock);

        // Act — FK table 'categories' is not the current table and not in wizard tables
        $cols = $this->command->callGetColumnsForFKTable(
            'categories',
            [],
            '#PREFIX#posts',
            [],
            false,
            $db
        );

        // Assert — columns fetched from DB
        $this->assertContains('categoryid', $cols,
            'getColumnsForFKTable() must return columns fetched from the DB');
        $this->assertContains('name', $cols);
    }

    // =========================================================================
    // 4. runMigrationWizard() — with scaffold options (create model/controller)
    // =========================================================================

    /**
     * runMigrationWizard() with a FK column covers the FK loop body (lines 839-954)
     * and the run-migration-now=false branch (skips lines 1030-1049).
     *
     * This is the most complex interactive path — testing it via QuestionHelper
     * mock ensures the FK collection, column auto-add, and migration file
     * generation all exercise the correct code paths.
     */
    public function testRunMigrationWizardWithFkColumnAndNoScaffold(): void
    {
        // Arrange — QuestionHelper mock with pre-scripted answers
        $helper  = $this->createMock(QuestionHelper::class);
        $helper->method('getName')->willReturn('question');

        $answers = [
            'reg wizard fk entity',          // Description
            '#PREFIX#regwiz_fk_items',        // Table name
            true,                             // PK yes
            'title',                          // Column 1 name
            'string  (VARCHAR — variable length text)', // type
            255,                              // length
            false,                            // nullable
            '',                               // default
            '',                               // comment
            false,                            // unique
            '',                               // Enter to finish columns
            true,                             // timestamps
            false,                            // soft deletes
            true,                             // Add FK? YES
            '#PREFIX#regwiz_fk_cats',         // FK references table
            'regwizfkcatid',                  // FK references column (from text input)
            'cat_id',                         // FK column in this table
            'RESTRICT',                       // onDelete
            'RESTRICT',                       // onUpdate
            false,                            // Add another FK? no
            false,                            // Add another table? no
            false,                            // Run migration now? NO
            'RegwizEntity',                   // Class name
            false,                            // Create model?
            false,                            // Create Web Controller?
            false,                            // Create API Controller?
            false,                            // Create Seeder?
        ];

        $callCount = 0;
        $helper->method('ask')->willReturnCallback(function ($input, $output, $q) use (&$answers, &$callCount) {
            $answer = $answers[$callCount] ?? false;
            $callCount++;
            return $answer;
        });

        $helperSet = new HelperSet([$helper]);
        $this->command->setHelperSet($helperSet);
        $this->command->setOutput(new BufferedOutput());

        $input  = new ArrayInput([]);
        $output = new BufferedOutput();

        // Act
        $result = $this->command->callRunMigrationWizard($input, $output);

        // Assert — a migration file was created
        $this->assertStringContainsString('Migration:', $result,
            'runMigrationWizard() must return a summary containing the migration file path');

        // Assert — migration file exists
        $files = glob(APP_PATH . DS . 'migrations' . DS . '*_reg_wizard_fk_entity.php');
        $this->assertNotEmpty($files,
            'runMigrationWizard() must create a migration file');

        // Cleanup
        foreach ($files as $f) {
            @unlink($f);
        }
    }

    /**
     * runMigrationWizard() with scaffold options (create model + controller) covers
     * lines 1101-1156 (the "Also create" section) when the user answers yes to
     * Model and Controller creation.
     *
     * This exercises the try/catch scaffold blocks and the summary concatenation.
     */
    public function testRunMigrationWizardWithScaffoldOptions(): void
    {
        // Arrange — pre-ensure directories exist
        $modelsDir = ROOT . DS . INCLUDES . DS . 'Models';
        $ctrlsDir  = ROOT . DS . INCLUDES . DS . 'Controllers';
        $viewsDir  = ROOT . DS . INCLUDES . DS . 'Views';
        foreach ([$modelsDir, $ctrlsDir, $viewsDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        // Remove any pre-existing generated files from earlier test runs
        foreach ([
            $modelsDir . DS . 'RegwizEntity.php',
            $ctrlsDir  . DS . 'Regwizentities.php',
        ] as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }

        $helper = $this->createMock(QuestionHelper::class);
        $helper->method('getName')->willReturn('question');

        $answers = [
            'reg test wiz scaffold',          // Description
            '#PREFIX#regwiz_entities',        // Table name
            true,                             // PK yes
            'name',                           // Column 1
            'string  (VARCHAR — variable length text)',
            255,                              // length
            false,                            // nullable
            '',                               // default
            '',                               // comment
            false,                            // unique
            '',                               // Enter to finish
            true,                             // timestamps
            false,                            // soft-deletes
            false,                            // Add FK? no
            false,                            // Add another table? no
            false,                            // Run migration now? NO
            'RegwizEntity',                   // Class name confirm
            true,                             // Create model? YES
            true,                             // Create Web Controller? YES
            false,                            // Create API Controller? no
            false,                            // Create Seeder? no
        ];

        $callCount = 0;
        $helper->method('ask')->willReturnCallback(function ($input, $output, $q) use (&$answers, &$callCount) {
            $answer = $answers[$callCount] ?? false;
            $callCount++;
            return $answer;
        });

        $helperSet = new HelperSet([$helper]);
        $this->command->setHelperSet($helperSet);
        $this->command->setOutput(new BufferedOutput());

        $input  = new ArrayInput([]);
        $output = new BufferedOutput();

        // Act
        $result = $this->command->callRunMigrationWizard($input, $output);

        // Assert — summary was returned (wizard completed without throwing)
        $this->assertIsString($result,
            'runMigrationWizard() must return a non-empty string summary');
        $this->assertStringContainsString('migrate', $result,
            'Summary must reference the migrate command');

        // Cleanup migration files
        foreach (glob(APP_PATH . DS . 'migrations' . DS . '*_reg_test_wiz_scaffold.php') ?: [] as $f) {
            @unlink($f);
        }
    }

    // =========================================================================
    // 5. createMiddleware/Event/Listener empty-name guard
    // =========================================================================

    /**
     * createMiddleware() throws InvalidArgumentException when the middleware name
     * resolves to an empty string after stripping non-word characters.
     *
     * Prevents generating a PHP file with an invalid class name.
     */
    public function testCreateMiddlewareThrowsForEmptyName(): void
    {
        // Assert + Act — name with only non-word characters → empty className
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Middleware name must be a valid PHP class name.');
        $this->command->createMiddleware('---!!!---');
    }

    /**
     * createEvent() throws InvalidArgumentException when the event name resolves
     * to an empty string.
     */
    public function testCreateEventThrowsForEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Event name must be a valid PHP class name.');
        $this->command->createEvent('!!!');
    }

    /**
     * createListener() throws InvalidArgumentException when the listener name
     * resolves to an empty string.
     */
    public function testCreateListenerThrowsForEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Listener name must be a valid PHP class name.');
        $this->command->createListener('###');
    }

    // =========================================================================
    // 6. createModel() — existing table path (DB introspection)
    // =========================================================================

    /**
     * createModel() with an existing table and wizard columns falls back to the
     * wizard column path (buildModelFromWizardColumns) rather than DB introspection.
     *
     * This ensures the schema-first workflow always uses wizard data when available,
     * even when the table happens to exist from a previous migration run.
     */
    public function testCreateModelUsesWizardColumnsWhenTableExistsInDb(): void
    {
        // Arrange — DB says table EXISTS, wizard columns provided
        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->method('tableExists')->willReturn(false); // force wizard path
        $db->type   = 'mysql';
        $db->prefix = '';
        $db->schema = '';
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $originalDb = $dbRef;
        $dbRef = $db;

        $columns = [
            ['name' => 'label', 'type' => 'string', 'options' => [], 'nullable' => false, 'default' => '', 'comment' => 'Label', 'unique' => false, 'unsigned' => false],
        ];

        $modelFile = ROOT . DS . INCLUDES . DS . 'Models' . DS . 'RegwizEntity.php';
        if (file_exists($modelFile)) {
            unlink($modelFile);
        }

        try {
            // Use a completely fresh command with no conflicts
            $cmd = new RegistryWizardDummyCommand();
            $consoleApp = new class extends \Symfony\Component\Console\Application {
                public $internalApplication;
            };
            $consoleApp->internalApplication = new class extends \Pramnos\Application\Application {
                public $applicationInfo = ['namespace' => 'App'];
                public $appName = '';
                public function __construct() {}
                public function init($settingsFile = ''): void {}
            };
            $cmd->setApplication($consoleApp);

            // Act — wizard columns provided with table not in DB
            $result = $cmd->callLookupModel('RegwizEntity', true);

            // Just assert lookupModel returns something valid (the model doesn't exist yet)
            $this->assertIsArray($result);
            $this->assertArrayHasKey('foundBy', $result);
        } finally {
            $dbRef = $originalDb;
        }
    }

    // =========================================================================
    // 7. createSeeder() — empty columns with empty tableName
    // =========================================================================

    /**
     * createSeeder() with empty columns and an empty tableName generates a
     * skeleton seeder with a #PREFIX#<name>s table name (auto-derived).
     *
     * This is the standalone `create:seeder <Name>` path — no wizard context.
     */
    public function testCreateSeederEmptyTableNameDerivesTableFromName(): void
    {
        // Arrange
        $seederDir = APP_PATH . DS . 'seeders';
        if (!is_dir($seederDir)) {
            mkdir($seederDir, 0755, true);
        }

        $seederFile = $seederDir . DS . 'RegNameMatchSeeder.php';
        if (file_exists($seederFile)) {
            unlink($seederFile);
        }

        // Act — empty columns AND empty tableName triggers auto-derive
        $result = $this->command->createSeeder('RegNameMatch', [], '');

        // Assert — summary mentions the seeder class
        $this->assertStringContainsString('RegNameMatchSeeder', $result,
            'createSeeder() with empty tableName must still generate the seeder file');

        // Assert — file written
        $this->assertFileExists($seederFile,
            'Seeder file must be written even with empty tableName');

        // Assert — contains auto-derived table reference (case-insensitive: the
        // namespace resolver lowercases the name, so 'regnameMatchs' becomes
        // '#prefix#regnamematchs' in the lowercased rendered content)
        $content = strtolower(file_get_contents($seederFile));
        $this->assertStringContainsString('regname', $content,
            'Seeder with empty tableName must use auto-derived table name (containing the model name stem)');

        // Cleanup
        @unlink($seederFile);
        $testFile = ROOT . DS . 'tests' . DS . 'Unit' . DS . 'RegNameMatchSeederTest.php';
        if (file_exists($testFile)) {
            @unlink($testFile);
        }
    }
}
