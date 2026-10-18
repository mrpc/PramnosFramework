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
 *
 * # a live production move
 * php bin/pramnos db:migrate-from-mysql --source=… --phase=operational --exclude-tables=devicedata,logs
 * php bin/pramnos db:migrate-from-mysql --source=… --phase=cutover --exclude-tables=devicedata,logs
 * php bin/pramnos db:migrate-from-mysql --source=… --phase=backfill --tables=devicedata --time-column=date
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
            ->addOption('phase', null, InputOption::VALUE_REQUIRED, 'full | operational | cutover | backfill | verify', 'full')
            ->addOption('verify', null, InputOption::VALUE_NONE, 'full, cutover: compare every row with MySQL once the phase is done; backfill: digest each window as it is written, not only count it')
            ->addOption('defer-indexes', null, InputOption::VALUE_NONE, 'full, operational: drop the target\'s plain indexes for the load and make them again after')
            ->addOption('no-snapshot', null, InputOption::VALUE_NONE, 'full, operational: read each table a range of keys at a time, no transaction held open on MySQL')
            ->addOption('tables', null, InputOption::VALUE_REQUIRED, 'Only these source tables, comma-separated')
            ->addOption('exclude-tables', null, InputOption::VALUE_REQUIRED, 'Leave these source tables, comma-separated')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows per INSERT', '5000')
            ->addOption('batch-mb', null, InputOption::VALUE_REQUIRED, 'Most megabytes of values per batch, whatever the row count', '32')
            ->addOption('source-timezone', null, InputOption::VALUE_REQUIRED, 'The zone the source\'s DATETIME values were written in (default: this application\'s)')
            ->addOption('repair-encoding', null, InputOption::VALUE_REQUIRED, 'Repair double-encoded text (UTF-8 stored through latin1) in these columns, table.column, comma-separated')
            ->addOption('zero-date', null, InputOption::VALUE_REQUIRED, 'What MySQL\'s zero date becomes in a column that takes no NULL, e.g. "1970-01-01 00:00:00" (default: NULL, and the row is refused)')
            ->addOption('source-prefix', null, InputOption::VALUE_REQUIRED, 'The source tables\' prefix (default: this application\'s)')
            ->addOption('keep-constraints', null, InputOption::VALUE_NONE, 'Load with the target\'s keys checked, even where they could be off')
            ->addOption('null-orphans', null, InputOption::VALUE_NONE, 'After the load, set references that name no row to NULL where the column takes it')
            ->addOption('changed-column', null, InputOption::VALUE_REQUIRED, 'cutover: the column a changed row is told by, where a table has it (default: updated_at and the like)')
            ->addOption('with-deletes', null, InputOption::VALUE_NONE, 'cutover: delete here the rows deleted in MySQL since the operational copy')
            ->addOption('append-only', null, InputOption::VALUE_REQUIRED, 'cutover: tables without a change column read only for new rows, not compared, comma-separated')
            ->addOption('time-column', null, InputOption::VALUE_REQUIRED, 'backfill: the column windows are cut on')
            ->addOption('chunk-interval', null, InputOption::VALUE_REQUIRED, 'backfill: each window\'s length', '1 month')
            ->addOption('throttle-ms', null, InputOption::VALUE_REQUIRED, 'backfill: a pause after each batch', '50')
            ->addOption('compress-after-chunk', null, InputOption::VALUE_NONE, 'backfill: compress each window\'s chunks once it is done')
            ->addOption('until', null, InputOption::VALUE_REQUIRED, 'backfill: where the newest window ends (default: the cutover\'s moment)')
            ->addOption('restart', null, InputOption::VALUE_NONE, 'backfill: forget the windows done and start again; operational: copy again the tables an earlier run finished')
            ->addOption('transport', null, InputOption::VALUE_REQUIRED, 'php: rows through PHP; fdw: through mysql_fdw on the PostgreSQL server, where it is installed', 'php')
            ->addOption('fdw-host', null, InputOption::VALUE_REQUIRED, 'fdw: the MySQL host as the PostgreSQL server reaches it (default: the --source host)');
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
        $phase = (string) $input->getOption('phase');
        if (!in_array($phase, ['full', 'operational', 'cutover', 'backfill', 'verify'], true)) {
            $output->writeln('<error>No phase ' . $phase . ':</error> full, operational, cutover, backfill or verify.');

            return Command::FAILURE;
        }
        if ($phase === 'backfill' && ((string) $input->getOption('time-column') === '' || (string) $input->getOption('tables') === '')) {
            $output->writeln('<error>A backfill names its tables and the column its windows are cut on:</error> --tables=… --time-column=….');

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
        if ($input->getOption('source-timezone') !== null) {
            try {
                $migrator->sourceTimezone((string) $input->getOption('source-timezone'));
            } catch (\Throwable) {
                $output->writeln('<error>No time zone ' . $input->getOption('source-timezone') . ':</error> name one as Europe/Athens or UTC.');

                return Command::FAILURE;
            }
        }
        try {
            $migrator->zeroDate($input->getOption('zero-date') !== null ? (string) $input->getOption('zero-date') : null);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $migrator->batchBytes(max(1, (int) $input->getOption('batch-mb')) * 1024 * 1024);
        $migrator->repairEncoding($list($input->getOption('repair-encoding')));
        $plan     = $migrator->plan($list($input->getOption('tables')), $list($input->getOption('exclude-tables')),
            $input->getOption('time-column') !== null ? (string) $input->getOption('time-column') : null);
        $this->reportPlan($output, $plan);
        if ($input->getOption('dry-run')) {
            return Command::SUCCESS;
        }

        if ((string) $input->getOption('transport') === 'fdw') {
            $fdw = \Pramnos\Database\FromMysql\FdwTransport::open($target, $source, $input->getOption('fdw-host') !== null ? (string) $input->getOption('fdw-host') : null);
            if ($fdw === null) {
                $output->writeln('<error>The PostgreSQL server has no mysql_fdw.</error> Install it there, or move the rows through PHP (--transport=php).');

                return Command::FAILURE;
            }
            $migrator->useFdw($fdw);
            try {
                return $this->move($input, $output, $migrator, $plan, $phase, $list);
            } finally {
                $fdw->close();
            }
        }

        return $this->move($input, $output, $migrator, $plan, $phase, $list);
    }

    /**
     * Run the phase and report it.
     *
     * @param array<string, mixed> $plan
     */
    private function move(InputInterface $input, OutputInterface $output, Migrator $migrator, array $plan, string $phase, callable $list): int
    {
        $progress = static function (string $table, int $inserted, int $refused) use ($output): void {
            if ($output->isVerbose()) {
                $output->writeln('  ' . $table . ': ' . $inserted . ' rows' . ($refused > 0 ? ', ' . $refused . ' refused' : ''));
            }
        };
        $batch = (int) $input->getOption('batch-size');
        if ($phase === 'verify') {
            $until = $input->getOption('until') !== null ? strtotime((string) $input->getOption('until')) : null;
            try {
                $verified = $migrator->verify($plan, $batch, $input->getOption('time-column') !== null ? (string) $input->getOption('time-column') : null,
                    (string) $input->getOption('chunk-interval'), $until === false ? null : $until);
            } catch (\InvalidArgumentException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');

                return Command::FAILURE;
            }

            return $this->reportVerify($output, $verified);
        }
        if ($phase === 'backfill') {
            $until = $input->getOption('until') !== null ? strtotime((string) $input->getOption('until')) : null;
            try {
                $result = $migrator->backfill($plan, (string) $input->getOption('time-column'), (string) $input->getOption('chunk-interval'), $batch,
                    (int) $input->getOption('throttle-ms'), (bool) $input->getOption('compress-after-chunk'), (bool) $input->getOption('restart'),
                    $until === false ? null : $until, $progress, (bool) $input->getOption('verify'));
            } catch (\InvalidArgumentException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');

                return Command::FAILURE;
            }

            return $this->reportBackfill($output, $result);
        }
        if ($phase === 'cutover') {
            $result = $migrator->cutover($plan, $batch, $list($input->getOption('changed-column')), (bool) $input->getOption('with-deletes'),
                !$input->getOption('keep-constraints'), (bool) $input->getOption('null-orphans'), $progress, $list($input->getOption('append-only')));
            foreach ($result['tables'] as $name => $t) {
                if ($t['how'] !== null && $output->isVerbose()) {
                    $output->writeln($name . ': ' . $t['how']);
                }
                foreach (array_filter([$t['unseen'], $t['unchecked']]) as $note) {
                    $output->writeln('<comment>' . $name . ': ' . $note . '</comment>');
                }
                if ($t['deleted'] > 0) {
                    $output->writeln($name . ': ' . $t['deleted'] . ' row(s) deleted since the copy, deleted here too');
                }
            }
            $code = $this->reportResult($output, $result);
        } else {
            $result = $migrator->run($plan, $batch, !$input->getOption('keep-constraints'), (bool) $input->getOption('null-orphans'), $progress, $phase === 'operational',
                !$input->getOption('no-snapshot'), (bool) $input->getOption('restart'), (bool) $input->getOption('defer-indexes'));
            if ($phase === 'operational') {
                $output->writeln('The moment of the copy is recorded. Stop the application\'s writes, then run <info>--phase=cutover</info>.');
            }
            $code = $this->reportResult($output, $result);
        }
        if ($input->getOption('verify') && in_array($phase, ['full', 'cutover'], true)) {
            $verified = $this->reportVerify($output, $migrator->verify($plan, $batch));
            $code     = $code === Command::SUCCESS ? $verified : $code;
        }

        return $code;
    }

    /** @param array<string, array<string, mixed>> $result */
    private function reportVerify(OutputInterface $output, array $result): int
    {
        $output->writeln('<info>Compared with MySQL:</info>');
        $failed = false;
        foreach ($result as $name => $t) {
            $off    = $t['source'] !== $t['target'] || $t['missing'] + $t['different'] + $t['extra'] > 0 || ($t['mismatched'] ?? []) !== [];
            $failed = $failed || $off;
            $output->writeln(($off ? '<error>✗</error> ' : '<info>✓</info> ') . $name . ': ' . $t['source'] . ' row(s) in MySQL, ' . $t['target'] . ' here'
                . ($t['missing'] > 0 ? ', ' . $t['missing'] . ' missing' : '')
                . ($t['different'] > 0 ? ', ' . $t['different'] . ' different' : '')
                . ($t['extra'] > 0 ? ', ' . $t['extra'] . ' MySQL has not' : '')
                . ($t['keys'] !== [] ? ' — e.g. key ' . implode('; ', $t['keys']) : ''));
            if (isset($t['windows'])) {
                $output->writeln('      ' . $t['windows'] . ' window(s) compared' . (($t['mismatched'] ?? []) === [] ? ', all equal' : ''));
                foreach (array_slice($t['mismatched'], 0, 10) as $window) {
                    $output->writeln('      <error>window ' . $window . '</error>');
                }
            }
            if ($t['unchecked'] !== null) {
                $output->writeln('      <comment>' . $t['unchecked'] . '</comment>');
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
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
            if ($t['unfinished'] ?? false) {
                $output->writeln('      <comment>made by an earlier run that stopped:</comment> its indexes and keys are made now');
            }
            if ($t['finish']['statements'] !== []) {
                $output->writeln('      then ' . count($t['finish']['statements']) . ' index(es) and foreign key(s), as MySQL has them');
            }
            if ($t['finish']['skipped'] !== []) {
                $output->writeln('      <comment>not made, PostgreSQL has no plain equivalent:</comment> ' . implode(', ', $t['finish']['skipped']));
            }
            if ($t['sourceOnly'] !== []) {
                $output->writeln('      <comment>not moved, the target has no such column:</comment> ' . implode(', ', $t['sourceOnly']));
            }
            if (($t['computed'] ?? []) !== []) {
                $output->writeln('      not moved, the target computes them: ' . implode(', ', $t['computed']));
            }
            if ($t['required'] !== []) {
                $output->writeln('      <error>the target requires, and the source has not:</error> ' . implode(', ', $t['required']) . ' — every row will be refused');
            }
            foreach ($t['encoding'] ?? [] as $e) {
                if ($e['charset'] !== null) {
                    $output->writeln('      <comment>' . $e['column'] . ' is ' . $e['charset'] . ' in MySQL:</comment> read as UTF-8, converted by MySQL');
                }
                if ($e['suspect'] > 0) {
                    $output->writeln('      <error>' . $e['column'] . ': ' . $e['suspect'] . ' of ' . $e['sampled'] . ' sampled value(s) look double-encoded</error> — "'
                        . mb_strimwidth((string) $e['example'], 0, 40, '…') . '" would be "' . mb_strimwidth((string) $e['repaired'], 0, 40, '…') . '"; --repair-encoding=' . $name . '.' . $e['column'] . ' repairs them');
                }
            }
            if (($t['timeIndexed'] ?? null) === false) {
                $column = (string) $plan['timeColumn'];
                $output->writeln('      <error>' . $column . ' has no index in MySQL that starts with it:</error> every window reads the whole table — '
                    . 'CREATE INDEX ' . $name . '_' . $column . ' ON `' . $name . '` (`' . $column . '`) before the backfill');
            }
            foreach ($t['caseless'] ?? [] as $c) {
                $output->writeln('      <comment>' . $c['column'] . ($c['unique'] ? ' is unique' : ' is looked up') . ' without regard to case in MySQL (' . $c['collation'] . '):</comment> '
                    . 'here \'A\' and \'a\' are two values' . ($c['unique'] ? ', both allowed' : '') . ', and = matches one — citext, or lower() in the index and the query, keeps MySQL\'s answer');
            }
            if ($t['compressed']) {
                $output->writeln('      <error>the target holds compressed chunks:</error> rows into them are refused; decompress first');
            }
        }
        $zone = $plan['zone'];
        if ($zone['columns'] !== []) {
            $output->writeln('DATETIME values going into timestamptz are read as <info>' . $zone['name'] . '</info> time: ' . implode(', ', $zone['columns']));
            if ($zone['offset'] !== $zone['serverOffset']) {
                $output->writeln('<comment>The MySQL server runs at UTC' . self::offset($zone['serverOffset']) . ', ' . $zone['name'] . ' is at UTC' . self::offset($zone['offset'])
                    . ':</comment> if the application wrote them in the server\'s time, name that zone with --source-timezone.');
            }
        }
        if (($plan['objects'] ?? []) !== []) {
            $output->writeln('<comment>Not moved — MySQL logic the migrations are to make again:</comment>');
            foreach ($plan['objects'] as $o) {
                $output->writeln('  ' . $o['kind'] . ' ' . $o['name'] . ($o['on'] !== null ? ' (on ' . $o['on'] . ')' : '')
                    . ($o['there'] ? ' — the target has one of that name' : ' — <error>the target has none</error>'));
            }
        }
        if ($plan['cycles'] !== []) {
            $output->writeln('<comment>These point at each other and load in name order:</comment> ' . implode(', ', $plan['cycles']));
        }
    }

    /** An offset in minutes as `+03:00`. */
    private static function offset(int $minutes): string
    {
        return ($minutes < 0 ? '-' : '+') . sprintf('%02d:%02d', intdiv(abs($minutes), 60), abs($minutes) % 60);
    }

    /** @param array<string, array<string, mixed>> $result */
    private function reportBackfill(OutputInterface $output, array $result): int
    {
        $failed = false;
        foreach ($result as $name => $t) {
            if ($t['error'] !== null) {
                $output->writeln('<error>✗</error> ' . $name . ': ' . $t['error']);
                $failed = true;
                continue;
            }
            $failed = $failed || $t['refused'] > 0 || ($t['mismatched'] ?? []) !== [];
            $output->writeln(($t['refused'] > 0 ? '<error>✗</error> ' : '<info>✓</info> ') . $name . ': ' . $t['windows'] . ' window(s), '
                . $t['inserted'] . ' inserted' . ($t['refused'] > 0 ? ', ' . $t['refused'] . ' refused' : '')
                . ($t['skipped'] > 0 ? ', ' . $t['skipped'] . ' window(s) done before' : '')
                . ($t['fdw'] > 0 ? ', ' . $t['fdw'] . ' through mysql_fdw' : '')
                . ($t['compressed'] > 0 ? ', ' . $t['compressed'] . ' chunk(s) compressed' : '')
                . (($t['policyRestored'] ?? false) ? ', compression policy put back' : ''));
            foreach ($t['errors'] as $error) {
                $output->writeln('      ' . $error);
            }
            foreach ($t['mismatched'] ?? [] as $window) {
                $output->writeln('      <error>window ' . $window . '</error> — run it again with --restart, or compare it with --phase=verify');
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /** @param array<string, mixed> $result */
    private function reportResult(OutputInterface $output, array $result): int
    {
        $refused = 0;
        foreach ($result['tables'] as $name => $t) {
            $refused += $t['refused'];
            $output->writeln(($t['refused'] > 0 ? '<error>✗</error> ' : '<info>✓</info> ') . $name . ': ' . $t['inserted'] . ' inserted'
                . (($t['transport'] ?? 'php') === 'fdw' ? ' (mysql_fdw)' : '')
                . ($t['refused'] > 0 ? ', ' . $t['refused'] . ' refused' : '')
                . ($t['nulled'] > 0 ? ', ' . $t['nulled'] . ' value(s) that were not what the column takes, as NULL' : '')
                . (($t['repaired'] ?? 0) > 0 ? ', ' . $t['repaired'] . ' double-encoded value(s) repaired' : '')
                . (($t['zeroDates'] ?? 0) > 0 ? ', ' . $t['zeroDates'] . ' zero date(s) replaced (--zero-date)' : '')
                . (($t['nulBytes'] ?? 0) > 0 ? ', ' . $t['nulBytes'] . ' value(s) with NUL bytes, dropped from the text' : '')
                . (isset($t['resumed']) ? ' — ' . $t['resumed'] : ''));
            foreach ($t['errors'] as $error) {
                $output->writeln('      ' . $error);
            }
        }
        foreach ($result['deferred'] ?? [] as $name => $d) {
            $output->writeln($name . ': ' . $d['made'] . ' index(es) set aside for the load, made again');
            foreach ($d['errors'] as $error) {
                $output->writeln('<error>' . $name . ': ' . $error . '</error> — the definition is kept; the next run tries again');
            }
        }
        foreach ($result['finished'] ?? [] as $name => $f) {
            foreach ($f['unchecked'] as $validate) {
                $output->writeln('<comment>' . $name . ': a foreign key its rows break is kept NOT VALID</comment> — once they are dealt with: ' . $validate);
            }
            foreach ($f['errors'] as $error) {
                $output->writeln('<error>' . $name . ': ' . $error . '</error>');
            }
        }
        foreach ($result['orphans'] as $o) {
            $output->writeln('<comment>' . $o['rows'] . ' row(s) of ' . $o['table'] . '.' . $o['column'] . ' name no ' . $o['references'] . '</comment>'
                . match (true) {
                    $o['nulled']    => ' — set to NULL',
                    !$o['nullable'] => ' — the column takes no NULL: delete them, or add what they name',
                    default         => ' — <info>--null-orphans</info> sets them to NULL',
                });
        }
        $output->writeln(count($result['sequences']) . ' sequence(s) moved past the highest id.');

        return $refused === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
