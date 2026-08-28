<?php

declare(strict_types=1);

namespace Pramnos\Mcp\Tools;

use Pramnos\Mcp\McpToolInterface;

/**
 * MCP tool: say **no** when a documented framework rule has been broken.
 *
 * ## Why this exists, given that the rules are already written down
 *
 * They are, and `framework-docs` now makes them findable. That is a different mechanism from
 * this one, and only this one has evidence behind it: **every rule checked here is something
 * that happened after the guide describing it was written.** Prose tells a reader who goes
 * looking; this tells one who did not.
 *
 * The six rules are not a style guide. Each is a defect that is invisible at the moment it is
 * introduced — the wrong table name that matches nothing, the flash message that reappears on
 * reload, the view variable that vanishes, the migration an installation skips. None of them
 * fails loudly, which is exactly why a mechanical check earns its keep.
 *
 * ## What it will not do
 *
 * It will not guess. A finding here has to name a **construction**, not a name that resembles
 * one — a lesson from a check in this framework's own history that flagged `var rows` in six
 * unrelated functions because it matched an identifier rather than a redeclaration, and was
 * deleted for it. Where a rule genuinely cannot be decided mechanically, the finding says so
 * in its own text rather than asserting a verdict it cannot support.
 *
 * ## Suppression, with a reason that is not optional
 *
 * ```php
 * // pramnos-check: ignore raw-sql — window function the builder cannot express
 * $result = $db->query('SELECT ... OVER (PARTITION BY ...)');
 * ```
 *
 * Same line or the line above. The reason after the dash is **required**: a suppression with
 * no reason is reported as its own finding, because the point of rule 12's "leave a one-line
 * comment saying why" is that the next reader knows it was considered rather than missed.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class PramnosCheckTool implements McpToolInterface
{
    /**
     * Directory names never scanned, at any depth.
     *
     * `vendor` first: the framework's own code is not the caller's to fix, and scanning it
     * would report the framework to itself.
     *
     * @var list<string>
     */
    private const SKIP_DIRECTORIES = [
        'vendor', 'node_modules', '.git', '.svn', 'site', 'coverage', 'cache',
        'build', 'dist', '.svelte-kit', 'var',
    ];

    /**
     * Every rule, with the one-line statement a finding carries.
     *
     * @var array<string, string>
     */
    private const RULES = [
        'raw-sql'                       => 'Raw SQL where the query builder belongs',
        'unqualified-authserver'        => 'An authserver table named without its schema',
        'flash-query-params'            => 'A message passed as a query parameter',
        'view-reserved-props'           => 'A view variable that collides with the View engine',
        'baseline-migration-timestamp'  => 'A migration using the 2020_01_01 baseline epoch',
        'duplicate-debug-panel'         => 'A second debug panel beside the framework\'s own',
        'unexplained-suppression'       => 'A pramnos-check suppression with no reason',
    ];

    /**
     * The project being checked.
     *
     * @var string
     */
    private string $root;

    /**
     * @param string|null $root The project root; defaults to `ROOT`, which is the consuming
     *                          application — deliberately unlike {@see FrameworkDocsTool},
     *                          which reads the framework's own directory.
     */
    public function __construct(?string $root = null)
    {
        $this->root = $root ?? (defined('ROOT') ? (string) ROOT : (string) getcwd());
    }

    /**
     * Machine-readable identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'pramnos-check';
    }

    /**
     * One sentence for `tools/list`.
     *
     * @return string
     */
    public function description(): string
    {
        return 'Check this application against the framework rules that are broken in '
            . 'practice: raw SQL where the query builder belongs, authserver tables named '
            . 'without their schema, flash messages passed as query parameters, view '
            . 'variables that collide with the View engine, migrations on the skipped '
            . 'baseline epoch, and a second debug panel. Run it after writing code and '
            . 'before calling a change finished — each of these fails silently.';
    }

    /**
     * Input schema.
     *
     * @return array{type: string, properties: array<string, mixed>}
     */
    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'path' => [
                    'type'        => 'string',
                    'description' => 'Project-relative subtree to check — e.g. "src/Models" '
                        . 'or a single file. Omit to check the whole project.',
                ],
                'rules' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string', 'enum' => array_keys(self::RULES)],
                    'description' => 'Rules to run. Omit to run all of them.',
                ],
                'since' => [
                    'type'        => 'string',
                    'description' => 'Only findings on lines you changed. `HEAD` for everything '
                        . 'uncommitted, `staged` for the index, or any ref (`main`, `HEAD~3`). '
                        . 'This is the one to use before calling a change finished — without it '
                        . 'the answer is dominated by findings older than your work.',
                ],
            ],
        ];
    }

    /**
     * Run the checks.
     *
     * @param  array<string, mixed> $input `path` and `rules`, both optional
     * @return array<string, mixed>
     */
    public function execute(array $input): mixed
    {
        $target = isset($input['path']) ? trim((string) $input['path']) : '';
        $rules  = $this->requestedRules($input);
        $since  = isset($input['since']) ? trim((string) $input['since']) : '';

        if ($rules === []) {
            return [
                'error'     => 'No known rule was requested.',
                'available' => self::RULES,
            ];
        }

        $scanRoot = $this->resolveTarget($target);
        if ($scanRoot === null) {
            return ['error' => 'No such path in this project: ' . $target];
        }

        $files = $this->files($scanRoot);

        // Reported rather than returned as "nothing wrong". An empty scan satisfies every
        // rule perfectly, and the two answers mean opposite things.
        if ($files === []) {
            return [
                'error' => 'No files to check under ' . $this->relative($scanRoot)
                    . '. Nothing was verified — this is not a pass.',
            ];
        }

        $findings = [];
        foreach ($files as $path => $contents) {
            foreach ($this->inspect($path, $contents, $rules) as $finding) {
                $findings[] = $finding;
            }
        }

        // Migrations and the debug panel are properties of the tree, not of one file.
        if (in_array('baseline-migration-timestamp', $rules, true)) {
            $findings = array_merge($findings, $this->checkMigrationNames($scanRoot));
        }
        if (in_array('duplicate-debug-panel', $rules, true)) {
            $findings = array_merge($findings, $this->checkDebugPanels($files));
        }

        usort(
            $findings,
            fn(array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]
        );

        $answer = [
            'checked' => count($files),
            'root'    => $this->relative($scanRoot),
            'rules'   => $rules,
        ];

        if ($since !== '') {
            $narrowed = $this->narrowToDiff($findings, $since);

            if (isset($narrowed['error'])) {
                return $narrowed + $answer;
            }

            $answer += $narrowed;
            $findings = $narrowed['findings'];
        }

        $answer['findings'] = $findings;
        $answer['verdict']  = $findings === []
            ? ($since !== ''
                ? 'No findings on the lines you changed.'
                : 'No findings. ' . count($files) . ' files checked against '
                    . count($rules) . ' rules.')
            : count($findings) . ' finding(s). Each of these fails silently at runtime, '
                . 'so none of them will show up as an error later.';

        return $answer;
    }

    /**
     * Only the findings that sit on lines this change touched.
     *
     * The reason the whole `since` option exists. Run over `src/`, this tool reports nine
     * raw-SQL findings and sixty-seven flash-query-parameter ones — every one of them older
     * than whatever is being worked on. Its own guide says so. The predictable consequence is
     * that nobody runs it, including the assistant told to run it before calling a change
     * finished: with seventy-six pre-existing findings there is no way to see your own three.
     *
     * Two findings are kept regardless of line: the ones about the *tree* rather than a line —
     * a migration on the skipped baseline epoch, and a duplicate debug panel — because both are
     * caused by adding a file, and the file being new is the finding.
     *
     * @param  list<array<string, mixed>> $findings
     * @return array<string, mixed>
     */
    private function narrowToDiff(array $findings, string $since): array
    {
        $changes = (new \Pramnos\Support\GitChanges($this->root))->changedLines($since);

        if ($changes['error'] !== null) {
            return [
                'error' => $changes['error'],
                'note'  => 'Without a diff there is nothing to narrow to. Drop `since` to '
                    . 'check everything, and read the count as a baseline rather than as a '
                    . 'verdict on your change.',
            ];
        }

        $kept = [];

        foreach ($findings as $finding) {
            $file = (string) ($finding['file'] ?? '');
            $line = (int) ($finding['line'] ?? 0);

            // A whole-file finding — line 1 by convention — is kept when the file itself is
            // new or touched, because there is no meaningful line to compare.
            $wholeFile = in_array(
                (string) ($finding['rule'] ?? ''),
                ['baseline-migration-timestamp', 'duplicate-debug-panel'],
                true
            );

            if (!isset($changes['files'][$file])) {
                continue;
            }

            if ($wholeFile || \Pramnos\Support\GitChanges::touches($changes['files'], $file, $line)) {
                $kept[] = $finding;
            }
        }

        return [
            'since'         => $changes['ref'],
            'changed_files' => count($changes['files']),
            'changed_lines' => array_sum(array_map('count', $changes['files'])),
            'suppressed'    => count($findings) - count($kept),
            'findings'      => $kept,
            'note'          => count($findings) === count($kept)
                ? null
                : (count($findings) - count($kept)) . ' finding(s) elsewhere in the project '
                    . 'were left out — they are not from this change. Drop `since` to see them.',
        ];
    }

    /**
     * The rules to run, validated against the known set.
     *
     * @param  array<string, mixed> $input The tool input
     * @return list<string>
     */
    private function requestedRules(array $input): array
    {
        if (!isset($input['rules']) || !is_array($input['rules']) || $input['rules'] === []) {
            return array_keys(self::RULES);
        }

        $rules = [];
        foreach ($input['rules'] as $rule) {
            $rule = (string) $rule;
            if (isset(self::RULES[$rule])) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * An absolute path inside the project, or null.
     *
     * @param  string $target A project-relative path, possibly empty
     * @return string|null
     */
    private function resolveTarget(string $target): ?string
    {
        $base = realpath($this->root);
        if ($base === false) {
            return null;
        }
        if ($target === '') {
            return $base;
        }

        $resolved = realpath($base . '/' . ltrim($target, '/'));

        // Inside the project or nowhere. The path arrives from a model, and a check tool is
        // not a way to read arbitrary files from disk.
        return ($resolved !== false && str_starts_with($resolved, $base)) ? $resolved : null;
    }

    /**
     * Every checkable file under a root.
     *
     * @param  string $scanRoot Absolute path to a file or directory
     * @return array<string, string> Project-relative path => contents
     */
    private function files(string $scanRoot): array
    {
        $extensions = ['php', 'js', 'mjs', 'ts', 'svelte', 'vue'];

        if (is_file($scanRoot)) {
            $contents = @file_get_contents($scanRoot);

            return $contents === false ? [] : [$this->relative($scanRoot) => $contents];
        }

        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($scanRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }
            if (!in_array(strtolower($file->getExtension()), $extensions, true)) {
                continue;
            }

            $relative = $this->relative($file->getPathname());
            if ($this->isSkipped($relative)) {
                continue;
            }

            $contents = @file_get_contents($file->getPathname());
            if ($contents !== false) {
                $files[$relative] = $contents;
            }
        }

        return $files;
    }

    /**
     * Whether a project-relative path lies in a skipped directory.
     *
     * @param  string $relative Project-relative path
     * @return bool
     */
    private function isSkipped(string $relative): bool
    {
        foreach (explode('/', $relative) as $segment) {
            if (in_array($segment, self::SKIP_DIRECTORIES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A path relative to the project root.
     *
     * @param  string $absolute An absolute path
     * @return string
     */
    private function relative(string $absolute): string
    {
        $base = realpath($this->root);

        return ($base !== false && str_starts_with($absolute, $base . '/'))
            ? substr($absolute, strlen($base) + 1)
            : $absolute;
    }

    /**
     * Every finding in one file.
     *
     * @param  string       $path     Project-relative path
     * @param  string       $contents The file
     * @param  list<string> $rules    Rules to apply
     * @return list<array<string, mixed>>
     */
    private function inspect(string $path, string $contents, array $rules): array
    {
        $findings = [];

        // Two views of the same file, and both are needed.
        //
        // Rules match against **code**, with comment bodies blanked: a docblock showing
        // `$db->query('SELECT NOW()')` as an example is documentation, not a defect, and the
        // first version of this tool reported exactly that in `Tinker`'s own usage note. A
        // check that flags the guide teaching the right thing is a check nobody keeps running.
        //
        // Suppressions are read from the **original** lines, because a suppression *is* a
        // comment. Blanking first and then looking for it — which is what the first version of
        // this method did — silences nothing and fails its own tests.
        $raw  = preg_split('/\R/', $contents) ?: [];
        $code = preg_split('/\R/', $this->blankComments($path, $contents)) ?: [];

        foreach ($code as $index => $line) {
            $number      = $index + 1;
            $rawLine     = $raw[$index] ?? '';
            $rawPrevious = $raw[$index - 1] ?? '';

            foreach ($rules as $rule) {
                $finding = match ($rule) {
                    'raw-sql'                  => $this->rawSql($path, $line),
                    'unqualified-authserver'   => $this->unqualifiedAuthserver($path, $line),
                    'flash-query-params'       => $this->flashQueryParams($path, $line),
                    'view-reserved-props'      => $this->viewReservedProps($path, $line),
                    // Reads the original line: what it looks for only ever appears in one.
                    'unexplained-suppression'  => $this->unexplainedSuppression($path, $rawLine),
                    default                    => null,
                };

                if ($finding === null) {
                    continue;
                }
                if ($this->isSuppressed($rule, $rawLine, $rawPrevious)) {
                    continue;
                }

                $findings[] = $finding + [
                    'rule'    => $rule,
                    'file'    => $path,
                    'line'    => $number,
                    'excerpt' => trim(mb_substr($rawLine, 0, 200)),
                ];
            }
        }

        return $findings;
    }

    /**
     * The file with comment bodies blanked, preserving line numbering.
     *
     * `token_get_all()` for PHP, so a `//` inside a string literal is not mistaken for a
     * comment. For the JavaScript-family files a regular expression is used, which is
     * imprecise in principle and sufficient here: no rule that runs against those files
     * matches on anything a comment would plausibly contain.
     *
     * Newlines inside a stripped block are kept, or every finding after the first docblock
     * would report the wrong line.
     *
     * @param  string $path     Project-relative path
     * @param  string $contents The file
     * @return string
     */
    private function blankComments(string $path, string $contents): string
    {
        if (!str_ends_with($path, '.php')) {
            return (string) preg_replace_callback(
                '#/\*.*?\*/|//[^\r\n]*#s',
                fn(array $m): string => (string) preg_replace('/[^\r\n]/', ' ', $m[0]),
                $contents
            );
        }

        $out = '';
        foreach (@token_get_all($contents) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    $out .= preg_replace('/[^\r\n]/', ' ', $token[1]);
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    /**
     * Whether a rule is suppressed for this line, with a reason.
     *
     * A suppression carrying no reason does not suppress. It is reported by
     * {@see unexplainedSuppression()} instead, so silencing a check always leaves a sentence
     * behind saying why.
     *
     * @param  string $rule     The rule name
     * @param  string $line     The offending line
     * @param  string $previous The line above it
     * @return bool
     */
    private function isSuppressed(string $rule, string $line, string $previous): bool
    {
        $pattern = '/pramnos-check:\s*ignore\s+' . preg_quote($rule, '/')
            . '\s*[—\-]\s*(\S.*)$/u';

        return (bool) (preg_match($pattern, $line) || preg_match($pattern, $previous));
    }

    /**
     * Rule 12 — raw SQL in code the query builder can express.
     *
     * Only DML is reported. DDL, `information_schema` introspection and driver-specific
     * features stay raw by the rule's own text, so flagging them would train a reader to
     * ignore this check — and migrations are excluded wholesale for the same reason: they
     * must emit exact SQL.
     *
     * Tests are excluded too. A fixture that inserts a row with a literal statement is
     * setting up a database, not expressing application logic, and the builder would make it
     * harder to see what the fixture is.
     *
     * @param  string $path The file
     * @param  string $line The line
     * @return array<string, string>|null
     */
    private function rawSql(string $path, string $line): ?array
    {
        if ($this->isMigration($path) || $this->isTest($path)) {
            return null;
        }

        // A statement passed to the database, not the word SELECT in prose. `query(`,
        // `prepareQuery(`, `execute(` — followed on the same line by a quoted DML verb.
        if (!preg_match('/\b(?:query|prepareQuery|execute)\s*\(\s*[\'"]?\s*(select|insert\s+into|update|delete\s+from)\b/i', $line)) {
            return null;
        }

        // Introspection and driver-specific catalogs, which rule 12 exempts in its own text.
        // `timescaledb_information` and `pg_extension` are here because they were reported by
        // the first run of this tool against the framework itself — a reminder that the
        // exemption list is discovered, not designed.
        if (preg_match(
            '/information_schema|pg_catalog|pg_class|pg_extension|pg_stat|'
            . 'timescaledb_information|SHOW\s+(COLUMNS|TABLES|INDEX|STATUS|VARIABLES)/i',
            $line
        )) {
            return null;
        }

        // A statement with no table to address is not something the builder could express:
        // `SELECT version()`, `SELECT NOW()`, `SELECT DATABASE()`, `SELECT LASTVAL()`,
        // `SELECT 1` as a connectivity probe, `select @@global.long_query_time`. Sixteen of
        // the twenty-nine findings from the first run against this framework were of exactly
        // this shape, and every one of them was noise.
        if (preg_match('/\bselect\b/i', $line) && !preg_match('/\bfrom\b/i', $line)) {
            return null;
        }
        if (preg_match('/@@|\b(?:version|now|database|lastval|last_insert_id|current_setting)\s*\(/i', $line)
            && !preg_match('/\bfrom\s+[`"\']?[a-z_#]/i', $line)
        ) {
            return null;
        }

        return [
            'summary' => self::RULES['raw-sql'],
            'why'     => 'The builder is the only layer that knows the dialect. It resolves '
                . 'schema-qualified names per driver, quotes identifiers, and binds '
                . 'parameters instead of interpolating them. Hand-written SQL has already '
                . 'produced both of those bugs in practice.',
            'fix'     => 'Rewrite with $db->queryBuilder()->table(...)->where(...)->get(). '
                . 'If this statement genuinely cannot be expressed — a window function, a '
                . 'driver-specific feature — suppress it with the reason: '
                . '// pramnos-check: ignore raw-sql — <why>',
        ];
    }

    /**
     * An `authserver` table named without its schema.
     *
     * The table list is read from the framework's own migrations rather than hardcoded, so it
     * cannot drift out of step with the schema the framework creates.
     *
     * Fails silently and in opposite ways per driver: on PostgreSQL `authserver` is a real
     * schema and is not on the default `search_path`, so an unqualified name matches nothing;
     * on MySQL the qualified name becomes `authserver_x`, a different table entirely.
     *
     * @param  string $path The file
     * @param  string $line The line
     * @return array<string, string>|null
     */
    private function unqualifiedAuthserver(string $path, string $line): ?array
    {
        foreach ($this->authserverTables() as $table) {
            // `->table('x')`, `from x`, `join x` — a table position, not the word anywhere.
            $pattern = '/(?:->table\s*\(\s*[\'"]|\b(?:from|join|into|update)\s+[`"\']?)'
                . '(?<!authserver[._])' . preg_quote($table, '/') . '\b/i';

            if (preg_match($pattern, $line)) {
                return [
                    'summary' => self::RULES['unqualified-authserver'] . ": {$table}",
                    'why'     => 'On PostgreSQL `authserver` is a real schema and is not on '
                        . 'the default search_path, so the unqualified name matches nothing '
                        . 'and the query returns no rows without an error. On MySQL the '
                        . 'qualified form resolves to `authserver_' . $table . '`, so the '
                        . 'unqualified name is a different table again.',
                    'fix'     => "Write it as 'authserver.{$table}' and let the builder "
                        . 'resolve it per driver.',
                ];
            }
        }

        return null;
    }

    /**
     * The framework's `authserver`-schema tables, read from its own migrations.
     *
     * @return list<string>
     */
    private function authserverTables(): array
    {
        static $tables = null;
        if ($tables !== null) {
            return $tables;
        }

        $tables    = [];
        $directory = dirname(__DIR__, 4) . '/database/migrations/framework';

        if (is_dir($directory)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $source = @file_get_contents($file->getPathname());
                if ($source === false) {
                    continue;
                }
                if (preg_match_all('/[\'"]authserver\.([a-z0-9_]+)[\'"]/i', $source, $m)) {
                    foreach ($m[1] as $table) {
                        $tables[strtolower($table)] = true;
                    }
                }
            }
        }

        $tables = array_keys($tables);
        sort($tables);

        return $tables;
    }

    /**
     * A message or error passed as a query parameter instead of a flash message.
     *
     * The symptom is a message that reappears every time the page is reloaded, because the
     * text is in the URL rather than consumed once from the session.
     *
     * @param  string $path The file
     * @param  string $line The line
     * @return array<string, string>|null
     */
    private function flashQueryParams(string $path, string $line): ?array
    {
        // In a URL position: after `?` or `&`, with a value being assigned. Not `$message =`,
        // and not a form field named message.
        if (!preg_match('/[?&](message|error|success|warning)=/i', $line, $match)) {
            return null;
        }

        // Reading one is how an application supports an inbound link it does not control.
        if (preg_match('/\b(getParam|get\s*\(|\$_GET|input\s*\(|request)\b/i', $line)) {
            return null;
        }

        return [
            'summary' => self::RULES['flash-query-params'] . ": ?{$match[1]}=",
            'why'     => 'A message in the URL is shown again on every reload, and stays in '
                . 'browser history and in whatever the user pastes when asking for help. It '
                . 'is also user-controlled text arriving in a page that displays it.',
            'fix'     => 'Use the flash API: $this->addMessage(\'…\') or '
                . '$this->addError(\'…\') before redirecting. The view reads it once.',
        ];
    }

    /**
     * A view variable whose name the View engine already uses.
     *
     * Matched as an **assignment onto a view object**, not as the appearance of the word. A
     * check in this framework's history flagged an identifier rather than a construction and
     * had to be deleted; the shape matters more than the name.
     *
     * @param  string $path The file
     * @param  string $line The line
     * @return array<string, string>|null
     */
    private function viewReservedProps(string $path, string $line): ?array
    {
        $reserved = 'sections|path|model|_layout';

        if (!preg_match(
            '/(?:\$\w*[Vv]iew\w*|\$this->view)\s*->\s*(' . $reserved . ')\s*=(?!=)/',
            $line,
            $match
        )) {
            return null;
        }

        return [
            'summary' => self::RULES['view-reserved-props'] . ": \${$match[1]}",
            'why'     => 'The View engine uses this name internally, so the value is '
                . 'overwritten and the variable is simply absent in the template — with no '
                . 'error, no warning, and nothing in the log.',
            'fix'     => 'Rename the variable — e.g. $' . $match[1] . 'List, $current'
                . ucfirst($match[1]) . ' — and use the new name in the template.',
        ];
    }

    /**
     * A suppression comment with no reason after it.
     *
     * The reason is the entire value of the mechanism. `ignore raw-sql` with nothing after it
     * silences the check and tells the next reader nothing, which is the state the rule's own
     * "leave a one-line comment saying why" exists to prevent.
     *
     * @param  string $path The file
     * @param  string $line The line
     * @return array<string, string>|null
     */
    private function unexplainedSuppression(string $path, string $line): ?array
    {
        if (!preg_match('/pramnos-check:\s*ignore\s+([a-z\-]+)\s*(.*)$/u', $line, $match)) {
            return null;
        }

        $remainder = trim($match[2]);
        if (preg_match('/^[—\-]\s*\S/u', $remainder)) {
            return null;   // has a reason
        }

        return [
            'summary' => self::RULES['unexplained-suppression'] . ": {$match[1]}",
            'why'     => 'A suppression with no reason silences the check and leaves the next '
                . 'reader unable to tell a considered exception from an oversight. This one '
                . 'does not suppress anything.',
            'fix'     => "// pramnos-check: ignore {$match[1]} — <why this is correct here>",
        ];
    }

    /**
     * Migrations on the baseline epoch that existing installations skip.
     *
     * A separate pass because it is a property of a filename, not of any line inside it.
     *
     * Deliberately advisory in its wording: a project whose database predates the migration
     * system legitimately *has* `2020_01_01_*` files, and nothing in the filesystem
     * distinguishes those from a new one written today. The finding states the test to apply
     * rather than asserting a verdict it cannot support.
     *
     * @param  string $scanRoot Absolute path being checked
     * @return list<array<string, mixed>>
     */
    private function checkMigrationNames(string $scanRoot): array
    {
        $findings = [];

        foreach ($this->files($scanRoot) as $path => $contents) {
            if (!$this->isMigration($path)) {
                continue;
            }
            if (!preg_match('/(^|\/)2020_01_01_\d+_/', $path)) {
                continue;
            }
            if ($this->isSuppressed('baseline-migration-timestamp', $contents, '')) {
                continue;
            }

            $findings[] = [
                'rule'    => 'baseline-migration-timestamp',
                'file'    => $path,
                'line'    => 1,
                'excerpt' => basename($path),
                'summary' => self::RULES['baseline-migration-timestamp'],
                'why'     => 'The 2020_01_01 prefix is the baseline epoch. Installations that '
                    . 'predate the migration system set migration_cutoff = 2020_01_02_000000 '
                    . 'to skip all of it, so a new migration with this prefix is silently '
                    . 'never run there — and nothing reports that it was skipped.',
                'fix'     => 'If this migration is new, rename it with today\'s date '
                    . '(' . date('Y_m_d') . '_000001_…). If it is part of the original '
                    . 'baseline, suppress it: // pramnos-check: ignore '
                    . 'baseline-migration-timestamp — original baseline migration',
            ];
        }

        return $findings;
    }

    /**
     * A second debug panel, beside the one the framework ships.
     *
     * Identified by what only a panel does: **consume the `_debug` payload** the framework
     * injects into API responses. That is a construction — nothing else has a reason to read
     * that key — rather than a filename that looks debug-ish, which is the matcher that
     * failed here before.
     *
     * Reported only when the framework's own `lib/debug.js` is present, because otherwise
     * there is nothing being duplicated: a project scaffolded before it existed has to read
     * the payload itself.
     *
     * @param  array<string, string> $files The scanned files
     * @return list<array<string, mixed>>
     */
    private function checkDebugPanels(array $files): array
    {
        $frameworkPanel = null;
        foreach (array_keys($files) as $path) {
            if (str_ends_with($path, 'lib/debug.js')) {
                $frameworkPanel = $path;
                break;
            }
        }

        if ($frameworkPanel === null) {
            return [];
        }

        $findings = [];
        foreach ($files as $path => $contents) {
            if ($path === $frameworkPanel) {
                continue;
            }
            if (!preg_match('/\.(js|mjs|ts|svelte|vue)$/i', $path)) {
                continue;
            }
            if (!preg_match('/(?:\.|\[[\'"])_debug\b/', $contents, $match, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            if ($this->isSuppressed('duplicate-debug-panel', $contents, '')) {
                continue;
            }

            $findings[] = [
                'rule'    => 'duplicate-debug-panel',
                'file'    => $path,
                'line'    => 1 + substr_count(substr($contents, 0, $match[0][1]), "\n"),
                'excerpt' => 'reads the _debug payload',
                'summary' => self::RULES['duplicate-debug-panel'],
                'why'     => 'The framework ships ' . $frameworkPanel . ', already wired to '
                    . 'this payload. A second reader of `_debug` is usually a panel written '
                    . 'because the first one was not known to exist — which has happened, and '
                    . 'is the reason the documentation rules were rewritten.',
                'fix'     => 'Use the shipped panel. If this file consumes `_debug` for '
                    . 'something else — a test, a custom report — suppress it with that '
                    . 'reason: // pramnos-check: ignore duplicate-debug-panel — <why>',
            ];
        }

        return $findings;
    }

    /**
     * Whether a path is a migration.
     *
     * @param  string $path Project-relative path
     * @return bool
     */
    private function isMigration(string $path): bool
    {
        return str_contains($path, 'migrations/');
    }

    /**
     * Whether a path is test code.
     *
     * @param  string $path Project-relative path
     * @return bool
     */
    private function isTest(string $path): bool
    {
        return str_starts_with($path, 'tests/')
            || str_contains($path, '/tests/')
            || str_ends_with($path, 'Test.php')
            // `Testing/` holds the base classes tests extend — fixture teardown, not
            // application logic. `DatabaseTestCase` deleting rows with a literal statement is
            // clearer than the builder would be, and it is not shipped behaviour.
            || str_contains($path, '/Testing/');
    }
}
