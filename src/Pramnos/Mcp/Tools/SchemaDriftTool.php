<?php

declare(strict_types=1);

namespace Pramnos\Mcp\Tools;

use Pramnos\Application\Application;
use Pramnos\Database\MigrationLoader;
use Pramnos\Database\MigrationRunner;
use Pramnos\Mcp\McpToolInterface;

/**
 * MCP tool: the tables the migrations describe, against the tables that are actually there.
 *
 * `list-tables` and `query-schema` read the **live** database; `migration-status` reads the
 * **migrations**. The question that matters is neither of those: *does a migration create this
 * table, and has it run here?* The gap between the two answers is a whole category of bug, and
 * it is invisible from either side.
 *
 * Three findings, and they are three different problems:
 *
 * - **A table nothing creates.** It exists, code queries it, and a fresh installation will not
 *   have it — because it was made by hand, or by a migration somebody deleted, or it is a
 *   leftover from a schema that moved. The failure is a deploy to a new environment, months
 *   later, by somebody who was not there.
 * - **A migration that ran and whose table is missing.** The history says applied; the table is
 *   not there. Something dropped it, or the migration's `up()` returned early on a condition
 *   that is no longer true. This is the alarming one: every future run considers it done.
 * - **A migration that has not run here.** Ordinary — the table is simply not created yet — and
 *   listed apart from the two above so it does not drown them.
 *
 * The migrations are **read, not executed**: their source is tokenised for `createTable()`,
 * `dropTableIfExists()` and `hasTable()` string arguments. Running them to find out what they
 * create is not an option a tool gets to take.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class SchemaDriftTool implements McpToolInterface
{
    /**
     * The schema methods that name a table as their first argument.
     *
     * `hasTable` is in the list, and it is not redundant. A migration that writes raw SQL —
     * which several do, because a hypertable or a schema-qualified table is not something the
     * builder expresses — interpolates the table name into the query, so the *only* literal
     * spelling of it in the file is the `hasTable()` guard above it. Without this the tool
     * reports every such table as created by nothing, which is the loudest possible false
     * alarm about the most carefully written migrations in the project.
     */
    private const CREATORS = ['createtable', 'createtableifnotexists', 'hastable', 'quotetable'];

    /** @var array<string, true> Migrations that legitimately create nothing on some engines. */
    private array $conditional = [];

    /** Set while scanning a migration that names a table with something other than a literal. */
    private bool $unresolved = false;

    /** @var list<string> The migrations whose table names could not be read statically. */
    private array $unreadable = [];

    public function __construct(private readonly Application $app)
    {
    }

    /**
     * Has this migration declared itself conditional?
     *
     * Read from the source rather than from an instance, like everything else here: a migration
     * is a class whose constructor wants an application and whose `up()` talks to a database.
     */
    protected function isConditional(string $source): bool
    {
        return preg_match('/\$conditional\s*=\s*true/i', $source) === 1;
    }

    public function name(): string
    {
        return 'schema-drift';
    }

    public function description(): string
    {
        return 'Which live tables no migration creates, and which migrations have run without '
            . 'leaving their table behind. The gap between the schema on disk and the schema in '
            . 'the database — neither `list-tables` nor `migration-status` can see it.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => [
                    'type' => 'string',
                    'description' => 'Ask about one table: which migration creates it, and '
                        . 'whether that migration has run here.',
                ],
            ],
        ];
    }

    public function execute(array $input): mixed
    {
        $db = $this->app->database ?? null;

        if ($db === null || !$db->connected) {
            return ['error' => 'No database connection, so there is nothing to compare against.'];
        }

        $declared = $this->declaredTables();
        $live     = $this->liveTables();
        $applied  = $this->appliedSlugs();
        $prefix   = (string) ($db->prefix ?? '');

        $one = trim((string) ($input['table'] ?? ''));

        if ($one !== '') {
            return $this->one($one, $declared, $live, $applied, $prefix);
        }

        $unmanaged   = [];
        $missing     = [];
        $pending     = [];
        $conditional = [];

        foreach ($live as $table) {
            if ($this->declarationFor($table, $declared, $prefix) === null) {
                $unmanaged[] = $table;
            }
        }

        foreach ($declared as $table => $slugs) {
            if ($this->isLive($table, $live, $prefix)) {
                continue;
            }

            $ran = array_values(array_filter($slugs, static fn (string $slug): bool => isset($applied[$slug])));

            if ($ran !== []) {
                /*
                 * A migration that declared itself conditional is not a finding.
                 *
                 * `pramnos.framework_policies` exists on MySQL and plain PostgreSQL and must
                 * *not* exist on TimescaleDB, which manages its own policies — so the history
                 * saying applied with no table is the migration behaving exactly as designed.
                 * Reported as drift, it is the loudest possible false alarm, and one false
                 * alarm at the top of a report is enough to make somebody stop reading it.
                 */
                // `$unconditional`, not `$declared`: this loop is `foreach ($declared as …)`,
                // and reassigning it here emptied the map it was iterating — so the report
                // counted zero declared tables while listing findings derived from them.
                $unconditional = array_values(array_filter(
                    $ran,
                    fn (string $slug): bool => !isset($this->conditional[$slug])
                ));

                if ($unconditional === []) {
                    $conditional[] = ['table' => $table, 'migrations' => $ran];

                    continue;
                }

                $missing[] = ['table' => $table, 'migrations' => $unconditional];

                continue;
            }

            $pending[] = ['table' => $table, 'migrations' => array_values($slugs)];
        }

        sort($unmanaged);

        return array_filter([
            'live_tables'     => count($live),
            'declared_tables' => count($declared),
            'unmanaged'       => $unmanaged === [] ? null : $unmanaged,
            'unmanaged_note'  => $unmanaged === [] ? null
                : 'These exist and no migration creates them. A fresh installation will not have '
                . 'them, and the deploy that discovers it will be somebody else\'s.'
                . ($this->unreadable === [] ? '' : ' Read the caveat below before acting on this '
                    . 'list: some of it may be a table this tool could not see.'),
            'unreadable_migrations' => $this->unreadable === [] ? null : $this->unreadable,
            'unreadable_note' => $this->unreadable === [] ? null
                : 'These migrations name their table with a constant or a setting rather than a '
                . 'literal, so it cannot be read without running them — and a table they create '
                . 'appears above as though nothing created it. The migration history table is '
                . 'the other case: it is created by the runner, not by a migration.',
            'applied_but_missing' => $missing === [] ? null : $missing,
            'applied_but_missing_note' => $missing === [] ? null
                : 'The history says these migrations ran, and their table is not there. Every '
                . 'future run will consider them done.',
            'not_created_yet' => $pending === [] ? null : $pending,
            'conditional'     => $conditional === [] ? null : $conditional,
            'conditional_note' => $conditional === [] ? null
                : 'These migrations declared that they create nothing on some engines, and this '
                . 'is one of them. Not drift.',
            'verdict'         => $this->verdict($unmanaged, $missing, $pending),
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * One table: what creates it, and whether that has run.
     *
     * @param array<string, list<string>> $declared
     * @param list<string>                $live
     * @param array<string, mixed>        $applied
     * @return array<string, mixed>
     */
    private function one(string $table, array $declared, array $live, array $applied, string $prefix): array
    {
        $slugs = $this->declarationFor($table, $declared, $prefix)
            ?? $this->declarationFor($prefix . $table, $declared, $prefix);
        $exists = $this->isLive($table, $live, $prefix) || in_array($table, $live, true);

        if ($slugs === null) {
            return [
                'table'  => $table,
                'exists' => $exists,
                'migrations' => [],
                'verdict' => $exists
                    ? 'It is there and no migration creates it. Somebody made it by hand, or the '
                        . 'migration that did was deleted — either way a fresh installation will '
                        . 'not have it.'
                    : 'No migration creates it and it does not exist. If code queries it, that '
                        . 'code is querying a table this project does not have.',
            ];
        }

        $ran = array_values(array_filter($slugs, static fn (string $slug): bool => isset($applied[$slug])));

        return [
            'table'      => $table,
            'exists'     => $exists,
            'migrations' => $slugs,
            'applied'    => $ran,
            'verdict'    => match (true) {
                $exists && $ran !== [] => 'Created by a migration that has run here.',
                $exists                => 'It is there, but the migration that creates it has not '
                    . 'run here — so something else made it, and the two may not agree.',
                $ran !== []            => 'The migration ran and the table is not there. Every '
                    . 'future run will consider it done.',
                default                => 'Not created yet — the migration is pending.',
            },
        ];
    }

    /**
     * Every table name a migration declares, mapped to the migrations that declare it.
     *
     * Read from the source with `token_get_all()` rather than by running anything: a migration
     * is a class whose `up()` talks to a live database, and a tool that executed one to find out
     * what it creates would be a tool that migrates the database by being asked a question.
     *
     * @return array<string, list<string>>
     */
    protected function declaredTables(): array
    {
        $tables = [];

        foreach ($this->migrationFiles() as $path) {
            $source = @file_get_contents($path);

            if ($source === false) {
                continue;
            }

            /*
             * The slug the history records, not the filename.
             *
             * `MigrationRunner` stores `create_users_table` — the part after the timestamp —
             * and comparing the full basename against it matches nothing, so every applied
             * migration reads as "has not run here". Which is a confidently wrong answer about
             * the one thing this tool exists to say.
             */
            $slug = $this->slugOf($path);

            if ($this->isConditional($source)) {
                $this->conditional[$slug] = true;
            }

            $this->unresolved = false;
            $names            = $this->tablesIn($source);

            if ($this->unresolved) {
                $this->unreadable[] = $slug;
            }

            foreach ($names as $table) {
                $tables[$table] ??= [];

                if (!in_array($slug, $tables[$table], true)) {
                    $tables[$table][] = $slug;
                }
            }
        }

        ksort($tables);

        return $tables;
    }

    /**
     * The table names one migration's source creates.
     *
     * Only the creators, not every mention: `hasTable()` guards the creation of the same table,
     * and `dropTableIfExists()` is `down()` undoing it, so counting those would say nothing new.
     * A `table()` in a data migration names something another migration created.
     *
     * @return list<string>
     */
    protected function tablesIn(string $source): array
    {
        $tokens = token_get_all($source);
        $found  = [];
        $count  = count($tokens);

        /*
         * `CREATE TABLE <name>` and `CREATE VIEW <name>`, for the migrations that write SQL.
         *
         * Only when the name is literal — an interpolated `{$t}` is caught by the `hasTable()`
         * guard above it instead, and guessing at a variable's value is how a tool starts
         * reporting tables that do not exist.
         */
        if (preg_match_all(
            '~CREATE\s+(?:OR\s+REPLACE\s+)?(?:MATERIALIZED\s+)?(?:TABLE|VIEW)\s+'
            . '(?:IF\s+NOT\s+EXISTS\s+)?[`"\[]?([A-Za-z0-9_.#]+)[`"\]]?~i',
            $source,
            $matches
        ) > 0) {
            foreach ($matches[1] as $name) {
                /*
                 * The optional `IF NOT EXISTS` group backtracks.
                 *
                 * `CREATE TABLE IF NOT EXISTS {$t}` has no literal name, so the engine gives up
                 * on the optional group and matches `IF` as the table — and the tool reports a
                 * pending migration for a table called "IF". The interpolated case is covered
                 * by the `hasTable()` guard above it; here it just has to be refused.
                 */
                if (in_array(strtoupper($name), ['IF', 'NOT', 'EXISTS'], true)) {
                    continue;
                }

                // `migrations.` — a name cut off at an interpolation, or a sentence in a
                // comment that happened to contain the words. A table name has a body on both
                // sides of any dot in it.
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $name) !== 1) {
                    continue;
                }

                $found[] = $name;
            }
        }

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            if (!in_array(strtolower($token[1]), self::CREATORS, true)) {
                continue;
            }

            // The next non-whitespace token has to be `(`, and the one after it the name.
            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];

                if (is_array($next) && $next[0] === T_WHITESPACE) {
                    continue;
                }

                if ($next !== '(') {
                    break;
                }

                for ($k = $j + 1; $k < $count; $k++) {
                    $argument = $tokens[$k];

                    if (is_array($argument) && $argument[0] === T_WHITESPACE) {
                        continue;
                    }

                    if (is_array($argument) && $argument[0] === T_CONSTANT_ENCAPSED_STRING) {
                        $name = trim($argument[1], "'\"");

                        if ($name !== '') {
                            $found[] = $name;
                        }

                        break;
                    }

                    /*
                     * A table named by something other than a literal.
                     *
                     * `createTable(DeferredWriteQueue::TABLE, …)`, or a name read from a
                     * setting with a default. Statically there is nothing to read, and the
                     * consequence is that the table it creates lands in `unmanaged` — reported
                     * as "no migration creates this", which is false and is the worst thing
                     * this tool can say. So the file is recorded as unreadable instead, and the
                     * report says so beside the list.
                     */
                    $this->unresolved = true;

                    break;
                }

                break;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * The slug a migration file is recorded under, matching {@see \Pramnos\Database\Migration::getSlug()}.
     */
    protected function slugOf(string $path): string
    {
        $base = basename($path, '.php');

        return preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_(.+)$/', $base, $matches) === 1
            ? strtolower($matches[1])
            : strtolower($base);
    }

    /** @return list<string> */
    protected function migrationFiles(): array
    {
        $root  = defined('ROOT') ? (string) ROOT : (string) getcwd();
        $files = [];

        foreach (MigrationLoader::resolveDefaultDirectories($root) as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /** @return list<string> */
    protected function liveTables(): array
    {
        $db = $this->app->database;

        /*
         * The application's own tables, and nothing an extension put in the database.
         *
         * TimescaleDB alone contributes about sixty tables across `_timescaledb_catalog`,
         * `_timescaledb_internal` and friends — none of which any migration creates, all of
         * which are correct. Listed, they bury the three findings that matter under sixty that
         * do not, which is the same as having no tool.
         *
         * The schema is kept for anything outside `public`, because that is how a migration
         * writes it — `authserver.permissions` — and flattening it here is what made a
         * schema-qualified table and an unprefixed legacy one look like the same table.
         *
         * Views are included. A migration that creates one is a migration whose object either
         * exists or does not, and asking only about `BASE TABLE` reported every view in the
         * project as "applied without leaving its table" — the loudest finding this tool has,
         * about the objects it was most confidently right that it had created.
         */
        $sql = $db->type === 'postgresql'
            ? "SELECT table_schema, table_name AS name FROM information_schema.tables
               WHERE table_schema NOT IN ('pg_catalog', 'information_schema')
                 AND table_schema NOT LIKE '\\_timescaledb%'
                 AND table_schema NOT LIKE 'timescaledb%'
                 AND table_type IN ('BASE TABLE', 'VIEW') ORDER BY table_schema, table_name"
            : "SELECT NULL AS table_schema, table_name AS name FROM information_schema.tables
               WHERE table_schema = DATABASE() AND table_type IN ('BASE TABLE', 'VIEW')
               ORDER BY table_name";

        try {
            $result = $db->query($sql);
        } catch (\Throwable) {
            return [];
        }

        $tables = [];

        foreach (($result ? $result->fetchAll() : []) as $row) {
            $name   = (string) ($row['name'] ?? '');
            $schema = (string) ($row['table_schema'] ?? '');

            if ($name === '') {
                continue;
            }

            $tables[] = $schema !== '' && $schema !== 'public'
                ? $schema . '.' . $name
                : $name;
        }

        return $tables;
    }

    /** @return array<string, true> */
    protected function appliedSlugs(): array
    {
        try {
            $history = (new MigrationRunner($this->app->database))->getHistory();
        } catch (\Throwable) {
            return [];
        }

        $slugs = [];

        foreach ($history as $row) {
            $key = (string) ($row['key'] ?? '');

            if ($key !== '') {
                $slugs[$key] = true;
            }
        }

        return $slugs;
    }

    /**
     * The migrations that declare a live table, allowing for the prefix and for a schema name.
     *
     * A migration writes `posts`; the database holds `pf_posts`. And `authserver.permissions`
     * in a migration is `authserver_permissions` on MySQL — the exact mismatch that started
     * this tool, where a schema-qualified name and an unprefixed legacy table looked like two
     * spellings of one thing and were two different tables.
     *
     * @param  array<string, list<string>> $declared
     * @return list<string>|null
     */
    private function declarationFor(string $liveTable, array $declared, string $prefix): ?array
    {
        foreach ($declared as $name => $slugs) {
            if ($this->same($name, $liveTable, $prefix)) {
                return $slugs;
            }
        }

        return null;
    }

    /** @param list<string> $live */
    private function isLive(string $declared, array $live, string $prefix): bool
    {
        foreach ($live as $table) {
            if ($this->same($declared, $table, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Are these two spellings the same table? */
    private function same(string $declared, string $live, string $prefix): bool
    {
        return $this->key($declared, $prefix) === $this->key($live, $prefix);
    }

    /**
     * One table name, normalised to the thing every spelling of it has in common.
     *
     * The same table is written four ways across a project, and comparing the strings is how a
     * drift tool produces a page of findings that are all the same table twice:
     *
     * - `#PREFIX#usersettings` in a migration, `usersettings` in the database;
     * - `authserver.permissions` in a migration on PostgreSQL, `authserver_permissions` on
     *   MySQL, and `permissions` in the legacy code that predates the schema;
     * - a prefix on one side and not the other.
     *
     * So the prefix comes off, the separator is flattened, and the case is dropped. What is
     * deliberately *not* dropped is the schema itself: `authserver.permissions` and a bare
     * legacy `permissions` are two different tables, and treating them as one is the exact bug
     * this tool was written for.
     */
    private function key(string $name, string $prefix): string
    {
        $name = str_replace('#PREFIX#', '', $name);

        if ($prefix !== '' && str_starts_with($name, $prefix)) {
            $name = substr($name, strlen($prefix));
        }

        return strtolower(str_replace('.', '_', trim($name, '`"[] ')));
    }

    /**
     * @param list<string>                $unmanaged
     * @param list<array<string, mixed>>  $missing
     * @param list<array<string, mixed>>  $pending
     */
    private function verdict(array $unmanaged, array $missing, array $pending): string
    {
        if ($unmanaged === [] && $missing === []) {
            return $pending === []
                ? 'Every live table is created by a migration, and every migration has left its '
                    . 'table behind.'
                : count($pending) . ' table(s) are not created yet — run `migrate`. Nothing has '
                    . 'drifted.';
        }

        $parts = [];

        if ($unmanaged !== []) {
            $parts[] = count($unmanaged) . ' live table(s) no migration creates';
        }

        if ($missing !== []) {
            $parts[] = count($missing) . ' migration(s) applied without their table';
        }

        return implode(', and ', $parts) . '. '
            . (count($parts) === 1 ? 'It fails' : 'Both fail')
            . ' on a fresh installation rather than here.';
    }
}
