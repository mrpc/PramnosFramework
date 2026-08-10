<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;

/**
 * Repairs two things that no installation created before today could have right.
 *
 * **1. `authserver.gdpr_requests.notes` → `processing_notes`.** The table was
 * modelled on a production schema that names the column `processing_notes`; the
 * framework migration created it as `notes`. Every installation created from
 * that migration has the wrong name, and the two schemas would have to be
 * reconciled by hand the first time they met. Renaming is safe and lossless —
 * the column keeps its type, its nullability and its rows.
 *
 * **2. Five foreign keys that were never created anywhere.**
 * `2020_01_01_000050` adds them with `schema('public')->table('gdpr_requests')`,
 * but these tables live in the `authserver` schema. The lookup found no such
 * table in `public`, the guard skipped the block, and the skip was
 * indistinguishable from success. That migration is fixed, but it is recorded as
 * applied on every existing installation, so it will never run again — which is
 * exactly the class of gap this migration exists to close.
 *
 * Everything here is guarded and idempotent: a correct database comes out
 * unchanged, and an installation missing only some of it gets only that.
 *
 * Current-date timestamp (§9) so it auto-runs on installations whose
 * migration_cutoff skips the 2020_01_01 baseline.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class RepairGdprRequestsAndAuthserverForeignKeys extends Migration
{
    public string $feature      = 'auth';
    public string $scope        = 'framework';
    public int    $priority     = 70;
    public array  $dependencies = ['create_gdpr_requests_table'];
    public $description  = 'Renames gdpr_requests.notes to processing_notes and adds the five authserver foreign keys that were never created';

    /**
     * The foreign keys `2020_01_01_000050` intended but never created.
     *
     * Each entry is [child table, child column, referenced table, referenced
     * column, constraint name] — the same shape and the same names that
     * migration uses, so an installation that gets them from either source ends
     * up identical.
     *
     * @var array<int, array{0:string,1:string,2:string,3:string,4:string}>
     */
    private const FOREIGN_KEYS = [
        ['authserver.user_privacy_settings',   'userid', 'users', 'userid', 'fk_user_privacy_settings_userid'],
        ['authserver.user_consents',           'userid', 'users', 'userid', 'fk_user_consents_userid'],
        ['authserver.data_processing_records', 'userid', 'users', 'userid', 'fk_data_processing_records_userid'],
        ['authserver.gdpr_requests',           'userid', 'users', 'userid', 'fk_gdpr_requests_userid'],
        ['authserver.user_activity_log',       'userid', 'users', 'userid', 'fk_user_activity_log_userid'],
    ];

    public function up(): void
    {
        $this->renameProcessingNotes();
        $this->addMissingForeignKeys();
    }

    /**
     * Rename the notes column, if this installation has the old name.
     */
    private function renameProcessingNotes(): void
    {
        $schema = $this->schema();
        $table  = 'authserver.gdpr_requests';

        if (!$schema->hasTable($table)) {
            return;
        }

        // Already correct, or already renamed by a previous run.
        if ($schema->hasColumn($table, 'processing_notes')) {
            return;
        }

        if (!$schema->hasColumn($table, 'notes')) {
            // Neither column: not a table this migration understands. Leaving it
            // alone is the only safe answer — inventing a column would hide
            // whatever actually happened here.
            \Pramnos\Logs\Logger::log(
                'Skipping gdpr_requests column rename: the table has neither '
                . '"notes" nor "processing_notes".',
                'migrations'
            );

            return;
        }

        $schema->table($table, function ($blueprint) {
            $blueprint->renameColumn('notes', 'processing_notes');
        });

        \Pramnos\Logs\Logger::log(
            'Renamed authserver.gdpr_requests.notes to processing_notes.',
            'migrations'
        );
    }

    /**
     * Add each foreign key that this installation is missing.
     */
    private function addMissingForeignKeys(): void
    {
        foreach (self::FOREIGN_KEYS as [$table, $column, $references, $onColumn, $constraint]) {
            if (!$this->canAddForeignKey($table, $column, $references, $onColumn, $constraint)) {
                continue;
            }

            $this->schema()->table(
                $table,
                function ($blueprint) use ($column, $references, $onColumn, $constraint) {
                    $blueprint->foreign($column)
                        ->references($onColumn)
                        ->on($references)
                        ->onDelete('cascade')
                        ->onUpdate('cascade')
                        ->name($constraint);
                }
            );

            \Pramnos\Logs\Logger::log(
                "Added missing foreign key $constraint on $table.",
                'migrations'
            );
        }
    }

    /**
     * Is it safe to add this foreign key on this installation?
     *
     * The same three questions `2020_01_01_000050` asks — does the child table
     * have the column, does the referenced table exist, does it have the column
     * — plus the one that matters here: is the constraint already there? A
     * repair that re-adds an existing constraint fails on its second run, which
     * is worse than not repairing at all.
     *
     * @param  string $table      Child table, schema-qualified
     * @param  string $column     Child column
     * @param  string $references Referenced table
     * @param  string $onColumn   Referenced column
     * @param  string $constraint Constraint name
     * @return bool
     */
    private function canAddForeignKey($table, $column, $references, $onColumn, $constraint): bool
    {
        $schema = $this->schema();

        if (!$schema->hasTable($table)) {
            return false;
        }
        if ($this->constraintExists($table, $constraint)) {
            return false;
        }
        if (!$schema->hasColumn($table, $column)) {
            $this->skip($constraint, "$table has no column '$column'");

            return false;
        }
        if (!$schema->hasTable($references)) {
            $this->skip($constraint, "referenced table '$references' does not exist");

            return false;
        }
        if (!$schema->hasColumn($references, $onColumn)) {
            $this->skip($constraint, "$references has no column '$onColumn'");

            return false;
        }

        // These tables have gone years without the constraint, so nothing has
        // been stopping a row from outliving the user it points at. Adding the
        // key on top of such a row fails — and a failing ALTER aborts the whole
        // migration batch, taking unrelated migrations down with it. Better to
        // report the orphans and let the next run add the key once they are
        // dealt with: skipping is recoverable, a broken batch is not.
        $orphans = $this->countOrphans($table, $column, $references, $onColumn);
        if ($orphans > 0) {
            $this->skip(
                $constraint,
                "$table has $orphans row(s) whose $column has no match in "
                . "$references.$onColumn. Resolve them (delete, or repoint at a "
                . 'real user) and run migrations again'
            );

            return false;
        }

        return true;
    }

    /**
     * How many child rows point at a parent row that is not there?
     *
     * Raw SQL on purpose: this joins across two schemas (`authserver` and
     * `public` on PostgreSQL, two prefixed tables on MySQL) and exists only to
     * decide whether DDL is safe — the sort of introspection the query builder
     * is not the right tool for. Both names still come from the builder, so the
     * per-driver spelling is not hand-written.
     *
     * @param  string $table      Child table, schema-qualified
     * @param  string $column     Child column
     * @param  string $references Referenced table
     * @param  string $onColumn   Referenced column
     * @return int
     */
    private function countOrphans($table, $column, $references, $onColumn): int
    {
        $schema = $this->schema();
        $child  = $schema->quoteTable($table);
        $parent = $schema->quoteTable($references);

        try {
            $result = $this->DB()->query(
                "SELECT COUNT(*) AS cnt FROM $child c"
                . " LEFT JOIN $parent p ON c.$column = p.$onColumn"
                . " WHERE c.$column IS NOT NULL AND p.$onColumn IS NULL"
            );

            return $result ? (int) ($result->fields['cnt'] ?? 0) : 0;
        } catch (\Throwable $ex) {
            // Not being able to count is not a licence to add the key blindly.
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);

            return 1;
        }
    }

    /**
     * Does the constraint already exist on this table?
     *
     * information_schema addresses a table by schema and bare name, so a
     * qualified name has to be split; asking for
     * `table_name = 'authserver.gdpr_requests'` matches nothing, and "nothing"
     * reads as "missing", which would make this try to create it on every run.
     *
     * @param  string $table      Schema-qualified child table
     * @param  string $constraint Constraint name
     * @return bool
     */
    private function constraintExists($table, $constraint): bool
    {
        $db = $this->DB();

        if ($db->getDriverName() === 'pgsql') {
            [$schema, $bare] = strpos($table, '.') !== false
                ? explode('.', $table, 2)
                : ['public', $table];

            return $db->selectOne(
                'SELECT 1 FROM information_schema.table_constraints
                 WHERE table_schema = ? AND table_name = ? AND constraint_name = ?',
                [$schema, $bare, $constraint]
            ) !== null;
        }

        // MySQL has no schemas in this sense: the builder flattens
        // `authserver.x` to `authserver_x` inside the current database.
        return $db->selectOne(
            'SELECT 1 FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$this->schema()->resolveTableName($table), $constraint]
        ) !== null;
    }

    /**
     * Record that a foreign key was skipped, and why.
     *
     * A silent skip is indistinguishable from success, which is how five
     * constraints went missing everywhere without anyone noticing.
     *
     * @param string $constraint
     * @param string $reason
     */
    private function skip($constraint, $reason): void
    {
        \Pramnos\Logs\Logger::log(
            "Skipping foreign key $constraint: $reason.",
            'migrations'
        );
    }

    /**
     * Reverses the rename only.
     *
     * The foreign keys are deliberately left in place: they are what
     * `2020_01_01_000050` always intended to create, and dropping them on a
     * rollback of *this* migration would leave the database further from the
     * declared schema than before it ran.
     */
    public function down(): void
    {
        $schema = $this->schema();
        $table  = 'authserver.gdpr_requests';

        if (!$schema->hasTable($table) || !$schema->hasColumn($table, 'processing_notes')) {
            return;
        }
        if ($schema->hasColumn($table, 'notes')) {
            return;
        }

        $schema->table($table, function ($blueprint) {
            $blueprint->renameColumn('processing_notes', 'notes');
        });
    }
}
