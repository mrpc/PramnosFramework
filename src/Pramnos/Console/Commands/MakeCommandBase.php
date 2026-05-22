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
        $this->schema = $input->getOption('schema');
        $this->dbtable = $input->getOption('table');
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
            $v = trim((string) $v);
            if ($v === '') throw new \RuntimeException('Description cannot be empty.');
            return $v;
        });
        $description = $helper->ask($input, $output, $q);

        // ── Table name ───────────────────────────────────────────────────────
        $q = new Question(' <info>Table name</info> (use #PREFIX# for the db prefix, e.g. #PREFIX#users): ');
        $q->setValidator(function ($v) {
            $v = trim((string) $v);
            if ($v === '') throw new \RuntimeException('Table name cannot be empty.');
            return $v;
        });
        $tableName = $helper->ask($input, $output, $q);

        // ── Primary key ───────────────────────────────────────────────────────
        $q = new ConfirmationQuestion(
            ' Add auto-increment primary key <info>id</info>? [<comment>yes</comment>] ', true
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
                $output->writeln('');
                $output->writeln(' <comment>─── Additional table ────────────────────────────────────────────────</comment>');
                $q = new Question(' <info>Table name</info> (use #PREFIX# for the db prefix): ');
                $q->setValidator(function ($v) {
                    $v = trim((string) $v);
                    if ($v === '') throw new \RuntimeException('Table name cannot be empty.');
                    return $v;
                });
                $tableName = $helper->ask($input, $output, $q);

                $q = new ConfirmationQuestion(
                    ' Add auto-increment primary key <info>id</info>? [<comment>yes</comment>] ', true
                );
                $hasPk = $helper->ask($input, $output, $q);
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
                    $q = new Question('   Precision (total digits) [<comment>10</comment>]: ', '10');
                    $options['total'] = (int) $helper->ask($input, $output, $q);
                    $q = new Question('   Scale (decimal places) [<comment>2</comment>]: ', '2');
                    $options['places'] = (int) $helper->ask($input, $output, $q);
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
                        $default = null;
                    } else {
                        $default = (string) $rawDefault; // '' or whatever the user typed
                    }
                } else {
                    $q = new Question("   Default value (blank = none): ", null);
                    $rawDefault = $helper->ask($input, $output, $q);
                    $default = ($rawDefault === null || $rawDefault === '') ? null : $rawDefault;
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

                // Column name — autocomplete from columns defined in this table so far
                $definedColNames = array_column($columns, 'name');
                if ($hasPk) {
                    array_unshift($definedColNames,
                        $this->getBlueprintCompiler()->getSingularPrimaryKey($tableName));
                }
                $q = new Question('   Column name (e.g. user_id): ');
                $q->setValidator(fn($v) => trim((string)$v) !== ''
                    ? trim($v)
                    : throw new \RuntimeException('Column name required.'));
                if (!empty($definedColNames)) {
                    $q->setAutocompleterValues($definedColNames);
                }
                $fkCol = $helper->ask($input, $output, $q);

                // References table — autocomplete + validation against known tables
                $q = new Question('   References table: ');
                if (!empty($allTableNames)) {
                    $q->setAutocompleterValues($allTableNames);
                }
                $q->setValidator(function ($v) use ($allTableNames, $dbAvailable, $fkDb) {
                    $v = trim((string) $v);
                    if ($v === '') {
                        throw new \RuntimeException('Table name required.');
                    }
                    // DB was unreachable when we built the list — accept anything non-empty
                    if (!$dbAvailable) {
                        return $v;
                    }
                    // Direct match in combined list (migration tables + DB tables)
                    if (in_array($v, $allTableNames, true)) {
                        return $v;
                    }
                    // Resolve #PREFIX# placeholder and retry
                    if ($fkDb !== null && $fkDb->prefix !== '') {
                        $resolved = str_replace('#PREFIX#', $fkDb->prefix, $v);
                        if (in_array($resolved, $allTableNames, true)) {
                            return $v;
                        }
                    }
                    // Final fallback: ask the DB directly (handles schema differences,
                    // prefixed table names not in the autocomplete list, etc.)
                    if ($fkDb !== null) {
                        try {
                            if ($fkDb->tableExists($v)) {
                                return $v;
                            }
                        } catch (\Throwable $e) {
                            // tableExists() failed — be lenient rather than blocking the user
                            return $v;
                        }
                    }
                    throw new \RuntimeException(
                        "Table '{$v}' not found in the database or this migration. "
                        . "Use Tab to autocomplete from known tables."
                    );
                });
                $fkTable = $helper->ask($input, $output, $q);

                // References column — ChoiceQuestion from known columns, fallback to text
                $refColumns = ($fkDb !== null)
                    ? $this->getColumnsForFKTable(
                        $fkTable, $tables, $tableName, $columns, $hasPk, $fkDb
                      )
                    : [];
                if (!empty($refColumns)) {
                    $defaultIdx = array_search('id', $refColumns);
                    $defaultIdx = $defaultIdx !== false ? $defaultIdx : 0;
                    $q = new ChoiceQuestion(
                        '   References column [<comment>' . $refColumns[$defaultIdx] . '</comment>]: ',
                        $refColumns,
                        $defaultIdx
                    );
                    $fkRef = $helper->ask($input, $output, $q);
                } else {
                    $q = new Question('   References column [<comment>id</comment>]: ', 'id');
                    $fkRef = trim((string) $helper->ask($input, $output, $q)) ?: 'id';
                }

                $q = new ChoiceQuestion(
                    '   On delete [<comment>RESTRICT</comment>]: ',
                    ['RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION'],
                    0
                );
                $fkOnDelete = $helper->ask($input, $output, $q);

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

        // Announce secondary tables if any
        if (count($tables) > 1) {
            $output->writeln('');
            $output->writeln(' <comment>Note: scaffold below targets the first table (' . $tableName . ').</comment>');
            $output->writeln(' <comment>Run create:model / create:controller for additional tables separately.</comment>');
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
            $this->createView($name, true);
            $content .= "OK\n";
        } catch (\Exception $ex) {
            $content .= "FAIL - " . $ex->getMessage() . "\n";
        }
        return $content . "\n";
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

        $files = array();

        $indexContent = 'Hello World';
        $editContent = '';
        $className = self::getProperClassName($name, false);
        $filename = $path . DS . $className . '.php';
        $objectName = ucfirst($name);
        $primaryKey = 'id';

        if ($full) {
            $database = \Pramnos\Database\Database::getInstance();
            $objectName = ucfirst($name);

            // Look up the model in the registry first
            $modelInfo = $this->lookupModel($name, true);
            
            // Get the model class from the lookup
            $modelClass = $modelInfo['className'];
            
            // Determine table name - either from specified option or from model name
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


            $formContent = '';

            $allFields = array();
            $primaryKey = '';
            $count = 0;
            
            // First pass to collect field information
            while ($result->fetch()) {
                $count++;
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
                
                // Store all field names and their display names
                if ($result->fields['Comment'] != '') {
                    $fieldDisplayName = $result->fields['Comment'];
                } else {
                    $fieldDisplayName = ucfirst(str_replace('_', ' ', $result->fields['Field']));
                }
                
                $allFields[] = array(
                    'name' => $result->fields['Field'],
                    'display' => $fieldDisplayName,
                    'isPrimary' => $primary
                );
            }
            
            // Reset result cursor for form generation
            $result = $database->getColumns($tableName, $this->schema);
            
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
                
                if ($result->fields['Comment'] != '') {
                        $fieldName = $result->fields['Comment'];
                } else {
                        $fieldName = ucfirst($result->fields['Field']);
                }
                $field = $result->fields['Field'];

                $basicType = explode('(', $result->fields['Type']);
                if (!$primary) {
                    // Check if this field is a foreign key
                    $isForeignKey = false;
                    if ($database->type == 'postgresql') {
                        $isForeignKey = $result->fields['ForeignKey'] == 't' || $result->fields['ForeignKey'] === true;
                    } else {
                        $isForeignKey = !empty($result->fields['ForeignKey']);
                    }

                    if ($isForeignKey && !empty($result->fields['ForeignTable'])) {
                        // This is a foreign key field
                        $foreignTable = $result->fields['ForeignTable'];
                        $foreignSchema = $result->fields['ForeignSchema'];
                        $foreignColumn = $result->fields['ForeignColumn'];
                        
                        // Special handling for user foreign keys
                        $isUserForeignKey = ($foreignColumn == 'userid' && ($foreignTable == 'users' || $foreignTable == '#PREFIX#users'));
                        
                        if ($isUserForeignKey) {
                            // Use userList variable for user foreign keys
                            $foreignListVar = 'userList';
                        } else {
                            // Get potential model name from foreign table for variable access
                            $foreignModelName = self::getProperClassName($foreignTable, true);
                            $foreignListVar = lcfirst($foreignModelName) . 'List';
                        }
                        if ($isUserForeignKey) {
                            $formContent .= <<<content
            <div class="form-group">
                <label for="{$field}">{$fieldName}:</label>
                <?php if (is_array(\$this->{$foreignListVar}) && count(\$this->{$foreignListVar}) > 0): ?>
                <!-- Foreign key field with available options from {$foreignTable} -->
                <select id="{$field}" name="{$field}" class="form-control">
                    <option value="">Select {$fieldName}</option>
                    <?php foreach (\$this->{$foreignListVar} as \$item): ?>
                        <?php 
                        // Find suitable display field (first non-numeric field)
                        \$selected = \$this->model->{$field} == \$item->{$foreignColumn} ? 'selected' : '';
                        ?>
                        <option value="<?php echo \$item->{$foreignColumn}; ?>" <?php echo \$selected; ?>>
                            <?php echo htmlspecialchars(\$item->username); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <!-- No foreign key data available, fallback to number input -->
                <input type="number" value="<?php echo \$this->model->{$field}; ?>" step="1" id="{$field}" name="{$field}" class="form-control">
                <small class="form-text text-muted">Foreign key to {$foreignTable} table</small>
                <?php endif; ?>
            </div>

content;
                        } else {
                            $formContent .= <<<content
            <div class="form-group">
                <label for="{$field}">{$fieldName}:</label>
                <?php if (is_array(\$this->{$foreignListVar}) && count(\$this->{$foreignListVar}) > 0): ?>
                <!-- Foreign key field with available options from {$foreignTable} -->
                <select id="{$field}" name="{$field}" class="form-control">
                    <option value="">Select {$fieldName}</option>
                    <?php foreach (\$this->{$foreignListVar} as \$item): ?>
                        <?php 
                        // Find suitable display field (first non-numeric field)
                        \$displayField = null;
                        \$itemData = \$item->getData();
                        foreach (\$itemData as \$key => \$value) {
                            // Skip the ID field for display purposes
                            if (\$key != '{$foreignColumn}' && !is_numeric(\$value)) {
                                \$displayField = \$key;
                                break;
                            }
                        }
                        // If no suitable display field found, use the foreign key
                        \$displayField = \$displayField ?: '{$foreignColumn}';
                        \$selected = \$this->model->{$field} == \$item->{$foreignColumn} ? 'selected' : '';
                        ?>
                        <option value="<?php echo \$item->{$foreignColumn}; ?>" <?php echo \$selected; ?>>
                            <?php echo htmlspecialchars(\$item->{\$displayField}); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <!-- No foreign key data available, fallback to number input -->
                <input type="number" value="<?php echo \$this->model->{$field}; ?>" step="1" id="{$field}" name="{$field}" class="form-control">
                <small class="form-text text-muted">Foreign key to {$foreignTable} table</small>
                <?php endif; ?>
            </div>

content;
                        }

                    } else {
                        switch ($basicType[0]) {
                            case "tinyint":
                            case "smallint":
                            case "integer":
                            case "int":
                            case "mediumint":
                            case "bigint":
$formContent .= <<<content
            <div class="form-group">
                <label for="{$field}">{$fieldName}:</label>
                <input type="number" value="<?php echo \$this->model->{$field}; ?>" step="1" id="{$field}" name="{$field}" class="form-control">
            </div>

content;
                                break;

                            case "float":
                            case "double":
$formContent .= <<<content
            <div class="form-group">
                <label for="{$field}">{$fieldName}:</label>
                <input type="number" step="0.0001" value="<?php echo \$this->model->{$field}; ?>" id="{$field}" name="{$field}" class="form-control">
            </div>

content;
                                break;

                            case "bool":
                            case "boolean":
$formContent .= <<<content
            <div class="form-group">
            <label for="{$field}">{$fieldName}:</label>
                <select id="{$field}" name="{$field}" class="form-control">
                    <option <?php if (\$this->model->{$field} == 0): echo 'selected'; endif;?> value="0"><?php l('No');?></option>
                    <option <?php if (\$this->model->{$field} == 1): echo 'selected'; endif;?> value="1"><?php l('Yes');?></option>
                </select>
            </div>

content;
                                break;

                            case "timestamp":
                            case "timestamptz":
                            case "timestamp with time zone":
                            case "timestamp without time zone":
                            case "datetime":
                            case "date":
$formContent .= <<<content
            <div class="form-group">
                <label for="{$field}">{$fieldName}:</label>
                <input type="datetime-local" value="<?php echo \$this->model->{$field} ? date('Y-m-d\\TH:i', strtotime(\$this->model->{$field})) : ''; ?>" id="{$field}" name="{$field}" class="form-control">
            </div>

content;
                                break;

                            case "text":
$formContent .= <<<content
            <div class="form-group">
                <label for="{$field}">{$fieldName}:</label>
                <textarea id="{$field}" name="{$field}" class="form-control"><?php echo \$this->model->{$field}; ?></textarea>
            </div>

content;
                                break;

                            default:
$formContent .= <<<content
            <div class="form-group">
                <label for="{$field}">{$fieldName}:</label>
                <input type="text" value="<?php echo \$this->model->{$field}; ?>" id="{$field}" name="{$field}" class="form-control">
            </div>

content;
                                break;
                        }
                    }
                }
                $formContent .= "\n";
            }

            $editContent = <<<content
<div class="card">
    <div class="card-body">
        <form action="[sURL]{$className}/save/<?php echo \$this->model->{$primaryKey}; ?>" method="post" role="form">

{$formContent}

            <div class="form-group">
                <button type="submit" class="btn btn-primary"><?php l('Save'); ?></button>
            </div>
        </form>

    </div>
</div>
content;

            // Generate datatable columns for all fields
            $datatableColumns = "";
            foreach ($allFields as $field) {
                $displayName = $field['display'];
                $datatableColumns .= "\$datatable->addColumn('{$displayName}', true, true, true, '', '', true, 'left', true);\n";
            }

            $indexContent = <<<content
<div class="card">
    <div class="card-header">
        <h1 class="page-head-line">
            {$objectName} list
        </h1>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-12">
                <a href="<?php echo sURL; ?>{$className}/edit/0"><button type="button" class="btn btn-primary"><i class="fa fa-plus"></i> <?php l('New'); ?></button></a>
            </div>
            <br /><br />
        </div>
<?php
\$datatable = new \Pramnos\Html\Datatable('{$name}', URL . '{$className}/get{$className}');

{$datatableColumns}
\$datatable->addColumn('Ενέργeιες');

\$datatable->jui = false;
\$datatable->bootstrap = true;
echo \$datatable->render();
?>
    </div>
</div>
content;
        }


        $files[] = array (
            'reason' => 'Index File',
            'file' => $viewPath . DS . strtolower($name) . '.html.php',
            'content' => $indexContent
        );
        $files[] = array (
            'reason' => 'Edit Resource',
            'file' => $viewPath . DS . 'edit.html.php',
            'content' => $editContent
        );
        $files[] = array (
            'reason' => 'Show Resource',
            'file' => $viewPath . DS . 'show.html.php',
            'content' => <<<content
<div class="card">
    <div class="card-header">
        <h1 class="page-head-line">
            View {$objectName}
        </h1>
    </div>
    <div class="card-body">
        <div class="row mb-3">
            <div class="col-md-12">
                <div class="btn-group">
                    <a href="[sURL]{$className}" class="btn btn-secondary"><i class="fa fa-arrow-left"></i> Back to List</a>
                    <a href="[sURL]{$className}/edit/<?php echo \$this->model->{$primaryKey}; ?>" class="btn btn-primary"><i class="fa fa-edit"></i> Edit</a>
                    <a onclick="return confirm('<?php l('Are you sure?');?>');" href="[sURL]{$className}/delete/<?php echo \$this->model->{$primaryKey}; ?>" class="btn btn-danger"><i class="fa fa-trash"></i> Delete</a>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <tbody>
                    <?php
                    \$data = \$this->model->getData();
                    foreach (\$data as \$field => \$value):
                        // Convert field name to readable format
                        \$displayName = ucwords(str_replace('_', ' ', \$field));
                    ?>
                        <tr>
                            <th style="width: 30%"><?php echo \$displayName; ?></th>
                            <td>
                                <?php 
                                if (is_bool(\$value)) {
                                    echo \$value ? 'Yes' : 'No';
                                } elseif (\$value === null) {
                                    echo '<span class="text-muted">N/A</span>';
                                } elseif (is_array(\$value) || is_object(\$value)) {
                                    echo '<pre>' . htmlspecialchars(json_encode(\$value, JSON_PRETTY_PRINT)) . '</pre>';
                                } else {
                                    echo htmlspecialchars(\$value);
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
content
        );
        $actualName = ucfirst($name);
        $date = date('d/m/Y H:i');
        $fileContent = <<<content
<?php

/**
 * {$actualName} View
 * REASON
 * Auto generated at: {$date}
 */

defined('SP') or die('No startpoint defined...');
content;
        $fileContent .= "\n?"
            . ">\nCONTENT";
        $return = "Files: \n";
        foreach ($files as $file) {
            $return .= ' - ' . $file['file'] . "\n";
            file_put_contents(
                $file['file'],
                str_replace(
                    array('REASON', 'CONTENT', '[sURL]'),
                    array($file['reason'], $file['content'], '<?php echo sURL;?>'),
                    $fileContent
                )
            );
        }

        return $return . "\nView created.";

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
        $fileContent = <<<content
<?php
namespace {$namespace};

/**
 * {$className} Controller
 * Auto generated at: {$date}
 */
class {$className} extends \Pramnos\Application\Controller
{

    /**
     * {$className} controller constructor
     * @param Application \$application
     */
    public function __construct(?\Pramnos\Application\Application \$application = null)
    {
        parent::__construct(\$application);
    }
    

content;
        
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

            $fileContent .= <<<content
    /**
     * @api {get} 1.0/$modelClassLower List
     * @apiVersion 1.0.0
     * @apiGroup $modelClass
     * @apiName list$modelClass
     * @apiDescription List of $modelClass objects with pagination, search, sorting and field selection
     *
     * @apiHeader {String} apiKey Application unique api key
     * @apiHeader {String} accessToken Authenticated user access token
     *
     * @apiParam  {Number} [page=0] Page number for pagination. Set to 0 to get all results
     * @apiParam  {Number} [limit=20] Limit number of results per page
     * @apiParam  {String} [sort] Sort by field. Syntax: [+-]fieldname,[+-]fieldname. You can use the following fields: $fieldList
     * @apiParam  {String} [search] Global search term. You can use this to search across all fields.
     *                              It also supports input for specific search fields using JSON format,
     *                              like: {\"field1\": \"value1\", \"field2\": \"value2\"}
     * @apiParam  {String} [fields] Specify which fields you want returned by using the fields parameter 
     *                              and listing each field. This overrides the defaults and returns only 
     *                              the fields you specify, and the ID of the object, which is always returned.
     *                              Can be comma-separated string or JSON array: \"field1,field2\" or [\"field1\",\"field2\"]
     *
     * @apiSuccess {Array} data List of $modelClass objects
     * @apiSuccess {Object} [pagination] Pagination information (only when page > 0)
     * @apiSuccess {Number} pagination.currentpage Current page number
     * @apiSuccess {Number} pagination.itemsperpage Items per page
     * @apiSuccess {Number} pagination.totalitems Total number of items
     * @apiSuccess {Number} pagination.totalpages Total number of pages
     * @apiSuccess {Boolean} pagination.hasnext Whether there is a next page
     * @apiSuccess {Boolean} pagination.hasprevious Whether there is a previous page
     * @apiSuccess {Array} fields List of fields included in the response
$returnContent
     * @apiUse InvalidAccessToken
     * @apiUse APIKeyMissing
     * @apiUse APIKeyInvalid
     * @apiUse InternalServerError
     */
    public function display()
    {
        if (!isset(\$_SESSION['user']) || !is_object(\$_SESSION['user'])) {
            return array('status' => 401);
        }
        \$user = \$_SESSION['user'];
        if (\$user->userid < 2) {
            return array('status' => 401);
        }
        
        \$model = new \\{$modelNameSpace}\\$modelClass(\$this);
        
        // Get parameters from request
        \$fields = \Pramnos\Http\Request::staticGet('fields', array(), 'get');
        \$search = \Pramnos\Http\Request::staticGet('search', '', 'get');
        \$sort = \Pramnos\Http\Request::staticGet('sort', '', 'get');
        \$page = (int) \Pramnos\Http\Request::staticGet('page', 0, 'get', 'int');
        \$limit = (int) \Pramnos\Http\Request::staticGet('limit', 20, 'get', 'int');
        
        // Use the new getApiList method for enhanced pagination, search, and field selection
        return \$model->getApiList(
            \$fields, 
            \$search, 
            \$sort, 
            \$page, 
            \$limit,
            false, // debug
            false, // returnAsModels
            false   // useGetData
        );
    }

    /**
     * @api {get} 1.0/$modelClassLower/:$primaryKey Read
     * @apiVersion 1.0.0
     * @apiGroup $modelClass
     * @apiName read$modelClass
     * @apiDescription Read a specific $modelClass object
     *
     * @apiHeader {String} apiKey Application unique api key
     * @apiHeader {String} accessToken Authenticated user access token
     * @apiParam  {Number} $primaryKey Id to load
     *
     * @apiSuccess {{$modelClass}} data A $modelClass object
$returnContent
     * @apiUse InvalidAccessToken
     * @apiUse APIKeyMissing
     * @apiUse APIKeyInvalid
     * @apiUse InternalServerError
     *
     */
    public function read$modelClass(\$$primaryKey)
    {
        if (!isset(\$_SESSION['user']) || !is_object(\$_SESSION['user'])) {
            return array('status' => 401);
        }
        \$user = \$_SESSION['user'];
        if (\$user->userid < 2) {
            return array('status' => 401);
        }
        
        
        \$model = new \\{$modelNameSpace}\\$modelClass(\$this);
        \$model->load(\$$primaryKey);
        if (\$model->$primaryKey == 0) {
            return array(
                'status' => 404
            );
        }
        \$data = \$model->getData();
        return array('data' => \$data);
    }

    /**
     * @api {post} 1.0/$modelClassLower Create
     * @apiVersion 1.0.0
     * @apiGroup $modelClass
     * @apiName create$modelClass
     * @apiDescription Create a $modelClass
     *
     * @apiHeader {String} apiKey Application unique api key
     * @apiHeader {String} accessToken Authenticated user access token
     * 
$saveContent
     *
     * @apiSuccess {{$modelClass}} data A $modelClass object
     * @apiUse InvalidAccessToken
     * @apiUse APIKeyMissing
     * @apiUse APIKeyInvalid
     * @apiUse InternalServerError
     *
     */
    public function create$modelClass()
    {
        if (!isset(\$_SESSION['user']) || !is_object(\$_SESSION['user'])) {
            return array('status' => 401);
        }
        \$user = \$_SESSION['user'];
        
        

        \$model = new \\{$modelNameSpace}\\$modelClass(\$this);

 
$postContent
        

        \$model->save();
        
        return array(
            'status' => 201,
            'data' => \$model->getData()
        );
    }


    /**
     * @api {put} 1.0/$modelClassLower/:$primaryKey Update
     * @apiVersion 1.0.0
     * @apiGroup $modelClass
     * @apiName update$modelClass
     * @apiDescription Update a specific $modelClass object
     *
     * @apiHeader {String} apiKey Application unique api key
     * @apiHeader {String} accessToken Authenticated user access token
     * @apiParam  {Number} $primaryKey Id to update
     * 
     * 
$updateContent
     * @apiSuccess {{$modelClass}} data A $modelClass object
     * 
     * @apiUse InvalidAccessToken
     * @apiUse APIKeyMissing
     * @apiUse APIKeyInvalid
     * @apiUse InternalServerError
     *
     */
    public function update$modelClass(\$$primaryKey)
    {
        if (!isset(\$_SESSION['user']) || !is_object(\$_SESSION['user'])) {
            return array('status' => 401);
        }
        \$user = \$_SESSION['user'];
        
        \$model = new \\{$modelNameSpace}\\$modelClass(\$this);
        \$model->load((int) \$$primaryKey);
        if (\$model->$primaryKey == 0) {
            return array(
                'status' => 404
            );
        }

 
$putContent

        
        \$model->save();
        return array(
            'status' => 202,
            'data' => \$model->getData()
        );
    }

    /**
     * @api {delete} 1.0/$modelClassLower/:$primaryKey Delete
     * @apiVersion 1.0.0
     * @apiGroup $modelClass
     * @apiName delte$modelClass
     * @apiDescription Delete a $modelClass
     *
     * @apiHeader {String} apiKey Application unique api key
     * @apiHeader {String} accessToken Authenticated user access token
     * @apiParam  {Number} $primaryKey Id to delete
     *
     *
     * @apiUse InvalidAccessToken
     * @apiUse APIKeyMissing
     * @apiUse APIKeyInvalid
     * @apiUse InternalServerError
     *
     */
    public function delete$modelClass(\$$primaryKey)
    {
        \$model = new \\{$modelNameSpace}\\$modelClass(\$this);
        \$model->load((int) \$$primaryKey);
        if (\$model->$primaryKey == 0) {
            return array(
                'status' => 404
            );
        }
        \$model->delete(\$$primaryKey);
        return array(
            'status' => 202
        );

    }



}
content;


$routerContent = <<<content
\$router->delete(
    '/$modelClassLower/{{$primaryKey}}',
    function (\$$primaryKey) {
        \$controller = \$this->getController('$className');
        return \$controller->delete$modelClass(\$$primaryKey);
    }
);

\$router->put(
    '/$modelClassLower/{{$primaryKey}}',
    function (\$$primaryKey) {
        \$controller = \$this->getController('$className');
        return \$controller->update$modelClass(\$$primaryKey);
    }
);

\$router->get(
    '/$modelClassLower/{{$primaryKey}}',
    function (\$$primaryKey) {
        \$controller = \$this->getController('$className');
        return \$controller->read$modelClass(\$$primaryKey);
    }
);

\$router->get(
    '/$modelClassLower',
    function () {
        \$controller = \$this->getController('$className');
        return \$controller->display();
    }
);

\$router->post(
    '/$modelClassLower',
    function () {
        \$controller = \$this->getController('$className');
        return \$controller->create$modelClass();
    }
);

content;


      
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
            . "File: {$filename}\n\nController created. \n";
    }

    /**
     * Creates a controller.
     *
     * When $wizardColumns is provided (from the migration wizard) a full CRUD
     * controller and view files are generated from those definitions — no DB
     * round-trip required. The generated views adapt to the app's scaffold_theme
     * and installed libraries (datatables, select2) from assets.json.
     *
     * @param string $name           Entity name
     * @param bool   $full           Generate full CRUD (vs. skeleton)
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
        $date = date('d/m/Y H:i');
        $fileContent = <<<content
<?php
namespace {$namespace};

/**
 * {$className} Controller
 * Auto generated at: {$date}
 */
class {$className} extends \Pramnos\Application\Controller
{

    /**
     * {$className} controller constructor
     * @param Application \$application
     */
    public function __construct(?\Pramnos\Application\Application \$application = null)
    {
        \$this->addAuthAction(
            array('edit', 'save', 'delete', 'show', 'get{$className}')
        );
        parent::__construct(\$application);
    }
    

content;
        if (!$full) {
            // Simple controller skeleton generated from stub — no DB introspection needed
            $viewName    = strtolower($name);
            $fileContent = $this->renderStub('controller', [
                'namespace' => $namespace,
                'class'     => $className,
                'view'      => $viewName,
            ]);

            if (file_put_contents($filename, $fileContent) === false) {
                throw new \Exception('Cannot write controller file.');
            }

            $testLine = $this->generateTestStub(
                $className,
                $namespace,
                defined('ROOT') ? ROOT : getcwd(),
                'controller_test'
            );

            return "Namespace: {$namespace}\n"
                 . "Class:     {$className}\n"
                 . "File:      {$filename}\n"
                 . $testLine
                 . "\nController created.";
        } else {
            $database = \Pramnos\Database\Database::getInstance();
            $viewName = strtolower($name);

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
                $result = $this->createControllerAndViewsFromWizard(
                    $name, $namespace, $modelNameSpace, $modelClass,
                    $className, $tableName, $path,
                    $wizardColumns, $wizardForeignKeys,
                    $filename
                );
                $testLine = $this->generateTestStub(
                    $className, $namespace,
                    defined('ROOT') ? ROOT : getcwd(),
                    'controller_test'
                );
                return "Namespace: {$namespace}\n"
                     . "Class:     {$className}\n"
                     . "File:      {$filename}\n"
                     . $testLine
                     . "\n" . $result;
            }

            if (!$database->tableExists($tableName)) {
                throw new \Exception(
                    'Table: ' . $tableName . ' does not exist.'
                );
            }
            $result = $database->getColumns($tableName, $this->schema);


            $saveContent = '';
            $foreignKeyModels = array();
            $editContent = '';

            $primaryKey = '';
            $firstField = ''; // Initialize firstField variable
            $count = 0;
            while ($result->fetch()) {
                $count++;
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
                
                // Store the second field as the first non-primary field for display
                if ($count == 2 && !$primary) {
                    $firstField = $result->fields['Field'];
                } else if ($count > 2 && empty($firstField) && !$primary) {
                    // If the second field was the primary key, use the next non-primary field
                    $firstField = $result->fields['Field'];
                }
                
                // Check if this is a foreign key field
                $isForeignKey = false;
                if ($database->type == 'postgresql') {
                    $isForeignKey = $result->fields['ForeignKey'] == 't' || $result->fields['ForeignKey'] === true;
                } else {
                    $isForeignKey = !empty($result->fields['ForeignKey']);
                }
                
                // If this is a foreign key, store information to load related models
                if ($isForeignKey && !empty($result->fields['ForeignTable'])) {
                    $foreignTable = $result->fields['ForeignTable'];
                    $foreignSchema = $result->fields['ForeignSchema'];
                    $foreignColumn = $result->fields['ForeignColumn'];
                    
                    // Special handling for user foreign keys
                    $isUserForeignKey = ($foreignColumn == 'userid' && ($foreignTable == 'users' || $foreignTable == '#PREFIX#users'));
                    
                    if (!$isUserForeignKey) {
                        // Get potential model name from foreign table
                        $foreignModelName = self::getProperClassName($foreignTable, true);
                        
                        // Check if foreign model exists
                        $foreignModelClass = "\\{$modelNameSpace}\\{$foreignModelName}";
                        $foreignModelFile = $path . "/../Models/{$foreignModelName}.php";
                        
                        // Store foreign key information
                        $foreignKeyModels[$result->fields['Field']] = [
                            'table' => $foreignTable,
                            'schema' => $foreignSchema,
                            'column' => $foreignColumn,
                            'modelClass' => $foreignModelName,
                            'modelNamespace' => $modelNameSpace,
                            'field' => $result->fields['Field'],
                            'exists' => file_exists($foreignModelFile),
                            'isUserForeignKey' => false
                        ];

                        // Check the model registry for the foreign model
                        $registryFile = ROOT . DS . 'app' . DS . 'model-registry.json';
                        if (file_exists($registryFile)) {
                            $registry = json_decode(file_get_contents($registryFile), true);
                            if (json_last_error() === JSON_ERROR_NONE && is_array($registry)) {
                                foreach ($registry as $model) {
                                    if (isset($model['table']) && $model['table'] === $foreignTable) {
                                        $foreignKeyModels[$result->fields['Field']]['modelClass'] = $model['className'];
                                        $foreignKeyModels[$result->fields['Field']]['modelNamespace'] = $model['namespace'];
                                        $foreignKeyModels[$result->fields['Field']]['exists'] = true;
                                        break;
                                    }
                                }
                            }
                        }
                    } else {
                        // Special handling for user foreign keys
                        $foreignKeyModels[$result->fields['Field']] = [
                            'table' => $foreignTable,
                            'schema' => $foreignSchema,
                            'column' => $foreignColumn,
                            'field' => $result->fields['Field'],
                            'isUserForeignKey' => true
                        ];
                    }
                }
                
                $basicType = explode('(', $result->fields['Type']);
                if (!$primary) {
                    switch ($basicType[0]) {
                        case "tinyint":
                        case "smallint":
                        case "integer":
                        case "int":
                        case "mediumint":
                        case "bigint":
                            $saveContent .= '        $model->'
                                . $result->fields['Field']
                                . ' = $request->get(\''
                                . $result->fields['Field']
                                . '\', \'\', \'post\', \'int\');'
                                . "\n";
                            break;
                        case "float":
                        case "double":
                            $saveContent .= '        $model->'
                                . $result->fields['Field']
                                . ' = (float) $request->get(\''
                                . $result->fields['Field']
                                . '\', \'\', \'post\');'
                                . "\n";
                            break;
                        case "bool":
                        case "boolean":
                            $saveContent .= '        $model->'
                                . $result->fields['Field']
                                . ' = (bool) $request->get(\''
                                . $result->fields['Field']
                                . '\', \'\', \'post\');' . "\n";
                            break;
                        default:
                            $saveContent .= '        $model->'
                                . $result->fields['Field']
                                . ' = trim('
                                . "\n            strip_tags(\n"
                                . '                $request->get(\''
                                . $result->fields['Field']
                                . '\', \'\', \'post\')'
                                . "\n            )"
                                . "\n        );\n";
                            break;
                    }
                }

            }

            // Create code to load related models for foreign keys
            $loadForeignModelsContent = '';
            foreach ($foreignKeyModels as $field => $fkInfo) {
                if (isset($fkInfo['exists']) && $fkInfo['exists']) {
                    $varName = lcfirst($fkInfo['modelClass']) . 'List';
                    $loadForeignModelsContent .= '        // Load ' . $fkInfo['modelClass'] . ' data for foreign key ' . $field . "\n";
                    $loadForeignModelsContent .= '        $' . $varName . ' = new \\' . $fkInfo['modelNamespace'] . '\\' . $fkInfo['modelClass'] . '($this);' . "\n";
                    $loadForeignModelsContent .= '        $view->' . $varName . ' = $' . $varName . '->getList();' . "\n\n";
                } elseif (isset($fkInfo['isUserForeignKey']) && $fkInfo['isUserForeignKey']) {
                    $loadForeignModelsContent .= '        // Load user data for foreign key ' . $field . "\n";
                    $loadForeignModelsContent .= '        $view->userList = \Pramnos\User\User::getUsers();' . "\n\n";
                }
            }

            $fileContent .= <<<content
    /**
     * Display a listing of the resource
     * @return string
     */
    public function display()
    {
        \$view = \$this->getView('{$viewName}');
        \$model = new \\{$modelNameSpace}\\$modelClass(\$this);

        \$view->items = \$model->getList();
        \$this->application->addbreadcrumb('{$className}', sURL . '{$className}');
        \$doc = \Pramnos\Framework\Factory::getDocument();
        \$doc->title = '{$className}';
        return \$view->display();
    }

    /**
     * Display the specified resource
     * @return string
     */
    public function show()
    {
        \$view = \$this->getView('{$viewName}');
        \$model = new \\{$modelNameSpace}\\$modelClass(\$this);
        \$request = new \Pramnos\Http\Request();
        \$model->load(\$request->getOption());
        \$view->addModel(\$model);
        \$this->application->addbreadcrumb('{$className}', sURL . '{$className}');
        \$this->application->addbreadcrumb('View ' . \$model->{$primaryKey}, sURL . '{$className}/show/' . \$model->{$primaryKey});
        \$doc = \Pramnos\Framework\Factory::getDocument();
        \$doc->title = \$model->{$primaryKey} . ' | {$className}';
        return \$view->display('show');
    }

    /**
     * Show the form for creating a new resource or editing an existing one
     * @return string
     */
    public function edit()
    {
        \$view = \$this->getView('{$viewName}');
        \$model = new \\{$modelNameSpace}\\$modelClass(\$this);
        \$request = new \Pramnos\Http\Request();
        \$model->load(\$request->getOption());
        \$view->addModel(\$model);

{$loadForeignModelsContent}
        \$this->application->addbreadcrumb('{$className}', sURL . '{$className}');
        if (\$model->{$primaryKey} > 0) {
            \$this->application->addbreadcrumb('View ' . \$model->{$primaryKey}, sURL . '{$className}/show/' . \$model->{$primaryKey});
            \$this->application->addbreadcrumb('Edit', sURL . '{$className}/edit/' . \$model->{$primaryKey});
        } else {
            \$this->application->addbreadcrumb('Create', sURL . '{$className}/edit/0');
        }
        
        \$doc = \Pramnos\Framework\Factory::getDocument();
        \$doc->title = (\$model->{$primaryKey} > 0 ? 'Edit' : 'Create') . ' | {$className}';
        
        return \$view->display('edit');
    }

    /**
     * Store a newly created or edited resource in storage.
     */
    public function save()
    {
        \$model = new \\{$modelNameSpace}\\$modelClass(\$this);
        \$request = new \Pramnos\Http\Request();
        \$model->load(\$request->getOption());
{$saveContent}
        \$model->save();
        \$this->redirect(sURL . '{$className}');
    }

    /**
     * Remove the specified resource from storage
     */
    public function delete()
    {
        \$model = new \\{$modelNameSpace}\\$modelClass(\$this);
        \$request = new \Pramnos\Http\Request();
        \$model->delete(\$request->getOption());
        \$this->redirect(sURL . '{$className}');
    }

    /**
     * Returns the resource in JSON format
     * @return string
     */
    public function get{$className}()
    {
        \$model = new \\{$modelNameSpace}\\$modelClass(\$this);
        \Pramnos\Framework\Factory::getDocument('json');
        return \$model->getJsonList((bool)\Pramnos\Http\Request::staticGet('multiple', 0, 'int', 'get'));
    }

    /**
     * Get an API-formatted list with pagination, field selection, and search capabilities
     * @param array \$fields Array of field names to include in response. If empty, includes all fields
     * @param string|array \$search Search parameter: if string, performs global search across all fields; if array, performs field-specific searches ['fieldname' => 'search_term']
     * @param string \$order Order by clause (e.g., "field ASC" or "field DESC")
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

}
content;
        }        file_put_contents($filename, $fileContent);

        $testLine = $this->generateTestStub(
            $className,
            $namespace,
            defined('ROOT') ? ROOT : getcwd(),
            'controller_test'
        );

        return "Namespace: {$namespace}\n"
            . "Class: {$className}\n"
            . "File: {$filename}\n"
            . $testLine
            . "\nController created.";
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

        $vendorBase = (defined('ROOT') ? ROOT : getcwd()) . '/www/assets/vendor';
        return [
            'theme'      => $theme,
            'datatables' => is_dir($vendorBase . '/datatables'),
            'select2'    => is_dir($vendorBase . '/select2'),
            'bootstrap'  => ($theme === 'bootstrap') || is_dir($vendorBase . '/bootstrap'),
        ];
    }

    /**
     * Generate a full CRUD controller + views from wizard column definitions.
     *
     * This is the schema-first path called from createController() when wizard
     * columns are available. Generates:
     *   - Controller file with display/show/edit/save/delete/getApiList methods
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

        // ── Build $saveContent from wizard columns ──────────────────────────
        $saveContent        = '';
        $loadForeignContent = '';
        $fkByColumn         = [];
        foreach ($foreignKeys as $fk) {
            $fkByColumn[$fk['column']] = $fk;
        }
        $firstNonPkField = '';

        foreach ($columns as $col) {
            $colName = $col['name'];
            $colType = $col['type'];
            if (empty($firstNonPkField)) {
                $firstNonPkField = $colName;
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

        if (empty($firstNonPkField)) {
            $firstNonPkField = $primaryKey;
        }

        // ── Controller source ────────────────────────────────────────────────
        $fileContent = <<<PHP
<?php
namespace {$namespace};

/**
 * {$className} Controller
 * Auto generated at: {$date}
 */
class {$className} extends \Pramnos\Application\Controller
{

    /**
     * {$className} controller constructor
     */
    public function __construct(?\Pramnos\Application\Application \$application = null)
    {
        \$this->addAuthAction(['edit', 'save', 'delete', 'show']);
        parent::__construct(\$application);
    }

    /**
     * Display a listing of the resource
     */
    public function display(): string
    {
        \$view  = \$this->getView('{$viewName}');
        \$model = new \\{$modelNameSpace}\\{$modelClass}(\$this);
        \$view->items = \$model->getList();
        \$doc = \Pramnos\Framework\Factory::getDocument();
        \$doc->title = '{$className}';
        \$this->application->addbreadcrumb('{$className}', sURL . '{$className}');
        return \$view->display();
    }

    /**
     * Display the specified resource
     */
    public function show(): string
    {
        \$view    = \$this->getView('{$viewName}');
        \$model   = new \\{$modelNameSpace}\\{$modelClass}(\$this);
        \$request = new \Pramnos\Http\Request();
        \$model->load(\$request->getOption());
        \$view->addModel(\$model);
        \$doc = \Pramnos\Framework\Factory::getDocument();
        \$doc->title = \$model->{$firstNonPkField} . ' | {$className}';
        \$this->application->addbreadcrumb('{$className}', sURL . '{$className}');
        \$this->application->addbreadcrumb((string)\$model->{$firstNonPkField}, sURL . '{$className}/show/' . \$model->{$primaryKey});
        return \$view->display('show');
    }

    /**
     * Show the form for creating or editing a resource
     */
    public function edit(): string
    {
        \$view    = \$this->getView('{$viewName}');
        \$model   = new \\{$modelNameSpace}\\{$modelClass}(\$this);
        \$request = new \Pramnos\Http\Request();
        \$model->load(\$request->getOption());
        \$view->addModel(\$model);
{$loadForeignContent}
        \$doc = \Pramnos\Framework\Factory::getDocument();
        \$doc->title = (\$model->{$primaryKey} > 0 ? 'Edit' : 'Create') . ' | {$className}';
        \$this->application->addbreadcrumb('{$className}', sURL . '{$className}');
        if (\$model->{$primaryKey} > 0) {
            \$this->application->addbreadcrumb((string)\$model->{$firstNonPkField}, sURL . '{$className}/show/' . \$model->{$primaryKey});
            \$this->application->addbreadcrumb('Edit', sURL . '{$className}/edit/' . \$model->{$primaryKey});
        } else {
            \$this->application->addbreadcrumb('Create', sURL . '{$className}/edit/0');
        }
        return \$view->display('edit');
    }

    /**
     * Store a newly created or updated resource in storage
     */
    public function save(): void
    {
        \$model   = new \\{$modelNameSpace}\\{$modelClass}(\$this);
        \$request = new \Pramnos\Http\Request();
        \$model->load(\$request->getOption());
{$saveContent}
        \$model->save();
        \$this->redirect(sURL . '{$className}');
    }

    /**
     * Remove the specified resource from storage
     */
    public function delete(): void
    {
        \$model   = new \\{$modelNameSpace}\\{$modelClass}(\$this);
        \$request = new \Pramnos\Http\Request();
        \$model->delete(\$request->getOption());
        \$this->redirect(sURL . '{$className}');
    }

    /**
     * Return an API list (REST format with optional DataTables wrapper)
     */
    public function getApiList(\$fields = [], \$search = '',
        \$order = '', \$page = 0, \$itemsPerPage = 10,
        \$debug = false, \$returnAsModels = false, \$useGetData = true,
        \$format = '')
    {
        \Pramnos\Framework\Factory::getDocument('json');
        \$model = new \\{$modelNameSpace}\\{$modelClass}(\$this);
        return \$model->getApiList(
            \$fields, \$search, \$order, \$page, \$itemsPerPage,
            \$debug, \$returnAsModels, \$useGetData
        );
    }

}
PHP;

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

        return "Controller created.\n" . $viewSummary;
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
        $className = self::getProperClassName($name, false);
        $objectName = ucfirst($name);

        if (!is_dir($viewDir)) {
            mkdir($viewDir, 0755, true);
        }

        $useBootstrap  = $ui['bootstrap'];
        $useDatatables = $ui['datatables'];
        $useSelect2    = $ui['select2'];

        $fkByColumn = [];
        foreach ($foreignKeys as $fk) {
            $fkByColumn[$fk['column']] = $fk;
        }

        // ── List view ─────────────────────────────────────────────────────────
        if ($useDatatables) {
            $tableHeaders = '';
            $tableData    = '';
            foreach ($columns as $col) {
                if ($col['name'] === $primaryKey) continue;
                $display = $col['comment'] ?: ucfirst(str_replace('_', ' ', $col['name']));
                $tableHeaders .= "                        <th>{$display}</th>\n";
            }
            $listContent = <<<HTML
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h1 class="h4 mb-0">{$objectName}</h1>
        <a href="<?php echo sURL;?>{$className}/edit/0" class="btn btn-primary btn-sm">
            <i class="fa fa-plus"></i> Create
        </a>
    </div>
    <div class="card-body">
        <table id="{$viewName}-table" class="table table-striped table-hover w-100"
               data-dt-api="<?php echo sURL;?>{$className}/getApiList?format=datatables">
            <thead>
                <tr>
{$tableHeaders}                        <th>Actions</th>
                </tr>
            </thead>
        </table>
    </div>
</div>
<script>
$(document).ready(function() {
    PramnosDataTable.init('#{$viewName}-table', {
        columns: [
HTML;
            foreach ($columns as $col) {
                if ($col['name'] === $primaryKey) continue;
                $listContent .= "            { data: '{$col['name']}' },\n";
            }
            $listContent .= <<<HTML
            { data: null, orderable: false, render: function(data, type, row) {
                return '<a href="<?php echo sURL;?>{$className}/show/' + row.{$primaryKey} + '" class="btn btn-sm btn-info"><i class="fa fa-eye"></i></a> '
                     + '<a href="<?php echo sURL;?>{$className}/edit/' + row.{$primaryKey} + '" class="btn btn-sm btn-warning"><i class="fa fa-edit"></i></a> '
                     + '<a href="<?php echo sURL;?>{$className}/delete/' + row.{$primaryKey} + '" class="btn btn-sm btn-danger" onclick="return confirm(\'Delete?\')"><i class="fa fa-trash"></i></a>';
            }}
        ]
    });
});
</script>
HTML;
        } elseif ($useBootstrap) {
            $tableHeaders = '';
            foreach ($columns as $col) {
                if ($col['name'] === $primaryKey) continue;
                $display = $col['comment'] ?: ucfirst(str_replace('_', ' ', $col['name']));
                $tableHeaders .= "                <th>{$display}</th>\n";
            }
            $listContent = <<<HTML
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h1 class="h4 mb-0">{$objectName}</h1>
        <a href="<?php echo sURL;?>{$className}/edit/0" class="btn btn-primary btn-sm">
            <i class="fa fa-plus"></i> Create
        </a>
    </div>
    <div class="card-body table-responsive">
        <table class="table table-striped table-hover">
            <thead>
                <tr>
{$tableHeaders}                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty(\$this->items)): foreach (\$this->items as \$item): ?>
                <tr>
HTML;
            foreach ($columns as $col) {
                if ($col['name'] === $primaryKey) continue;
                $listContent .= "                    <td><?php echo htmlspecialchars((string)(\$item['{$col['name']}'] ?? '')); ?></td>\n";
            }
            $listContent .= <<<HTML
                    <td>
                        <a href="<?php echo sURL;?>{$className}/show/<?php echo \$item['{$primaryKey}']; ?>" class="btn btn-info btn-sm"><i class="fa fa-eye"></i></a>
                        <a href="<?php echo sURL;?>{$className}/edit/<?php echo \$item['{$primaryKey}']; ?>" class="btn btn-warning btn-sm"><i class="fa fa-edit"></i></a>
                        <a href="<?php echo sURL;?>{$className}/delete/<?php echo \$item['{$primaryKey}']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete?')"><i class="fa fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="<?php echo count([]) + 2; ?>" class="text-center text-muted">No records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
HTML;
        } else {
            $listContent = <<<HTML
<h1>{$objectName}</h1>
<p><a href="<?php echo sURL;?>{$className}/edit/0">Create New</a></p>
<table border="1" cellpadding="5">
    <thead><tr>
HTML;
            foreach ($columns as $col) {
                if ($col['name'] === $primaryKey) continue;
                $display = $col['comment'] ?: ucfirst(str_replace('_', ' ', $col['name']));
                $listContent .= "        <th>{$display}</th>\n";
            }
            $listContent .= "        <th>Actions</th>\n    </tr></thead>\n    <tbody>\n";
            $listContent .= "    <?php if (!empty(\$this->items)): foreach (\$this->items as \$item): ?>\n    <tr>\n";
            foreach ($columns as $col) {
                if ($col['name'] === $primaryKey) continue;
                $listContent .= "        <td><?php echo htmlspecialchars((string)(\$item['{$col['name']}'] ?? '')); ?></td>\n";
            }
            $listContent .= <<<HTML
        <td>
            <a href="<?php echo sURL;?>{$className}/show/<?php echo \$item['{$primaryKey}']; ?>">View</a> |
            <a href="<?php echo sURL;?>{$className}/edit/<?php echo \$item['{$primaryKey}']; ?>">Edit</a> |
            <a href="<?php echo sURL;?>{$className}/delete/<?php echo \$item['{$primaryKey}']; ?>" onclick="return confirm('Delete?')">Delete</a>
        </td>
    </tr>
    <?php endforeach; else: ?>
    <tr><td colspan="2">No records found.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
HTML;
        }

        // ── Edit/create form view ──────────────────────────────────────────────
        $formFields = '';
        foreach ($columns as $col) {
            $colName  = $col['name'];
            $colType  = $col['type'];
            $display  = $col['comment'] ?: ucfirst(str_replace('_', ' ', $colName));
            $nullable = !empty($col['nullable']);
            $required = $nullable ? '' : ' required';
            $isFk     = isset($fkByColumn[$colName]);

            if ($useBootstrap) {
                $formFields .= "<div class=\"form-group mb-3\">\n";
                $formFields .= "    <label for=\"{$colName}\" class=\"form-label\">{$display}</label>\n";
            } else {
                $formFields .= "<div>\n    <label for=\"{$colName}\">{$display}</label>\n";
            }

            if ($isFk) {
                $fk         = $fkByColumn[$colName];
                $refTable   = $fk['on'];
                $isUserFk   = ($refTable === 'users' || $refTable === '#PREFIX#users');
                $listVar    = $isUserFk ? 'userList' : lcfirst(self::getProperClassName($refTable, true)) . 'List';
                $select2Cls = $useSelect2 ? ' select2' : '';
                $bsCls      = $useBootstrap ? ' form-select' : '';
                $formFields .= "<select id=\"{$colName}\" name=\"{$colName}\" class=\"{$bsCls}{$select2Cls}\"{$required}>\n";
                $formFields .= "    <option value=\"\">-- Select {$display} --</option>\n";
                $formFields .= "    <?php if (is_array(\$this->{$listVar})): foreach (\$this->{$listVar} as \$opt): ?>\n";
                $formFields .= "    <option value=\"<?php echo \$opt['id']; ?>\" <?php echo \$this->model->{$colName} == \$opt['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)(\$opt['name'] ?? \$opt['id'])); ?></option>\n";
                $formFields .= "    <?php endforeach; endif; ?>\n";
                $formFields .= "</select>\n";
                if ($useSelect2) {
                    $formFields .= "<script>\$('#{$colName}').select2();</script>\n";
                }
            } elseif ($colType === 'boolean') {
                $bsCls = $useBootstrap ? ' form-check-input' : '';
                $formFields .= "<input type=\"checkbox\" id=\"{$colName}\" name=\"{$colName}\" value=\"1\" class=\"{$bsCls}\" <?php echo \$this->model->{$colName} ? 'checked' : ''; ?>>\n";
            } elseif (in_array($colType, ['text', 'longtext'], true)) {
                $bsCls = $useBootstrap ? ' form-control' : '';
                $formFields .= "<textarea id=\"{$colName}\" name=\"{$colName}\" class=\"{$bsCls}\" rows=\"4\"{$required}><?php echo htmlspecialchars((string)(\$this->model->{$colName} ?? '')); ?></textarea>\n";
            } elseif (in_array($colType, ['date', 'datetime', 'timestamp'], true)) {
                $inputType = $colType === 'date' ? 'date' : 'datetime-local';
                $bsCls = $useBootstrap ? ' form-control' : '';
                $formFields .= "<input type=\"{$inputType}\" id=\"{$colName}\" name=\"{$colName}\" class=\"{$bsCls}\" value=\"<?php echo htmlspecialchars((string)(\$this->model->{$colName} ?? '')); ?>\"{$required}>\n";
            } else {
                $inputType = in_array($colType, ['integer', 'biginteger', 'tinyinteger', 'smallinteger', 'decimal', 'float', 'double'], true) ? 'number' : 'text';
                $bsCls = $useBootstrap ? ' form-control' : '';
                $formFields .= "<input type=\"{$inputType}\" id=\"{$colName}\" name=\"{$colName}\" class=\"{$bsCls}\" value=\"<?php echo htmlspecialchars((string)(\$this->model->{$colName} ?? '')); ?>\"{$required}>\n";
            }
            $formFields .= "</div>\n";
        }

        $submitCls = $useBootstrap ? ' btn btn-primary' : '';
        $backCls   = $useBootstrap ? ' btn btn-secondary' : '';
        $cardStart = $useBootstrap ? "<div class=\"card\">\n    <div class=\"card-header\"><h1 class=\"h4 mb-0\"><?php echo \$this->model->{$primaryKey} > 0 ? 'Edit' : 'Create'; ?> {$objectName}</h1></div>\n    <div class=\"card-body\">\n" : "<h1><?php echo \$this->model->{$primaryKey} > 0 ? 'Edit' : 'Create'; ?> {$objectName}</h1>\n";
        $cardEnd   = $useBootstrap ? "    </div>\n</div>\n" : '';

        $editContent = <<<HTML
{$cardStart}<form method="post" action="<?php echo sURL;?>{$className}/save/<?php echo \$this->model->{$primaryKey}; ?>">
{$formFields}
    <a href="<?php echo sURL;?>{$className}" class="{$backCls}">Back</a>
    <button type="submit" class="{$submitCls}">Save</button>
</form>
{$cardEnd}
HTML;

        // ── Show (detail) view ────────────────────────────────────────────────
        $editBtn  = $useBootstrap ? "class=\"btn btn-primary\"" : '';
        $delBtn   = $useBootstrap ? "class=\"btn btn-danger\" onclick=\"return confirm('Delete?')\"" : "onclick=\"return confirm('Delete?')\"";
        $backBtn  = $useBootstrap ? "class=\"btn btn-secondary\"" : '';
        $cardS    = $useBootstrap ? "<div class=\"card\">\n    <div class=\"card-header\">" : '';
        $cardH    = $useBootstrap ? "</div>\n    <div class=\"card-body\">" : '';
        $cardE    = $useBootstrap ? "\n    </div>\n</div>" : '';

        $showContent = <<<HTML
{$cardS}<h1 class="h4 mb-0">View {$objectName}</h1>{$cardH}
<div class="mb-3">
    <a href="<?php echo sURL;?>{$className}" {$backBtn}>&laquo; Back</a>
    <a href="<?php echo sURL;?>{$className}/edit/<?php echo \$this->model->{$primaryKey}; ?>" {$editBtn}>Edit</a>
    <a href="<?php echo sURL;?>{$className}/delete/<?php echo \$this->model->{$primaryKey}; ?>" {$delBtn}>Delete</a>
</div>
<table class="<?php echo '{$useBootstrap}' ? 'table table-bordered' : ''; ?>">
    <tbody>
        <?php \$data = \$this->model->getData(); foreach (\$data as \$field => \$value): ?>
        <tr>
            <th><?php echo ucwords(str_replace('_', ' ', htmlspecialchars(\$field))); ?></th>
            <td><?php
                if (is_bool(\$value)) { echo \$value ? 'Yes' : 'No'; }
                elseif (\$value === null) { echo '<em>—</em>'; }
                elseif (is_array(\$value)) { echo '<pre>' . htmlspecialchars(json_encode(\$value, JSON_PRETTY_PRINT)) . '</pre>'; }
                else { echo htmlspecialchars((string)\$value); }
            ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>{$cardE}
HTML;

        // ── Write view files ──────────────────────────────────────────────────
        $viewTemplate = <<<'VPHP'
<?php
/**
 * VIEW_REASON
 * Auto generated
 */
defined('SP') or die('No startpoint defined...');
?>
VIEW_CONTENT
VPHP;

        $files = [
            $viewDir . DS . strtolower($name) . '.html.php' => ['Index / List', $listContent],
            $viewDir . DS . 'edit.html.php'                  => ['Edit / Create Form', $editContent],
            $viewDir . DS . 'show.html.php'                  => ['Show / Detail', $showContent],
        ];

        $summary = "Views:\n";
        foreach ($files as $file => [$reason, $content]) {
            $out = str_replace(
                ['VIEW_REASON', 'VIEW_CONTENT'],
                [$reason, $content],
                $viewTemplate
            );
            file_put_contents($file, $out);
            $summary .= "  - {$file}\n";
        }

        return $summary;
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

        return <<<PHP
<?php
namespace {$namespace};

/**
 * {$className} Model
 * Auto generated at: {$date}
 */
class {$className} extends \Pramnos\Application\Model
{

{$props}
{$schemaBlock}    /**
     * Primary key in database
     * @var string
     */
    protected \$_primaryKey = "{$primaryKey}";

    /**
     * Database table
     * @var string
     */
    protected \$_dbtable = "{$tableName}";

    /**
     * Load from database
     * @param string {$primaryKeyVal} ID to load
     * @param string \$key Primary key on database
     * @param boolean \$debug Show debug information
     * @return \$this
     */
    public function load({$primaryKeyVal}, \$key = null, \$debug = false)
    {
        return parent::_load({$primaryKeyVal}, null, \$key, \$debug);
    }

    /**
     * Save to database
     * @param boolean \$autoGetValues If true, get all values from \$_REQUEST
     * @param boolean \$debug Show debug information
     * @return \$this
     */
    public function save(\$autoGetValues = false, \$debug = false)
    {
{$foreignFixes}
        return parent::_save(null, null, \$autoGetValues, \$debug);
    }

    /**
     * Delete from database
     * @param integer {$primaryKeyVal} ID to delete
     * @return \$this
     */
    public function delete({$primaryKeyVal})
    {
        return parent::_delete({$primaryKeyVal}, null, null);
    }

    /**
     * Return all data as array
     * @return array
     */
    public function getData()
    {
        \$data = parent::getData();
{$arrayFix}
        return \$data;
    }

    /**
     * Return an API-formatted list with pagination, field selection, and search
     * @param array  \$fields       Fields to include (empty = all)
     * @param string \$search       Global search term
     * @param string \$order        ORDER BY clause
     * @param int    \$page         Page number (0 = no pagination)
     * @param int    \$itemsPerPage Items per page
     * @param bool   \$debug        Show debug info
     * @param bool   \$returnAsModels Return model objects instead of arrays
     * @param bool   \$useGetData   Use getData() to extract data
     * @return array
     */
    public function getApiList(\$fields = [], \$search = '',
        \$order = '', \$page = 0, \$itemsPerPage = 10,
        \$debug = false, \$returnAsModels = false, \$useGetData = true)
    {
        return parent::_getApiList(
            \$fields, \$search, \$order, '', '', '',
            null, null, \$page, \$itemsPerPage, \$debug, \$returnAsModels, \$useGetData
        );
    }
}
PHP;
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
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
            if (file_exists($filename)) {
                throw new \Exception('Model already exists: ' . $filename);
            }

            // If wizard columns were provided, generate a full model from them
            // (schema-first: migration created but not yet run).
            if (!empty($wizardColumns)) {
                $content = $this->buildModelFromWizardColumns(
                    $namespace, $className, $tableName,
                    $wizardColumns, $wizardForeignKeys
                );
            } else {
                $content = $this->renderStub('model', [
                    'namespace'  => $namespace,
                    'class'      => $className,
                    'table'      => $tableName,
                    'primaryKey' => $this->getSingularPrimaryKey($tableName),
                ]);
            }
            if (file_put_contents($filename, $content) === false) {
                throw new \Exception('Cannot write model file.');
            }
            $testLine = $this->generateTestStub(
                $className, $namespace, defined('ROOT') ? ROOT : getcwd()
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


        $date = date('d/m/Y H:i');
        $fileContent = <<<content
<?php
namespace {$namespace};

/**
 * {$className} Model
 * Auto generated at: {$date}
 */
class {$className} extends \Pramnos\Application\Model
{


    

content;

        if ($this->schema != '') {
            $fileContent .= <<<content
    /**
     * Database schema
     * @var string
     */
    protected \$_dbschema = '{$this->schema}';

content;
        }

        $arrayFix = '';
        $foreignFixes = '';
        $primaryKey = '';
        $firstNonPrimaryField = '';
        $count = 0;
        
        // First pass - find primary key and first non-primary field
        $result = $database->getColumns($tableName, $this->schema);
        while ($result->fetch()) {
            $count++;
            $isPrimary = false;
            if ($database->type == 'postgresql') {
                if ($result->fields['PrimaryKey'] == 't' || $result->fields['PrimaryKey'] === true) {
                    $primaryKey = $result->fields['Field'];
                    $isPrimary = true;
                }
            } elseif (isset($result->fields['Key']) && $result->fields['Key'] == 'PRI') {
                $primaryKey = $result->fields['Field'];
                $isPrimary = true;
            }
            
            // Get the first non-primary field to use for display
            if (!$isPrimary && empty($firstNonPrimaryField)) {
                $firstNonPrimaryField = $result->fields['Field'];
            }
        }
        
        // If no field was found, use 'name' as a fallback
        if (empty($firstNonPrimaryField)) {
            $firstNonPrimaryField = 'name';
        }
        
        // Get columns again for the second pass since we can't rewind/reset the previous result
        $result = $database->getColumns($tableName, $this->schema);

        // Store all field names for the getJsonList method
        $allFields = array();
        
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
            
            // Store field name for use in getJsonList
            $allFields[] = $result->fields['Field'];
            
            $type = 'string';
            $basicType = explode('(', $result->fields['Type']);
            switch ($basicType[0]) {
                case "tinyint":
                case "smallint":
                case "integer":
                case "int":
                case "mediumint":
                case "bigint":
                    if ($database->type == 'postgresql' && $result->fields['ForeignKey'] == "t") {
                        $foreignFixes .= '        if ($this->' . $result->fields['Field'] . ' == 0) {' . "\n";
                        $foreignFixes .= '            $this->' . $result->fields['Field'] . ' = null;' . "\n";
                        $foreignFixes .= '        }' . "\n";
                    }
                    $type = 'int';
                    $arrayFix .= '        if (isset($data[\'' . $result->fields['Field'] . '\']) &&  $data[\'' . $result->fields['Field'] . '\'] !== null) {' . "\n";
                    $arrayFix .= '            $data[\'' . $result->fields['Field'] . '\'] = (int) $this->' . $result->fields['Field'] . ";\n";
                    $arrayFix .= '        }' . "\n";
                    break;
                case "decimal":
                case "numeric":
                case "float":
                case "double":
                    $type = 'float';
                    $arrayFix .= '        if (isset($data[\'' . $result->fields['Field'] . '\']) &&  $data[\'' . $result->fields['Field'] . '\'] !== null) {' . "\n";
                    $arrayFix .= '            $data[\'' . $result->fields['Field'] . '\'] = (float) $this->' . $result->fields['Field'] . ";\n";
                    $arrayFix .= '        }' . "\n";
                    break;
                case "bool":
                case "boolean":
                    $type = 'bool';
                    $arrayFix .= '        $data[\'' . $result->fields['Field'] . '\'] = (bool) $this->' . $result->fields['Field'] . ";\n";
                    break;
                default: 
                    $type = 'string';
                    break;
            }

            $fileContent .= "    /**\n";
            if ($result->fields['Comment'] != '') {
                $fileContent .= "     * "
                    . $result->fields['Comment']
                    . "\n";
            }
            if ($primary) {
                if ($result->fields['Comment'] != '') {
                    $fileContent .= "     * Primary Key \n";
                } else {
                    $fileContent .= "     * (Primary Key) \n";
                }
            }
            $fileContent .= "     * @var "
                . $type
                . "\n"
                . "     */\n"
                . "    public $"
                . $result->fields['Field']
                . ";\n";
        }
        if ($primaryKey != '') {
            $fileContent .= "    /**\n"
                . "     * Primary key in database\n"
                . "     * @var string\n"
                . "     */\n"
                . '    protected $_primaryKey = "'
                . $primaryKey
                . "\";\n\n";
        }
        $fileContent .= "    /**\n"
            . "     * Database table\n"
            . "     * @var string\n"
            . "     */\n"
            . '    protected $_dbtable = "'
            . $tableName
            . "\";\n\n";

        $primaryKeyVal = '$primaryKey';
        if ($primaryKey != '') {
            $primaryKeyVal = '$' . $primaryKey;
        }

        // Get the controller name here once, before generating the model
        $controllerName = self::getProperClassName($name, false);

        $theFieldsTxt = '';
        $lastField = end($allFields);
        foreach ($allFields as $field) {
            $theFieldsTxt .= '            \'' . $field . '\'';
            if ($field !== $lastField) {
                $theFieldsTxt .= ',';
            }
            $theFieldsTxt .= "\n";
        }


        $fileContent .= <<<content
    /**
     * Load from database
     * @param string {$primaryKeyVal} ID to load
     * @param string \$key Primary key on database
     * @param boolean   \$debug Show debug information
     * @return \$this
     */
    public function load({$primaryKeyVal},
        \$key = NULL, \$debug = false)
    {
        return parent::_load({$primaryKeyVal}, null, \$key, \$debug);
    }

    /**
     * Save to database
     * @param boolean   \$autoGetValues If true, get all values from \$_REQUEST
     * @param boolean   \$debug Show debug information (and die)
     * @return          \$this
     */
    public function save(\$autoGetValues = false, \$debug = false)
    {
$foreignFixes
        return parent::_save(null, null, \$autoGetValues, \$debug);
    }


    /**
     * Delete from database
     * @param integer {$primaryKeyVal} ID to delete
     * @return \$this
     */
    public function delete({$primaryKeyVal})
    {
        return parent::_delete({$primaryKeyVal}, null, null);
    }

    /**
     * Return all data as array
     * @return array
     */
    public function getData()
    {
        \$data = parent::getData();
$arrayFix
        return \$data;
    }

    /**
     * List objects
     * @param string \$filter Filter for where statement in database query
     * @param string \$order Order for database query
     * @return {$className}[]
     */
    public function getList(\$filter = NULL, \$order = NULL)
    {
        return parent::_getList(\$filter, \$order);
    }

    /**
     * Get an API-formatted list with pagination, field selection, and search capabilities
     * @param array \$fields Array of field names to include in response. If empty, includes all fields
     * @param string|array \$search Search parameter: if string, performs global search across all fields; if array, performs field-specific searches ['fieldname' => 'search_term']
     * @param string \$order Order by clause (e.g., "field ASC" or "field DESC")
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

}
content;

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
            $testLine = $this->generateTestStub(
                $className, $namespace, defined('ROOT') ? ROOT : getcwd()
            );
        }

        return "Namespace: {$namespace}\n"
            . "Class:     {$className}\n"
            . "File:      {$filename}\n"
            . $testLine
            . "\n" . ($isUpdate ? "Model updated." : "Model created.");
    }

    /**
     * Add getApiList method to an existing model file
     * @param string $filename Path to the model file
     * @return bool Success status
     */
    protected function addGetApiListMethod($filename)
    {
        $fileContents = file_get_contents($filename);
        
        // Find the position just before the last closing brace
        $lastBracePosition = strrpos($fileContents, '}');
        
        if ($lastBracePosition === false) {
            return false;
        }
        
        $getApiListMethod = <<<'METHOD'

    /**
     * Get an API-formatted list with pagination, field selection, and search capabilities
     * @param array $fields Array of field names to include in response. If empty, includes all fields
     * @param string|array $search Search parameter: if string, performs global search across all fields; if array, performs field-specific searches ['fieldname' => 'search_term']
     * @param string $order Order by clause (e.g., "field ASC" or "field DESC")
     * @param int $page Current page number (1-based, 0 = no pagination)
     * @param int $itemsPerPage Number of items per page (ignored if $page = 0)
     * @param bool $debug Show debug information
     * @param bool $returnAsModels If true, return objects as models, otherwise return as arrays
     * @param bool $useGetData If true, use getData() to return data instead of model properties (returning an array)
     * @return array API response with pagination info and data
     */
    public function getApiList($fields = array(), $search = '', 
        $order = '', $page = 0, $itemsPerPage = 10, 
        $debug = false, $returnAsModels = false, $useGetData = true)
    {
        return parent::_getApiList(
            $fields, $search, $order, '', '', '',
            null, null, $page, $itemsPerPage, $debug, $returnAsModels, $useGetData
        );
    }

METHOD;
        
        // Insert the method just before the last closing brace
        $newFileContents = substr_replace($fileContents, $getApiListMethod, $lastBracePosition, 0);
        
        return file_put_contents($filename, $newFileContents) !== false;
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