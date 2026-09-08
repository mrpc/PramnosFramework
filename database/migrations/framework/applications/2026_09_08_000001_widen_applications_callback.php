<?php

namespace Pramnos\Framework\Migrations\Applications;

use Pramnos\Database\Migration;

/**
 * Widens `applications.callback` to `text` on installations the create-table never reached.
 *
 * ## Why the framework has to ship this
 *
 * One application has several legitimate callbacks: the same client is developed on
 * `http://localhost:3000`, tested on a staging host and run in production, and all three
 * belong to one registration. `2020_01_01_000025_create_applications_table` declares
 * `text`, so a database the framework created has the room.
 *
 * A database that predates the migration system does not. Where `public.applications` is
 * legacy schema, `create_applications_table` is `Skipped (cutoff)` and its `text` never
 * applied — the column is `character varying(255)`. Three long callbacks plus separators is
 * about 190 characters, so a fourth raises `value too long for type character
 * varying(255)`.
 *
 * The consuming application wrote this migration and **withdrew it**, because the ALTER
 * cannot be done from outside the framework:
 *
 * ```
 * ERROR: cannot alter type of a column used by a view or rule
 * DETAIL: rule _RETURN on view applications.oauth2_application_permissions
 *         depends on column "callback"
 * ```
 *
 * Widening therefore means dropping and recreating framework view definitions from inside
 * an application migration, and keeping them in step by hand for ever after. That is a
 * worse risk than the ceiling. The framework owns the create-table *and* the views, so it
 * is the only place the three can move together.
 *
 * ## How the views are kept in step
 *
 * Not by copying their definitions here — that is the duplication the application refused
 * to take on, and it would be the same duplication wherever it lived. Instead the views are
 * dropped with `CASCADE` and then **{@see CreateApplicationsViews} is re-run**, which drops
 * and recreates all eleven from the framework's own definitions. It is written to be
 * repeatable (`DROP VIEW IF EXISTS … CASCADE` before each `CREATE VIEW`), so re-running it
 * is what it is for.
 *
 * There is therefore one definition of every view, in the migration that owns it, and this
 * migration knows only that they exist.
 *
 * ## MySQL
 *
 * `MODIFY` on a column a view selects is allowed, so there is nothing to drop. The column
 * is widened the same way and the views are left alone.
 */
class WidenApplicationsCallback extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';

    /**
     * After the views exist, so re-running them puts back what was there.
     *
     * `CreateApplicationsViews` is priority 30; this has to be later, or the re-run would
     * be creating them for the first time in an order nothing has established.
     */
    public int    $priority     = 35;

    public array  $dependencies = ['create_applications_table'];

    public $description = 'Widens applications.callback to text so a client can register more than one redirect URI';

    /**
     * The views that select the column, and therefore block the ALTER.
     *
     * Named rather than discovered, and the reason is that discovery is the harder half to
     * get right: a `pg_depend` walk has to be recursive (a view on a view blocks it too)
     * and has to drop in reverse dependency order. `CASCADE` plus a re-run of the migration
     * that owns all eleven does the same job without a graph, and cannot leave one behind.
     *
     * `usage_statistics` is the 7 KB one.
     *
     * @var list<string>
     */
    private const DEPENDENT_VIEWS = [
        'applications.oauth2_application_permissions',
        'applications.usage_statistics',
    ];

    public function up(): void
    {
        if (!$this->columnIsNarrow()) {
            // Every database the framework created is already `text`. Saying so and
            // stopping is the whole of the work on those.
            return;
        }

        if ($this->DB()->schema()->getCapabilities()->isPostgreSQL()) {
            $this->widenPostgreSQL();

            return;
        }

        $this->widenMySQL();
    }

    /**
     * Deliberately empty.
     *
     * Narrowing the column back would truncate every registration that has used the room —
     * which is the whole point of the migration — and there is no width to go back to that
     * is correct for every installation: the ones the framework created were always `text`.
     */
    public function down(): void
    {
    }

    /**
     * Is the column still the narrow legacy type?
     *
     * Read from `information_schema` rather than assumed from the install's age: an
     * installation may have widened it by hand, and doing the drop-and-recreate dance on a
     * column that is already `text` is eleven views rebuilt for nothing.
     */
    private function columnIsNarrow(): bool
    {
        $schema = $this->DB()->schema();
        if (!$schema->hasTable('applications') || !$schema->hasColumn('applications', 'callback')) {
            return false;
        }

        $result = $this->DB()->query(
            $this->DB()->prepareQuery(
                "SELECT data_type, COALESCE(character_maximum_length, 0) AS len
                   FROM information_schema.columns
                  WHERE table_name = %s AND column_name = 'callback'
                  ORDER BY table_schema
                  LIMIT 1",
                $this->DB()->prefix . 'applications'
            )
        );

        if (!$result || !$result->numRows) {
            // Try the unprefixed name: PostgreSQL puts it in a schema rather than a prefix.
            $result = $this->DB()->query(
                "SELECT data_type, COALESCE(character_maximum_length, 0) AS len
                   FROM information_schema.columns
                  WHERE table_name = 'applications' AND column_name = 'callback'
                  ORDER BY table_schema
                  LIMIT 1"
            );
        }

        if (!$result || !$result->numRows) {
            return false;
        }

        $type = strtolower((string) ($result->fields['data_type'] ?? ''));

        // `text` has no length; anything with one is a ceiling somebody will reach.
        return $type !== 'text' && (int) ($result->fields['len'] ?? 0) > 0;
    }

    private function widenPostgreSQL(): void
    {
        foreach (self::DEPENDENT_VIEWS as $view) {
            $this->DB()->query('DROP VIEW IF EXISTS ' . $view . ' CASCADE');
        }

        $this->DB()->query('ALTER TABLE applications ALTER COLUMN callback TYPE text');

        \Pramnos\Logs\Logger::log(
            'applications.callback widened to text; rebuilding the applications views',
            'migrations'
        );

        // The one definition of every view, in the migration that owns it.
        (new \Pramnos\Framework\Migrations\Applications\CreateApplicationsViews(
            $this->application
        ))->up();
    }

    private function widenMySQL(): void
    {
        $table = $this->DB()->prefix . 'applications';

        $this->DB()->query('ALTER TABLE `' . $table . '` MODIFY `callback` TEXT NULL');
    }
}
