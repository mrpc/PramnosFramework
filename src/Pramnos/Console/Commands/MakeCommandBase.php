<?php

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Pramnos\Console\Make\BlueprintCompiler;
use Pramnos\Console\Make\FakeDataGenerator;
use Pramnos\Console\Make\NamespaceResolver;
use Pramnos\Console\Make\StubRenderer;

/**
 * Create something related to the application
 */
abstract class MakeCommandBase extends Command
{
    /**
     * The database schema
     * @var string|null
     */
    protected $schema = null;
    /**
     * The database table
     * @var string|null
     */
    protected $dbtable = null;

    protected OutputInterface $output;

    private ?BlueprintCompiler $blueprintCompiler = null;
    private ?FakeDataGenerator $fakeDataGenerator = null;
    private ?StubRenderer      $stubRenderer      = null;

    private function getBlueprintCompiler(): BlueprintCompiler
    {
        return $this->blueprintCompiler ??= new BlueprintCompiler();
    }

    private function getFakeDataGenerator(): FakeDataGenerator
    {
        return $this->fakeDataGenerator ??= new FakeDataGenerator();
    }

    private function getStubRenderer(): StubRenderer
    {
        return $this->stubRenderer ??= new StubRenderer();
    }

    /**
     * Return all table names currently visible in the database.
     *
     * Cross-DB: MySQL uses SHOW TABLES, PostgreSQL queries information_schema.
     * Returns bare table names (no schema prefix). Silently returns [] on error
     * so FK autocomplete degrades gracefully when the DB is unreachable.
     *
     * @return string[]
     */
    private function fetchTableNames(\Pramnos\Database\Database $db): array
    {
        try {
            if ($db->type === 'postgresql') {
                $schema = $db->schema ?: 'public';
                // Single-quote the schema literal directly — Database has no escape() method
                $sql = "SELECT table_name FROM information_schema.tables "
                     . "WHERE table_schema = '" . addslashes($schema) . "'"
                     . " AND table_type = 'BASE TABLE' ORDER BY table_name";
            } else {
                $sql = "SHOW TABLES";
            }
            $result = $db->query($sql);
            $names  = [];
            while ($result->fetch()) {
                $row = array_values($result->fields);
                if (!empty($row[0])) {
                    $names[] = (string) $row[0];
                }
            }
            return $names;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Return column names for a table, consulting wizard state first, then the DB.
     *
     * Resolution order:
     *   1. The table currently being defined in the wizard ($currentTable / $currentCols)
     *   2. A previously defined table in this wizard run ($tables array)
     *   3. An existing table in the database (via getColumns())
     *
     * @param string   $fkTable       The referenced table name (may contain #PREFIX#)
     * @param array    $tables        Tables already collected in this wizard run
     * @param string   $currentTable  The table being defined right now
     * @param array    $currentCols   Columns defined so far for $currentTable
     * @param bool     $currentHasPk  Whether $currentTable has an auto-increment PK
     * @param \Pramnos\Database\Database $db
     * @return string[]  Column names, or [] if unknown
     */
    private function getColumnsForFKTable(
        string                         $fkTable,
        array                          $tables,
        string                         $currentTable,
        array                          $currentCols,
        bool                           $currentHasPk,
        \Pramnos\Database\Database     $db
    ): array {
        // Helper: build column list from wizard column definitions
        $fromWizard = function(string $tbl, array $cols, bool $hasPk): array {
            $result = [];
            if ($hasPk) {
                $result[] = $this->getBlueprintCompiler()->getSingularPrimaryKey($tbl);
            }
            foreach ($cols as $col) {
                $result[] = $col['name'];
            }
            return $result;
        };

        // 1. Current table being defined
        if ($fkTable === $currentTable) {
            return $fromWizard($currentTable, $currentCols, $currentHasPk);
        }

        // 2. Previously defined tables in this wizard run
        foreach ($tables as $tbl) {
            if ($tbl['tableName'] === $fkTable) {
                return $fromWizard($tbl['tableName'], $tbl['columns'], $tbl['hasPk']);
            }
        }

        // 3. Existing DB table — getColumns() handles #PREFIX# and schema
        try {
            $result = $db->getColumns($fkTable, null, true);
            $cols   = [];
            while ($result->fetch()) {
                if (!empty($result->fields['Field'])) {
                    $cols[] = $result->fields['Field'];
                }
            }
            return $cols;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Command configuration
     */
    /**
     * Add common command options (schema, table).
     */
    protected function addCommonOptions()
    {
        $this->addArgument(
            'name', InputArgument::OPTIONAL, 'Name of the created object'
        );
        $this->addOption(
            'schema', 's', InputArgument::OPTIONAL, 'Database schema', null
        );
        $this->addOption(
            'table', 't', InputArgument::OPTIONAL, 'Database table', null
        );
    }

    /**
     * Command execution
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    /**
     * Prepare properties from input.
     */
    protected function prepareExecution(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        // Commands that don't call addCommonOptions() (e.g. project:git-webhook)
        // don't define these options — guard to avoid InvalidArgumentException.
        $this->schema = $input->hasOption('schema') ? $input->getOption('schema') : null;
        $this->dbtable = $input->hasOption('table') ? $input->getOption('table') : null;
    }

    /**
     * Create a middleware class from the middleware.stub template.
     *
     * Writes to src/Middleware/<Name>.php and generates a matching test stub at
     * tests/Unit/<Name>MiddlewareTest.php so new middlewares are never test-less.
     *
     * @param string $middlewareName PascalCase class name (e.g. RateLimit)
     * @return string Summary of created files
     * @throws \Exception
     */
    public function createMiddleware(string $middlewareName): string
    {
        $application = $this->getApplication()->internalApplication;
        $application->init();

        $namespace = isset($application->applicationInfo['namespace'])
            ? $application->applicationInfo['namespace']
            : 'App';

        $className = ucfirst(preg_replace('/\W+/', '', $middlewareName));
        if ($className === '') {
            throw new \InvalidArgumentException('Middleware name must be a valid PHP class name.');
        }

        $dir = defined('ROOT') ? ROOT . '/src/Middleware' : getcwd() . '/src/Middleware';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $filename = $dir . '/' . $className . '.php';
        if (file_exists($filename)) {
            throw new \Exception("Middleware $className already exists at $filename.");
        }

        $stub = $this->renderStub('middleware', [
            'namespace' => $namespace . '\\Middleware',
            'class'     => $className,
        ]);

        if (!file_put_contents($filename, $stub)) {
            throw new \Exception("Cannot write middleware file: $filename");
        }

        $testOutput = $this->generateTestStub($className . 'Middleware', $namespace);

        return "Namespace: {$namespace}\\Middleware\n"
            . "Class:     {$className}\n"
            . "File:      {$filename}\n"
            . $testOutput
            . "\nMiddleware created.";
    }

    /**
     * Create a service class from the service.stub template.
     *
     * Writes to src/Services/<Name>.php. A service encapsulates a slice of
     * application logic + its data access (the services-oriented style), so the
     * stub wires an injectable Database and shows a QueryBuilder example. Generates
     * a matching test stub.
     *
     * @param string $serviceName PascalCase class name (e.g. BillingService)
     * @return string Summary of created files
     * @throws \Exception
     */
    public function createService(string $serviceName): string
    {
        $application = $this->getApplication()->internalApplication;
        $application->init();

        $namespace = isset($application->applicationInfo['namespace'])
            ? $application->applicationInfo['namespace']
            : 'App';

        $className = ucfirst(preg_replace('/\W+/', '', $serviceName));
        if ($className === '') {
            throw new \InvalidArgumentException('Service name must be a valid PHP class name.');
        }

        $dir = defined('ROOT') ? ROOT . '/src/Services' : getcwd() . '/src/Services';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $filename = $dir . '/' . $className . '.php';
        if (file_exists($filename)) {
            throw new \Exception("Service $className already exists at $filename.");
        }

        // Best-effort table guess: strip a trailing "Service" and snake_case.
        $base  = preg_replace('/Service$/', '', $className);
        $table = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $base ?: $className));

        $stub = $this->renderStub('service', [
            'namespace' => $namespace . '\\Services',
            'class'     => $className,
            'table'     => $table,
        ]);

        if (!file_put_contents($filename, $stub)) {
            throw new \Exception("Cannot write service file: $filename");
        }

        $testOutput = $this->generateTestStub($className, $namespace);

        return "Namespace: {$namespace}\\Services\n"
            . "Class:     {$className}\n"
            . "File:      {$filename}\n"
            . $testOutput
            . "\nService created.";
    }

    /**
     * Create an event class from the event.stub template.
     *
     * Writes to src/Events/<Name>.php. An event is a plain value object that
     * carries the payload for Event::fire(). Generates a matching test stub.
     *
     * @param string $eventName PascalCase class name (e.g. UserRegistered)
     * @return string Summary of created files
     * @throws \Exception
     */
    public function createEvent(string $eventName): string
    {
        $application = $this->getApplication()->internalApplication;
        $application->init();

        $namespace = isset($application->applicationInfo['namespace'])
            ? $application->applicationInfo['namespace']
            : 'App';

        $className = ucfirst(preg_replace('/\W+/', '', $eventName));
        if ($className === '') {
            throw new \InvalidArgumentException('Event name must be a valid PHP class name.');
        }

        $dir = defined('ROOT') ? ROOT . '/src/Events' : getcwd() . '/src/Events';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $filename = $dir . '/' . $className . '.php';
        if (file_exists($filename)) {
            throw new \Exception("Event $className already exists at $filename.");
        }

        $stub = $this->renderStub('event', [
            'namespace' => $namespace . '\\Events',
            'class'     => $className,
        ]);

        if (!file_put_contents($filename, $stub)) {
            throw new \Exception("Cannot write event file: $filename");
        }

        $testOutput = $this->generateTestStub($className . 'Event', $namespace . '\\Events');

        return "Namespace: {$namespace}\\Events\n"
            . "Class:     {$className}\n"
            . "File:      {$filename}\n"
            . $testOutput
            . "\nEvent created.";
    }

    /**
     * Create a listener class from the listener.stub template.
     *
     * Writes to src/Listeners/<Name>.php implementing ListenerInterface.
     * Register the listener with: Event::listen('event.name', MyListener::class)
     * Generates a matching test stub.
     *
     * @param string $listenerName PascalCase class name (e.g. SendWelcomeEmail)
     * @return string Summary of created files
     * @throws \Exception
     */
    public function createListener(string $listenerName): string
    {
        $application = $this->getApplication()->internalApplication;
        $application->init();

        $namespace = isset($application->applicationInfo['namespace'])
            ? $application->applicationInfo['namespace']
            : 'App';

        $className = ucfirst(preg_replace('/\W+/', '', $listenerName));
        if ($className === '') {
            throw new \InvalidArgumentException('Listener name must be a valid PHP class name.');
        }

        $dir = defined('ROOT') ? ROOT . '/src/Listeners' : getcwd() . '/src/Listeners';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $filename = $dir . '/' . $className . '.php';
        if (file_exists($filename)) {
            throw new \Exception("Listener $className already exists at $filename.");
        }

        $stub = $this->renderStub('listener', [
            'namespace' => $namespace . '\\Listeners',
            'class'     => $className,
        ]);

        if (!file_put_contents($filename, $stub)) {
            throw new \Exception("Cannot write listener file: $filename");
        }

        $testOutput = $this->generateTestStub($className . 'Listener', $namespace . '\\Listeners');

        return "Namespace: {$namespace}\\Listeners\n"
            . "Class:     {$className}\n"
            . "File:      {$filename}\n"
            . $testOutput
            . "\nListener created.";
    }

    /**
     * Create a database migration
     * @param string $migrationName
     * @return string
     * @throws \Exception
     */
    public function createMigration($migrationName)
    {
        // Slug: lowercase alphanum + underscores only
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower(strip_tags($migrationName ?? '')));
        $slug = trim($slug, '_');

        $application = $this->getApplication()->internalApplication;
        $application->init();

        if (isset($application->applicationInfo['namespace'])) {
            $namespace = $application->applicationInfo['namespace'];
        } else {
            $namespace = 'App';
        }
        if ($application->appName != '') {
            $namespace .= '\\' . $application->appName;
        }
        $fullNamespace = $namespace . '\\Migrations';

        // Directory: app/migrations/ (discovered automatically by MigrationLoader)
        $migrationDir = APP_PATH . DS . 'migrations';
        if (!is_dir($migrationDir) && !mkdir($migrationDir, 0755, true)) {
            throw new \Exception('Cannot create migrations directory.');
        }

        // PascalCase class name from slug (e.g. create_users_table → CreateUsersTable)
        $className = str_replace(' ', '', ucwords(str_replace('_', ' ', $slug)));

        // Timestamp-based filename keeps MigrationLoader sort order correct
        $timestamp = date('Y_m_d_His');
        $filename  = $timestamp . '_' . $slug . '.php';
        $filePath  = $migrationDir . DS . $filename;

        if (file_exists($filePath)) {
            throw new \Exception('Migration file already exists: ' . $filename);
        }

        $content = $this->renderStub('migration', [
            'namespace'   => $fullNamespace,
            'class'       => $className,
            'description' => $migrationName,
            'date'        => date('d/m/Y H:i'),
            'up_body'     => '        // TODO: implement',
            'down_body'   => '        // TODO: implement',
        ]);

        if (file_put_contents($filePath, $content) === false) {
            throw new \Exception('Cannot write migration file.');
        }

        return "Namespace: {$fullNamespace}\n"
             . "Class:     {$className}\n"
             . "File:      {$filePath}\n\n"
             . "Migration created.\n"
             . "Run with: php bin/pramnos migrate";
    }

    // ── Stub rendering ────────────────────────────────────────────────────────

    /**
     * Render a scaffolding stub template with token substitution.
     *
     * Looks for the stub in scaffolding/templates/<name>.stub inside the
     * framework package directory. Falls back to an embedded minimal skeleton
     * so the command works even when the scaffolding directory is absent.
     *
     * @param string               $stubName  Stub identifier without extension
     * @param array<string,string> $tokens    Substitution map (key → value)
     * @return string Rendered content
     */
    public function renderStub(string $stubName, array $tokens): string
    {
        return $this->getStubRenderer()->render($stubName, $tokens);
    }

    /**
     * Generate a PHPUnit test stub for a newly created class.
     *
     * Writes to <baseDir>/tests/Unit/<className>Test.php. Silently skips if the
     * file already exists or the directory cannot be created.
     *
     * @param string $baseDir  Project root. Defaults to ROOT constant or cwd.
     * @return string Human-readable summary line (empty if skipped).
     */
    public function generateTestStub(string $className, string $namespace, string $baseDir = '', string $stubName = 'test'): string
    {
        if ($baseDir === '') {
            $baseDir = defined('ROOT') ? ROOT : getcwd();
        }

        $testsDir = $baseDir . '/tests/Unit';
        if ($stubName === 'controller_test') {
            $testsDir = $baseDir . '/tests/Feature';
        }
        if (!is_dir($testsDir)) {
            @mkdir($testsDir, 0777, true);
        }

        $testFile = $testsDir . '/' . $className . 'Test.php';
        if (file_exists($testFile)) {
            return '';
        }

        $stub = $this->renderStub($stubName, [
            'class' => $className,
            'namespace' => $namespace,
            'route' => strtolower($className)
        ]);
        if (file_put_contents($testFile, $stub) !== false) {
            return "Test:      $testFile\n";
        }
        return '';
    }

    /**
     * Generate a schema-aware integration test for a generated CRUD model.
     *
     * Writes <baseDir>/tests/Unit/Models/<className>Test.php from the
     * crud-model-test.stub template. Unlike the trivial generateTestStub(), this
     * exercises the ACTUAL generated model shape: it asserts the model extends
     * \Pramnos\Application\Model, declares a public property for every column
     * (PK included), performs a save→load→getData→delete round-trip that sets a
     * typed sample value per scalar column and verifies each persisted, and
     * checks the getApiList() envelope. Foreign-key columns are excluded from the
     * round-trip so the test does not require parent rows to exist.
     *
     * The generated test extends Tests\BaseTestCase — the same base class
     * Init.php scaffolds into new projects — so it runs in the target project's
     * Unit suite against the real test database.
     *
     * @param string $className   Model class name (e.g. Product)
     * @param string $namespace   Model namespace (e.g. App\Models)
     * @param array  $columns     Wizard-shaped column definitions (no PK)
     * @param string $primaryKey  Primary key column name
     * @param string $tableName   Database table (may contain #PREFIX#)
     * @param array  $foreignKeys Wizard-shaped FK definitions (column keys)
     * @param string $baseDir     Project root; defaults to ROOT or cwd
     * @return string Human-readable summary line (empty if skipped)
     */
    public function buildModelTest(
        string $className,
        string $namespace,
        array  $columns,
        string $primaryKey,
        string $tableName,
        array  $foreignKeys = [],
        string $baseDir = ''
    ): string {
        if ($baseDir === '') {
            $baseDir = defined('ROOT') ? ROOT : getcwd();
        }

        $testsDir = $baseDir . '/tests/Unit/Models';
        if (!is_dir($testsDir)) {
            @mkdir($testsDir, 0777, true);
        }

        $testFile = $testsDir . '/' . $className . 'Test.php';
        if (file_exists($testFile)) {
            return '';
        }

        // FK columns are left unset in the round-trip so no parent row is needed.
        $fkNames = array_column($foreignKeys, 'column');

        // PK is asserted as a property and as a getData() key regardless of type.
        $propertyAssertions = "        \$this->assertTrue(property_exists(\$model, '{$primaryKey}'), 'primary key property must exist');\n";
        $assertGetData      = "            \$this->assertArrayHasKey('{$primaryKey}', \$data);\n";
        $setColumns         = '';
        $assertPersisted    = '';

        foreach ($columns as $col) {
            $name = $col['name'];
            $type = $col['type'] ?? 'string';

            $propertyAssertions .= "        \$this->assertTrue(property_exists(\$model, '{$name}'), 'column {$name} must map to a property');\n";
            $assertGetData      .= "            \$this->assertArrayHasKey('{$name}', \$data);\n";

            if (in_array($name, $fkNames, true)) {
                continue;
            }

            [$literal, $assertLine] = $this->modelTestSample($type, $name);
            $setColumns      .= "        \$model->{$name} = {$literal};\n";
            $assertPersisted .= "            {$assertLine}\n";
        }

        if ($setColumns === '') {
            $setColumns = "        // No scalar (non-FK) columns to populate.\n";
        }
        if ($assertPersisted === '') {
            $assertPersisted = "            // No scalar (non-FK) columns to verify.\n";
        }

        $stub = $this->renderStub('crud-model-test', [
            'class'              => $className,
            'namespace'          => $namespace,
            'primaryKey'         => $primaryKey,
            'tableName'          => $tableName,
            'propertyAssertions' => rtrim($propertyAssertions, "\n"),
            'setColumns'         => rtrim($setColumns, "\n"),
            'assertPersisted'    => rtrim($assertPersisted, "\n"),
            'assertGetData'      => rtrim($assertGetData, "\n"),
        ]);

        if (file_put_contents($testFile, $stub) !== false) {
            return "Test:      {$testFile}\n";
        }
        return '';
    }

    /**
     * Return a typed sample-value literal and its reload assertion for a column,
     * keyed by the wizard logical type.
     *
     * The literal is what the round-trip test assigns before save(); the
     * assertion verifies the value survived the database round-trip. Scalar
     * types (string/int/float/bool) are asserted exactly; formats the database
     * may normalise (date/datetime/json/binary) are asserted non-empty so the
     * test stays robust across MySQL / PostgreSQL.
     *
     * @param string $logicalType Wizard logical type (string, integer, float, …)
     * @param string $colName     Column name (used inside the assertion)
     * @return array{0:string,1:string} [phpLiteral, assertionStatement]
     */
    private function modelTestSample(string $logicalType, string $colName): array
    {
        switch ($logicalType) {
            case 'integer':
            case 'biginteger':
            case 'tinyinteger':
            case 'smallinteger':
                return ['42', "\$this->assertEquals(42, (int) \$reloaded->{$colName});"];
            case 'decimal':
            case 'float':
            case 'double':
                return ['3.5', "\$this->assertEqualsWithDelta(3.5, (float) \$reloaded->{$colName}, 0.01);"];
            case 'boolean':
                return ['1', "\$this->assertEquals(1, (int) \$reloaded->{$colName});"];
            case 'date':
                return ["'2020-01-01'", "\$this->assertNotEmpty(\$reloaded->{$colName});"];
            case 'datetime':
            case 'timestamp':
                return ["'2020-01-01 12:00:00'", "\$this->assertNotEmpty(\$reloaded->{$colName});"];
            case 'json':
                return ["'{\"sample\":\"value\"}'", "\$this->assertNotEmpty(\$reloaded->{$colName});"];
            case 'binary':
                return ["'binary-sample'", "\$this->assertNotEmpty(\$reloaded->{$colName});"];
            case 'uuid':
                return [
                    "'11111111-1111-1111-1111-111111111111'",
                    "\$this->assertEquals('11111111-1111-1111-1111-111111111111', (string) \$reloaded->{$colName});",
                ];
            case 'char':
                return ["'x'", "\$this->assertEquals('x', (string) \$reloaded->{$colName});"];
            default: // string, text, longtext, and any unknown type
                return ["'sample text'", "\$this->assertEquals('sample text', (string) \$reloaded->{$colName});"];
        }
    }

    /**
     * Generate a schema-aware Feature test for a generated CRUD controller.
     *
     * Writes <baseDir>/tests/Feature/<className>Test.php from the
     * crud-controller-test.stub template. It asserts the controller extends
     * \Pramnos\Application\Controller, that the constructor registered the
     * expected public actions (show, data) and login-gated actions (edit, save,
     * delete) — read directly from the public $actions / $actions_auth arrays —
     * and dispatches two requests through the framework's in-memory TestClient:
     * the list route (expecting a 2xx HTML render) and the data() JSON endpoint
     * (terminate() mocked so exit does not kill the runner; the echoed payload
     * is decoded and checked for a DataTables row container).
     *
     * The generated test extends Tests\BaseTestCase and uses
     * \Pramnos\Testing\TestClient — the same base/helper Init.php scaffolds — so
     * it runs in the target project's suite.
     *
     * @param string $className Controller class name (e.g. Products)
     * @param string $namespace Controller namespace (e.g. App\Controllers)
     * @param string $tableName Database table (informational; may be empty)
     * @param string $baseDir   Project root; defaults to ROOT or cwd
     * @return string Human-readable summary line (empty if skipped)
     */
    public function buildControllerTest(
        string $className,
        string $namespace,
        string $tableName = '',
        string $baseDir = ''
    ): string {
        if ($baseDir === '') {
            $baseDir = defined('ROOT') ? ROOT : getcwd();
        }

        $testsDir = $baseDir . '/tests/Feature';
        if (!is_dir($testsDir)) {
            @mkdir($testsDir, 0777, true);
        }

        $testFile = $testsDir . '/' . $className . 'Test.php';
        if (file_exists($testFile)) {
            return '';
        }

        $stub = $this->renderStub('crud-controller-test', [
            'class'     => $className,
            'namespace' => $namespace,
            'route'     => strtolower($className),
        ]);

        if (file_put_contents($testFile, $stub) !== false) {
            return "Test:      {$testFile}\n";
        }
        return '';
    }

    // ── Migration body builders ───────────────────────────────────────────────

    /**
     * Get singular primary key name from a table name (e.g. users -> userid).
     */
    protected function getSingularPrimaryKey(string $tableName): string
    {
        return $this->getBlueprintCompiler()->getSingularPrimaryKey($tableName);
    }

    /**
     * Build the PHP code for a migration up() body using SchemaBuilder.
     *
     * Returns a string ready to be dropped into the `{{ up_body }}` stub token.
     * Indented with 8 spaces (method body level).
     *
     * @param string  $tableName   Table name as it will appear in the DB (may include #PREFIX#)
     * @param bool    $hasPk       Whether to add auto-increment increments('id')
     * @param array   $columns     Column definitions (see blueprintCall() for shape)
     * @param bool    $timestamps  Whether to call $table->timestamps()
     * @param bool    $softDeletes Whether to call $table->softDeletes()
     * @param array   $foreignKeys Foreign key definitions: [{column, references, on, onDelete}]
     * @return string PHP source, indented for insertion inside up()
     */
    public function buildMigrationUpBody(
        string $tableName,
        bool $hasPk,
        array $columns,
        bool $timestamps,
        bool $softDeletes,
        array $foreignKeys
    ): string {
        return $this->getBlueprintCompiler()->buildMigrationUpBody(
            $tableName, $hasPk, $columns, $timestamps, $softDeletes, $foreignKeys
        );
    }

    /**
     * Build the PHP code for a migration down() body.
     *
     * @param string $tableName Table name passed to SchemaBuilder::dropIfExists()
     * @return string PHP source, indented for insertion inside down()
     */
    public function buildMigrationDownBody(string $tableName): string
    {
        return $this->getBlueprintCompiler()->buildMigrationDownBody($tableName);
    }

    /**
     * Convert a single column definition array to a Blueprint method call string.
     *
     * @param array $col {
     *   name: string, type: string, options: array,
     *   nullable: bool, default: mixed, unique: bool, unsigned: bool, comment: string
     * }
     * @return string e.g. "$table->string('email', 255)->unique();"
     */
    public function blueprintCall(array $col): string
    {
        return $this->getBlueprintCompiler()->blueprintCall($col);
    }

    /**
     * Generate a PHP expression that produces a plausible fake value for a column.
     *
     * Uses column-name heuristics first, then falls back to type-based defaults.
     * The returned expression uses `$i` as a loop counter variable (1-based).
     * This is used by buildSeederFields() to populate seeder templates.
     *
     * @param string $colName Column name (used for name-based heuristics)
     * @param string $colType Blueprint type string (string, integer, boolean, …)
     * @param array  $options Blueprint constructor options (length, total, places, …)
     * @return string PHP expression without trailing semicolon
     */
    public function generateFakeValue(string $colName, string $colType, array $options = []): string
    {
        return $this->getFakeDataGenerator()->generateFakeValue($colName, $colType, $options);
    }

    /**
     * Build the fields block for a seeder template ({{ fields }} token).
     *
     * Skips auto-managed columns (id, created_at, updated_at, deleted_at).
     *
     * @param array $columns Column definitions (same shape as used by blueprintCall)
     * @return string Multi-line PHP key => value pairs, no surrounding braces
     */
    public function buildSeederFields(array $columns): string
    {
        return $this->getFakeDataGenerator()->buildSeederFields($columns);
    }

    // ── Seeder creator ────────────────────────────────────────────────────────

    /**
     * Create a database seeder class populated with plausible fake data.
     *
     * When $columns is non-empty (wizard flow), the seeder body is generated
     * from the column definitions so each field gets type-appropriate fake data.
     * When $columns is empty (standalone `create seeder <Name>` call), a bare
     * skeleton with a single // TODO comment is written instead.
     *
     * @param string $name      Base name for the seeder (e.g. "User" → "UserSeeder")
     * @param array  $columns   Column definitions (from wizard); empty = skeleton only
     * @param string $tableName Table name written into the seeder class property
     * @return string Summary of created files
     * @throws \Exception
     */
    public function createSeeder(string $name, array $columns, string $tableName): string
    {
        $application = $this->getApplication()->internalApplication;
        $application->init();

        $namespace = isset($application->applicationInfo['namespace'])
            ? $application->applicationInfo['namespace']
            : 'App';
        if ($application->appName != '') {
            $namespace .= '\\' . $application->appName;
        }
        $seederNamespace = $namespace . '\\Seeders';

        $baseName  = self::getProperClassName($name, true);
        $className = $baseName . 'Seeder';

        $seederDir = APP_PATH . DS . 'seeders';
        if (!is_dir($seederDir) && !mkdir($seederDir, 0755, true)) {
            throw new \Exception('Cannot create seeders directory.');
        }

        $filename = $seederDir . DS . $className . '.php';
        if (file_exists($filename)) {
            throw new \Exception("Seeder {$className} already exists at {$filename}.");
        }

        if (empty($columns)) {
            // Standalone call — bare skeleton
            $fieldsCode = '                // TODO: add column => fake-value pairs';
            $resolvedTable = $tableName ?: '#PREFIX#' . strtolower($baseName) . 's';
        } else {
            $fieldsCode    = $this->buildSeederFields($columns);
            $resolvedTable = $tableName;
        }

        $content = $this->renderStub('seeder', [
            'namespace' => $seederNamespace,
            'class'     => $className,
            'table'     => $resolvedTable,
            'date'      => date('d/m/Y H:i'),
            'fields'    => $fieldsCode,
            'count'     => '10',
        ]);

        if (file_put_contents($filename, $content) === false) {
            throw new \Exception('Cannot write seeder file.');
        }

        $testLine = $this->generateTestStub(
            $className, $seederNamespace, defined('ROOT') ? ROOT : getcwd()
        );

        return "Namespace: {$seederNamespace}\n"
             . "Class:     {$className}\n"
             . "File:      {$filename}\n"
             . $testLine
             . "\nSeeder created.";
    }

    // ── Migration wizard ──────────────────────────────────────────────────────

    /**
     * Interactive CLI wizard for `create migration` (no name argument supplied).
     *
     * Guides the developer through: description, table name, primary key, column
     * definitions (loop), timestamps, soft-deletes, foreign keys (loop), then
     * optionally creates Model / Web Controller / API Controller / Seeder from
     * the same schema definition without requiring a database connection.
     *
     * Uses Symfony Console QuestionHelper so it works in any terminal.
     *
     * @return string Final summary of all created files
     */
    protected function runMigrationWizard(InputInterface $input, OutputInterface $output): string
    {
        /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
        $helper = $this->getHelper('question');

        $output->writeln('');
        $output->writeln(' <comment>─── create:migration — Interactive Wizard ──────────────────────────</comment>');
        $output->writeln('');

        // ── Description ──────────────────────────────────────────────────────
        $q = new Question(' <info>Migration description</info> (e.g. "create users table"): ');
        $q->setValidator(function ($v) {
            // @codeCoverageIgnoreStart
            // Validator closures are only invoked when the wizard gathers real user
            // input; no unit test simulates interactive console I/O.
            $v = trim((string) $v);
            if ($v === '') throw new \RuntimeException('Description cannot be empty.');
            return $v;
            // @codeCoverageIgnoreEnd
        });
        $description = $helper->ask($input, $output, $q);

        // ── Table name ───────────────────────────────────────────────────────
        $q = new Question(' <info>Table name</info> (use #PREFIX# for the db prefix, e.g. #PREFIX#users): ');
        $q->setValidator(function ($v) {
            // @codeCoverageIgnoreStart
            $v = trim((string) $v);
            if ($v === '') throw new \RuntimeException('Table name cannot be empty.');
            return $v;
            // @codeCoverageIgnoreEnd
        });
        $tableName = $helper->ask($input, $output, $q);

        // ── Primary key ───────────────────────────────────────────────────────
        $pkName = $this->getBlueprintCompiler()->getSingularPrimaryKey($tableName);
        $q = new ConfirmationQuestion(
            " Add auto-increment primary key <info>{$pkName}</info>? [<comment>yes</comment>] ", true
        );
        $hasPk = $helper->ask($input, $output, $q);

        // ── Columns loop ──────────────────────────────────────────────────────
        // Type labels shown to user → internal type names used in migration/model
        $colTypeMap = [
            'string  (VARCHAR — variable length text)'  => 'string',
            'char    (CHAR — fixed length text)'         => 'char',
            'integer (INT)'                              => 'integer',
            'biginteger (BIGINT)'                        => 'biginteger',
            'decimal (DECIMAL — exact numeric)'          => 'decimal',
            'float   (FLOAT)'                            => 'float',
            'double  (DOUBLE)'                           => 'double',
            'boolean (TINYINT 0/1)'                      => 'boolean',
            'text    (TEXT — long text, no length limit)' => 'text',
            'longtext (LONGTEXT)'                        => 'longtext',
            'date    (DATE)'                             => 'date',
            'datetime (DATETIME)'                        => 'datetime',
            'timestamp (TIMESTAMP)'                      => 'timestamp',
            'json    (JSON)'                             => 'json',
            'uuid    (UUID / CHAR 36)'                   => 'uuid',
            'binary  (BLOB)'                             => 'binary',
        ];
        $colTypeLabels = array_keys($colTypeMap);

        // ── Tables loop — each iteration collects one table definition ────────
        $tables = [];  // array of [{tableName, hasPk, columns, timestamps, softDeletes, foreignKeys}]

        $firstTable = true;
        do {
            if (!$firstTable) {
                // @codeCoverageIgnoreStart
                // The additional-table loop is only entered when the wizard asks for
                // a second (or later) table. Tests run the wizard with a single table only.
                $output->writeln('');
                $output->writeln(' <comment>─── Additional table ────────────────────────────────────────────────</comment>');
                $q = new Question(' <info>Table name</info> (use #PREFIX# for the db prefix): ');
                $q->setValidator(function ($v) {
                    $v = trim((string) $v);
                    if ($v === '') throw new \RuntimeException('Table name cannot be empty.');
                    return $v;
                });
                $tableName = $helper->ask($input, $output, $q);

                $pkName = $this->getBlueprintCompiler()->getSingularPrimaryKey($tableName);
                $q = new ConfirmationQuestion(
                    " Add auto-increment primary key <info>{$pkName}</info>? [<comment>yes</comment>] ", true
                );
                $hasPk = $helper->ask($input, $output, $q);
                // @codeCoverageIgnoreEnd
            }
            $firstTable = false;

            $columns = [];

            $output->writeln('');
            $output->writeln(' <comment>── Columns ──────────────────────────────────────────────────────────</comment>');
            $output->writeln(' <info>Tip:</info> to set an <info>empty string</info> as default, type <comment>\'\'</comment> (two single quotes).');
            $output->writeln('');

            while (true) {
                $q = new Question(' Column name (<info>Enter to finish</info>): ');
                $colName = trim((string) $helper->ask($input, $output, $q));
                if ($colName === '') {
                    break;
                }

                $q = new ChoiceQuestion('   Type [<comment>string (VARCHAR)</comment>]: ', $colTypeLabels, 0);
                $q->setErrorMessage('Type "%s" is not valid.');
                $colTypeLabel = $helper->ask($input, $output, $q);
                $colType = $colTypeMap[$colTypeLabel] ?? 'string';

                $options = [];
                if (in_array($colType, ['string', 'char'], true)) {
                    $defaultLen = $colType === 'char' ? '1' : '255';
                    $q = new Question("   Length [<comment>{$defaultLen}</comment>]: ", $defaultLen);
                    $q->setValidator(fn($v) => is_numeric($v) && (int)$v > 0 ? (int)$v : (int)$defaultLen);
                    $options['length'] = (int) $helper->ask($input, $output, $q);
                } elseif (in_array($colType, ['decimal', 'float'], true)) {
                    // @codeCoverageIgnoreStart
                    // decimal/float column options are only reached when the user
                    // selects one of those types in the interactive column wizard.
                    $q = new Question('   Precision (total digits) [<comment>10</comment>]: ', '10');
                    $options['total'] = (int) $helper->ask($input, $output, $q);
                    $q = new Question('   Scale (decimal places) [<comment>2</comment>]: ', '2');
                    $options['places'] = (int) $helper->ask($input, $output, $q);
                    // @codeCoverageIgnoreEnd
                }

                $q = new ConfirmationQuestion('   Nullable? [<comment>no</comment>] ', false);
                $nullable = $helper->ask($input, $output, $q);

                // String-family types default to '' when the user presses Enter;
                // other types default to no default (null). NULL forces explicit opt-in.
                $isStringType = in_array($colType, ['string', 'char', 'text', 'longtext'], true);
                if ($isStringType) {
                    $q = new Question("   Default value [<comment>''</comment>] (NULL = no default): ", '');
                    $rawDefault = $helper->ask($input, $output, $q);
                    if (strtolower((string) $rawDefault) === 'null') {
                        $default = null; // @codeCoverageIgnore — only reached when user types "null" in wizard
                    } else {
                        $default = (string) $rawDefault; // '' or whatever the user typed
                    }
                } else {
                    // @codeCoverageIgnoreStart
                    // Non-string default value path is only reached for non-string column
                    // types in the interactive wizard; tests only exercise string columns.
                    $q = new Question("   Default value (blank = none): ", null);
                    $rawDefault = $helper->ask($input, $output, $q);
                    $default = ($rawDefault === null || $rawDefault === '') ? null : $rawDefault;
                    // @codeCoverageIgnoreEnd
                }

                $q = new Question('   Comment (blank = none): ', '');
                $comment = trim((string) $helper->ask($input, $output, $q));

                $q = new ConfirmationQuestion('   Unique? [<comment>no</comment>] ', false);
                $unique = $helper->ask($input, $output, $q);

                $columns[] = [
                    'name'     => $colName,
                    'type'     => $colType,
                    'options'  => $options,
                    'nullable' => $nullable,
                    'default'  => $default,
                    'comment'  => $comment,
                    'unique'   => $unique,
                    'unsigned' => false,
                ];
                $output->writeln('');
            }

            // ── Timestamps / soft-deletes ──────────────────────────────────────
            $output->writeln('');
            $q = new ConfirmationQuestion(
                ' Add <info>timestamps</info> (created_at / updated_at)? [<comment>yes</comment>] ', true
            );
            $timestamps = $helper->ask($input, $output, $q);

            $q = new ConfirmationQuestion(
                ' Add <info>soft-delete</info> column (deleted_at)? [<comment>no</comment>] ', false
            );
            $softDeletes = $helper->ask($input, $output, $q);

            // ── Foreign keys loop ──────────────────────────────────────────────
            $foreignKeys = [];
            $output->writeln('');
            $output->writeln(' <comment>── Foreign keys ─────────────────────────────────────────────────────</comment>');

            // Build the combined table list (DB tables + migration tables defined so far)
            // used for autocomplete and validation. Gracefully degrade if DB is unavailable.
            $fkDb        = null;
            $dbAvailable = false;   // tracks whether we successfully queried the DB
            $existingDbTables = [];
            try {
                $fkDb = \Pramnos\Database\Database::getInstance();
                if (!$fkDb->connected) {
                    $fkDb->connect();
                }
                $existingDbTables = $this->fetchTableNames($fkDb);
                $dbAvailable      = true;
            } catch (\Throwable $e) {
                // DB not available during wizard — FK validation will be lenient
            }
            $migrationTableNames  = array_column($tables, 'tableName');
            $migrationTableNames[] = $tableName; // current table being defined
            $allTableNames = array_unique(array_merge($existingDbTables, $migrationTableNames));
            sort($allTableNames);

            while (true) {
                $q = new ConfirmationQuestion(' Add a foreign key? [<comment>no</comment>] ', false);
                if (!$helper->ask($input, $output, $q)) {
                    break;
                }

                // Step 1 — References table (autocomplete + validation)
                $q = new Question('   References table: ');
                if (!empty($allTableNames)) {
                    $q->setAutocompleterValues($allTableNames);
                }
                $q->setValidator(function ($v) use ($allTableNames, $dbAvailable, $fkDb) {
                    // @codeCoverageIgnoreStart
                    // FK validator closure body is only reached when the wizard asks
                    // the user to input a references table name.
                    $v = trim((string) $v);
                    if ($v === '') {
                        throw new \RuntimeException('Table name required.');
                    }
                    if (!$dbAvailable) {
                        return $v;
                    }
                    if (in_array($v, $allTableNames, true)) {
                        return $v;
                    }
                    if ($fkDb !== null && $fkDb->prefix !== '') {
                        $resolved = str_replace('#PREFIX#', $fkDb->prefix, $v);
                        if (in_array($resolved, $allTableNames, true)) {
                            return $v;
                        }
                    }
                    if ($fkDb !== null) {
                        try {
                            if ($fkDb->tableExists($v)) {
                                return $v;
                            }
                        } catch (\Throwable $e) {
                            return $v;
                        }
                    }
                    throw new \RuntimeException(
                        "Table '{$v}' not found in the database or this migration. "
                        . "Use Tab to autocomplete from known tables."
                    );
                    // @codeCoverageIgnoreEnd
                });
                $fkTable = $helper->ask($input, $output, $q);

                // Step 2 — References column (default = PK of the referenced table)
                $refColumns = ($fkDb !== null)
                    ? $this->getColumnsForFKTable(
                        $fkTable, $tables, $tableName, $columns, $hasPk, $fkDb
                      )
                    : [];
                if (!empty($refColumns)) {
                    // @codeCoverageIgnoreStart
                    // Branch where FK table has known columns is only reached when
                    // the DB is connected and the referenced table has listed columns.
                    $expectedPk = $this->getBlueprintCompiler()->getSingularPrimaryKey($fkTable);
                    $defaultIdx = array_search($expectedPk, $refColumns);
                    $defaultIdx = $defaultIdx !== false ? (int) $defaultIdx : 0;
                    $q = new ChoiceQuestion(
                        '   References column [<comment>' . $refColumns[$defaultIdx] . '</comment>]: ',
                        $refColumns,
                        $defaultIdx
                    );
                    $fkRef = $helper->ask($input, $output, $q);
                    // @codeCoverageIgnoreEnd
                } else {
                    $expectedPk = $this->getBlueprintCompiler()->getSingularPrimaryKey($fkTable);
                    $q = new Question(
                        "   References column [<comment>{$expectedPk}</comment>]: ", $expectedPk
                    );
                    $fkRef = trim((string) $helper->ask($input, $output, $q)) ?: $expectedPk;
                }

                // Step 3 — Column name in this table (default = references column)
                $definedColNames = array_column($columns, 'name');
                if ($hasPk) {
                    array_unshift($definedColNames,
                        $this->getBlueprintCompiler()->getSingularPrimaryKey($tableName));
                }
                $q = new Question(
                    "   Column name in this table [<comment>{$fkRef}</comment>]: ", $fkRef
                );
                $q->setValidator(fn($v) => trim((string)$v) !== ''
                    ? trim($v)
                    : throw new \RuntimeException('Column name required.'));
                if (!empty($definedColNames)) {
                    $q->setAutocompleterValues($definedColNames);
                }
                $fkCol = $helper->ask($input, $output, $q);

                // Step 4 — On delete / On update
                $q = new ChoiceQuestion(
                    '   On delete [<comment>RESTRICT</comment>]: ',
                    ['RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION'],
                    0
                );
                $fkOnDelete = $helper->ask($input, $output, $q);

                $q = new ChoiceQuestion(
                    '   On update [<comment>RESTRICT</comment>]: ',
                    ['RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION'],
                    0
                );
                $fkOnUpdate = $helper->ask($input, $output, $q);

                // Add the FK column to the column list if not already defined
                $alreadyDefined = !empty(array_filter($columns, fn($c) => $c['name'] === $fkCol));
                if (!$alreadyDefined) {
                    $columns[] = [
                        'name'     => $fkCol,
                        'type'     => 'biginteger',
                        'options'  => [],
                        'nullable' => $fkOnDelete === 'SET NULL',
                        'default'  => null,
                        'comment'  => '',
                        'unique'   => false,
                        'unsigned' => true,
                    ];
                }

                $foreignKeys[] = [
                    'column'     => $fkCol,
                    'references' => $fkRef,
                    'on'         => $fkTable,
                    'onDelete'   => $fkOnDelete,
                    'onUpdate'   => $fkOnUpdate,
                ];
                $output->writeln('');
            }

            $tables[] = [
                'tableName'   => $tableName,
                'hasPk'       => $hasPk,
                'columns'     => $columns,
                'timestamps'  => $timestamps,
                'softDeletes' => $softDeletes,
                'foreignKeys' => $foreignKeys,
            ];

            $output->writeln('');
            $q = new ConfirmationQuestion(
                ' Add <info>another table</info> to this migration? [<comment>no</comment>] ', false
            );
        } while ($helper->ask($input, $output, $q));

        // ── Write migration ───────────────────────────────────────────────────
        $application = $this->getApplication()->internalApplication;
        $application->init();

        $namespace = isset($application->applicationInfo['namespace'])
            ? $application->applicationInfo['namespace']
            : 'App';
        if ($application->appName != '') {
            $namespace .= '\\' . $application->appName;
        }
        $fullNamespace = $namespace . '\\Migrations';

        $slug      = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(strip_tags($description))), '_');
        $className = str_replace(' ', '', ucwords(str_replace('_', ' ', $slug)));
        $timestamp = date('Y_m_d_His');
        $migDir    = APP_PATH . DS . 'migrations';
        if (!is_dir($migDir)) {
            mkdir($migDir, 0755, true);
        }
        $filePath = $migDir . DS . $timestamp . '_' . $slug . '.php';

        // Build up() and down() bodies for all collected tables
        $upBodyParts   = [];
        $downBodyParts = [];
        foreach ($tables as $tbl) {
            $upBodyParts[]   = $this->buildMigrationUpBody(
                $tbl['tableName'], $tbl['hasPk'], $tbl['columns'],
                $tbl['timestamps'], $tbl['softDeletes'], $tbl['foreignKeys']
            );
            // down() drops in reverse order
            array_unshift($downBodyParts, $this->buildMigrationDownBody($tbl['tableName']));
        }
        // Prefix with a single $schema instance — createTable() calls reuse it.
        $upBody   = "        \$schema = \$this->application->database->schema();\n\n"
                  . implode("\n\n", $upBodyParts);
        $downBody = implode("\n", $downBodyParts);

        $content = $this->renderStub('migration', [
            'namespace'   => $fullNamespace,
            'class'       => $className,
            'description' => $description,
            'date'        => date('d/m/Y H:i'),
            'up_body'     => $upBody,
            'down_body'   => $downBody,
        ]);

        file_put_contents($filePath, $content);

        $output->writeln('');
        $output->writeln(" <info>✓ Migration created:</info> {$filePath}");
        $output->writeln('');

        // ── Run migration now? ────────────────────────────────────────────────
        $q = new ConfirmationQuestion(
            ' Run this migration <info>now</info>? [<comment>yes</comment>] ', true
        );
        if ($helper->ask($input, $output, $q)) {
            // @codeCoverageIgnoreStart
            // "Run now" block requires a live DB connection and a real migration
            // file to be present on disk — not exercised in unit tests.
            try {
                $output->writeln(' Running migration...');
                $app  = $this->getApplication()->internalApplication;
                $db   = \Pramnos\Database\Database::getInstance();
                if (!$db->connected) {
                    $db->connect();
                }
                $dirs       = [$migDir];
                $migrations = \Pramnos\Database\MigrationLoader::loadFromDirectories($dirs, $app);
                $runner     = new \Pramnos\Database\MigrationRunner($db);
                $runner->run($migrations, [], function (string $event, string $slug, string $error) use ($output): void {
                    if ($event === 'ran') {
                        $output->writeln(' <info>✓ Migrated:</info> ' . $slug);
                    } else {
                        $output->writeln(' <error>Failed:</error>   ' . $slug . ' — ' . strtok(trim($error), "\n"));
                    }
                });
                $output->writeln(' <info>✓ Migration complete.</info>');
            } catch (\Exception $e) {
                $output->writeln(" <comment>Migration failed: {$e->getMessage()}</comment>");
                $output->writeln(" Run manually with: php bin/pramnos migrate");
            }
            // @codeCoverageIgnoreEnd
        }

        // ── Post-creation scaffold options ────────────────────────────────────
        // Use the first table's definition for model/controller/seeder creation.
        // For multi-table migrations the user can run create:model separately for
        // additional tables.
        $primaryTable  = $tables[0];
        $tableName     = $primaryTable['tableName'];
        $columns       = $primaryTable['columns'];
        $foreignKeys   = $primaryTable['foreignKeys'];
        $hasPk         = $primaryTable['hasPk'];

        $stripped   = preg_replace('/^#PREFIX#/', '', $tableName);
        $entityName = str_replace(' ', '', ucwords(str_replace('_', ' ', $stripped)));

        // Ask the user to confirm or override the derived class name.
        // Singularisation is approximate (e.g. 'whores' → 'whor' instead of 'whore'),
        // so the user must always have a chance to correct it before any file is written.
        $suggestedClass = self::getProperClassName($entityName, true);
        $output->writeln('');
        $q = new Question(
            " <info>Class name</info> (singular) [<comment>{$suggestedClass}</comment>]: ",
            $suggestedClass
        );
        $q->setValidator(function ($v) {
            // @codeCoverageIgnoreStart
            // Class name validator body is only executed during interactive I/O.
            $v = trim((string) $v);
            if ($v === '') {
                throw new \RuntimeException('Class name cannot be empty.');
            }
            if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $v)) {
                throw new \RuntimeException(
                    'Class name must start with an uppercase letter and contain only letters/digits.'
                );
            }
            return $v;
            // @codeCoverageIgnoreEnd
        });
        $entityName = $helper->ask($input, $output, $q);

        // Announce secondary tables if any
        if (count($tables) > 1) {
            // @codeCoverageIgnoreStart
            // Secondary-tables note only shown when the wizard collected >1 tables;
            // tests exercise the single-table path only.
            $output->writeln('');
            $output->writeln(' <comment>Note: scaffold below targets the first table (' . $tableName . ').</comment>');
            $output->writeln(' <comment>Run create:model / create:controller for additional tables separately.</comment>');
            // @codeCoverageIgnoreEnd
        }

        $summary = "Migration: {$filePath}\n";

        $output->writeln('');
        $output->writeln(' <comment>── Also create ────────────────────────────────────────────────────────</comment>');

        $q = new ConfirmationQuestion(
            " Create <info>Model</info> ({$entityName})? [<comment>yes</comment>] ", true
        );
        if ($helper->ask($input, $output, $q)) {
            try {
                $this->dbtable = $tableName;
                $result = $this->createModel($entityName, $columns, $foreignKeys);
                $summary .= $result . "\n";
                $output->writeln("   <info>✓</info> Model created.");
            } catch (\Exception $e) {
                $output->writeln("   <comment>Model skipped: {$e->getMessage()}</comment>");
            }
        }

        $q = new ConfirmationQuestion(
            " Create <info>Web Controller</info> ({$entityName}Controller)? [<comment>yes</comment>] ", true
        );
        if ($helper->ask($input, $output, $q)) {
            try {
                $result = $this->createController($entityName, true, $columns, $foreignKeys);
                $summary .= $result . "\n";
                $output->writeln("   <info>✓</info> Controller created.");
            } catch (\Exception $e) {
                $output->writeln("   <comment>Controller skipped: {$e->getMessage()}</comment>");
            }
        }

        $q = new ConfirmationQuestion(
            " Create <info>API Controller</info> ({$entityName}ApiController)? [<comment>yes</comment>] ", true
        );
        if ($helper->ask($input, $output, $q)) {
            try {
                $result = $this->createApi($entityName);
                $summary .= $result . "\n";
                $output->writeln("   <info>✓</info> API Controller created.");
            } catch (\Exception $e) {
                $output->writeln("   <comment>API Controller skipped: {$e->getMessage()}</comment>");
            }
        }

        $q = new ConfirmationQuestion(
            " Create <info>Seeder</info> ({$entityName}Seeder with fake data)? [<comment>yes</comment>] ", true
        );
        if ($helper->ask($input, $output, $q)) {
            try {
                $result = $this->createSeeder($entityName, $columns, $tableName);
                $summary .= $result . "\n";
                $output->writeln("   <info>✓</info> Seeder created.");
            } catch (\Exception $e) {
                $output->writeln("   <comment>Seeder skipped: {$e->getMessage()}</comment>");
            }
        }

        $output->writeln('');

        return $summary . "\nRun the migration with: php bin/pramnos migrate";
    }


    // ── Entity creators ───────────────────────────────────────────────────────

    /**
     * Creates a CRUD system based on a model name
     * @param string $name
     * @return string
     */
    public function createCrud($name)
    {
        $content = "Creating Model: ";
        try {
            $this->createModel($name);
            $content .= "OK\n";
        } catch (\Exception $ex) {
            $content .= "FAIL - " . $ex->getMessage() . "\n";
        }
        $content .= "Creating Controller: ";
        try {
            $this->createController($name, true);
            $content .= "OK\n";
        } catch (\Exception $ex) {
            $content .= "FAIL - " . $ex->getMessage() . "\n";
        }
        $content .= "Creating View: ";
        try {
            // A full controller now scaffolds its own CRUD views (list/edit/show)
            // as part of createController(), which delegates to the shared wizard
            // generator. Only fall back to the standalone view generator when the
            // controller step did not already produce them — otherwise createView()
            // would (correctly) refuse to overwrite the freshly generated views.
            if (!$this->crudViewsExist($name)) {
                $this->createView($name, true);
            }
            $content .= "OK\n";
        } catch (\Exception $ex) {
            $content .= "FAIL - " . $ex->getMessage() . "\n";
        }
        return $content . "\n";
    }

    /**
     * Determine whether the CRUD view directory for an entity already exists and
     * contains generated files.
     *
     * Used by createCrud() to avoid double-generating views: a full controller
     * now scaffolds its list/edit/show templates itself, so the standalone view
     * generator must be skipped when those files are already present.
     *
     * @param string $name Entity name (the view dir is its lowercase form)
     * @return bool True when the view directory holds at least one file
     */
    protected function crudViewsExist(string $name): bool
    {
        $application = $this->getApplication()->internalApplication;

        $path = ROOT . DS . INCLUDES . DS;
        if ($application->appName != '') {
            $path .= $application->appName . DS;
        }
        $viewPath = $path . 'Views' . DS . strtolower($name);

        if (!is_dir($viewPath)) {
            return false;
        }
        $files = array_diff((array) scandir($viewPath), ['.', '..']);
        return !empty($files);
    }

    /**
     * Look up a model by table name using either class naming conventions or the model registry
     * 
     * @param string $name Base name to look up
     * @param bool $forceSingular Whether to force singular form when checking by convention
     * @return array|null Found model information or null if not found
     */
    protected function lookupModel($name, $forceSingular = true)
    {
        $application = $this->getApplication()->internalApplication;
        $database = \Pramnos\Database\Database::getInstance();
        
        // Try to determine table name
        if ($this->dbtable != null) {
            $tableName = $this->dbtable;
        } else {
            $tableName = self::getModelTableName($name);
        }
        
        // Prepare namespace
        $namespace = 'Pramnos';
        if (isset($application->applicationInfo['namespace'])) {
            $namespace = $application->applicationInfo['namespace'];
        }
        if ($application->appName != '') {
            $namespace .= '\\' . $application->appName;
        }
        $namespace .= '\\Models';
        
        // Try convention-based approach first
        $conventionClassName = self::getProperClassName($name, $forceSingular);
        $fullConventionClassName = '\\' . $namespace . '\\' . $conventionClassName;
        
        // Check if the model exists by convention
        if (class_exists($fullConventionClassName)) {
            return [
                'className' => $conventionClassName,
                'namespace' => $namespace,
                'fullClassName' => $fullConventionClassName,
                'foundBy' => 'convention'
            ];
        }
        
        // If we have a specific table name, try to locate it in the registry
        $registryFile = ROOT . DS . 'app' . DS . 'model-registry.json';
        if (file_exists($registryFile)) {
            $registry = json_decode(file_get_contents($registryFile), true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($registry)) {
                // Check the registry for a model matching this table
                foreach ($registry as $model) {
                    $registryTableName = $model['table'] ?? '';
                    $registrySchema = $model['schema'] ?? '';
                    
                    // Check if this model matches the table we're looking for
                    if ($registryTableName === $tableName || 
                        str_replace('#PREFIX#', $database->prefix, $registryTableName) === $tableName) {
                        
                        // If schema is specified, make sure it matches too
                        if ($this->schema !== null && $registrySchema !== $this->schema) {
                            continue;
                        }
                        
                        return [
                            'className' => $model['className'],
                            'namespace' => $model['namespace'],
                            'fullClassName' => $model['fullClassName'],
                            'foundBy' => 'registry'
                        ];
                    }
                }
                
                // If we still haven't found it, try a case-insensitive search by name
                $lowercaseName = strtolower($name);
                foreach ($registry as $model) {
                    if (strtolower($model['className']) === $lowercaseName || 
                        strpos(strtolower($model['table']), $lowercaseName) !== false) {
                        
                        return [
                            'className' => $model['className'],
                            'namespace' => $model['namespace'],
                            'fullClassName' => $model['fullClassName'],
                            'foundBy' => 'registry_name_match'
                        ];
                    }
                }
            }
        }
        
        // Return the convention-based lookup result as a fallback, even though the class doesn't exist
        return [
            'className' => $conventionClassName,
            'namespace' => $namespace,
            'fullClassName' => $fullConventionClassName,
            'foundBy' => 'convention_fallback'
        ];
    }

    /**
     * Creates a view
     * @param string $name Name of the view
     * @param bool $full Create a full crud view (Create/List/Edit/Delete)
     */
    protected function createView($name, $full = false)
    {
        $application = $this->getApplication()->internalApplication;
        $application->init();

        $path = ROOT . DS . INCLUDES . DS;
        if ($application->appName != '') {
            $path .= $application->appName . DS;
        }
        $path .= 'Views';
        $viewPath = $path . DS . strtolower($name);

        // Check if directory exists and is not empty
        if (file_exists($viewPath)) {
            $files = array_diff(scandir($viewPath), array('.', '..'));
            if (!empty($files)) {
                throw new \Exception('View already exists and contains files.');
            }
        } else {
            mkdir($viewPath, 0755, true);
        }

        if ($full) {
            // ── Full CRUD views: unified onto the SAME admin-style per-theme
            // generator the wizard/CRUD path uses (mirrors how createController()
            // and createModel() were unified). Resolve the backing table,
            // introspect it into wizard-shaped column/FK arrays and DELEGATE to
            // createViewsFromWizard(), which renders the list/edit/show views from
            // scaffolding/templates/crud-view-*.stub for the detected theme. The
            // old per-column inline heredoc view generator has been retired.
            $database  = \Pramnos\Database\Database::getInstance();
            $className = self::getProperClassName($name, false);

            // Determine table name — either from the specified option or by
            // convention from the entity name.
            if ($this->dbtable != null) {
                $tableName = $this->dbtable;
            } else {
                $tableName = self::getModelTableName($name);
            }

            // The table must already exist — a full CRUD view is generated from a
            // real schema. Fail loudly (identical to the controller/model
            // generators) rather than emitting a schema-less view.
            if (!$database->tableExists($tableName)) {
                throw new \Exception(
                    "Table '{$tableName}' not found for {$className}. "
                    . "Create it first with `create:migration`."
                );
            }

            // Normalise the live table into the SAME wizard column/FK shape the
            // migration-wizard path produces, then delegate to the shared view
            // generator so there is a single admin-style view code path.
            [$columns, $foreignKeys] = $this->introspectTableAsWizardColumns($tableName);
            $ui         = $this->detectUiSetup();
            $primaryKey = $this->getSingularPrimaryKey($tableName);

            return $this->createViewsFromWizard(
                $name, $columns, $foreignKeys, $primaryKey, $ui
            );
        }

        // ── Simple (non-CRUD) view: a single themed placeholder file ──────────
        // De-heredoc'd into scaffolding/templates/simple-view.stub. It does NOT
        // require a table and is not a CRUD view — just a bare starting point.
        $actualName = ucfirst($name);
        $date       = date('d/m/Y H:i');
        $viewFile   = $viewPath . DS . strtolower($name) . '.html.php';

        $content = $this->renderStub('simple-view', [
            'objectName' => $actualName,
            'date'       => $date,
        ]);

        file_put_contents($viewFile, $content);

        return "Files: \n - {$viewFile}\n\nView created.";
    }

    /**
     * Creates a controller
     * @param string $name Name of the controller to be created
     * @param bool $full Create a full crud controller
     */
    protected function createApi($name)
    {
        $application = $this->getApplication()->internalApplication;
        $application->init();

        $path = ROOT . DS . INCLUDES . DS;

        if (isset($application->applicationInfo['namespace'])) {
            $namespace = $application->applicationInfo['namespace'];
        } else {
            $namespace = 'Pramnos';
        }
        if ($application->appName != '') {
            $namespace .= '\\' . $application->appName;
            $path .= $application->appName . DS;
        }
        $namespace .= '\\Api\\Controllers';

        $path .= 'Api/Controllers';
        // Use the exact entity name provided by the user for API controllers
        $className = ucfirst($name);
        $filename = $path . DS . $className . '.php';

        if (class_exists('\\' . $namespace . '\\'. $className)
            || file_exists($filename)) {
            throw new \Exception('Controller already exists.');
        }
        if (!file_exists($path)) {
            mkdir($path);
        }


        

        $date = date('d/m/Y H:i');
        
            $database = \Pramnos\Database\Database::getInstance();
            $viewName = strtolower($name);
            $modelNameSpace = str_replace("Api\Controllers", "Models", $namespace);
            
            // Use the entity name provided by user for the model class name
            $modelClass = self::getProperClassName($name, true);
            $modelClassLower = strtolower($modelClass);
            
            // Look up the model in the registry to get correct namespace if it exists
            $modelInfo = $this->lookupModel($name, true);
            
            // If we found the model in the registry, use its namespace
            if ($modelInfo['foundBy'] === 'registry' || $modelInfo['foundBy'] === 'registry_name_match') {
                $modelNameSpace = $modelInfo['namespace'];
                // But still use the user-specified entity name for the class
                $modelClass = self::getProperClassName($name, true);
            }

            if ($this->dbtable != null) {
                $tableName = $this->dbtable;
            } else {
                $tableName = self::getModelTableName($name);
            }
            


            if (!$database->tableExists($tableName)) {
                throw new \Exception(
                    'Table: ' . $tableName . ' does not exist.'
                );
            }
            $result = $database->getColumns($tableName, $this->schema);


            $saveContent = '';
            $updateContent = '';
            $returnContent = '';
            $postContent = '';
            $putContent = '';
            $primaryKey = '';

            $routerContent = '';

            while ($result->fetch()) {
                $primary = false;
                if ($database->type == 'postgresql') {
                    if ($result->fields['PrimaryKey'] == 't' || $result->fields['PrimaryKey'] === true) {
                        $primaryKey = $result->fields['Field'];
                        $primary = true;
                    }
                } elseif (isset($result->fields['Key'])
                    && $result->fields['Key'] == 'PRI') {
                        $primaryKey = $result->fields['Field'];
                        $primary = true;
                }
                $basicType = explode('(', $result->fields['Type']);
                switch ($basicType[0]) {
                    case "tinyint":
                    case "smallint":
                    case "integer":
                    case "int":
                    case "mediumint":
                    case "bigint":

                        $returnContent .= '     * @apiSuccess {Number} data.' . $result->fields['Field'] . ' ' . $result->fields['Comment'] . "\n";
                        if (!$primary) {
                            if ($result->fields['Null'] == 'YES') {
                                $saveContent .= '     * @apiBody {Number} [' . $result->fields['Field'] . '] ' . $result->fields['Comment'] . "\n";
                                $postContent .= '        $model->' . $result->fields['Field'] . ' = \Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', null, \'post\', \'int\');' . "\n";
                                $postContent .= '        if ($model->' . $result->fields['Field'] . ' == 0) {' . "\n";
                                $postContent .= '            $model->' . $result->fields['Field'] . ' = null;' . "\n";
                                $postContent .= '        }' . "\n";
                            } else {
                                $saveContent .= '     * @apiBody {Number} ' . $result->fields['Field'] . ' ' . $result->fields['Comment'] . "\n";
                                $postContent .= '        $model->' . $result->fields['Field'] . ' = \Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', 0, \'post\', \'int\');' . "\n";
                            }
                            $updateContent .= '     * @apiBody {Number} [' . $result->fields['Field'] . '] ' . $result->fields['Comment'] . "\n";
                            $putContent .= '        $model->' . $result->fields['Field'] . ' = \Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', $model->' . $result->fields['Field'] . ', \'put\', \'int\');' . "\n";
                            
                        }
                        break;
                    case "float":
                    case "double":
                        $returnContent .= '     * @apiSuccess {Number} data.' . $result->fields['Field'] . ' ' . $result->fields['Comment'] . "\n";
                        if (!$primary) {
                            if ($result->fields['Null'] == 'YES') {
                                $saveContent .= '     * @apiBody {Number} [' . $result->fields['Field'] . ']  ' . $result->fields['Comment'] . "\n";
                                $postContent .= '        $model->' . $result->fields['Field'] . ' = \Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', null, \'post\');' . "\n";
                                $postContent .= '        if ($model->' . $result->fields['Field'] . ' == 0) {' . "\n";
                                $postContent .= '            $model->' . $result->fields['Field'] . ' = null;' . "\n";
                                $postContent .= '        }' . "\n";
                            } else {
                                $saveContent .= '     * @apiBody {Number} ' . $result->fields['Field'] . '  ' . $result->fields['Comment'] . "\n";
                                $postContent .= '        $model->' . $result->fields['Field'] . ' = \Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', 0, \'post\');' . "\n";
                            }
                            $updateContent .= '      * @apiBody {Number} [' . $result->fields['Field'] . '] ' . $result->fields['Comment'] . "\n";
                            $putContent .= '        $model->' . $result->fields['Field'] . ' = \Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', $model->' . $result->fields['Field'] . ', \'put\');' . "\n";
                        }
                        break;
                    case "bool":
                    case "boolean":
                        $returnContent .= '     * @apiSuccess {Boolean} data.' . $result->fields['Field'] . ' ' . $result->fields['Comment'] . "\n";
                        if (!$primary) { 
                            $postContent .= '        $tmpVar = \Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', null, \'post\');' . "\n";
                            $postContent .= '        if ($tmpVar == \'true\' || $tmpVar == \'on\' || $tmpVar == "yes" || $tmpVar === \'1\' || $tmpVar === 1) {' . "\n";
                            $postContent .= '            $tmpVar = true; ' . "\n";
                            $postContent .= '        } else { ' . "\n";
                            $postContent .= '            $tmpVar = false; ' . "\n";
                            $postContent .= '        } ' . "\n";
                            $saveContent .= '      * @apiBody {Boolean} [' . $result->fields['Field'] . '] ' . $result->fields['Comment'] . "\n";
                            $postContent .= '        $model->' . $result->fields['Field'] . ' = $tmpVar;' . "\n";   
                        }
                        $updateContent .= '     * @apiBody {Boolean} [' . $result->fields['Field'] . ']  ' . $result->fields['Comment'] . "\n";
                        $putContent .= '       $model->' . $result->fields['Field'] . ' = \Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', $model->' . $result->fields['Field'] . ', \'put\', \'int\');' . "\n";
                        break;
                    case "json":
                        $returnContent .= '     * @apiSuccess {JSON} data.' . $result->fields['Field'] . ' ' . $result->fields['Comment'] . "\n";
                        if (!$primary) {
                            if ($result->fields['Null'] == 'YES') {
                                $saveContent .= '     * @apiBody {JSON} [' . $result->fields['Field'] . '] ' . $result->fields['Comment'] . "\n";
                                $postContent .= '        $model->' . $result->fields['Field'] . ' = trim(\Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', null, \'post\'));' . "\n";
                            } else {
                                $saveContent .= '     * @apiBody {JSON} ' . $result->fields['Field'] . ' ' . $result->fields['Comment'] . "\n";
                                $postContent .= '        $model->' . $result->fields['Field'] . ' = trim(\Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', \'\', \'post\'));' . "\n";
                            }
                            $updateContent .= '     * @apiBody {JSON} [' . $result->fields['Field'] . '] ' . $result->fields['Comment'] . "\n";
                            $putContent .= '        $model->' . $result->fields['Field'] . ' = trim(\Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', $model->' . $result->fields['Field'] . ', \'put\'));' . "\n";
                        }
                        break;
                    default:
                        $returnContent .= '     * @apiSuccess {String} data.' . $result->fields['Field'] . ' ' . $result->fields['Comment'] . "\n";
                        if (!$primary) {
                            if ($result->fields['Null'] == 'YES') {
                                $saveContent .= '     * @apiBody {String} [' . $result->fields['Field'] . '] ' . $result->fields['Comment'] . "\n";
                                $postContent .= '        $model->' . $result->fields['Field'] . ' = trim(strip_tags(\Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', null, \'post\')));' . "\n";
                            } else {
                                $saveContent .= '     * @apiBody {String} ' . $result->fields['Field'] . ' ' . $result->fields['Comment'] . "\n";
                                $postContent .= '        $model->' . $result->fields['Field'] . ' = trim(strip_tags(\Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', \'\', \'post\')));' . "\n";
                            }
                            $updateContent .= '     * @apiBody {String} [' . $result->fields['Field'] . '] ' . $result->fields['Comment'] . "\n";
                            $putContent .= '        $model->' . $result->fields['Field'] . ' = trim(strip_tags(\Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', $model->' . $result->fields['Field'] . ', \'put\')));' . "\n";
                        }
                        break;
                }

            }


            // Generate field list for API documentation
            $fieldList = '';
            $result = $database->getColumns($tableName, $this->schema);
            $fields = array();
            while ($result->fetch()) {
                $fields[] = $result->fields['Field'];
            }
            $fieldList = implode(', ', $fields);

            $fileContent = $this->renderStub('api-controller', [
                'namespace'       => $namespace,
                'className'       => $className,
                'date'            => $date,
                'modelClassLower' => $modelClassLower,
                'modelClass'      => $modelClass,
                'primaryKey'      => $primaryKey,
                'modelNameSpace'  => $modelNameSpace,
                'fieldList'       => $fieldList,
                'returnContent'   => $returnContent,
                'saveContent'     => $saveContent,
                'postContent'     => $postContent,
                'updateContent'   => $updateContent,
                'putContent'      => $putContent,
            ]);


$routerContent = $this->renderStub('api-routes', [
    'modelClassLower' => $modelClassLower,
    'primaryKey'      => $primaryKey,
    'className'       => $className,
    'modelClass'      => $modelClass,
]);


      
        file_put_contents($filename, $fileContent);



        $routerFile = ROOT . '/src/Api/routes.php';
        $routerContentOriginal = file_get_contents($routerFile);
        if (strpos($routerContentOriginal, $routerContent) === false) {
            $routerContentOriginal = str_replace(
                'return $router->dispatch($newRequest);',
                $routerContent . "\n\n" . 'return $router->dispatch($newRequest);',
                $routerContentOriginal
            );
            file_put_contents($routerFile, $routerContentOriginal);
        }


        return "Namespace: {$namespace}\n"
            . "Class: {$className}\n"
            . "File: {$filename}\n"
            . "API URL: sURL . 'api/v1/{$className}'\n\n"
            . "Controller created. \n";
    }

    /**
     * Creates a controller.
     *
     * When $wizardColumns is provided (from the migration wizard) a full CRUD
     * controller and view files are generated from those definitions — no DB
     * round-trip required. The generated views adapt to the app's scaffold_theme
     * and installed libraries (datatables, select2) from assets.json.
     *
     * The controller is ALWAYS a full CRUD artifact rendered from
     * crud-controller.stub. The former "simple skeleton" mode has been removed.
     *
     * @param string $name           Entity name
     * @param bool   $full           Deprecated/ignored — generation is always
     *                               full CRUD. Kept only for call-site BC.
     * @param array  $wizardColumns  Column definitions from runMigrationWizard()
     * @param array  $wizardForeignKeys FK definitions from runMigrationWizard()
     */
    protected function createController($name, $full = false, array $wizardColumns = [], array $wizardForeignKeys = [])
    {
        $application = $this->getApplication()->internalApplication;
        $application->init();
        $output = $this->output;
        $path = ROOT . DS . INCLUDES . DS;

        if (isset($application->applicationInfo['namespace'])) {
            $namespace = $application->applicationInfo['namespace'];
        } else {
            $namespace = 'Pramnos';
        }
        if ($application->appName != '') {
            $namespace .= '\\' . $application->appName;
            $path .= $application->appName . DS;
        }
        $namespace .= '\\Controllers';

        $path .= 'Controllers';
        $lastLetter = substr($name, -1);
        $className = self::getProperClassName($name, false);
        $filename = $path . DS . $className . '.php';


        if (class_exists('\\' . $namespace . '\\'. $className)
            || file_exists($filename)) {
            throw new \Exception('Controller already exists.');
        }
        if (!file_exists($path)) {
            mkdir($path);
        }
        // Generation is ALWAYS full CRUD (the $full parameter is ignored — the
        // simple skeleton path was removed). Either wizard columns are supplied
        // (migration wizard) or the live table is introspected. If neither is
        // available the table must be created first, so we fail loudly rather
        // than emitting a schema-less stub.
        $database = \Pramnos\Database\Database::getInstance();

        // Look up the model in the registry first
        $modelInfo = $this->lookupModel($name, true);
        $modelNameSpace = $modelInfo['namespace'];
        $modelClass = $modelInfo['className'];

        if ($modelInfo['foundBy'] === 'registry' || $modelInfo['foundBy'] === 'registry_name_match') {
            if (isset($this->output)) {
                $this->output->writeln("Using model " . $modelClass . " found in registry");
            }
        }

        if ($this->dbtable != null) {
            $tableName = $this->dbtable;
        } else {
            $tableName = self::getModelTableName($name);
        }

        // ── Wizard-columns path (schema-first, no DB round-trip) ──────────
        if (!empty($wizardColumns)) {
            $columns     = $wizardColumns;
            $foreignKeys = $wizardForeignKeys;
        } else {
            // ── DB-introspection path (table must already exist) ─────────
            // Convert the live table definition into the SAME wizard column /
            // foreign-key array shape, then delegate to the shared CRUD
            // generator so a full controller is always produced from
            // crud-controller.stub — identical to the migration-wizard path.
            if (!$database->tableExists($tableName)) {
                throw new \Exception(
                    "Table '{$tableName}' not found for {$className}. "
                    . "Create it first with `create:migration`."
                );
            }
            [$columns, $foreignKeys] = $this->introspectTableAsWizardColumns($tableName);
        }

        $result = $this->createControllerAndViewsFromWizard(
            $name, $namespace, $modelNameSpace, $modelClass,
            $className, $tableName, $path,
            $columns, $foreignKeys,
            $filename
        );
        $testLine = $this->buildControllerTest(
            $className, $namespace, $tableName,
            defined('ROOT') ? ROOT : getcwd()
        );
        return "Namespace: {$namespace}\n"
             . "Class:     {$className}\n"
             . "File:      {$filename}\n"
             . $testLine
             . "\n" . $result;
    }

    /**
     * Introspect an existing database table and return its column and
     * foreign-key definitions in the SAME array shape produced by the
     * migration wizard (runMigrationWizard()).
     *
     * This lets the full-controller generator take a single code path: whether
     * the column metadata originates from the wizard (schema-first) or from a
     * live table (DB-first), it is normalised here and handed to
     * createControllerAndViewsFromWizard(), which renders from
     * scaffolding/templates/crud-controller.stub.
     *
     * Handles both MySQL and PostgreSQL result shapes returned by
     * Database::getColumns() — primary key (Key='PRI' / PrimaryKey=true),
     * nullability (Null='YES'/'NO'), and foreign keys (ForeignKey +
     * ForeignTable/ForeignColumn). The convention-derived primary key is added
     * by the CRUD generator itself, so the detected PK column is intentionally
     * excluded from the returned columns — mirroring the wizard array, which
     * never contains the primary key.
     *
     * @param string $tableName Table to introspect (may contain #PREFIX#)
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     *               [$columns, $foreignKeys] in wizard shape
     */
    private function introspectTableAsWizardColumns(string $tableName): array
    {
        $database = \Pramnos\Database\Database::getInstance();
        $result   = $database->getColumns($tableName, $this->schema);

        $columns     = [];
        $foreignKeys = [];

        while ($result->fetch()) {
            $field = $result->fields['Field'];

            // Primary key — driver-specific flag names.
            $isPrimary = false;
            if ($database->type == 'postgresql') {
                $isPrimary = ($result->fields['PrimaryKey'] == 't'
                    || $result->fields['PrimaryKey'] === true);
            } elseif (isset($result->fields['Key'])
                && $result->fields['Key'] == 'PRI') {
                $isPrimary = true;
            }

            // Foreign key — driver-specific flag names.
            $isForeignKey = false;
            if ($database->type == 'postgresql') {
                $isForeignKey = ($result->fields['ForeignKey'] == 't'
                    || $result->fields['ForeignKey'] === true);
            } else {
                $isForeignKey = !empty($result->fields['ForeignKey']);
            }

            if ($isForeignKey && !empty($result->fields['ForeignTable'])) {
                // FK array keys match the wizard shape consumed by
                // createControllerAndViewsFromWizard()/createViewsFromWizard():
                // 'column' (this table), 'references' (referenced column),
                // 'on' (referenced table), 'onDelete'/'onUpdate'.
                $foreignKeys[] = [
                    'column'     => $field,
                    'references' => $result->fields['ForeignColumn'] ?? 'id',
                    'on'         => $result->fields['ForeignTable'],
                    'onDelete'   => '',
                    'onUpdate'   => '',
                ];
            }

            // Skip the primary key column: the generator derives it by
            // convention (getSingularPrimaryKey) and it must not be emitted as
            // an editable form field.
            if ($isPrimary) {
                continue;
            }

            $logicalType = $this->mapSqlTypeToLogical(
                (string) $result->fields['Type']
            );

            $nullable = isset($result->fields['Null'])
                && strtoupper((string) $result->fields['Null']) === 'YES';

            $columns[] = [
                'name'     => $field,
                'type'     => $logicalType,
                'options'  => [],
                'nullable' => $nullable,
                'default'  => $result->fields['COLUMN_DEFAULT']
                    ?? ($result->fields['column_default'] ?? null),
                'comment'  => $result->fields['Comment'] ?? '',
                'unique'   => false,
                'unsigned' => $isForeignKey && in_array(
                    $logicalType,
                    ['integer', 'biginteger', 'tinyinteger', 'smallinteger'],
                    true
                ),
            ];
        }

        return [$columns, $foreignKeys];
    }

    /**
     * Map a raw SQL column type (MySQL or PostgreSQL) to the logical type
     * vocabulary used by the migration wizard and the CRUD/view generators.
     *
     * Logical types: string, char, integer, biginteger, tinyinteger,
     * smallinteger, decimal, float, double, boolean, text, longtext, date,
     * datetime, timestamp, json, uuid, binary. Unknown types fall back to
     * 'string'. MySQL's conventional boolean tinyint(1) is detected before the
     * length qualifier is stripped.
     *
     * @param string $rawType Raw type as reported by Database::getColumns()
     * @return string Logical type
     */
    private function mapSqlTypeToLogical(string $rawType): string
    {
        $raw = strtolower(trim($rawType));

        // MySQL convention: tinyint(1) is a boolean.
        if (preg_match('/^tinyint\s*\(\s*1\s*\)/', $raw)) {
            return 'boolean';
        }

        // Strip any length/precision qualifier and collapse whitespace so
        // multi-word PostgreSQL types ("character varying", "timestamp without
        // time zone") normalise cleanly.
        $base = trim(explode('(', $raw)[0]);
        $base = preg_replace('/\s+/', ' ', $base);

        switch ($base) {
            case 'tinyint':
                return 'tinyinteger';
            case 'smallint':
            case 'smallserial':
            case 'int2':
                return 'smallinteger';
            case 'mediumint':
            case 'int':
            case 'integer':
            case 'serial':
            case 'int4':
                return 'integer';
            case 'bigint':
            case 'bigserial':
            case 'int8':
                return 'biginteger';
            case 'bool':
            case 'boolean':
                return 'boolean';
            case 'decimal':
            case 'numeric':
            case 'money':
                return 'decimal';
            case 'float':
            case 'real':
            case 'float4':
                return 'float';
            case 'double':
            case 'double precision':
            case 'float8':
                return 'double';
            case 'char':
            case 'character':
            case 'bpchar':
                return 'char';
            case 'varchar':
            case 'character varying':
            case 'string':
                return 'string';
            case 'tinytext':
            case 'mediumtext':
            case 'text':
                return 'text';
            case 'longtext':
                return 'longtext';
            case 'date':
                return 'date';
            case 'datetime':
                return 'datetime';
            case 'timestamp':
            case 'timestamp without time zone':
            case 'timestamp with time zone':
            case 'timestamptz':
                return 'timestamp';
            case 'json':
            case 'jsonb':
                return 'json';
            case 'uuid':
                return 'uuid';
            case 'binary':
            case 'varbinary':
            case 'blob':
            case 'tinyblob':
            case 'mediumblob':
            case 'longblob':
            case 'bytea':
                return 'binary';
            default:
                return 'string';
        }
    }


    /**
     * Detect which UI libraries are installed in this project.
     *
     * Checks $applicationInfo['scaffold_theme'] and the presence of known
     * library directories under www/assets/vendor/.
     *
     * @return array{theme:string, datatables:bool, select2:bool, bootstrap:bool}
     */
    protected function detectUiSetup(): array
    {
        $application = $this->getApplication()->internalApplication;
        $theme = $application->applicationInfo['scaffold_theme'] ?? 'plain-css';

        // Library availability is driven by the PROJECT'S OWN configuration — the
        // vendor libraries the app registers in App\Application::registerVendorLibraries()
        // (which ran during $application->init() before we got here and is reflected
        // on the Document) — not by probing www/assets/vendor on disk. select2 is not
        // a framework default, so isScriptRegistered() is an accurate config signal
        // for whether the project actually opted into Select2.
        $doc = \Pramnos\Framework\Factory::getDocument();
        $vendorBase = (defined('ROOT') ? ROOT : getcwd()) . '/www/assets/vendor';
        return [
            'theme'      => $theme,
            'datatables' => is_dir($vendorBase . '/datatables'),
            'select2'    => $doc->isScriptRegistered('select2'),
            'bootstrap'  => ($theme === 'bootstrap') || is_dir($vendorBase . '/bootstrap'),
        ];
    }

    /**
     * Generate a full CRUD controller + views from wizard column definitions.
     *
     * This is the schema-first path called from createController() when wizard
     * columns are available. Generates:
     *   - Controller file with display/show/edit/save/delete/data methods
     *   - views/{entity}/ directory with list, edit, and show HTML templates
     *
     * The list view uses DataTables (serverSide) when available, Bootstrap table
     * otherwise. Forms use Select2 for FK fields when available.
     *
     * @return string Summary of created files
     */
    protected function createControllerAndViewsFromWizard(
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
        $date       = date('d/m/Y H:i');
        $viewName   = strtolower($name);
        $primaryKey = $this->getSingularPrimaryKey($tableName);
        $ui         = $this->detectUiSetup();
        $useSelect2 = !empty($ui['select2']);
        // The generated list view uses the framework's server-side DataTable
        // (see display()/data() in the stub): the shell is rendered server-side
        // and rows are streamed over AJAX from the data() action via
        // \Pramnos\Html\Datatable\Datasource::getList(). Bootstrap markup is
        // emitted by the Datatable only when the app scaffold_theme is
        // 'bootstrap'.
        $bootstrapFlag = ($ui['theme'] === 'bootstrap') ? 'true' : 'false';

        // ── Build $saveContent + DataTable columns/fields from wizard columns ──
        $saveContent        = '';
        $loadForeignContent = '';
        $fkMapEntries       = [];
        $fkByColumn         = [];
        foreach ($foreignKeys as $fk) {
            $fkByColumn[$fk['column']] = $fk;
        }
        $firstNonPkField = '';

        // DataTable column chain + the aligned field list for data(). The
        // primary key is fetched first (so data() can build the row links) and
        // is NOT shown as its own column; then one visible column per non-PK
        // column, and finally a non-sortable/non-searchable HTML "Actions"
        // column. $dataFields order mirrors the addColumn() order (PK first).
        $columnCalls = [];
        $dataFields  = ["'" . $primaryKey . "'"];

        foreach ($columns as $col) {
            $colName = $col['name'];
            $colType = $col['type'];
            if (empty($firstNonPkField)) {
                $firstNonPkField = $colName;
            }
            if ($colName !== $primaryKey) {
                $label = (isset($col['comment']) && $col['comment'] !== '')
                    ? $col['comment']
                    : ucwords(str_replace('_', ' ', $colName));
                $columnCalls[] = "addColumn('" . addslashes($label) . "')";
                $dataFields[]  = "'" . $colName . "'";
            }
            if (in_array($colType, ['integer', 'biginteger', 'tinyinteger', 'smallinteger'], true)) {
                $saveContent .= "        \$model->{$colName} = \$request->get('{$colName}', '', 'post', 'int');\n";
            } elseif (in_array($colType, ['float', 'double', 'decimal'], true)) {
                $saveContent .= "        \$model->{$colName} = (float) \$request->get('{$colName}', '', 'post');\n";
            } elseif ($colType === 'boolean') {
                $saveContent .= "        \$model->{$colName} = (bool) \$request->get('{$colName}', '', 'post');\n";
            } else {
                $saveContent .= "        \$model->{$colName} = trim(strip_tags(\$request->get('{$colName}', '', 'post')));\n";
            }

            if (isset($fkByColumn[$colName])) {
                $fk = $fkByColumn[$colName];
                $refTable      = $fk['on'];
                $isUserFk      = ($refTable === 'users' || $refTable === '#PREFIX#users');

                // fkMap entry (column → [related model class, related pk]) feeds
                // the controller's fkOptions() AJAX action, which reuses the
                // related model's _getApiList() to serve Select2 remote options.
                if ($isUserFk) {
                    $fkClass = '\\Pramnos\\User\\User';
                    $fkPk    = 'userid';
                } else {
                    $foreignModel = self::getProperClassName($refTable, true);
                    $fkClass      = '\\' . $modelNameSpace . '\\' . $foreignModel;
                    $fkPk         = $fk['references'] ?? 'id';
                }
                $fkMapEntries[$colName] = [$fkClass, $fkPk];

                if ($useSelect2) {
                    // Select2 loads options over AJAX from fkOptions(); the edit
                    // form only needs the currently-selected option's display text
                    // (the full list is NOT loaded — it would bloat/break for a FK
                    // to a table with thousands of rows).
                    if ($isUserFk) {
                        $loadForeignContent .= "        if (\$model->{$colName}) {\n";
                        $loadForeignContent .= "            \${$colName}Selected = new \\Pramnos\\User\\User(\$model->{$colName});\n";
                        $loadForeignContent .= "            \$view->{$colName}SelectedText = \${$colName}Selected->username ?? \$model->{$colName};\n";
                        $loadForeignContent .= "        }\n";
                    } else {
                        $foreignModel = self::getProperClassName($refTable, true);
                        $loadForeignContent .= "        if (\$model->{$colName}) {\n";
                        $loadForeignContent .= "            \${$colName}Selected = new \\{$modelNameSpace}\\{$foreignModel}(\$this);\n";
                        $loadForeignContent .= "            \${$colName}Selected->load(\$model->{$colName});\n";
                        $loadForeignContent .= "            \$view->{$colName}SelectedText = \${$colName}Selected->name ?? \${$colName}Selected->title ?? \${$colName}Selected->label ?? \$model->{$colName};\n";
                        $loadForeignContent .= "        }\n";
                    }
                } else {
                    // Small-table fallback: eagerly load the full option list for
                    // the native <select>.
                    if ($isUserFk) {
                        $loadForeignContent .= "        \$view->userList = \\Pramnos\\User\\User::getUsers();\n";
                    } else {
                        $foreignModel = self::getProperClassName($refTable, true);
                        $varName      = lcfirst($foreignModel) . 'List';
                        $loadForeignContent .= "        \${$varName} = new \\{$modelNameSpace}\\{$foreignModel}(\$this);\n";
                        $loadForeignContent .= "        \$view->{$varName} = \${$varName}->getList();\n";
                    }
                }
            }
        }

        // ── Build the fkOptions() action + $fkMap literal ─────────────────────
        // Only emitted when the entity actually has foreign keys, so the plain
        // no-FK controller keeps its lean `['show', 'data']` action set.
        $publicActions   = "['show', 'data']";
        $fkOptionsMethod  = '';
        if ($fkMapEntries !== []) {
            $publicActions = "['show', 'data', 'fkOptions']";
            $literalLines  = [];
            foreach ($fkMapEntries as $col => [$fkClass, $fkPk]) {
                $literalLines[] = "            '" . $col . "' => ['"
                    . addslashes($fkClass) . "', '" . addslashes($fkPk) . "'],";
            }
            $fkMapLiteral    = "[\n" . implode("\n", $literalLines) . "\n        ]";
            $fkOptionsMethod = $this->buildFkOptionsMethod($fkMapLiteral);
        }

        if (empty($firstNonPkField)) {
            $firstNonPkField = $primaryKey;
        }

        // Always finish the chain with the non-sortable/non-searchable HTML
        // Actions column and assemble the chain body injected into the stub as
        // `$dt->{{ datatableColumns }};`.
        $columnCalls[]    = "addColumn('Actions', true, false, false, 'html')";
        $datatableColumns = implode("\n           ->", $columnCalls);
        $dataFieldsToken  = implode(', ', $dataFields);

        // ── Controller source ────────────────────────────────────────────────
        // Rendered from scaffolding/templates/crud-controller.stub. The dynamic
        // sub-blocks computed above ($datatableColumns, $dataFieldsToken,
        // $loadForeignContent, $saveContent) are injected as tokens; keep them
        // in sync with the matching {{ token }} placeholders in that stub.
        $fileContent = $this->renderStub('crud-controller', [
            'namespace'          => $namespace,
            'className'          => $className,
            'date'               => $date,
            'viewName'           => $viewName,
            'modelNameSpace'     => $modelNameSpace,
            'modelClass'         => $modelClass,
            'tableName'          => $tableName,
            'bootstrapFlag'      => $bootstrapFlag,
            'datatableColumns'   => $datatableColumns,
            'dataFields'         => $dataFieldsToken,
            'firstNonPkField'    => $firstNonPkField,
            'primaryKey'         => $primaryKey,
            'loadForeignContent' => $loadForeignContent,
            'saveContent'        => $saveContent,
            'publicActions'      => $publicActions,
            'fkOptionsMethod'    => $fkOptionsMethod,
        ]);

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
        if (file_exists($controllerFile)) {
            throw new \Exception('Controller already exists: ' . $controllerFile);
        }
        if (file_put_contents($controllerFile, $fileContent) === false) {
            throw new \Exception('Cannot write controller file.');
        }

        // ── Views ─────────────────────────────────────────────────────────────
        $viewSummary = $this->createViewsFromWizard(
            $name, $columns, $foreignKeys, $primaryKey, $ui
        );

        // Resolve an actual, clickable test URL when the app URL is known (it is
        // after $application->init() in createController), so the developer can
        // hit the new controller immediately; fall back to the sURL expression.
        $testUrl = defined('sURL') && sURL !== ''
            ? rtrim((string) sURL, '/') . '/' . $className
            : "sURL . '{$className}'";

        return "Controller created.\n"
            . "URL: sURL . '{$className}'\n"
            . $viewSummary
            . "\nTest it now: {$testUrl}\n";
    }

    /**
     * Build the controller fkOptions() action source injected as the
     * {{ fkOptionsMethod }} token in crud-controller.stub.
     *
     * The action feeds foreign-key <select> fields (Select2 remote): rather than
     * eagerly rendering every related row into the edit form (which bloats/breaks
     * for a FK to a table with thousands of rows), the <select> loads its options
     * over AJAX from here. It looks the requested field up in the generated
     * $fkMap (field → [related model class, related pk]) and REUSES the related
     * model's _getApiList() — the same search/paging pipeline the rest of the app
     * uses — instead of a bespoke query. Rows are mapped to Select2's
     * {id, text} shape and the envelope's hasnext flag drives infinite scroll.
     *
     * @param string $fkMapLiteral PHP array literal for $fkMap (already indented)
     * @return string PHP source for the fkOptions() method (leading newline)
     */
    protected function buildFkOptionsMethod(string $fkMapLiteral): string
    {
        return <<<PHP

    /**
     * AJAX endpoint feeding foreign-key <select> fields (Select2 remote).
     *
     * Reuses the related model's _getApiList() so FK dropdowns share the app's
     * search/paging pipeline instead of eagerly loading every related row.
     */
    public function fkOptions(): void
    {
        \Pramnos\Framework\Factory::getDocument('json');

        // field => [related model class, related primary key].
        \$fkMap = {$fkMapLiteral};

        \$request = new \Pramnos\Http\Request();
        \$field   = (string) \$request->get('field', '', 'get');
        \$q       = (string) \$request->get('q', '', 'get');
        \$page    = (int) \$request->get('page', 1, 'get', 'int');
        if (\$page < 1) {
            \$page = 1;
        }

        if (\$field === '' || !isset(\$fkMap[\$field])) {
            echo json_encode(['results' => []]);
            \$this->terminate();
            return;
        }

        [\$modelClass, \$pk] = \$fkMap[\$field];

        \$model = new \$modelClass(\$this);
        \$res   = \$model->_getApiList([], \$q, '', '', '', '', null, null, \$page, 20, false, false, true);

        \$results = [];
        foreach ((\$res['data'] ?? []) as \$row) {
            \$results[] = [
                'id'   => \$row[\$pk] ?? null,
                'text' => (string) (\$row['name'] ?? \$row['title'] ?? \$row['label'] ?? \$row['username'] ?? (\$row[\$pk] ?? '')),
            ];
        }

        echo json_encode([
            'results'    => \$results,
            'pagination' => ['more' => (bool) (\$res['pagination']['hasnext'] ?? false)],
        ]);
        \$this->terminate();
    }

PHP;
    }

    /**
     * Generate view HTML templates for a CRUD entity from wizard column definitions.
     *
     * Generates three files inside views/{entity}/:
     *   - {entity}.html.php  (list view)
     *   - edit.html.php      (create/edit form)
     *   - show.html.php      (detail view)
     *
     * The templates adapt to the installed UI libraries (bootstrap, datatables, select2).
     *
     * @return string Summary line
     */
    protected function createViewsFromWizard(
        string $name,
        array  $columns,
        array  $foreignKeys,
        string $primaryKey,
        array  $ui
    ): string {
        $application = $this->getApplication()->internalApplication;
        $application->init();

        $viewBasePath = ROOT . DS . INCLUDES . DS;
        if ($application->appName != '') {
            $viewBasePath .= $application->appName . DS;
        }
        $viewBasePath .= 'Views';
        $viewDir  = $viewBasePath . DS . strtolower($name);
        $className        = self::getProperClassName($name, false);
        $viewName         = strtolower($name);
        $objectName       = ucfirst($name);
        $objectNamePlural = $objectName . 's';

        if (!is_dir($viewDir)) {
            mkdir($viewDir, 0755, true);
        }

        // ── Resolve the per-theme stub set ─────────────────────────────────────
        // detectUiSetup() reports the scaffold theme as 'plain-css' | 'bootstrap'
        // | 'tailwind'. Map it to the crud-view stub suffix; anything unknown
        // falls back to the plain-css set so generation never fails on a theme we
        // don't recognise. The list view is now a thin shell around the
        // controller's server-side \Pramnos\Html\Datatable (rows stream over AJAX
        // from data()), so there is no per-column table markup here any more.
        $themeName  = $ui['theme'] ?? 'plain-css';
        $themeKey   = match ($themeName) {
            'bootstrap' => 'bootstrap',
            'tailwind'  => 'tailwind',
            default     => 'plain',
        };
        $useSelect2 = !empty($ui['select2']);

        $fkByColumn = [];
        foreach ($foreignKeys as $fk) {
            $fkByColumn[$fk['column']] = $fk;
        }

        // ── Edit/create form fields (theme-specific markup) ────────────────────
        // The loop-built fragment mirrors the field wrappers/labels/controls of
        // the framework's admin edit views for the detected theme; it is injected
        // into the edit stub as the {{ formFields }} token.
        $formFields = $this->buildWizardFormFields(
            $columns, $fkByColumn, $primaryKey, $themeKey, $useSelect2, $className
        );

        // ── Render the three per-theme view stubs ──────────────────────────────
        $listContent = $this->renderStub('crud-view-' . $themeKey . '-list', [
            'objectName'       => $objectName,
            'objectNamePlural' => $objectNamePlural,
            'className'        => $className,
        ]);
        $editContent = $this->renderStub('crud-view-' . $themeKey . '-edit', [
            'objectName' => $objectName,
            'className'  => $className,
            'primaryKey' => $primaryKey,
            'formFields' => $formFields,
        ]);
        $showContent = $this->renderStub('crud-view-' . $themeKey . '-show', [
            'objectName' => $objectName,
            'className'  => $className,
            'primaryKey' => $primaryKey,
        ]);

        // ── Write view files ───────────────────────────────────────────────────
        $files = [
            $viewDir . DS . strtolower($name) . '.html.php' => $listContent,
            $viewDir . DS . 'edit.html.php'                  => $editContent,
            $viewDir . DS . 'show.html.php'                  => $showContent,
        ];

        $summary = "Views:\n";
        foreach ($files as $file => $content) {
            file_put_contents($file, $content);
            $summary .= "  - {$file}\n";
        }

        return $summary;
    }

    /**
     * Build the theme-specific form-field fragment for the generated edit view.
     *
     * Emits one field block per non-primary-key column, mirroring the field
     * wrappers, labels and control classes of the framework's admin edit views
     * for the selected theme (plain-css inline styles / Bootstrap form-control /
     * Tailwind utilities). Foreign-key columns become a <select> populated from
     * the controller-provided list variable; booleans a checkbox; text/longtext
     * a textarea; date/datetime a date control; numerics a number input. The
     * primary key is skipped — it travels in the save() URL, not the form body.
     *
     * @param array  $columns     Wizard/introspected column definitions
     * @param array  $fkByColumn  FK definitions keyed by local column name
     * @param string $primaryKey  Primary key column name (skipped)
     * @param string $themeKey    'plain' | 'bootstrap' | 'tailwind'
     * @param bool   $useSelect2  Whether Select2 is installed (adds .select2 + init)
     * @param string $className   Web controller class name (for the Select2 AJAX
     *                            fkOptions URL); defaults to '' for BC.
     * @return string HTML fragment injected as the {{ formFields }} token
     */
    protected function buildWizardFormFields(
        array  $columns,
        array  $fkByColumn,
        string $primaryKey,
        string $themeKey,
        bool   $useSelect2,
        string $className = ''
    ): string {
        $ind = str_repeat(' ', 16);

        // Per-theme markup presets for the field wrapper, label and controls.
        switch ($themeKey) {
            case 'bootstrap':
                $group      = ' class="mb-3"';
                $labelAttr  = ' class="form-label"';
                $inputAttr  = ' class="form-control"';
                $selectAttr = ' class="form-select"';
                $areaAttr   = ' class="form-control"';
                break;
            case 'tailwind':
                $group      = ' class="mb-4"';
                $labelAttr  = ' class="block text-sm font-medium text-gray-700 mb-1"';
                $inputAttr  = ' class="w-full px-3 py-2 border border-gray-300 rounded-sm text-sm"';
                $selectAttr = ' class="w-full px-3 py-2 border border-gray-300 rounded-sm text-sm"';
                $areaAttr   = ' class="w-full px-3 py-2 border border-gray-300 rounded-sm text-sm"';
                break;
            default: // plain-css
                $group      = ' style="margin-bottom:12px"';
                $labelAttr  = ' style="display:block;font-weight:600;margin-bottom:4px"';
                $inputAttr  = ' style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box"';
                $selectAttr = ' style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px"';
                $areaAttr   = ' style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box"';
                break;
        }

        $out = '';
        foreach ($columns as $col) {
            $colName = $col['name'];
            if ($colName === $primaryKey) {
                continue;
            }
            $colType  = $col['type'];
            $display  = (isset($col['comment']) && $col['comment'] !== '')
                ? $col['comment']
                : ucwords(str_replace('_', ' ', $colName));
            $required = empty($col['nullable']) ? ' required' : '';
            $modelVal = '<?php echo htmlspecialchars((string)($this->model->' . $colName . ' ?? \'\')); ?>';

            $out .= $ind . '<div' . $group . '>' . "\n";

            if (isset($fkByColumn[$colName])) {
                // Foreign key → <select>.
                $fk       = $fkByColumn[$colName];
                $refTable = $fk['on'];
                $isUserFk = ($refTable === 'users' || $refTable === '#PREFIX#users');
                if ($useSelect2) {
                    // Select2 remote: load options over AJAX from the controller's
                    // fkOptions() action instead of eagerly rendering every related
                    // row (which bloats/breaks for large tables). Only the
                    // currently-selected option is pre-rendered so the existing
                    // value shows in edit mode; a new/empty record renders just the
                    // placeholder.
                    $selAttr = ($themeKey === 'plain')
                        ? ' class="select2"' . $selectAttr
                        : (string) preg_replace('/class="([^"]*)"/', 'class="$1 select2"', $selectAttr);
                    $out .= $ind . '    <label for="' . $colName . '"' . $labelAttr . '>' . $display . '</label>' . "\n";
                    $out .= $ind . '    <select id="' . $colName . '" name="' . $colName . '"' . $selAttr . $required . '>' . "\n";
                    $out .= $ind . '        <option value="">-- Select ' . $display . ' --</option>' . "\n";
                    $out .= $ind . '        <?php if (!empty($this->model->' . $colName . ')): ?>' . "\n";
                    $out .= $ind . '        <option value="<?php echo $this->model->' . $colName . '; ?>" selected><?php echo htmlspecialchars((string)($this->' . $colName . 'SelectedText ?? $this->model->' . $colName . ')); ?></option>' . "\n";
                    $out .= $ind . '        <?php endif; ?>' . "\n";
                    $out .= $ind . '    </select>' . "\n";
                    $out .= $ind . '    <script>' . "\n";
                    $out .= $ind . '    $(\'#' . $colName . '\').select2({ ajax: { url: \'<?php echo sURL; ?>' . $className . '/fkOptions?field=' . $colName . '\', dataType: \'json\', delay: 250, data: function(params){ return { q: params.term, page: params.page || 1 }; }, processResults: function(data){ return { results: data.results, pagination: data.pagination }; } }, minimumInputLength: 0, width: \'100%\' });' . "\n";
                    $out .= $ind . '    </script>' . "\n";
                } else {
                    // Small-table fallback: native <select> eagerly populated from
                    // the controller-provided list variable.
                    $listVar = $isUserFk
                        ? 'userList'
                        : lcfirst(self::getProperClassName($refTable, true)) . 'List';
                    $out .= $ind . '    <label for="' . $colName . '"' . $labelAttr . '>' . $display . '</label>' . "\n";
                    $out .= $ind . '    <select id="' . $colName . '" name="' . $colName . '"' . $selectAttr . $required . '>' . "\n";
                    $out .= $ind . '        <option value="">-- Select ' . $display . ' --</option>' . "\n";
                    $out .= $ind . '        <?php if (is_array($this->' . $listVar . ')): foreach ($this->' . $listVar . ' as $opt): ?>' . "\n";
                    $out .= $ind . '        <option value="<?php echo $opt[\'id\']; ?>" <?php echo $this->model->' . $colName . ' == $opt[\'id\'] ? \'selected\' : \'\'; ?>><?php echo htmlspecialchars((string)($opt[\'name\'] ?? $opt[\'id\'])); ?></option>' . "\n";
                    $out .= $ind . '        <?php endforeach; endif; ?>' . "\n";
                    $out .= $ind . '    </select>' . "\n";
                }
            } elseif ($colType === 'boolean') {
                // Checkbox — form-check group on Bootstrap, inline label elsewhere.
                $checked = '<?php echo ($this->model->' . $colName . ' ?? 0) ? \'checked\' : \'\'; ?>';
                if ($themeKey === 'bootstrap') {
                    $out .= $ind . '    <div class="form-check">' . "\n";
                    $out .= $ind . '        <input class="form-check-input" type="checkbox" id="' . $colName . '" name="' . $colName . '" value="1" ' . $checked . '>' . "\n";
                    $out .= $ind . '        <label class="form-check-label" for="' . $colName . '">' . $display . '</label>' . "\n";
                    $out .= $ind . '    </div>' . "\n";
                } elseif ($themeKey === 'tailwind') {
                    $out .= $ind . '    <label class="flex items-center gap-2 text-sm text-gray-700">' . "\n";
                    $out .= $ind . '        <input type="checkbox" id="' . $colName . '" name="' . $colName . '" value="1" ' . $checked . '>' . "\n";
                    $out .= $ind . '        ' . $display . "\n";
                    $out .= $ind . '    </label>' . "\n";
                } else {
                    $out .= $ind . '    <label style="display:flex;align-items:center;gap:6px;font-weight:normal">' . "\n";
                    $out .= $ind . '        <input type="checkbox" id="' . $colName . '" name="' . $colName . '" value="1" ' . $checked . '>' . "\n";
                    $out .= $ind . '        ' . $display . "\n";
                    $out .= $ind . '    </label>' . "\n";
                }
            } elseif (in_array($colType, ['text', 'longtext'], true)) {
                $out .= $ind . '    <label for="' . $colName . '"' . $labelAttr . '>' . $display . '</label>' . "\n";
                $out .= $ind . '    <textarea id="' . $colName . '" name="' . $colName . '"' . $areaAttr . ' rows="4"' . $required . '>' . $modelVal . '</textarea>' . "\n";
            } elseif (in_array($colType, ['date', 'datetime', 'timestamp'], true)) {
                $inputType = $colType === 'date' ? 'date' : 'datetime-local';
                $out .= $ind . '    <label for="' . $colName . '"' . $labelAttr . '>' . $display . '</label>' . "\n";
                $out .= $ind . '    <input type="' . $inputType . '" id="' . $colName . '" name="' . $colName . '"' . $inputAttr . ' value="' . $modelVal . '"' . $required . '>' . "\n";
            } else {
                $inputType = in_array($colType, ['integer', 'biginteger', 'tinyinteger', 'smallinteger', 'decimal', 'float', 'double'], true)
                    ? 'number'
                    : 'text';
                $out .= $ind . '    <label for="' . $colName . '"' . $labelAttr . '>' . $display . '</label>' . "\n";
                $out .= $ind . '    <input type="' . $inputType . '" id="' . $colName . '" name="' . $colName . '"' . $inputAttr . ' value="' . $modelVal . '"' . $required . '>' . "\n";
            }

            $out .= $ind . '</div>' . "\n";
        }

        return rtrim($out, "\n");
    }

    /**
     * Build a full model PHP file from wizard column definitions.
     *
     * Used when the table does not yet exist (schema-first workflow). Produces
     * the same structure as when the table IS in the database — typed public
     * properties, $primaryKey, $dbtable, load/save/delete/getData/getApiList.
     *
     * @param string $namespace
     * @param string $className
     * @param string $tableName
     * @param array  $columns       Wizard column definitions
     * @param array  $foreignKeys   Wizard FK definitions
     * @return string PHP source
     */
    public function buildModelFromWizardColumns(
        string $namespace,
        string $className,
        string $tableName,
        array  $columns,
        array  $foreignKeys = []
    ): string {
        $date          = date('d/m/Y H:i');
        $primaryKey    = $this->getSingularPrimaryKey($tableName);
        $arrayFix      = '';
        $foreignFixes  = '';
        $allFields     = [$primaryKey];

        // Map wizard type → PHP type
        $phpTypeMap = [
            'integer'     => 'int',
            'biginteger'  => 'int',
            'tinyinteger' => 'int',
            'smallinteger'=> 'int',
            'decimal'     => 'float',
            'float'       => 'float',
            'double'      => 'float',
            'boolean'     => 'bool',
            'json'        => 'array',
        ];

        // Build FK lookup for cascade nulling
        $fkColumns = [];
        foreach ($foreignKeys as $fk) {
            $fkColumns[$fk['column']] = $fk;
        }

        $props = "    /**\n     * (Primary Key)\n     * @var int\n     */\n    public \${$primaryKey};\n";

        foreach ($columns as $col) {
            $colName  = $col['name'];
            $colType  = $col['type'];
            $phpType  = $phpTypeMap[$colType] ?? 'string';
            $comment  = $col['comment'] ?? '';
            $allFields[] = $colName;

            $props .= "    /**\n";
            if ($comment !== '') {
                $props .= "     * {$comment}\n";
            }
            $props .= "     * @var {$phpType}\n     */\n    public \${$colName};\n";

            switch ($phpType) {
                case 'int':
                    if (isset($fkColumns[$colName]) && ($fkColumns[$colName]['onDelete'] ?? '') === 'SET NULL') {
                        $foreignFixes .= "        if (\$this->{$colName} == 0) {\n";
                        $foreignFixes .= "            \$this->{$colName} = null;\n        }\n";
                    }
                    $arrayFix .= "        if (isset(\$data['{$colName}']) && \$data['{$colName}'] !== null) {\n";
                    $arrayFix .= "            \$data['{$colName}'] = (int) \$this->{$colName};\n        }\n";
                    break;
                case 'float':
                    $arrayFix .= "        if (isset(\$data['{$colName}']) && \$data['{$colName}'] !== null) {\n";
                    $arrayFix .= "            \$data['{$colName}'] = (float) \$this->{$colName};\n        }\n";
                    break;
                case 'bool':
                    $arrayFix .= "        \$data['{$colName}'] = (bool) \$this->{$colName};\n";
                    break;
            }
        }

        $theFieldsTxt = '';
        $last = end($allFields);
        foreach ($allFields as $f) {
            $theFieldsTxt .= "            '{$f}'" . ($f !== $last ? ',' : '') . "\n";
        }

        $controllerName = self::getProperClassName($tableName, false);
        $primaryKeyVal  = "\${$primaryKey}";

        $schemaBlock = $this->schema
            ? "    /** @var string */\n    protected \$_dbschema = '{$this->schema}';\n\n"
            : '';

        return $this->renderStub('crud-model', [
            'namespace'     => $namespace,
            'className'     => $className,
            'date'          => $date,
            'props'         => $props,
            'schemaBlock'   => $schemaBlock,
            'primaryKey'    => $primaryKey,
            'tableName'     => $tableName,
            'primaryKeyVal' => $primaryKeyVal,
            'foreignFixes'  => $foreignFixes,
            'arrayFix'      => $arrayFix,
        ]);
    }

    /**
     * Creates a model.
     *
     * When $wizardColumns is provided (from the migration wizard) the model is
     * generated from those definitions even if the table does not yet exist in
     * the database — no DB round-trip required.
     *
     * @param string $name           Entity name (PascalCase or as entered)
     * @param array  $wizardColumns  Column definitions from runMigrationWizard()
     * @param array  $wizardForeignKeys FK definitions from runMigrationWizard()
     */
    protected function createModel($name, array $wizardColumns = [], array $wizardForeignKeys = [])
    {
        $application = $this->getApplication()->internalApplication;
        $application->init();
        $database = \Pramnos\Database\Database::getInstance();
        if ($this->dbtable != null) {
            $tableName = $this->dbtable;
        } else {
            $tableName = self::getModelTableName($name);
        }

        // Compute namespace/path/className before the table check.
        $path = ROOT . DS . INCLUDES . DS;

        if (isset($application->applicationInfo['namespace'])) {
            $namespace = $application->applicationInfo['namespace'];
        } else {
            $namespace = 'Pramnos';
        }
        if ($application->appName != '') {
            $namespace .= '\\' . $application->appName;
            $path .= $application->appName . DS;
        }
        $namespace .= '\\Models';
        $path .= 'Models';

        $className = self::getProperClassName($name, true);
        $filename  = $path . DS . $className . '.php';

        if (!$database->tableExists($tableName)) {
            // The model is ALWAYS a full CRUD artifact built from crud-model.stub.
            // Without a live table we can only proceed when wizard columns were
            // supplied (schema-first: migration created but not yet run). If
            // neither is available the table must be created first — fail loudly
            // rather than emitting a schema-less skeleton (the simple model
            // template was removed).
            if (empty($wizardColumns)) {
                throw new \Exception(
                    "Table '{$tableName}' not found for {$className}. "
                    . "Create it first with `create:migration`."
                );
            }
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
            if (file_exists($filename)) {
                throw new \Exception('Model already exists: ' . $filename);
            }

            $content = $this->buildModelFromWizardColumns(
                $namespace, $className, $tableName,
                $wizardColumns, $wizardForeignKeys
            );
            if (file_put_contents($filename, $content) === false) {
                throw new \Exception('Cannot write model file.');
            }
            $testLine = $this->buildModelTest(
                $className, $namespace, $wizardColumns,
                $this->getSingularPrimaryKey($tableName), $tableName,
                $wizardForeignKeys, defined('ROOT') ? ROOT : getcwd()
            );
            return "Namespace: {$namespace}\n"
                 . "Class:     {$className}\n"
                 . "File:      {$filename}\n"
                 . $testLine;
        }

        $result = $database->getColumns($tableName, $this->schema);
        
        $isUpdate = false;
        if (class_exists('\\' . $namespace . '\\'. $className)
            && file_exists($filename)) {  
            $isUpdate = true;
            $updateResult = $this->updateModel('\\' . $namespace . '\\'. $className, $result, $filename);
            
            // Check if getApiList method exists, if not, add it
            $fileContents = file_get_contents($filename);
            if (strpos($fileContents, 'function getApiList(') === false) {
                // Find the position just before the last closing brace
                $lastBracePosition = strrpos($fileContents, '}');
                
                if ($lastBracePosition !== false) {
                    $getApiListMethod = "
    /**
     * Get an API-formatted list with pagination, field selection, and search capabilities
     * @param array \$fields Array of field names to include in response. If empty, includes all fields
     * @param string|array \$search Search parameter: if string, performs global search across all fields; if array, performs field-specific searches ['fieldname' => 'search_term']
     * @param string \$order Order by clause (e.g., \"field ASC\" or \"field DESC\")
     * @param int \$page Current page number (1-based, 0 = no pagination)
     * @param int \$itemsPerPage Number of items per page (ignored if \$page = 0)
     * @param bool \$debug Show debug information
     * @param bool \$returnAsModels If true, return objects as models, otherwise return as arrays
     * @param bool \$useGetData If true, use getData() to return data instead of model properties (returning an array)
     * @return array API response with pagination info and data
     */
    public function getApiList(\$fields = array(), \$search = '', 
        \$order = '', \$page = 0, \$itemsPerPage = 10, 
        \$debug = false, \$returnAsModels = false, \$useGetData = true)
    {
        return parent::_getApiList(
            \$fields, \$search, \$order, '', '', '',
            null, null, \$page, \$itemsPerPage, \$debug, \$returnAsModels, \$useGetData
        );
    }

";
                    
                    // Insert the method just before the last closing brace
                    $newFileContents = substr_replace($fileContents, $getApiListMethod, $lastBracePosition, 0);
                    file_put_contents($filename, $newFileContents);
                }
            }
        } elseif (class_exists('\\' . $namespace . '\\'. $className)
            && file_exists($filename)) {  
                throw new \Exception(
                    'Model already exists and cannot be updated'
                );
        }
        if (!file_exists($path)) {
            mkdir($path);
        }


        // Normalise the live table definition into the SAME wizard column /
        // foreign-key array shape and delegate to the shared schema-first
        // generator (crud-model.stub), so the DB-introspection path and the
        // migration-wizard path converge on one code path — mirroring
        // createController()/createControllerAndViewsFromWizard().
        if (!empty($wizardColumns)) {
            $columns     = $wizardColumns;
            $foreignKeys = $wizardForeignKeys;
        } else {
            [$columns, $foreignKeys] = $this->introspectTableAsWizardColumns($tableName);
        }

        $fileContent = $this->buildModelFromWizardColumns(
            $namespace, $className, $tableName, $columns, $foreignKeys
        );

        file_put_contents($filename, $fileContent);

        if (!$isUpdate) {
            // Register model in the registry for easier lookup
            $this->registerModelInRegistry([
                'className' => $className,
                'namespace' => $namespace,
                'fullClassName' => '\\' . $namespace . '\\' . $className,
                'table' => $tableName,
                'schema' => $this->schema ?? '',
                'timestamp' => date('Y-m-d H:i:s'),
                'generatedBy' => 'createModel'
            ]);
        }

        $testLine = '';
        if (!$isUpdate) {
            $testLine = $this->buildModelTest(
                $className, $namespace, $columns,
                $this->getSingularPrimaryKey($tableName), $tableName,
                $foreignKeys, defined('ROOT') ? ROOT : getcwd()
            );
        }

        return "Namespace: {$namespace}\n"
            . "Class:     {$className}\n"
            . "File:      {$filename}\n"
            . $testLine
            . "\n" . ($isUpdate ? "Model updated." : "Model created.");
    }

    /**
     * Register or update model information in the registry JSON file
     * 
     * @param array $modelInfo Information about the model to register
     * @return bool Success status
     */
    protected function registerModelInRegistry(array $modelInfo)
    {
        $registryDir = ROOT . DS . 'app';
        $registryFile = $registryDir . DS . 'model-registry.json';
        
        // Create directory if it doesn't exist
        if (!file_exists($registryDir)) {
            if (!mkdir($registryDir, 0755, true)) {
                return false;
            }
        }
        
        // Load existing registry or create new one
        $registry = [];
        if (file_exists($registryFile)) {
            $fileContents = file_get_contents($registryFile);
            if (!empty($fileContents)) {
                $registry = json_decode($fileContents, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $registry = []; // Reset if JSON was invalid
                }
            }
        }
        
        // Use model's full class name as the key for easy lookup
        $modelKey = $modelInfo['fullClassName'];
        
        // Check if the model already exists in registry
        $existingModelEntry = false;
        foreach ($registry as $index => $entry) {
            if (isset($entry['fullClassName']) && $entry['fullClassName'] === $modelKey) {
                $existingModelEntry = true;
                
                // Update existing entry but preserve creation timestamp if it exists
                if (isset($registry[$index]['createdAt'])) {
                    $modelInfo['createdAt'] = $registry[$index]['createdAt'];
                } else {
                    $modelInfo['createdAt'] = $modelInfo['timestamp'];
                }
                
                $modelInfo['updatedAt'] = $modelInfo['timestamp'];
                $registry[$index] = $modelInfo;
                break;
            }
        }
        
        // Add new entry if it doesn't exist
        if (!$existingModelEntry) {
            $modelInfo['createdAt'] = $modelInfo['timestamp'];
            $modelInfo['updatedAt'] = $modelInfo['timestamp'];
            $registry[] = $modelInfo;
        }
        
        // Write updated registry back to file with pretty formatting
        return file_put_contents(
            $registryFile, 
            json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ) !== false;
    }

    /**
     * Get the fully qualified table name with schema if needed
     * @param string $table Table name
     * @param bool $addSchema Add schema to the table name
     * @return string
     */
    protected function getFullTableName($table, $addSchema = true)
    {
        $database = \Pramnos\Database\Database::getInstance();
        
        if (!$addSchema) {
            return str_replace(
                '#PREFIX#', $database->prefix, $table
            );
        }
        
        // For PostgreSQL with schema defined, prepend the schema
        if ($database->type == 'postgresql' && !empty($this->schema)) {
            return str_replace(
                '#PREFIX#', $database->prefix, $this->schema . '.' . $table
            );
        } elseif ($database->type == 'postgresql' && !empty($database->schema)) {
            return str_replace(
                '#PREFIX#', $database->prefix, $database->schema . '.' . $table
            );
        }
        
        return str_replace(
            '#PREFIX#', $database->prefix, $table
        );
    }


    /**
     * Get proper class name for a model based on naming conventions.
     *
     * @param string $name The input name
     * @param bool $forceSingular Force return in singular form
     * @return string Proper class name
     */
    public static function getProperClassName($name, $forceSingular = true)
    {
        return NamespaceResolver::getProperClassName($name, $forceSingular);
    }

    /**
     * Get model table name from a model name.
     *
     * @param string $name Model name
     * @return string Table name with prefix placeholder
     */
    public static function getModelTableName($name)
    {
        return NamespaceResolver::getModelTableName($name);
    }

}