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
 * problem, a `SameSite` mistake — everything except a column width. The width is not
 * mentioned anywhere on the path: the error is in the application log, several lines down,
 * phrased as an insert failure with no mention of sessions or of authentication.
 *
 * ## Both columns, not only the one that overflowed
 *
 * `agent` has the same ceiling and the same consequence for a long User-Agent. It is
 * truncated in PHP before the insert, so it never raised — which is exactly why it is worth
 * widening here rather than leaving: the truncation is a workaround for a column width, and
 * once the width is gone the workaround can go with it.
 *
 * ## Cost
 *
 * On PostgreSQL, `varchar(n)` to `text` is a catalogue change: no table rewrite, no scan.
 * On MySQL it is a rebuild, of a table that holds active sessions and is small.
 *
 * There is nothing to drop first — no view or rule selects either column, which is the
 * difference between this and {@see \Pramnos\Framework\Migrations\Applications\WidenApplicationsCallback}.
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

    public function up(): void
    {
        $schema = $this->DB()->schema();

        if (!$schema->hasTable('sessions')) {
            // Nothing to widen. `create_sessions_table` declares `text` now, so a database
            // created after this lands never reaches here with a narrow column.
            return;
        }

        foreach (self::COLUMNS as $column) {
            if (!$schema->hasColumn('sessions', $column) || !$this->isNarrow($column)) {
                continue;
            }

            if ($schema->getCapabilities()->isPostgreSQL()) {
                $this->DB()->query(
                    'ALTER TABLE sessions ALTER COLUMN "' . $column . '" TYPE text'
                );
            } else {
                $this->DB()->query(
                    'ALTER TABLE `' . $this->DB()->prefix . 'sessions` '
                    . 'MODIFY `' . $column . '` TEXT'
                );
            }

            \Pramnos\Logs\Logger::log(
                'sessions.' . $column . ' widened to text',
                'migrations'
            );
        }
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
     * Is this column still a length-limited type?
     *
     * Read from the catalogue rather than assumed from the installation's age, so that
     * running this twice, or running it after somebody widened the column by hand, does no
     * work at all. `text` reports no `character_maximum_length`; anything that reports one
     * is a ceiling somebody will reach.
     */
    private function isNarrow(string $column): bool
    {
        $result = $this->DB()->query(
            $this->DB()->prepareQuery(
                "SELECT data_type AS dtype, COALESCE(character_maximum_length, 0) AS len
                   FROM information_schema.columns
                  WHERE table_name = %s AND column_name = %s
                  ORDER BY table_schema
                  LIMIT 1",
                $this->DB()->prefix . 'sessions',
                $column
            )
        );

        if (!$result || !$result->numRows) {
            // PostgreSQL has no prefix on this table; try the bare name.
            $result = $this->DB()->query(
                $this->DB()->prepareQuery(
                    "SELECT data_type AS dtype, COALESCE(character_maximum_length, 0) AS len
                       FROM information_schema.columns
                      WHERE table_name = 'sessions' AND column_name = %s
                      ORDER BY table_schema
                      LIMIT 1",
                    $column
                )
            );
        }

        if (!$result || !$result->numRows) {
            return false;
        }

        return strtolower((string) ($result->fields['dtype'] ?? '')) !== 'text'
            && (int) ($result->fields['len'] ?? 0) > 0;
    }
}
