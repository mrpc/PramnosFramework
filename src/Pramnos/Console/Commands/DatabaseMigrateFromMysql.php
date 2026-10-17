<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Database\Database;
use Pramnos\Database\FromMysql\Migrator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `db:migrate-from-mysql` — move an application's data from MySQL into the PostgreSQL its
 * migrations built.
 *
 * ```bash
 * php bin/pramnos migrate                                                     # the PostgreSQL tables
 * php bin/pramnos db:migrate-from-mysql --source=mysql://u:p@host/app --dry-run
 * php bin/pramnos db:migrate-from-mysql --source=mysql://u:p@host/app --phase=full
 * ```
 *
 * The application is configured for PostgreSQL; the source is named by `--source` or by
 * `'migration_source' => 'mysql://…'` in app.php. See {@see Migrator} for what moves and how.
 */
class DatabaseMigrateFromMysql extends Command
{
    protected function configure(): void
    {
        $this->setName('db:migrate-from-mysql')
            ->setDescription('Move the data from a MySQL database into this PostgreSQL one')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'The MySQL database: mysql://user:password@host:port/name (default: migration_source in app.php)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would move, change nothing')
            ->addOption('phase', null, InputOption::VALUE_REQUIRED, 'full: every table, in one consistent snapshot', 'full')
            ->addOption('tables', null, InputOption::VALUE_REQUIRED, 'Only these source tables, comma-separated')
            ->addOption('exclude-tables', null, InputOption::VALUE_REQUIRED, 'Leave these source tables, comma-separated')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows per INSERT', '5000')
            ->addOption('source-prefix', null, InputOption::VALUE_REQUIRED, 'The source tables\' prefix (default: this application\'s)')
            ->addOption('keep-constraints', null, InputOption::VALUE_NONE, 'Load with the target\'s keys checked, even where they could be off')
            ->addOption('null-orphans', null, InputOption::VALUE_NONE, 'After the load, set references that name no row to NULL where the column takes it');
    }

    /** The application the command runs for; a method, so a test can hand it one. */
    protected function application(): ?\Pramnos\Application\Application
    {
        $console = $this->getApplication();

        return $console !== null && property_exists($console, 'internalApplication') ? $console->internalApplication : \Pramnos\Application\Application::currentInstance();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app    = $this->application();
        $target = $app?->database;
        if (!$target instanceof Database || !in_array(strtolower((string) $target->type), ['postgresql', 'pgsql', 'postgres', 'timescaledb'], true)) {
            $output->writeln('<error>This application is not on PostgreSQL.</error> Configure it for the target database, run <info>migrate</info>, then run this.');

            return Command::FAILURE;
        }
        if ((string) $input->getOption('phase') !== 'full') {
            $output->writeln('<error>Only --phase=full is available.</error> The operational, cutover and backfill phases are not built yet.');

            return Command::FAILURE;
        }

        $dsn = (string) ($input->getOption('source') ?? ($app->applicationInfo['migration_source'] ?? ''));
        try {
            $source = $this->connectSource($dsn, (string) ($input->getOption('source-prefix') ?? $target->prefix));
        } catch (\Throwable $e) {
            $output->writeln('<error>Could not open the MySQL source: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $list     = static fn (mixed $value): array => array_values(array_filter(array_map('trim', explode(',', (string) $value))));
        $migrator = new Migrator($source, $target, $source->prefix, (string) $target->prefix);
        $plan     = $migrator->plan($list($input->getOption('tables')), $list($input->getOption('exclude-tables')));
        $this->reportPlan($output, $plan);
        if ($input->getOption('dry-run')) {
            return Command::SUCCESS;
        }

        $result = $migrator->run(
            $plan,
            (int) $input->getOption('batch-size'),
            !$input->getOption('keep-constraints'),
            (bool) $input->getOption('null-orphans'),
            static function (string $table, int $inserted, int $refused) use ($output): void {
                if ($output->isVerbose()) {
                    $output->writeln('  ' . $table . ': ' . $inserted . ' rows' . ($refused > 0 ? ', ' . $refused . ' refused' : ''));
                }
            }
        );

        return $this->reportResult($output, $result);
    }

    /**
     * A connection to the MySQL source from `mysql://user:password@host:port/name`.
     */
    protected function connectSource(string $dsn, string $prefix): Database
    {
        $parts = parse_url($dsn);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'mysql' || trim((string) ($parts['path'] ?? ''), '/') === '') {
            throw new \InvalidArgumentException('name it as mysql://user:password@host:port/name, with --source or migration_source in app.php');
        }
        $db           = new Database();
        $db->type     = 'mysql';
        $db->server   = (string) ($parts['host'] ?? 'localhost');
        $db->port     = isset($parts['port']) ? (int) $parts['port'] : 3306;
        $db->user     = urldecode((string) ($parts['user'] ?? 'root'));
        $db->password = urldecode((string) ($parts['pass'] ?? ''));
        $db->database = trim((string) $parts['path'], '/');
        $db->prefix   = $prefix;
        if (!$db->connect(false)) {
            throw new \RuntimeException('no connection to ' . $db->server . '/' . $db->database);
        }

        return $db;
    }

    /** @param array<string, mixed> $plan */
    private function reportPlan(OutputInterface $output, array $plan): void
    {
        $output->writeln('<info>' . count($plan['order']) . ' table(s)</info>, in this order. Load with keys off: ' . ($plan['replica'] ? 'yes' : 'no — the target user is not a superuser; rows are loaded parents first'));
        foreach ($plan['order'] as $name) {
            $t = $plan['tables'][$name];
            $output->writeln('  ' . $name . ' → ' . $t['target'] . ($t['create'] !== null ? ' <comment>(created: no migration makes it)</comment>' : '')
                . '  ~' . $t['rows'] . ' rows, ' . count($t['copied']) . ' column(s)');
            if ($t['sourceOnly'] !== []) {
                $output->writeln('      <comment>not moved, the target has no such column:</comment> ' . implode(', ', $t['sourceOnly']));
            }
            if ($t['required'] !== []) {
                $output->writeln('      <error>the target requires, and the source has not:</error> ' . implode(', ', $t['required']) . ' — every row will be refused');
            }
            if ($t['compressed']) {
                $output->writeln('      <error>the target holds compressed chunks:</error> rows into them are refused; decompress first');
            }
        }
        if ($plan['cycles'] !== []) {
            $output->writeln('<comment>These point at each other and load in name order:</comment> ' . implode(', ', $plan['cycles']));
        }
    }

    /** @param array<string, mixed> $result */
    private function reportResult(OutputInterface $output, array $result): int
    {
        $refused = 0;
        foreach ($result['tables'] as $name => $t) {
            $refused += $t['refused'];
            $output->writeln(($t['refused'] > 0 ? '<error>✗</error> ' : '<info>✓</info> ') . $name . ': ' . $t['inserted'] . ' inserted'
                . ($t['refused'] > 0 ? ', ' . $t['refused'] . ' refused' : '')
                . ($t['nulled'] > 0 ? ', ' . $t['nulled'] . ' value(s) that were not what the column takes, as NULL' : ''));
            foreach ($t['errors'] as $error) {
                $output->writeln('      ' . $error);
            }
        }
        foreach ($result['orphans'] as $o) {
            $output->writeln('<comment>' . $o['rows'] . ' row(s) of ' . $o['table'] . '.' . $o['column'] . ' name no ' . $o['references'] . '</comment>'
                . ($o['nulled'] ? ' — set to NULL' : ' — <info>--null-orphans</info> sets them to NULL'));
        }
        $output->writeln(count($result['sequences']) . ' sequence(s) moved past the highest id.');

        return $refused === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
