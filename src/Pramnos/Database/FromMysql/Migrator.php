<?php

declare(strict_types=1);

namespace Pramnos\Database\FromMysql;

use Pramnos\Database\Database;

/**
 * Moving an application's data from MySQL into the PostgreSQL its migrations built.
 *
 * ```php
 * $migrator = new Migrator($mysql, $postgres);
 * $plan     = $migrator->plan();                  // what would move, and what would not
 * $result   = $migrator->run($plan, batch: 5000); // move it
 * ```
 *
 * The PostgreSQL side is the target as its migrations made it — typed better than MySQL, often:
 * a flag that is a `boolean`, a unix time that is a `timestamptz`, a schema per area
 * (`authserver.x` for MySQL's `authserver_x`). Each row is read from MySQL in one consistent
 * snapshot, streamed, converted to what each target column takes ({@see TypeMapper}) and inserted
 * in batches with `ON CONFLICT DO NOTHING`, so a second run adds only what the first did not. A
 * table MySQL has and PostgreSQL does not is created from its MySQL definition.
 *
 * Tables load parents first ({@see TopologicalSorter}); where the target allows it the load runs
 * with `session_replication_role = replica`, keys unchecked as the rows arrive, and the orphans
 * that leaves are counted afterwards. Sequences are moved past the highest id last.
 *
 * Catalogue introspection throughout, and row transfer through the drivers' own calls: neither is
 * something the query builder reaches.
 */
final class Migrator
{
    /** PostgreSQL schemas that hold no application tables. */
    private const SYSTEM_SCHEMAS = "'pg_catalog', 'information_schema', 'pg_toast', 'timescaledb_information', 'timescaledb_experimental'";

    /** Most bind parameters one statement takes, kept under PostgreSQL's 65,535. */
    private const MAX_PARAMETERS = 60000;

    /** @var array<string, array<string, mixed>>|null The source's tables, read once */
    private ?array $sourceShape = null;

    private ?ProgressTracker $tracker = null;

    private ?FdwTransport $fdw = null;

    /** The zone the source's `datetime` values were written in. */
    private string $zone;

    /** @var array<string, array<string, true>> Columns whose double-encoded values are repaired, by table */
    private array $repair = [];

    /** @var array<string, list<string>> A target table's `GENERATED ALWAYS` identity columns, read once */
    private array $always = [];

    /** Rows of each table read to look for double-encoded text. */
    private const ENCODING_SAMPLE = 2000;

    /** The source server's own offset from UTC, in minutes, before the session was set to UTC. */
    private int $serverOffset;

    /** Move rows through `mysql_fdw` where it can; null moves them all through PHP. */
    public function useFdw(?FdwTransport $fdw): void
    {
        $this->fdw = $fdw;
    }

    public function __construct(
        private Database $source,
        private Database $target,
        private ?string $sourcePrefix = null,
        private ?string $targetPrefix = null,
    ) {
        $this->sourcePrefix ??= (string) $source->prefix;
        $this->targetPrefix ??= (string) $target->prefix;
        $this->zone           = date_default_timezone_get();
        // The source is read in UTC, as mysql_fdw reads it: a `timestamp` then arrives as the
        // moment it is, whatever the server's zone.
        $mysqli             = $source->getConnectionLink();
        // Named, not left to the client library's default: a connection in `latin1` would hand
        // over every Greek letter as two characters of nonsense, and nothing would refuse it.
        $mysqli->set_charset('utf8mb4');
        $this->serverOffset = (int) ($mysqli->query('SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS o')->fetch_assoc()['o'] ?? 0);
        $mysqli->query("SET time_zone = '+00:00'");
    }

    /**
     * Columns, as `table.column`, whose double-encoded values are repaired on the way —
     * {@see TypeMapper::repairEncoding()}, value by value. Their tables move through PHP.
     *
     * @param list<string> $columns
     */
    public function repairEncoding(array $columns): void
    {
        $this->repair = [];
        foreach ($columns as $column) {
            [$table, $name] = array_pad(explode('.', $column, 2), 2, '');
            if ($name !== '') {
                $this->repair[$table][$name] = true;
            }
        }
    }

    /**
     * The zone the source's `datetime` values were written in — by default this application's.
     * A `datetime` has no zone of its own; going into a `timestamptz`, it names a moment only once
     * one is given.
     *
     * @throws \Exception For a zone PHP does not know
     */
    public function sourceTimezone(string $zone): void
    {
        $this->zone = (new \DateTimeZone($zone))->getName();
    }

    /**
     * What would move: each MySQL table, the PostgreSQL table it goes into (or the one it would
     * be created as), its estimated rows, which columns move, which exist on one side only, which
     * the target requires and will not get, and the load order.
     *
     * @param list<string> $only Source tables to move, all when empty
     * @param list<string> $skip Source tables to leave
     * @return array{tables: array<string, array<string, mixed>>, order: list<string>, cycles: list<string>, replica: bool, zone: array{name: string, offset: int, serverOffset: int, columns: list<string>}}
     */
    public function plan(array $only = [], array $skip = []): array
    {
        $sources = $this->sourceTables();
        $targets = $this->targetTables();
        $byName  = [];
        foreach ($targets as $name => $shape) {
            $byName[$this->comparable($name, (string) $this->targetPrefix, true)] = $name;
        }

        // Where each source table goes: the target table of the same name, or one made after it.
        $targetOf = fn (string $source): string => $byName[$this->comparable($source, (string) $this->sourcePrefix, false)]
            ?? $this->targetPrefix . $this->comparable($source, (string) $this->sourcePrefix, false);

        $tables = [];
        foreach ($sources as $name => $shape) {
            if (($only !== [] && !in_array($name, $only, true)) || in_array($name, $skip, true)) {
                continue;
            }
            $target = $targetOf($name);
            $create = null;
            $finish = ['statements' => [], 'skipped' => []];
            // A table an earlier run made and stopped before finishing: it is there, without its
            // indexes and keys — and, unlike one the migrations made, is this tool's to finish.
            $unfinished = isset($targets[$target]) && $this->madeAndUnfinished($name, $targets);
            if (!isset($targets[$target])) {
                $create = $this->createStatement($target, $shape);
            }
            if ($create !== null || $unfinished) {
                $finish = $this->finishStatements($target, $shape, $targetOf);
            }
            $targetColumns = $target !== null && isset($targets[$target]) ? $targets[$target]['columns'] : [];
            // A column the target computes (`GENERATED ALWAYS AS (…) STORED`) takes no value: PostgreSQL makes it.
            $computed      = array_keys(array_filter($targetColumns, static fn (array $c): bool => $c['generated'] ?? false));
            $copied        = $create !== null ? array_keys($shape['columns']) : array_values(array_diff(array_intersect(array_keys($shape['columns']), array_keys($targetColumns)), $computed));
            $required      = [];
            foreach ($targetColumns as $column => $definition) {
                if (!in_array($column, $copied, true) && $definition['notnull'] && !$definition['default']) {
                    $required[] = $column;
                }
            }
            $tables[$name] = [
                'source'      => $name,
                'target'      => $target,
                'create'      => $create,
                'finish'      => $finish,
                'unfinished'  => $unfinished,
                'rows'        => $shape['rows'],
                'copied'      => $copied,
                'sourceOnly'  => $create !== null ? [] : array_values(array_diff(array_keys($shape['columns']), $copied, $computed)),
                'computed'    => array_values(array_intersect($computed, array_keys($shape['columns']))),
                'targetOnly'  => array_values(array_diff(array_keys($targetColumns), $copied)),
                'required'    => $required,
                'compressed'  => $create === null && $this->hasCompressedChunks($target),
                'encoding'    => $this->encodingFindings($name, $shape, $copied),
            ];
        }

        // The target's keys decide the order: they are the ones that refuse a row.
        $dependsOn = [];
        $byTarget  = [];
        foreach ($tables as $name => $table) {
            $byTarget[$table['target']] = $name;
        }
        foreach ($this->targetForeignKeys() as $key) {
            if (isset($byTarget[$key['table']], $byTarget[$key['references']])) {
                $dependsOn[$byTarget[$key['table']]][] = $byTarget[$key['references']];
            }
        }
        // A table that is to be made has no keys on the target yet: its MySQL ones decide.
        foreach ($tables as $name => $table) {
            if ($table['create'] !== null) {
                foreach ($sources[$name]['foreign'] as $key) {
                    if (isset($tables[$key['references']]) && $key['references'] !== $name) {
                        $dependsOn[$name][] = $key['references'];
                    }
                }
            }
        }
        $sorted = TopologicalSorter::sort(array_keys($tables), $dependsOn);

        // `datetime` columns that go into a `timestamptz`: what they mean depends on the zone.
        $wallClock = [];
        foreach ($tables as $name => $table) {
            foreach ($table['copied'] as $column) {
                if (preg_match('/^(datetime|date)\b/i', (string) $sources[$name]['columns'][$column]['type']) === 1
                    && ($targets[$table['target']]['columns'][$column]['type'] ?? '') === 'timestamp with time zone') {
                    $wallClock[] = $name . '.' . $column;
                }
            }
        }
        $offset = intdiv((new \DateTimeImmutable('now', new \DateTimeZone($this->zone)))->getOffset(), 60);

        return ['tables' => $tables, 'order' => $sorted['order'], 'cycles' => $sorted['cycles'], 'replica' => $this->replicaAllowed(),
            'zone' => ['name' => $this->zone, 'offset' => $offset, 'serverOffset' => $this->serverOffset, 'columns' => $wallClock]];
    }

    /**
     * Move the rows the plan describes.
     *
     * @param array<string, mixed> $plan       From {@see plan()}
     * @param bool                 $useReplica Load with the target's keys and triggers off, where allowed
     * @param bool                 $nullOrphans After a load without keys, set orphaned references to NULL where the column takes it
     * @param (callable(string, int, int): void)|null $progress Table, rows inserted, rows refused — after each batch
     * @param bool $operational Record each table's moment and highest id, for {@see cutover()}
     * @param bool $snapshot    Read every table in one snapshot; false reads each a range of keys
     *                          at a time, no transaction held open — for a source too busy to
     *                          keep one for hours, at the price of tables read at different moments
     * @return array{tables: array<string, array{inserted: int, refused: int, nulled: int, errors: list<string>}>, orphans: list<array<string, mixed>>, sequences: list<array<string, mixed>>, replica: bool, finished: array<string, array{made: int, unchecked: list<string>, errors: list<string>}>}
     */
    public function run(array $plan, int $batch = 5000, bool $useReplica = true, bool $nullOrphans = false, ?callable $progress = null, bool $operational = false, bool $snapshot = true): array
    {
        $result = ['tables' => [], 'orphans' => [], 'sequences' => [], 'replica' => false];
        foreach ($plan['order'] as $name) {
            $table = $plan['tables'][$name];
            if ($table['create'] !== null && !$this->hasTargetTable($table['target'])) {
                // Noted before it is made, so a run that stops anywhere after leaves the table
                // marked as this tool's, with its indexes and keys still to come.
                $this->tracker()->put($name, 'created', 0, 0, 0, ProgressTracker::RUNNING);
                $this->target->query($table['create']);
            }
        }

        $replica = $useReplica && $plan['replica'] && $this->setReplica(true);
        $result['replica'] = $replica;
        $mysqli = $this->source->getConnectionLink();
        // One snapshot for every table: rows that point at each other were read at one moment.
        if ($snapshot) {
            $mysqli->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        }
        try {
            // The moment of the snapshot, as the source itself tells it, and each table's highest
            // id in it: what the cutover reads changes from.
            $moment  = $mysqli->query('SELECT NOW() AS now, UNIX_TIMESTAMP() AS ts')->fetch_assoc();
            $highest = [];
            if ($operational) {
                foreach ($plan['order'] as $name) {
                    $key = $this->integerKey($name);
                    if ($key !== null) {
                        $max = $mysqli->query('SELECT MAX(`' . str_replace('`', '``', $key) . '`) AS m FROM `' . str_replace('`', '``', $name) . '`')->fetch_assoc();
                        $highest[$name] = $max['m'] === null ? 0 : (int) $max['m'];
                    }
                }
            }
            $targets = $this->targetTables();
            foreach ($plan['order'] as $name) {
                $table = $plan['tables'][$name];
                $result['tables'][$name] = $this->copyTable($table, $targets[$table['target']]['columns'] ?? [], max(1, $batch), $progress, '', [], 0, '', !$snapshot);
                if ($operational) {
                    $this->tracker()->put($name, 'operational', 0, (int) $moment['ts'], $result['tables'][$name]['inserted'], ProgressTracker::DONE, [
                        'now'   => (string) $moment['now'],
                        'maxId' => $highest[$name] ?? null,
                    ]);
                }
            }
        } finally {
            if ($snapshot) {
                $mysqli->query('COMMIT');
            }
            if ($replica) {
                $this->setReplica(false);
            }
        }

        // The indexes and keys of the tables made here, once their rows are in: faster than
        // keeping them up row by row, and a key that its rows break is still added, unchecked.
        $result['finished'] = [];
        foreach ($plan['order'] as $name) {
            if ($plan['tables'][$name]['create'] !== null || ($plan['tables'][$name]['unfinished'] ?? false)) {
                $result['finished'][$name] = $this->finish($plan['tables'][$name]['finish']['statements']);
                if ($result['finished'][$name]['errors'] === []) {
                    $this->tracker()->put($name, 'created', 0, 0, 0, ProgressTracker::DONE);
                }
            }
        }

        $copied = array_values(array_unique(array_map(static fn (array $t): string => $t['target'], array_intersect_key($plan['tables'], array_flip($plan['order'])))));
        if ($replica || $result['finished'] !== []) {
            $result['orphans'] = $this->orphans($copied, $nullOrphans);
        }
        // A key added unchecked over rows that broke it holds now that they are set to NULL.
        foreach ($result['finished'] as $name => $finished) {
            foreach ($finished['unchecked'] as $i => $validate) {
                if ($this->quietly($validate)) {
                    unset($result['finished'][$name]['unchecked'][$i]);
                }
            }
            $result['finished'][$name]['unchecked'] = array_values($result['finished'][$name]['unchecked']);
        }
        $result['sequences'] = $this->alignSequences($copied);

        return $result;
    }

    /** Columns a change after the operational copy is recognised by, when none is named. */
    public const CHANGE_COLUMNS = ['updated_at', 'updatedat', 'modified_at', 'modified', 'updated', 'last_modified', 'lastmodified', 'date_modified', 'lastupdate', 'last_update', 'changed_at', 'mtime'];

    /**
     * The cutover: what changed in MySQL since each table's operational copy, moved with the
     * application stopped, and written over what the target holds.
     *
     * A table with a change column (`updated_at` and the like, or `$changed`) is read for the
     * rows changed at or after the copy's moment, and for the rows past the highest id it saw. A
     * table without one is compared row by row with the target, by its primary key — single or
     * composite — and what is missing or differs is written: slower, as the whole table is read,
     * so a big table that only ever grows is named in `$appendOnly` and read for its new rows
     * only. With `$deletes`, the target's rows whose key MySQL no longer has are deleted, the
     * target walked a range of keys at a time. A table with no primary key is named: it cannot be
     * caught up, only copied again.
     *
     * @param array<string, mixed> $plan
     * @param list<string>         $changed    Change columns to use where a table has one
     * @param list<string>         $appendOnly Tables read only for rows past the copy's highest id
     * @return array{tables: array<string, array<string, mixed>>, orphans: list<array<string, mixed>>, sequences: list<array<string, mixed>>, replica: bool}
     */
    public function cutover(array $plan, int $batch = 5000, array $changed = [], bool $deletes = false, bool $useReplica = true, bool $nullOrphans = false, ?callable $progress = null, array $appendOnly = []): array
    {
        $result  = ['tables' => [], 'orphans' => [], 'sequences' => [], 'replica' => false];
        $replica = $useReplica && $plan['replica'] && $this->setReplica(true);
        $result['replica'] = $replica;
        $targets = $this->targetTables();
        try {
            foreach ($plan['order'] as $name) {
                $table = $plan['tables'][$name];
                $state = $this->tracker()->of($name, 'operational')[0] ?? null;
                $note  = ['inserted' => 0, 'refused' => 0, 'nulled' => 0, 'errors' => [], 'deleted' => 0, 'how' => null, 'unseen' => null, 'unchecked' => null];
                if ($state === null) {
                    $result['tables'][$name] = ['unseen' => 'not copied in the operational phase'] + $note;
                    continue;
                }
                $shape = $this->sourceTables()[$name];
                $key   = $shape['primary'];
                if ($key === [] || array_diff($key, $table['copied']) !== []) {
                    $result['tables'][$name] = ['unseen' => 'no primary key the target has: changes since the copy are not seen; copy it again'] + $note;
                    continue;
                }
                $column = null;
                foreach ([...$changed, ...self::CHANGE_COLUMNS] as $candidate) {
                    if (isset($shape['columns'][$candidate])) {
                        $column = $candidate;
                        break;
                    }
                }
                $integer   = $this->integerKey($name);
                $maxId     = $state['detail']['maxId'] ?? null;
                $newRows   = $integer !== null && $maxId !== null ? '`' . str_replace('`', '``', $integer) . '` > ' . (int) $maxId : null;
                $targetSet = $targets[$table['target']]['columns'] ?? [];
                if ($column === null && in_array($name, $appendOnly, true)) {
                    if ($newRows === null) {
                        $result['tables'][$name] = ['unseen' => 'append-only, but no integer key to tell new rows by'] + $note;
                        continue;
                    }
                    $stats = $this->copyTable($table, $targetSet, max(1, $batch), $progress, $newRows, $key)
                        + ['how' => 'new rows by ' . $integer, 'unseen' => 'append-only: rows that existed at the copy and changed since are not seen'];
                } elseif ($column !== null) {
                    $since = '`' . str_replace('`', '``', $column) . '` >= ' . $this->sourceMoment((int) $state['end'], (string) $shape['columns'][$column]['type']);
                    $stats = $this->copyTable($table, $targetSet, max(1, $batch), $progress, $newRows !== null ? $since . ' OR ' . $newRows : $since, $key)
                        + ['how' => 'changed by ' . $column, 'unseen' => null];
                } else {
                    // No way to tell a change but to look: every row against the target's.
                    $stats = ['inserted' => 0, 'refused' => 0, 'nulled' => 0, 'errors' => []];
                    $found = $this->ranges()->compare($name, $table['target'], $key, $table['copied'], $shape['columns'], $targetSet, max(1, $batch),
                        function (array $rows) use ($table, $key, &$stats, $progress, $name): void {
                            $this->insert($table['target'], $table['copied'], $rows, $stats, $key);
                            if ($progress !== null) {
                                $progress($name, $stats['inserted'], $stats['refused']);
                            }
                        });
                    $stats += ['how' => 'compared: ' . $found['missing'] . ' new, ' . $found['different'] . ' changed of ' . $found['rows'], 'unseen' => null];
                }
                $stats += ['deleted' => 0, 'unchecked' => null];
                if ($deletes) {
                    $gone               = $this->ranges()->extras($name, $table['target'], $key, $targetSet, max(1, $batch), true);
                    $stats['deleted']   = $gone['deleted'];
                    $stats['unchecked'] = $gone['unchecked'];
                }
                $this->tracker()->put($name, 'cutover', 0, time(), $stats['inserted'], ProgressTracker::DONE, ['deleted' => $stats['deleted']]);
                $result['tables'][$name] = $stats;
            }
        } finally {
            if ($replica) {
                $this->setReplica(false);
            }
        }
        $moved = array_values(array_map(static fn (array $t): string => $t['target'], array_intersect_key($plan['tables'], array_flip($plan['order']))));
        if ($replica) {
            $result['orphans'] = $this->orphans($moved, $nullOrphans);
        }
        $result['sequences'] = $this->alignSequences($moved);

        return $result;
    }

    /**
     * Whether the target holds what MySQL holds: each table's rows counted on both sides, then
     * every source row compared with the target's row of the same key, as the move would have
     * written it, and the target's rows MySQL no longer has looked for. Nothing is changed.
     *
     * Run it once the writes are stopped — after the cutover, before the application writes to
     * PostgreSQL — or rows written since are differences. A reference set to NULL as an orphan
     * is one too.
     *
     * @param array<string, mixed> $plan
     * @return array<string, array{source: int, target: int, missing: int, different: int, extra: int, keys: list<string>, unchecked: ?string}>
     */
    public function verify(array $plan, int $batch = 5000): array
    {
        $targets = $this->targetTables();
        $result  = [];
        foreach ($plan['order'] as $name) {
            $table  = $plan['tables'][$name];
            $shape  = $this->sourceTables()[$name];
            $quoted = '`' . str_replace('`', '``', $name) . '`';
            $stats  = [
                'source'    => (int) ($this->sourceRows('SELECT COUNT(*) AS n FROM ' . $quoted)[0]['n'] ?? 0),
                'target'    => isset($targets[$table['target']]) ? (int) ($this->rows('SELECT COUNT(*) AS n FROM ' . $this->quoted($table['target']))[0]['n'] ?? 0) : 0,
                'missing'   => 0, 'different' => 0, 'extra' => 0, 'keys' => [], 'unchecked' => null,
            ];
            if (!isset($targets[$table['target']])) {
                $result[$name] = ['unchecked' => 'the target has no such table'] + $stats;
                continue;
            }
            $key = $shape['primary'];
            if ($key === [] || array_diff($key, $table['copied']) !== []) {
                $result[$name] = ['unchecked' => 'no primary key the target has: rows counted, not compared'] + $stats;
                continue;
            }
            $same  = $this->ranges()->compare($name, $table['target'], $key, $table['copied'], $shape['columns'], $targets[$table['target']]['columns'], max(1, $batch));
            $extra = $this->ranges()->extras($name, $table['target'], $key, $targets[$table['target']]['columns'], max(1, $batch), false);
            $result[$name] = ['missing' => $same['missing'], 'different' => $same['different'], 'extra' => $extra['rows'],
                'keys' => array_slice([...$same['keys'], ...$extra['keys']], 0, 5), 'unchecked' => $extra['unchecked']] + $stats;
        }

        return $result;
    }

    /**
     * The backfill: a table's history, window by window, newest first, with the application
     * already running on PostgreSQL.
     *
     * Each window is `[start, end)` on `$timeColumn`, `$interval` long, the first ending at
     * `$until` — by default the cutover's moment, so what the application wrote since is not
     * touched. A window done is not read again: a stopped backfill continues where it stopped,
     * and `$restart` forgets that. On a hypertable, the compression policy is removed for the
     * duration and put back after; compressed chunks in a window are decompressed before rows go
     * in; with `$compress`, each window's chunks are compressed once it is done.
     *
     * @param array<string, mixed> $plan
     * @return array<string, array<string, mixed>> Per table: windows done and skipped, rows, chunks compressed
     */
    public function backfill(array $plan, string $timeColumn, string $interval = '1 month', int $batch = 5000, int $throttleMs = 50, bool $compress = false, bool $restart = false, ?int $until = null, ?callable $progress = null): array
    {
        // Checked before anything is touched — a policy suspended for a run that then refuses its
        // interval would stay off until the next one.
        self::back(time(), $interval);
        $until ??= $this->tracker()->latest('cutover') ?? $this->tracker()->latest('operational') ?? (int) $this->source->getConnectionLink()->query('SELECT UNIX_TIMESTAMP() AS ts')->fetch_assoc()['ts'];
        $targets = $this->targetTables();
        $result  = [];
        foreach ($plan['order'] as $name) {
            $table   = $plan['tables'][$name];
            $columns = $this->sourceTables()[$name]['columns'];
            $stats   = ['windows' => 0, 'skipped' => 0, 'inserted' => 0, 'refused' => 0, 'nulled' => 0, 'errors' => [], 'compressed' => 0, 'error' => null, 'fdw' => 0];
            if (!isset($columns[$timeColumn])) {
                $result[$name] = ['error' => 'has no column ' . $timeColumn] + $stats;
                continue;
            }
            if ($restart) {
                $this->tracker()->forget($name, 'backfill');
            }
            $type    = (string) $columns[$timeColumn]['type'];
            $integer = preg_match('/int/i', $type) === 1;
            $quoted  = '`' . str_replace('`', '``', $timeColumn) . '`';
            $first   = $this->source->getConnectionLink()->query('SELECT MIN(' . $quoted . ') AS m FROM `' . $name . '` WHERE ' . $quoted
                . ($integer ? ' > 0' : " > '1000-01-01'"))->fetch_assoc()['m'];
            if ($first !== null && !$integer) {
                $first = (new \DateTimeImmutable((string) $first, new \DateTimeZone(str_starts_with(strtolower($type), 'timestamp') ? 'UTC' : $this->zone)))->getTimestamp();
            }
            if ($first === null) {
                $result[$name] = $stats;
                continue;
            }
            $hypertable = $this->hypertableDimension($table['target']);
            $policy     = $hypertable !== null ? $this->suspendCompressionPolicy($name, $table['target']) : null;
            $done       = $this->tracker()->of($name, 'backfill');
            for ($end = $until; $end > (int) $first; $end = $start) {
                $start = self::back($end, $interval);
                if (($done[$start]['status'] ?? null) === ProgressTracker::DONE && $done[$start]['end'] === $end) {
                    $stats['skipped']++;
                    continue;
                }
                $this->tracker()->put($name, 'backfill', $start, $end, 0, ProgressTracker::RUNNING);
                if ($hypertable !== null) {
                    $this->chunks($table['target'], $hypertable, $start, $end, false);
                }
                // The window's ends as the column holds them; the same text serves the foreign table.
                $low      = $this->sourceMoment($start, $type);
                $high     = $this->sourceMoment($end, $type);
                $where    = $quoted . ' >= ' . $low . ' AND ' . $quoted . ' < ' . $high;
                $column   = '"' . str_replace('"', '""', $timeColumn) . '"';
                $fdwWhere = $this->fdw !== null ? $column . ' >= ' . $low . ' AND ' . $column . ' < ' . $high : null;
                $window = $this->copyTable($table, $targets[$table['target']]['columns'] ?? [], max(1, $batch), $progress, $where, [], $throttleMs, $fdwWhere);
                if ($compress && $hypertable !== null) {
                    $stats['compressed'] += $this->chunks($table['target'], $hypertable, $start, $end, true);
                }
                $this->tracker()->put($name, 'backfill', $start, $end, $window['inserted'], ProgressTracker::DONE, ['refused' => $window['refused']]);
                $stats['windows']++;
                $stats['fdw']      += ($window['transport'] ?? 'php') === 'fdw' ? 1 : 0;
                $stats['inserted'] += $window['inserted'];
                $stats['refused']  += $window['refused'];
                $stats['nulled']   += $window['nulled'];
                $stats['errors']    = array_slice([...$stats['errors'], ...$window['errors']], 0, 3);
            }
            if ($policy !== null) {
                $this->target->query("SELECT add_compression_policy('" . $table['target'] . "', " . $policy . ', if_not_exists => true)');
                $stats['policyRestored'] = true;
            }
            $result[$name] = $stats;
        }

        return $result;
    }

    /**
     * A moment one interval earlier.
     *
     * @throws \InvalidArgumentException For an interval that does not move back in time
     */
    private static function back(int $from, string $interval): int
    {
        try {
            $to = (new \DateTimeImmutable('@' . $from))->modify('-' . $interval)->getTimestamp();
        } catch (\Throwable) {
            // Not an interval at all ("banana").
            $to = $from;
        }
        if ($to >= $from) {
            throw new \InvalidArgumentException('The chunk interval "' . $interval . '" does not move back in time.');
        }

        return $to;
    }

    /**
     * A moment as a source column holding it compares: a unix time, a `timestamp` in UTC (the
     * session's zone), a `datetime` as the wall-clock time of the zone it was written in.
     */
    private function sourceMoment(int $at, string $mysqlType): string
    {
        if (preg_match('/int/i', $mysqlType) === 1) {
            return (string) $at;
        }
        $zone = new \DateTimeZone(str_starts_with(strtolower($mysqlType), 'timestamp') ? 'UTC' : $this->zone);

        return "'" . (new \DateTimeImmutable('@' . $at))->setTimezone($zone)->format('Y-m-d H:i:s') . "'";
    }

    /** Key-range reads and comparisons over the two sides. */
    private function ranges(): KeyRanges
    {
        return new KeyRanges($this->source, $this->target, $this->zone, $this->repair);
    }

    /** The tracker, on the target. */
    public function tracker(): ProgressTracker
    {
        return $this->tracker ??= new ProgressTracker($this->target);
    }

    /** A table's primary key when it is one integer column — the one new rows can be told by. */
    private function integerKey(string $table): ?string
    {
        $shape = $this->sourceTables()[$table] ?? null;
        if ($shape === null || count($shape['primary']) !== 1) {
            return null;
        }
        $key = $shape['primary'][0];

        return preg_match('/int/i', (string) $shape['columns'][$key]['type']) === 1 ? $key : null;
    }

    /** The type of a hypertable's time dimension, or null when the table is not one. */
    private function hypertableDimension(string $table): ?string
    {
        [$schema, $bare] = str_contains($table, '.') ? explode('.', $table, 2) : ['public', $table];
        try {
            $row = $this->rows("SELECT column_type AS t FROM timescaledb_information.dimensions WHERE hypertable_schema = '" . pg_escape_string($this->target->getConnectionLink(), $schema)
                . "' AND hypertable_name = '" . pg_escape_string($this->target->getConnectionLink(), $bare) . "' AND dimension_number = 1")[0] ?? null;
        } catch (\Throwable) {
            return null;
        }

        return $row === null ? null : strtolower((string) $row['t']);
    }

    /**
     * Remove a hypertable's compression policy for the backfill, remembering it, and answer the
     * `compress_after` to put back — or null when it had none. A policy removed by an earlier
     * run that stopped is still remembered, and put back by this one.
     */
    private function suspendCompressionPolicy(string $source, string $table): ?string
    {
        [$schema, $bare] = str_contains($table, '.') ? explode('.', $table, 2) : ['public', $table];
        $link = $this->target->getConnectionLink();
        $row  = $this->rows("SELECT config->>'compress_after' AS a FROM timescaledb_information.jobs WHERE proc_name = 'policy_compression' AND hypertable_schema = '"
            . pg_escape_string($link, $schema) . "' AND hypertable_name = '" . pg_escape_string($link, $bare) . "'")[0] ?? null;
        $remembered = $this->tracker()->of($source, 'backfill-policy')[0]['detail']['compressAfter'] ?? null;
        $after      = $row !== null ? (string) $row['a'] : $remembered;
        if ($after === null) {
            return null;
        }
        $this->tracker()->put($source, 'backfill-policy', 0, 0, 0, 'REMOVED', ['compressAfter' => $after]);
        if ($row !== null) {
            $this->target->query("SELECT remove_compression_policy('" . $table . "', if_exists => true)");
        }

        return ctype_digit($after) ? $after : "INTERVAL '" . pg_escape_string($link, $after) . "'";
    }

    /**
     * Decompress the chunks that overlap a window (`$compress` false), or compress those wholly
     * inside it (`$compress` true). Returns how many changed.
     */
    private function chunks(string $table, string $dimension, int $start, int $end, bool $compress): int
    {
        [$schema, $bare] = str_contains($table, '.') ? explode('.', $table, 2) : ['public', $table];
        $link    = $this->target->getConnectionLink();
        $integer = preg_match('/int/', $dimension) === 1;
        $low     = $integer ? 'range_start_integer' : 'range_start';
        $high    = $integer ? 'range_end_integer' : 'range_end';
        $from    = $integer ? (string) $start : 'to_timestamp(' . $start . ')';
        $to      = $integer ? (string) $end : 'to_timestamp(' . $end . ')';
        $which   = $compress
            ? 'NOT is_compressed AND ' . $low . ' >= ' . $from . ' AND ' . $high . ' <= ' . $to
            : 'is_compressed AND ' . $high . ' > ' . $from . ' AND ' . $low . ' < ' . $to;
        $changed = 0;
        foreach ($this->rows("SELECT format('%I.%I', chunk_schema, chunk_name) AS c FROM timescaledb_information.chunks WHERE hypertable_schema = '"
            . pg_escape_string($link, $schema) . "' AND hypertable_name = '" . pg_escape_string($link, $bare) . "' AND " . $which) as $row) {
            $this->target->query('SELECT ' . ($compress ? 'compress_chunk' : 'decompress_chunk') . "('" . $row['c'] . "', true)");
            $changed++;
        }

        return $changed;
    }

    /**
     * Stream one table: read unbuffered, convert, insert in batches; a batch that is refused is
     * tried row by row, so one bad row costs that row.
     *
     * @param array<string, mixed> $table
     * @param array<string, array<string, mixed>> $targetColumns
     * @param string       $where      A MySQL condition on the rows to read, or '' for all of them
     * @param list<string> $updateKey  Columns a conflict is judged on, to update the row; [] keeps the existing row
     * @param int          $throttleMs A pause after each batch, for a load beside live traffic
     * @param string|null  $fdwWhere   The same condition for the foreign table, or null where `mysql_fdw` is not to be used
     * @param bool         $byKey      Read a range of keys at a time, each its own statement, where the table has a primary key
     * @return array{inserted: int, refused: int, nulled: int, errors: list<string>}
     */
    private function copyTable(array $table, array $targetColumns, int $batch, ?callable $progress, string $where = '', array $updateKey = [], int $throttleMs = 0, ?string $fdwWhere = null, bool $byKey = false): array
    {
        $stats   = ['inserted' => 0, 'refused' => 0, 'nulled' => 0, 'errors' => [], 'repaired' => 0];
        $columns = $table['copied'];
        if ($columns === []) {
            return $stats;
        }
        $sourceTypes = $this->sourceTables()[$table['source']]['columns'];
        $repair = $this->repair[$table['source']] ?? [];
        if ($this->fdw !== null && $fdwWhere !== null && $updateKey === [] && $repair === []) {
            $moved = $this->fdw->copy($table['source'], $table['target'], $sourceTypes, $targetColumns, $columns, $fdwWhere, [], $this->zone, $this->overriding($table['target'], $columns));
            if ($moved !== null) {
                if ($progress !== null) {
                    $progress($table['source'], $moved, 0);
                }

                return ['inserted' => $moved, 'refused' => 0, 'nulled' => 0, 'errors' => [], 'transport' => 'fdw'];
            }
            // What PostgreSQL could not convert, PHP can: the same rows, the slower way.
            $stats['errors'][] = 'mysql_fdw refused it (' . $this->fdw->lastError() . '); moved through PHP';
        }
        $perBatch = max(1, min($batch, intdiv(self::MAX_PARAMETERS, count($columns))));
        $key      = $this->sourceTables()[$table['source']]['primary'];
        $read     = $byKey && $key !== [] && array_diff($key, $columns) === []
            ? (function () use ($table, $key, $columns, $perBatch, $where): \Generator {
                foreach ($this->ranges()->read($table['source'], $key, $columns, $perBatch, $where) as $range) {
                    yield from $range;
                }
            })()
            : $this->stream($table['source'], $columns, $where);
        $rows = [];
        foreach ($read as $row) {
            $converted = [];
            foreach ($columns as $column) {
                $raw = $row[$column];
                if (isset($repair[$column]) && is_string($raw) && ($repaired = TypeMapper::repairEncoding($raw)) !== null) {
                    $raw = $repaired;
                    $stats['repaired']++;
                }
                $value = TypeMapper::value($raw, (string) $sourceTypes[$column]['type'], (string) ($targetColumns[$column]['type'] ?? TypeMapper::toPostgres((string) $sourceTypes[$column]['type'])), $refused, $this->zone);
                $stats['nulled'] += $refused ? 1 : 0;
                $converted[] = $value;
            }
            $rows[] = $converted;
            if (count($rows) >= $perBatch) {
                $this->insert($table['target'], $columns, $rows, $stats, $updateKey);
                $rows = [];
                if ($progress !== null) {
                    $progress($table['source'], $stats['inserted'], $stats['refused']);
                }
                if ($throttleMs > 0) {
                    usleep($throttleMs * 1000);
                }
            }
        }
        if ($rows !== []) {
            $this->insert($table['target'], $columns, $rows, $stats, $updateKey);
            if ($progress !== null) {
                $progress($table['source'], $stats['inserted'], $stats['refused']);
            }
        }

        return $stats;
    }

    /**
     * A table's rows read by one unbuffered statement: streamed, whatever its size.
     *
     * @param list<string> $columns
     * @return \Generator<int, array<string, ?string>>
     */
    private function stream(string $table, array $columns, string $where): \Generator
    {
        $read = $this->source->getConnectionLink()->query(
            'SELECT ' . implode(', ', array_map(static fn (string $c): string => '`' . str_replace('`', '``', $c) . '`', $columns))
            . ' FROM `' . str_replace('`', '``', $table) . '`' . ($where !== '' ? ' WHERE ' . $where : ''),
            MYSQLI_USE_RESULT
        );
        try {
            while ($read && ($row = $read->fetch_assoc()) !== null) {
                yield $row;
            }
        } finally {
            if ($read) {
                $read->free();
            }
        }
    }

    /**
     * @param list<string>             $columns
     * @param list<list<mixed>>        $rows
     * @param array<string, mixed>     $stats
     * @param list<string>             $updateKey Conflict on these updates the row; [] leaves it
     */
    private function insert(string $table, array $columns, array $rows, array &$stats, array $updateKey = []): void
    {
        $connection = $this->target->getConnectionLink();
        $quote      = static fn (string $c): string => '"' . str_replace('"', '""', $c) . '"';
        $others     = array_values(array_diff($columns, $updateKey));
        $conflict   = $updateKey === []
            ? ' ON CONFLICT DO NOTHING'
            : ' ON CONFLICT (' . implode(', ', array_map($quote, $updateKey)) . ')'
                . ($others === [] ? ' DO NOTHING' : ' DO UPDATE SET ' . implode(', ', array_map(static fn (string $c): string => $quote($c) . ' = EXCLUDED.' . $quote($c), $others)));
        $head       = 'INSERT INTO ' . $this->quoted($table) . ' (' . implode(', ', array_map(static fn (string $c): string => '"' . str_replace('"', '""', $c) . '"', $columns)) . ')'
            . ($this->overriding($table, $columns) ? ' OVERRIDING SYSTEM VALUE' : '') . ' VALUES ';
        $statement  = static function (int $count, int $width) use ($head, $conflict): string {
            $groups = [];
            for ($r = 0, $n = 1; $r < $count; $r++) {
                $placeholders = [];
                for ($c = 0; $c < $width; $c++, $n++) {
                    $placeholders[] = '$' . $n;
                }
                $groups[] = '(' . implode(', ', $placeholders) . ')';
            }

            return $head . implode(', ', $groups) . $conflict;
        };

        $result = @pg_query_params($connection, $statement(count($rows), count($columns)), array_merge(...$rows));
        if ($result !== false) {
            $stats['inserted'] += pg_affected_rows($result);

            return;
        }
        foreach ($rows as $row) {
            $one = @pg_query_params($connection, $statement(1, count($columns)), $row);
            if ($one !== false) {
                $stats['inserted'] += pg_affected_rows($one);
                continue;
            }
            $stats['refused']++;
            if (count($stats['errors']) < 3) {
                $stats['errors'][] = trim((string) strtok(pg_last_error($connection), "\n"));
            }
        }
    }

    /**
     * Rows whose reference names no row of the table it points at, per key of the moved tables —
     * what a load without keys can leave. With `$null`, set to NULL where the column takes it.
     *
     * @param list<string> $tables
     * @return list<array{table: string, column: string, references: string, rows: int, nulled: bool, nullable: bool}>
     */
    private function orphans(array $tables, bool $null): array
    {
        $found = [];
        foreach ($this->targetForeignKeys() as $key) {
            if (!in_array($key['table'], $tables, true)) {
                continue;
            }
            $where = $this->quoted($key['table']) . ' c WHERE c."' . $key['column'] . '" IS NOT NULL AND NOT EXISTS (SELECT 1 FROM '
                . $this->quoted($key['references']) . ' p WHERE p."' . $key['referencedColumn'] . '" = c."' . $key['column'] . '")';
            $count = (int) ($this->rows('SELECT COUNT(*) AS n FROM ' . $where)[0]['n'] ?? 0);
            if ($count === 0) {
                continue;
            }
            $nulled = false;
            if ($null && $key['nullable']) {
                $this->target->query('UPDATE ' . $this->quoted($key['table']) . ' SET "' . $key['column'] . '" = NULL WHERE "' . $key['column']
                    . '" IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ' . $this->quoted($key['references']) . ' p WHERE p."' . $key['referencedColumn']
                    . '" = ' . $this->quoted($key['table']) . '."' . $key['column'] . '")');
                $nulled = true;
            }
            $found[] = ['table' => $key['table'], 'column' => $key['column'], 'references' => $key['references'], 'rows' => $count, 'nulled' => $nulled, 'nullable' => $key['nullable']];
        }

        return $found;
    }

    /**
     * Every sequence behind a column of the moved tables, set past its highest value, so the
     * next row the application writes does not collide with a moved one.
     *
     * @param list<string> $tables
     * @return list<array{table: string, column: string, next: int}>
     */
    private function alignSequences(array $tables): array
    {
        $aligned = [];
        foreach ($this->rows('SELECT ns.nspname AS s, c.relname AS t, a.attname AS col,'
            . " pg_get_serial_sequence(quote_ident(ns.nspname) || '.' || quote_ident(c.relname), a.attname) AS seq"
            . ' FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_namespace ns ON ns.oid = c.relnamespace'
            . " WHERE c.relkind IN ('r', 'p') AND a.attnum > 0 AND NOT a.attisdropped AND ns.nspname NOT IN (" . self::SYSTEM_SCHEMAS . ')') as $row) {
            $table = $row['s'] === 'public' ? (string) $row['t'] : $row['s'] . '.' . $row['t'];
            if ($row['seq'] === null || !in_array($table, $tables, true)) {
                continue;
            }
            $next = (int) ($this->rows('SELECT COALESCE(MAX("' . $row['col'] . '"), 0) + 1 AS n FROM ' . $this->quoted($table))[0]['n'] ?? 1);
            $this->target->query("SELECT setval('" . str_replace("'", "''", (string) $row['seq']) . "', " . $next . ', false)');
            $aligned[] = ['table' => $table, 'column' => (string) $row['col'], 'next' => $next];
        }

        return $aligned;
    }

    /** @return array<string, array{columns: array<string, array<string, mixed>>, primary: list<string>, rows: int, indexes: array<string, array<string, mixed>>, foreign: array<string, array<string, mixed>>}> */
    private function sourceTables(): array
    {
        if ($this->sourceShape !== null) {
            return $this->sourceShape;
        }
        $tables  = [];
        $mariadb = str_contains((string) ($this->sourceRows('SELECT VERSION() AS v')[0]['v'] ?? ''), 'MariaDB');
        foreach ($this->sourceRows("SELECT TABLE_NAME AS t, TABLE_ROWS AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME") as $row) {
            $tables[(string) $row['t']] = ['columns' => [], 'primary' => [], 'rows' => (int) $row['n'], 'indexes' => [], 'foreign' => []];
        }
        foreach ($this->sourceRows('SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLUMN_TYPE AS type, IS_NULLABLE AS n, COLUMN_DEFAULT AS d, EXTRA AS e, CHARACTER_SET_NAME AS cs'
            . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION') as $row) {
            if (isset($tables[$row['t']])) {
                $tables[$row['t']]['columns'][(string) $row['c']] = ['type' => (string) $row['type'], 'nullable' => $row['n'] === 'YES', 'auto' => str_contains((string) $row['e'], 'auto_increment'), 'charset' => $row['cs']]
                    + self::sourceDefault($row['d'], (string) $row['e'], $mariadb);
            }
        }
        // MariaDB keeps JSON as `longtext` with a `json_valid()` check: it is JSON all the same.
        if ($mariadb) {
            foreach ($this->sourceRows("SELECT TABLE_NAME AS t, CHECK_CLAUSE AS c FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CHECK_CLAUSE LIKE 'json_valid(%'") as $row) {
                if (preg_match('/^json_valid\(`([^`]+)`\)$/', (string) $row['c'], $m) === 1 && isset($tables[$row['t']]['columns'][$m[1]])) {
                    $tables[$row['t']]['columns'][$m[1]]['type'] = 'json';
                }
            }
        }
        foreach ($this->sourceRows("SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME = 'PRIMARY' ORDER BY TABLE_NAME, SEQ_IN_INDEX") as $row) {
            if (isset($tables[$row['t']])) {
                $tables[$row['t']]['primary'][] = (string) $row['c'];
            }
        }

        // Expression parts (no column) make an index PostgreSQL would have to be told about by hand.
        foreach ($this->sourceRows("SELECT TABLE_NAME AS t, INDEX_NAME AS i, NON_UNIQUE AS u, COLUMN_NAME AS c, INDEX_TYPE AS k FROM information_schema.STATISTICS"
            . " WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME <> 'PRIMARY' ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX") as $row) {
            if (isset($tables[$row['t']])) {
                $index = &$tables[$row['t']]['indexes'][(string) $row['i']];
                $index ??= ['unique' => (int) $row['u'] === 0, 'columns' => [], 'kind' => strtoupper((string) $row['k'])];
                $index['columns'][] = $row['c'] === null ? null : (string) $row['c'];
                unset($index);
            }
        }
        foreach ($this->sourceRows('SELECT k.TABLE_NAME AS t, k.CONSTRAINT_NAME AS n, k.COLUMN_NAME AS c, k.REFERENCED_TABLE_NAME AS rt, k.REFERENCED_COLUMN_NAME AS rc,'
            . ' r.UPDATE_RULE AS u, r.DELETE_RULE AS d FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r'
            . ' ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME'
            . ' WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION') as $row) {
            if (isset($tables[$row['t']])) {
                $key = &$tables[$row['t']]['foreign'][(string) $row['n']];
                $key ??= ['columns' => [], 'references' => (string) $row['rt'], 'referenced' => [], 'update' => (string) $row['u'], 'delete' => (string) $row['d']];
                $key['columns'][]    = (string) $row['c'];
                $key['referenced'][] = (string) $row['rc'];
                unset($key);
            }
        }

        return $this->sourceShape = $tables;
    }

    /** @return array<string, array{columns: array<string, array{type: string, notnull: bool, default: bool}>}> */
    private function targetTables(): array
    {
        $tables = [];
        foreach ($this->rows("SELECT table_schema AS s, table_name AS t FROM information_schema.tables WHERE table_type = 'BASE TABLE' AND table_schema NOT IN (" . self::SYSTEM_SCHEMAS . ") AND table_schema NOT LIKE '\\_timescaledb%'") as $row) {
            $tables[$row['s'] === 'public' ? (string) $row['t'] : $row['s'] . '.' . $row['t']] = ['columns' => []];
        }
        foreach ($this->rows('SELECT ns.nspname AS s, c.relname AS t, a.attname AS col, format_type(a.atttypid, a.atttypmod) AS type, a.attnotnull AS nn,'
            . ' (a.atthasdef OR a.attidentity <> \'\') AS def, a.attidentity AS ident, a.attgenerated AS gen FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_namespace ns ON ns.oid = c.relnamespace'
            . " WHERE c.relkind IN ('r', 'p') AND a.attnum > 0 AND NOT a.attisdropped") as $row) {
            $name = $row['s'] === 'public' ? (string) $row['t'] : $row['s'] . '.' . $row['t'];
            if (isset($tables[$name])) {
                $tables[$name]['columns'][(string) $row['col']] = ['type' => (string) $row['type'], 'notnull' => in_array($row['nn'], [true, 't', 1, '1'], true), 'default' => in_array($row['def'], [true, 't', 1, '1'], true),
                    'always' => $row['ident'] === 'a', 'generated' => (string) $row['gen'] !== ''];
            }
        }

        return $tables;
    }

    /** @return list<array{table: string, column: string, references: string, referencedColumn: string, nullable: bool}> */
    private function targetForeignKeys(): array
    {
        $keys = [];
        foreach ($this->rows('SELECT ns.nspname AS s, t.relname AS t, a.attname AS col, NOT a.attnotnull AS nullable, rns.nspname AS rs, r.relname AS rt, ra.attname AS rcol'
            . ' FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace ns ON ns.oid = t.relnamespace'
            . ' JOIN pg_class r ON r.oid = c.confrelid JOIN pg_namespace rns ON rns.oid = r.relnamespace'
            . ' JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = c.conkey[1] JOIN pg_attribute ra ON ra.attrelid = r.oid AND ra.attnum = c.confkey[1]'
            . " WHERE c.contype = 'f' AND array_length(c.conkey, 1) = 1") as $row) {
            $keys[] = [
                'table'            => $row['s'] === 'public' ? (string) $row['t'] : $row['s'] . '.' . $row['t'],
                'column'           => (string) $row['col'],
                'references'       => $row['rs'] === 'public' ? (string) $row['rt'] : $row['rs'] . '.' . $row['rt'],
                'referencedColumn' => (string) $row['rcol'],
                'nullable'         => in_array($row['nullable'], [true, 't', 1, '1'], true),
            ];
        }

        return $keys;
    }

    /**
     * A table name as both sides can be matched on: the side's prefix taken off, and on the
     * PostgreSQL side a schema written the way MySQL flattens it (`authserver.x` is `authserver_x`).
     */
    private function comparable(string $name, string $prefix, bool $target): string
    {
        if ($target && str_contains($name, '.')) {
            [$schema, $bare] = explode('.', $name, 2);
            $bare = $prefix !== '' && str_starts_with($bare, $prefix) ? substr($bare, strlen($prefix)) : $bare;

            return strtolower($schema . '_' . $bare);
        }

        return strtolower($prefix !== '' && str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name);
    }

    /**
     * The statement that creates a MySQL table nothing on the PostgreSQL side made: its columns
     * typed by {@see TypeMapper}, an auto-increment as an identity, the same primary key.
     *
     * @param array<string, mixed> $shape
     */
    private function createStatement(string $table, array $shape): string
    {
        $columns = [];
        foreach ($shape['columns'] as $name => $column) {
            $type = TypeMapper::toPostgres($column['type']);
            $sql  = '"' . str_replace('"', '""', $name) . '" ' . $type;
            $sql .= $column['auto'] ? ' GENERATED BY DEFAULT AS IDENTITY' : self::defaultClause($column, $type);
            $columns[] = $sql . ($column['nullable'] ? '' : ' NOT NULL');
        }
        if ($shape['primary'] !== []) {
            $columns[] = 'PRIMARY KEY (' . implode(', ', array_map(static fn (string $c): string => '"' . str_replace('"', '""', $c) . '"', $shape['primary'])) . ')';
        }

        return 'CREATE TABLE ' . $this->quoted($table) . ' (' . implode(', ', $columns) . ')';
    }

    /**
     * What a table made here gets once its rows are in: MySQL's indexes and unique keys, and its
     * foreign keys to the tables their references now live in. A `FULLTEXT` or `SPATIAL` index,
     * and one over an expression, have no plain PostgreSQL equivalent and are named instead. A
     * prefix (`name(20)`) is dropped: the index covers the whole column — still unique, where
     * the prefix was.
     *
     * @param array<string, mixed>     $shape
     * @param callable(string): string $targetOf Where a source table goes
     * @return array{statements: list<array{sql: string, unchecked: ?string}>, skipped: list<string>}
     */
    private function finishStatements(string $table, array $shape, callable $targetOf): array
    {
        $bare       = str_contains($table, '.') ? explode('.', $table, 2)[1] : $table;
        $quote      = static fn (string $c): string => '"' . str_replace('"', '""', $c) . '"';
        $columns    = static fn (array $list): string => implode(', ', array_map($quote, $list));
        $statements = [];
        $skipped    = [];
        foreach ($shape['indexes'] as $name => $index) {
            if (in_array($index['kind'], ['FULLTEXT', 'SPATIAL'], true) || in_array(null, $index['columns'], true)) {
                $skipped[] = (in_array(null, $index['columns'], true) ? 'expression' : $index['kind']) . ' index ' . $name;
                continue;
            }
            $statements[] = ['sql' => 'CREATE ' . ($index['unique'] ? 'UNIQUE ' : '') . 'INDEX IF NOT EXISTS ' . $quote(substr($bare . '_' . $name, 0, 63))
                . ' ON ' . $this->quoted($table) . ' (' . $columns($index['columns']) . ')', 'unchecked' => null];
        }
        foreach ($shape['foreign'] as $name => $key) {
            $constraint = $quote(substr($bare . '_' . $name, 0, 63));
            $add        = 'ALTER TABLE ' . $this->quoted($table) . ' ADD CONSTRAINT ' . $constraint . ' FOREIGN KEY (' . $columns($key['columns']) . ')'
                . ' REFERENCES ' . $this->quoted($targetOf($key['references'])) . ' (' . $columns($key['referenced']) . ')'
                . ' ON DELETE ' . $key['delete'] . ' ON UPDATE ' . $key['update'];
            $statements[] = ['sql' => $add, 'unchecked' => 'ALTER TABLE ' . $this->quoted($table) . ' VALIDATE CONSTRAINT ' . $constraint];
        }

        return ['statements' => $statements, 'skipped' => $skipped];
    }

    /**
     * Run what {@see finishStatements()} made. A foreign key its rows break is added `NOT VALID`
     * — kept for the rows to come — and its `VALIDATE` returned, for after the orphans are dealt
     * with; one already there (a second run) is left.
     *
     * @param list<array{sql: string, unchecked: ?string}> $statements
     * @return array{made: int, unchecked: list<string>, errors: list<string>}
     */
    private function finish(array $statements): array
    {
        $done = ['made' => 0, 'unchecked' => [], 'errors' => []];
        foreach ($statements as $statement) {
            if ($this->quietly($statement['sql'])) {
                $done['made']++;
                continue;
            }
            $error = trim((string) strtok(pg_last_error($this->target->getConnectionLink()), "\n"));
            if (str_contains($error, 'already exists')) {
                continue;
            }
            if ($statement['unchecked'] !== null && $this->quietly($statement['sql'] . ' NOT VALID')) {
                $done['made']++;
                $done['unchecked'][] = $statement['unchecked'];
                continue;
            }
            $done['errors'][] = $error;
        }

        return $done;
    }

    /**
     * Whether an earlier run made this table and stopped before its indexes and keys were in.
     * Asked only where the progress table exists, so a dry run on a fresh target creates nothing.
     *
     * @param array<string, mixed> $targets
     */
    private function madeAndUnfinished(string $source, array $targets): bool
    {
        if (!isset($targets[ProgressTracker::TABLE])) {
            return false;
        }

        return ($this->tracker()->of($source, 'created')[0]['status'] ?? null) === ProgressTracker::RUNNING;
    }

    /**
     * What a sample of a table's text says about its encoding: per text column that is not
     * UTF-8 in MySQL's catalogue, its character set; per column holding double-encoded values,
     * how many of the sample and one of them, repaired.
     *
     * @param array<string, mixed> $shape
     * @param list<string>         $copied
     * @return list<array{column: string, charset: ?string, suspect: int, sampled: int, example: ?string, repaired: ?string}>
     */
    private function encodingFindings(string $table, array $shape, array $copied): array
    {
        $text = array_values(array_filter($copied, static fn (string $c): bool => preg_match('/char|text|enum|set/i', (string) $shape['columns'][$c]['type']) === 1));
        if ($text === []) {
            return [];
        }
        $found  = [];
        $counts = array_fill_keys($text, 0);
        $first  = [];
        $rows   = $this->sourceRows('SELECT ' . implode(', ', array_map(static fn (string $c): string => '`' . str_replace('`', '``', $c) . '`', $text))
            . ' FROM `' . str_replace('`', '``', $table) . '` LIMIT ' . self::ENCODING_SAMPLE);
        foreach ($rows as $row) {
            foreach ($text as $column) {
                $value = $row[$column] ?? null;
                if (is_string($value) && ($repaired = TypeMapper::repairEncoding($value)) !== null) {
                    $counts[$column]++;
                    $first[$column] ??= [$value, $repaired];
                }
            }
        }
        foreach ($text as $column) {
            $charset = $shape['columns'][$column]['charset'] ?? null;
            $foreign = $charset !== null && !str_starts_with((string) $charset, 'utf8');
            if ($counts[$column] > 0 || $foreign) {
                $found[] = ['column' => $column, 'charset' => $foreign ? (string) $charset : null, 'suspect' => $counts[$column], 'sampled' => count($rows),
                    'example' => $first[$column][0] ?? null, 'repaired' => $first[$column][1] ?? null];
            }
        }

        return $found;
    }

    /**
     * Whether an insert of these columns names a `GENERATED ALWAYS` identity: PostgreSQL then
     * refuses the value — every row of the table — unless told `OVERRIDING SYSTEM VALUE`. The
     * moved id is the one the rows that point at it name, so it is kept, and the sequence moved
     * past it after.
     *
     * @param list<string> $columns
     */
    private function overriding(string $table, array $columns): bool
    {
        if (!isset($this->always[$table])) {
            [$schema, $bare] = str_contains($table, '.') ? explode('.', $table, 2) : ['public', $table];
            $link = $this->target->getConnectionLink();
            $this->always[$table] = array_column($this->rows("SELECT a.attname AS c FROM pg_attribute a JOIN pg_class t ON t.oid = a.attrelid JOIN pg_namespace n ON n.oid = t.relnamespace"
                . " WHERE n.nspname = '" . pg_escape_string($link, $schema) . "' AND t.relname = '" . pg_escape_string($link, $bare) . "' AND a.attidentity = 'a'"), 'c');
        }

        return array_intersect($this->always[$table], $columns) !== [];
    }

    /** Run a statement on the target; whether it succeeded, the error left for pg_last_error(). */
    private function quietly(string $sql): bool
    {
        return @pg_query($this->target->getConnectionLink(), $sql) !== false;
    }

    /**
     * A column's default as the two servers report it, made one: MySQL gives a literal bare and
     * marks an expression `DEFAULT_GENERATED`; MariaDB quotes a literal, leaves an expression
     * bare and reports no default as `NULL`.
     *
     * @return array{default: ?string, expression: bool}
     */
    private static function sourceDefault(mixed $default, string $extra, bool $mariadb): array
    {
        if ($default === null || ($mariadb && $default === 'NULL')) {
            return ['default' => null, 'expression' => false];
        }
        $default = (string) $default;
        if ($mariadb && preg_match("/^'(.*)'$/s", $default, $m) === 1) {
            return ['default' => str_replace("''", "'", $m[1]), 'expression' => false];
        }

        return ['default' => $default, 'expression' => $mariadb ? !is_numeric($default) : str_contains($extra, 'DEFAULT_GENERATED')];
    }

    /**
     * The `DEFAULT` a created column keeps: the current time, a number, a literal; not an
     * expression PostgreSQL has no word for, and not one that would not fit the column.
     *
     * @param array<string, mixed> $column
     */
    private static function defaultClause(array $column, string $type): string
    {
        $default = $column['default'];
        if ($default === null || $type === 'boolean' || $type === 'bytea') {
            return '';
        }
        if (preg_match('/^current_timestamp(\(\d*\))?$/i', $default) === 1) {
            return ' DEFAULT CURRENT_TIMESTAMP';
        }
        if ($column['expression'] || preg_match('/^0000-00-00/', $default) === 1) {
            return '';
        }
        if (preg_match('/^(smallint|integer|bigint|numeric|real|double precision)/', $type) === 1) {
            return is_numeric($default) ? ' DEFAULT ' . $default : '';
        }

        return " DEFAULT '" . str_replace("'", "''", $default) . "'";
    }

    /** Whether the target user may load with keys and triggers off. */
    private function replicaAllowed(): bool
    {
        $row = $this->rows("SELECT rolsuper AS s FROM pg_roles WHERE rolname = current_user")[0] ?? [];

        return in_array($row['s'] ?? null, [true, 't', 1, '1'], true);
    }

    private function setReplica(bool $on): bool
    {
        try {
            return $this->target->query('SET session_replication_role = ' . ($on ? 'replica' : 'origin')) !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasTargetTable(string $table): bool
    {
        return isset($this->targetTables()[$table]);
    }

    private function hasCompressedChunks(string $table): bool
    {
        try {
            [$schema, $bare] = str_contains($table, '.') ? explode('.', $table, 2) : ['public', $table];

            return $this->target->schema()->hasCompressedChunks($bare, $schema);
        } catch (\Throwable) {
            return false;
        }
    }

    /** `schema.table` or `table`, double-quoted for PostgreSQL. */
    private function quoted(string $table): string
    {
        return implode('.', array_map(static fn (string $part): string => '"' . str_replace('"', '""', $part) . '"', explode('.', $table, 2)));
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $result = $this->target->query($sql);
        $rows   = [];
        while ($result && $result->fetch()) {
            $rows[] = $result->fields;
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function sourceRows(string $sql): array
    {
        $result = $this->source->query($sql);
        $rows   = [];
        while ($result && $result->fetch()) {
            $rows[] = $result->fields;
        }

        return $rows;
    }
}
