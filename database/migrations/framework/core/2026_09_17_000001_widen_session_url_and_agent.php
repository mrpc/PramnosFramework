<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Migration;

/**
 * Widens `sessions.url` and `sessions.agent` to `text`.
 *
 * ## What the ceiling actually cost
 *
 * `sessions` is a tracking table: one row per visitor, rewritten on every request with the
 * address they are on. The address was `varchar(255)`, and an OAuth callback is longer than
 * that as a matter of course — three Google scopes, `state`, `code`, `iss`, `authuser` and
 * `prompt` came to **401 characters** on the first working round trip a consuming
 * application made.
 *
 * PostgreSQL refuses the whole statement:
 *
 * ```
 * ERROR:  value too long for type character varying(255)
 * INSERT INTO public."sessions" ("visitorid", "uname", …)
 * ```
 *
 * The tracking write then took the visitor's session with it — see
 * {@see \Pramnos\Http\Middleware\SessionTrackingMiddleware} for that half, which is fixed
 * separately and is the more serious of the two. What the person saw was the sign-in page
 * at the end of a successful OAuth consent, which reads as an expired session, a cookie
 * problem, a `SameSite` mistake — everything except a column width.
 *
 * ## Both columns, in one statement
 *
 * `agent` has the same ceiling and the same consequence for a long User-Agent. It is
 * truncated in PHP before the insert, so it never raised — which is exactly why it is worth
 * widening here rather than leaving: the truncation is a workaround for a column width, and
 * once the width is gone the workaround can go with it.
 *
 * One `ALTER` for both, because on MySQL each one is a full table rebuild. Measured on
 * 200,000 rows: **711 ms for both together against 1,564 ms for two separate statements**,
 * and the table is locked for the duration either way.
 *
 * ## Cost, measured rather than assumed
 *
 * | | 200,000 rows | why |
 * |---|---|---|
 * | PostgreSQL | **1.4 ms** | `varchar(n)` → `text` is binary-coercible with no new constraint, so it is a catalogue update. No rewrite, no scan; an `ACCESS EXCLUSIVE` lock for the length of that. |
 * | MySQL | **711 ms** | `ALGORITHM=INPLACE` is refused outright (*"Cannot change column type INPLACE"*), so it is `COPY`: the table is rebuilt and writes block. Roughly linear — a million rows is about four seconds. |
 *
 * In practice the table is bounded by five minutes of visitors: the middleware sweeps
 * `time < now-300` and nothing reads a stale row. 200,000 rows is therefore already an
 * unrealistic worst case, and it is the MySQL number that would matter if it were not.
 *
 * ## MySQL drops `NOT NULL` if you let it
 *
 * `MODIFY <column> TEXT` replaces the **whole** definition, so omitting `NOT NULL` makes
 * the column nullable — silently, and only on MySQL, where PostgreSQL's `ALTER … TYPE`
 * keeps it. Left alone, this migration would have given the two engines different schemas
 * and let the upsert write a null `url` into code that has never had one. The nullability is
 * read first and re-stated, rather than assumed: an installation whose `sessions` predates
 * the migration system may legitimately have either.
 */
class WidenSessionUrlAndAgent extends Migration
{
    public string $feature      = 'core';
    public string $scope        = 'framework';

    /**
     * After `create_sessions_table`, which is priority 10.
     */
    public int    $priority     = 15;

    public array  $dependencies = ['create_sessions_table'];

    public $description = 'Widens sessions.url and sessions.agent to text so a long URL cannot fail the tracking write';

    /**
     * The columns that hold something with no length anybody controls.
     *
     * @var list<string>
     */
    private const COLUMNS = ['url', 'agent'];

    /**
     * One `getColumns()` read per column, kept.
     *
     * @var array<string, array{type: string, nullable: bool}|null>
     */
    private array $shapes = [];

    public function up(): void
    {
        $schema = $this->DB()->schema();

        if (!$schema->hasTable('sessions')) {
            // Nothing to widen. `create_sessions_table` declares `text` now, so a database
            // created after this lands never reaches here with a narrow column.
            return;
        }

        $narrow = array_values(array_filter(
            self::COLUMNS,
            fn(string $column): bool => $schema->hasColumn('sessions', $column)
                && $this->isNarrow($column)
        ));

        if ($narrow === []) {
            // Every database the framework created is already `text`. Saying so and stopping
            // is the whole of the work on those, and on MySQL it saves a table rebuild.
            return;
        }

        if ($schema->getCapabilities()->isPostgreSQL()) {
            $this->widenPostgreSQL($narrow);

            return;
        }

        $this->widenMySQL($narrow);
    }

    /**
     * Deliberately empty.
     *
     * Narrowing either column again would truncate the rows that have used the room, to
     * restore a defect. There is also no width to go back to that is right everywhere: an
     * installation whose `sessions` predates the migration system may have had any.
     */
    public function down(): void
    {
    }

    /**
     * One `ALTER`, unless something is looking at the column.
     *
     * `ALTER … TYPE` is refused outright when a view or rule selects the column:
     *
     * ```
     * ERROR:  cannot alter type of a column used by a view or rule
     * DETAIL:  rule _RETURN on view app_active_pages depends on column "url"
     * ```
     *
     * The framework creates no view over `sessions`, so this cannot happen because of
     * anything it ships. An **application** may well have one — a live-page report over
     * `url` is an obvious thing to build — and the framework must not drop and recreate
     * somebody else's view to get its own `ALTER` through. {@see WidenApplicationsCallback}
     * can do exactly that only because it owns every view in the way.
     *
     * So the dependents are found first, and if there are any the migration **declines**:
     * the run carries on, `migrate:status` shows why and names them, and the next `migrate`
     * tries again once they are gone. Throwing would stop the whole run on a tracking
     * column, which is the wrong trade by a wide margin.
     *
     * @param list<string> $columns The narrow columns
     */
    private function widenPostgreSQL(array $columns): void
    {
        $dependents = $this->dependentViews($columns);
        if ($dependents !== []) {
            $this->decline(
                'PostgreSQL refuses ALTER … TYPE on a column a view selects, and these '
                . 'select sessions.' . implode('/', $columns) . ': '
                . implode(', ', $dependents)
                . '. Drop them, run migrate, and recreate them — or widen the columns by '
                . 'hand with ALTER TABLE sessions ALTER COLUMN url TYPE text (a catalogue '
                . 'change, no table rewrite), after which this migration finds nothing to do.'
            );

            return;
        }

        $this->DB()->query(
            'ALTER TABLE sessions '
            . implode(', ', array_map(
                fn(string $c): string => 'ALTER COLUMN "' . $c . '" TYPE text',
                $columns
            ))
        );

        $this->logWidened($columns);
    }

    /**
     * One `ALTER`, restating the nullability MySQL would otherwise discard.
     *
     * @param list<string> $columns The narrow columns
     */
    private function widenMySQL(array $columns): void
    {
        $clauses = [];
        foreach ($columns as $column) {
            // `TEXT` cannot carry a `DEFAULT` in MySQL, and neither column has ever had one,
            // so nullability is the only part of the definition there is to preserve.
            $clauses[] = 'MODIFY `' . $column . '` TEXT'
                . ($this->isNullable($column) ? ' NULL' : ' NOT NULL');
        }

        $this->DB()->query(
            'ALTER TABLE `' . $this->DB()->prefix . 'sessions` ' . implode(', ', $clauses)
        );

        $this->logWidened($columns);
    }

    /**
     * @param list<string> $columns
     */
    private function logWidened(array $columns): void
    {
        \Pramnos\Logs\Logger::log(
            'sessions.' . implode(' and sessions.', $columns) . ' widened to text',
            'migrations'
        );
    }

    /**
     * Is this column still a length-limited type?
     *
     * Through the framework's own column reader rather than a hand-written
     * `information_schema` query, and that is not tidiness. Two things go wrong with the
     * hand-written version, and both were live in this repository:
     *
     *  - **MySQL answers `information_schema` in upper case.** `DATA_TYPE`, not `data_type`,
     *    so `$result->fields['data_type']` is an empty string there and every comparison
     *    against it reads as "not text, therefore narrow" — right by accident on a narrow
     *    column, and on a wide one it rebuilds the table for nothing.
     *  - **`information_schema` spans every database on the server.** The idiom this was
     *    copied from ends `ORDER BY table_schema LIMIT 1`, which reads *some* database's
     *    `sessions` — alphabetically first, not necessarily the one connected. A second
     *    database on the same server with a table of the same name answers the question,
     *    and this was caught by a scratch database doing exactly that.
     *
     * `getColumns()` normalises both engines to a `Field`/`Type`/`Null` shape and asks the
     * connected database, so both problems have one place to be right.
     * {@see WidenApplicationsCallback} had the same hand-written read and the first defect.
     */
    private function isNarrow(string $column): bool
    {
        $shape = $this->columnShape($column);

        // `null` is "cannot be read" — a table that vanished between the check above and
        // here, or a permission. Doing nothing is the only safe answer to that.
        return $shape !== null && $shape['type'] !== 'text';
    }

    /**
     * Does this column accept nulls today?
     *
     * Read rather than assumed, so the migration changes the type and only the type. When it
     * cannot be read, `NOT NULL` is the answer: the framework's create-table has never made
     * either column nullable, and the stricter guess is the one that cannot let a null into
     * code that has never seen one.
     */
    private function isNullable(string $column): bool
    {
        return $this->columnShape($column)['nullable'] ?? false;
    }

    /**
     * One column's type and nullability, from one read.
     *
     * Memoised because `up()` asks about the type and then about the nullability, and
     * `getColumns()` is a catalogue query per call.
     *
     * @return array{type: string, nullable: bool}|null
     */
    private function columnShape(string $column): ?array
    {
        if (array_key_exists($column, $this->shapes)) {
            return $this->shapes[$column];
        }

        $this->shapes[$column] = null;

        try {
            $result = $this->DB()->getColumns('sessions', null, false, true);
        } catch (\Throwable) {
            return null;
        }

        if ($result === false) {
            return null;
        }

        while ($result->fetch()) {
            if (strcasecmp((string) ($result->fields['Field'] ?? ''), $column) !== 0) {
                continue;
            }

            $this->shapes[$column] = [
                'type'     => strtolower(explode('(', (string) ($result->fields['Type'] ?? ''))[0]),
                'nullable' => strtoupper((string) ($result->fields['Null'] ?? 'NO')) === 'YES',
            ];
            break;
        }

        return $this->shapes[$column];
    }

    /**
     * The views and materialised views that select any of these columns.
     *
     * Walks `pg_rewrite` rather than guessing at names: a view is recorded as a rule
     * (`_RETURN`) whose dependencies name the exact column, which is the same thing
     * PostgreSQL consults before refusing the `ALTER`. A materialised view — and therefore a
     * TimescaleDB continuous aggregate — appears here too.
     *
     * @param list<string> $columns
     * @return list<string> `schema.name` for each dependent
     */
    private function dependentViews(array $columns): array
    {
        $placeholders = implode(', ', array_map(
            fn(string $c): string => "'" . str_replace("'", "''", $c) . "'",
            $columns
        ));

        $result = $this->DB()->query(
            "SELECT DISTINCT c.relnamespace::regnamespace || '.' || c.relname AS dependent
               FROM pg_depend d
               JOIN pg_rewrite r ON r.oid = d.objid
               JOIN pg_class c ON c.oid = r.ev_class
               JOIN pg_class t ON t.oid = d.refobjid
               JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = d.refobjsubid
              WHERE t.relname = 'sessions'
                AND a.attname IN (" . $placeholders . ")
                AND c.relname <> 'sessions'
              ORDER BY 1"
        );

        if (!$result) {
            return [];
        }

        $dependents = [];
        while ($result->fetch()) {
            $name = (string) ($result->fields['dependent'] ?? '');
            if ($name !== '') {
                $dependents[] = $name;
            }
        }

        return $dependents;
    }
}
