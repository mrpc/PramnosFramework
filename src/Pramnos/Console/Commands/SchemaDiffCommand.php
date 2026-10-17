<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;
use Pramnos\Database\MigrationRunner;
use Pramnos\Database\SchemaDiff;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `schema:diff` — how this installation's tables differ from the ones the framework would make.
 *
 * It builds the reference rather than storing one: the framework's migrations, for the features
 * this application has on, run into an empty database on the same server, with the same prefix,
 * so the two are described by the same engine. Then the two are compared — columns, indexes by
 * the columns they cover, foreign keys — and the temporary database is dropped.
 *
 * ```bash
 * php bin/pramnos schema:diff                       # report; exit 1 while differences remain
 * php bin/pramnos schema:diff --apply               # add the missing columns and indexes
 * php bin/pramnos schema:diff --reference=ref_db    # an existing, empty reference database
 * ```
 *
 * An installation whose tables predate the framework had migrations that found each table
 * already there and returned, so its tables kept their own shape and nothing said so. Intentional
 * differences go in `'schema' => ['allow' => ['users.column.mobile.*', …]]`.
 */
class SchemaDiffCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('schema:diff')
            ->setDescription('Compare the database with the shape the framework\'s migrations would make')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Add what is missing: columns and indexes, as the framework declares them. Nothing is changed or removed.')
            ->addOption('reference', null, InputOption::VALUE_REQUIRED, 'An empty database to build the reference in, for a user that may not create one')
            ->addOption('keep', null, InputOption::VALUE_NONE, 'Keep the reference database instead of dropping it')
            ->addOption('fresh', null, InputOption::VALUE_NONE, 'Empty the --reference database first (every table, view and schema in it)')
            ->addOption('honour-exclude', null, InputOption::VALUE_NONE, 'Leave migrations.exclude out of the reference, as migrate does')
            ->addOption('allow', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A difference to allow (a glob over keys), besides schema.allow in app.php')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the differences as JSON');
    }

    /** The application the command runs for; a method, so a test can hand it one. */
    protected function application(): ?\Pramnos\Application\Application
    {
        $console = $this->getApplication();

        return $console !== null && property_exists($console, 'internalApplication') ? $console->internalApplication : \Pramnos\Application\Application::currentInstance();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app  = $this->application();
        $live = $app?->database;
        if (!$live instanceof Database) {
            $output->writeln('<error>No database connection available.</error>');

            return Command::FAILURE;
        }

        $name      = (string) ($input->getOption('reference') ?? '');
        $temporary = $name === '';
        $name      = $temporary ? substr($live->database, 0, 40) . '_pfref_' . bin2hex(random_bytes(3)) : $name;
        try {
            if ($temporary) {
                $this->createDatabase($live, $name);
            }
            $reference = $this->connectTo($live, $name);
        } catch (\Throwable $e) {
            $output->writeln('<error>Could not ' . ($temporary ? 'create' : 'open') . ' the reference database ' . $name . ': ' . $e->getMessage() . '</error>');
            $output->writeln(' A user that may not create databases names an empty one with <info>--reference=</info>.');

            return Command::FAILURE;
        }

        // A named reference has to be empty: an interrupted run, or a reference built last week,
        // left tables that every migration then finds and returns on.
        if (!$temporary && $this->tableCount($reference) > 0) {
            if (!$input->getOption('fresh')) {
                $reference->close();
                $output->writeln('<error>The reference database ' . $name . ' is not empty.</error> <info>--fresh</info> empties it first — every table, view and schema in it.');

                return Command::FAILURE;
            }
            $this->emptyDatabase($reference);
        }
        $this->ensureExtensions($live, $reference);

        // What the migrations will need, asked before running them: a user granted CREATE on a
        // database it made is not always granted ALTER or INDEX in it, and a reference half built
        // is a list of differences that are not there.
        $missing = $this->missingPrivilege($reference);
        if ($missing !== null) {
            $reference->close();
            if ($temporary) {
                $this->dropDatabase($live, $name);
            }
            $output->writeln('<error>The reference database cannot be built: ' . $missing . '.</error>');
            $output->writeln(' Grant the user CREATE, ALTER, INDEX, DROP and REFERENCES on it, or name one it has them on with <info>--reference=</info>.');

            return Command::FAILURE;
        }

        $allow = array_merge((array) ($app->applicationInfo['schema']['allow'] ?? []), (array) $input->getOption('allow'));
        try {
            $failed = $this->buildReference($app, $live, $reference, (bool) $input->getOption('honour-exclude'));
            foreach ($failed as $slug => $error) {
                $output->writeln('<comment>Reference: ' . $slug . ' failed — ' . strtok(trim($error), "\n") . '</comment>');
            }
            $shape = SchemaDiff::describe($reference, true);
            $found = SchemaDiff::compare($shape, SchemaDiff::describe($live), $allow);

            if ($input->getOption('apply')) {
                foreach (SchemaDiff::apply($live, $shape, $found) as $step) {
                    $output->writeln(($step['error'] === null ? '<info>Added:</info>  ' : '<error>Failed:</error> ') . $step['key']
                        . ($step['error'] === null ? '' : ' — ' . $step['error']));
                }
                $found = SchemaDiff::compare($shape, SchemaDiff::describe($live), $allow);
            }
        } finally {
            $reference->close();
            if ($temporary && !$input->getOption('keep')) {
                $this->dropDatabase($live, $name);
            } elseif ($temporary) {
                $output->writeln('Reference kept in <info>' . $name . '</info>.');
            }
        }

        $differences = array_values(array_filter($found, static fn (array $d): bool => $d['severity'] === SchemaDiff::DIFFERENCE));
        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($found, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $differences === [] ? Command::SUCCESS : Command::FAILURE;
        }

        $this->report($output, $found);
        // An allowance that matches nothing any more — the difference was fixed, or the key was
        // mistyped and never matched — is one to remove before it hides the next one.
        foreach ($allow as $glob) {
            $used = false;
            foreach ($found as $d) {
                $used = $used || fnmatch((string) $glob, $d['key']);
            }
            if (!$used) {
                $output->writeln('<comment>Allowed, but matches nothing: ' . $glob . '</comment>');
            }
        }

        return $differences === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Run the framework's migrations into the reference database, as a fresh installation of
     * this application would: its features, its exclusions, its prefix.
     *
     * The framework's database singleton points at the reference while they run, so nothing a
     * migration reaches through it lands in the installation's database.
     *
     * @return array<string, string> The migrations that failed, with why
     */
    protected function buildReference(\Pramnos\Application\Application $app, Database $live, Database $reference, bool $honourExclude = false): array
    {
        $copy           = clone $app;
        $copy->database = $reference;
        $scope          = MigrationLoader::scopeFor($app, true);
        $migrations     = array_values(array_filter(
            MigrationLoader::loadFromDirectories($scope['dirs'], $copy),
            static fn ($m): bool => $m->scope === 'framework'
        ));

        $singleton = &\Pramnos\Framework\Factory::getDatabase();
        $saved     = $singleton;
        $singleton = $reference;
        try {
            // The excluded ones too, by default. On an installation that adopted its tables they
            // are the CREATEs of exactly those tables: left out, the tables are missing from the
            // reference, never compared, and every migration that alters them fails there.
            $options = $honourExclude && $scope['exclude'] !== [] ? [MigrationRunner::OPTION_EXCLUDE => $scope['exclude']] : [];
            $runner  = new MigrationRunner($reference);
            // A migration may depend on another feature's, as `migrate` allows — loaded through
            // the copy, so they too write into the reference.
            if (method_exists($copy, 'frameworkMigrationPool')) {
                $runner->setDependencyPool(fn (): array => $copy->frameworkMigrationPool());
            }
            $result = $runner->run($migrations, $options);
        } finally {
            $singleton = $saved;
        }

        return (array) ($result['failed'] ?? []);
    }

    /** An empty database on the live one's server, in utf8mb4 on MySQL as the framework's tables are. */
    protected function createDatabase(Database $live, string $name): void
    {
        $postgres = in_array(strtolower((string) $live->type), ['postgresql', 'pgsql', 'postgres', 'timescaledb'], true);
        $live->query($postgres
            ? 'CREATE DATABASE "' . str_replace('"', '""', $name) . '"'
            : 'CREATE DATABASE `' . str_replace('`', '``', $name) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    /** A connection to `$name` with the live connection's credentials and prefix. */
    protected function connectTo(Database $live, string $name): Database
    {
        $db           = new Database();
        $db->type     = $live->type;
        $db->server   = $live->server;
        $db->port     = $live->port;
        $db->user     = $live->user;
        $db->password = $live->password;
        $db->prefix   = $live->prefix;
        $db->database = $name;
        if (!$db->connect(false)) {
            throw new \RuntimeException('no connection');
        }

        return $db;
    }

    /** The live database's extensions in the reference, so a migration that needs TimescaleDB finds it. */
    protected function ensureExtensions(Database $live, Database $reference): void
    {
        if (!self::postgres($live)) {
            return;
        }
        $extensions = $live->query("SELECT extname FROM pg_extension WHERE extname IN ('timescaledb', 'pgcrypto', 'uuid-ossp')");
        while ($extensions && $extensions->fetch()) {
            $reference->query('CREATE EXTENSION IF NOT EXISTS "' . $extensions->fields['extname'] . '"');
        }
    }

    /** How many tables a database holds, outside the system's own. */
    protected function tableCount(Database $db): int
    {
        $result = $db->query(self::postgres($db)
            ? "SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog', 'information_schema') AND table_schema NOT LIKE '\\_timescaledb%' AND table_schema NOT LIKE 'timescaledb%'"
            : 'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');

        return $result && $result->numRows ? (int) $result->fields['n'] : 0;
    }

    /**
     * Everything in a database: its tables and views on MySQL, its schemas on PostgreSQL —
     * `public` emptied and made again. Only for a reference named with `--reference --fresh`.
     */
    protected function emptyDatabase(Database $db): void
    {
        if (self::postgres($db)) {
            $schemas = $db->query("SELECT nspname FROM pg_namespace WHERE nspname NOT IN ('pg_catalog', 'information_schema', 'pg_toast', 'public')"
                . " AND nspname NOT LIKE 'pg\\_%' AND nspname NOT LIKE '\\_timescaledb%' AND nspname NOT LIKE 'timescaledb%' AND nspname NOT LIKE 'toolkit%'");
            $names = [];
            while ($schemas && $schemas->fetch()) {
                $names[] = (string) $schemas->fields['nspname'];
            }
            foreach ($names as $schema) {
                $db->query('DROP SCHEMA "' . str_replace('"', '""', $schema) . '" CASCADE');
            }
            $db->query('DROP SCHEMA public CASCADE');
            $db->query('CREATE SCHEMA public');

            return;
        }
        $objects = $db->query('SELECT TABLE_NAME AS t, TABLE_TYPE AS k FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        $drops   = [];
        while ($objects && $objects->fetch()) {
            $drops[] = ($objects->fields['k'] === 'VIEW' ? 'DROP VIEW IF EXISTS `' : 'DROP TABLE IF EXISTS `') . str_replace('`', '``', (string) $objects->fields['t']) . '`';
        }
        $db->query('SET foreign_key_checks = 0');
        try {
            foreach ($drops as $drop) {
                $db->query($drop);
            }
        } finally {
            $db->query('SET foreign_key_checks = 1');
        }
    }

    /**
     * What the user cannot do in the reference that the migrations will — or null when it can
     * do all of it. Tried on a table of its own, removed after.
     */
    protected function missingPrivilege(Database $db): ?string
    {
        $q     = self::postgres($db) ? '"' : '`';
        $table = $q . 'pf_schema_diff_probe' . $q;
        $steps = [
            'CREATE TABLE'  => 'CREATE TABLE ' . $table . ' (id INT PRIMARY KEY)',
            'ALTER TABLE'   => 'ALTER TABLE ' . $table . ' ADD COLUMN extra INT',
            'CREATE INDEX'  => 'CREATE INDEX pf_schema_diff_probe_extra ON ' . $table . ' (extra)',
            'DROP TABLE'    => 'DROP TABLE ' . $table,
        ];
        foreach ($steps as $what => $sql) {
            try {
                $ok = $db->query($sql) !== false;
            } catch (\Throwable) {
                $ok = false;
            }
            if (!$ok) {
                try {
                    $db->query('DROP TABLE IF EXISTS ' . $table);
                } catch (\Throwable) {
                    // Nothing to tidy: the step that failed is the answer.
                }

                return 'the user may not ' . $what;
            }
        }

        return null;
    }

    private static function postgres(Database $db): bool
    {
        return in_array(strtolower((string) $db->type), ['postgresql', 'pgsql', 'postgres', 'timescaledb'], true);
    }

    protected function dropDatabase(Database $live, string $name): void
    {
        $postgres = in_array(strtolower((string) $live->type), ['postgresql', 'pgsql', 'postgres', 'timescaledb'], true);
        $live->query($postgres
            ? 'DROP DATABASE IF EXISTS "' . str_replace('"', '""', $name) . '" WITH (FORCE)'
            : 'DROP DATABASE IF EXISTS `' . str_replace('`', '``', $name) . '`');
    }

    /**
     * The differences by table: what to resolve, what is allowed, and — with -v — what the
     * installation has beyond the framework.
     *
     * @param list<array<string, mixed>> $found
     */
    private function report(OutputInterface $output, array $found): void
    {
        $shown = static fn (mixed $value): string => $value === null ? '—' : (is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value);
        $count = [SchemaDiff::DIFFERENCE => 0, SchemaDiff::ALLOWED => 0, SchemaDiff::INFO => 0];
        $table = '';
        foreach ($found as $d) {
            $count[$d['severity']]++;
            if ($d['severity'] !== SchemaDiff::DIFFERENCE && !$output->isVerbose()) {
                continue;
            }
            if ($d['table'] !== $table) {
                $table = $d['table'];
                $output->writeln('');
                $output->writeln('<info>' . $table . '</info>');
            }
            $mark = ['difference' => '<error>✗</error>', 'allowed' => '<comment>~</comment>', 'info' => ' ·'][$d['severity']];
            $output->writeln('  ' . $mark . ' ' . $d['kind'] . ' ' . $d['name'] . ' ' . $d['aspect']
                . ($d['aspect'] === 'missing' || $d['aspect'] === 'extra' ? '' : '  framework: ' . $shown($d['reference']) . '  here: ' . $shown($d['live']))
                . '   <comment>' . $d['key'] . '</comment>');
        }
        $output->writeln('');
        $output->writeln($count[SchemaDiff::DIFFERENCE] === 0
            ? '<info>The tables match the framework\'s.</info>'
            : '<error>' . $count[SchemaDiff::DIFFERENCE] . ' difference(s).</error> <info>--apply</info> adds the missing columns and indexes; allow the intentional ones in schema.allow.');
        $output->writeln($count[SchemaDiff::ALLOWED] . ' allowed, ' . $count[SchemaDiff::INFO] . ' beyond the framework\'s shape' . ($output->isVerbose() ? '.' : ' (-v lists them).'));
    }
}
