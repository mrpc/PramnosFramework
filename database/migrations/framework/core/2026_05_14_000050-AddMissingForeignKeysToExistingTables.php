<?php

namespace Pramnos\Database\Migrations;

use Pramnos\Database\Migration;

/**
 * AddMissingForeignKeysToExistingTables migration.
 *
 * Adds missing foreign key constraints to existing tables that are present in
 * the reference application but were not included in the original framework migrations.
 *
 * This ensures referential integrity and matches the reference application schema exactly.
 * 
 * Backward compatible: Uses ALTER TABLE with IF NOT EXISTS style checks.
 */
class AddMissingForeignKeysToExistingTables extends Migration
{
    /**
     * Run LAST, after every create_* migration, so all referenced tables exist
     * before their FKs are added. This migration is the SOLE definer of several
     * of those FKs (e.g. the tokenactions FKs), so it must not sort ahead of the
     * tables it targets. A high priority number places it late in the batch.
     */
    public int $priority = 200;

    /**
     * Run the migration.
     *
     * @return void
     */
    public function up(): void
    {
        $db = $this->DB();

        // ===== usertokens table =====
        
        // Add FK: parentToken → usertokens.tokenid (SET NULL)
        // This allows tokens to reference a parent token (e.g., refresh token chains)
        if ($this->canAddForeignKey('usertokens', 'parentToken', 'usertokens', 'tokenid', 'fk_usertokens_parenttoken')) {
            $this->schema('public')
                ->table('usertokens', function ($table) {
                    $table->foreign('parentToken')
                        ->references('tokenid')
                        ->on('usertokens')
                        ->onDelete('set null')
                        ->onUpdate('cascade')
                        ->name('fk_usertokens_parenttoken');
                });
        }

        // Add FK: applicationid → applications.appid (SET NULL)
        // Note: applicationid column already exists in usertokens table
        if ($this->canAddForeignKey('usertokens', 'applicationid', 'public.applications', 'appid', 'fk_usertokens_applicationid')) {
            $this->schema('public')
                ->table('usertokens', function ($table) {
                    $table->foreign('applicationid')
                        ->references('appid')
                        ->on('public.applications')
                        ->onDelete('set null')
                        ->onUpdate('cascade')
                        ->name('fk_usertokens_applicationid');
                });
        }

        // ===== tokenactions table =====

        // Add FK: tokenid → usertokens.tokenid (CASCADE)
        if ($this->canAddForeignKey('tokenactions', 'tokenid', 'usertokens', 'tokenid', 'fk_tokenactions_tokenid')) {
            $this->schema('public')
                ->table('tokenactions', function ($table) {
                    $table->foreign('tokenid')
                        ->references('tokenid')
                        ->on('usertokens')
                        ->onDelete('cascade')
                        ->onUpdate('cascade')
                        ->name('fk_tokenactions_tokenid');
                });
        }

        // Add FK: urlid → urls.urlid (CASCADE)
        if ($this->canAddForeignKey('tokenactions', 'urlid', 'urls', 'urlid', 'fk_tokenactions_urlid')) {
            $this->schema('public')
                ->table('tokenactions', function ($table) {
                    $table->foreign('urlid')
                        ->references('urlid')
                        ->on('urls')
                        ->onDelete('cascade')
                        ->onUpdate('cascade')
                        ->name('fk_tokenactions_urlid');
                });
        }

        // ===== applications table =====

        // Add FK: owner → users.userid (SET NULL)
        if ($this->canAddForeignKey('applications', 'owner', 'users', 'userid', 'fk_applications_owner')) {
            $this->schema('public')
                ->table('applications', function ($table) {
                    $table->foreign('owner')
                        ->references('userid')
                        ->on('users')
                        ->onDelete('set null')
                        ->onUpdate('cascade')
                        ->name('fk_applications_owner');
                });
        }

        // ===== users table =====

        // Add FK: locationid → locations.locationid (SET NULL)
        // Conditional: only when the parent application has a locations table.
        // The framework does not define locations — it is an app-level concept.
        if ($this->canAddForeignKey('users', 'locationid', 'locations', 'locationid', 'fk_users_locationid')) {
            $this->schema('public')
                ->table('users', function ($table) {
                    $table->foreign('locationid')
                        ->references('locationid')
                        ->on('locations')
                        ->onDelete('set null')
                        ->onUpdate('cascade')
                        ->name('fk_users_locationid');
                });
        }

        // ===== GDPR tables (add explicit FKs to users table) =====

        // user_privacy_settings.userid → users.userid (CASCADE)
        if ($this->canAddForeignKey('authserver.user_privacy_settings', 'userid', 'users', 'userid', 'fk_user_privacy_settings_userid')) {
            $this->schema('public')
                ->table('authserver.user_privacy_settings', function ($table) {
                    $table->foreign('userid')
                        ->references('userid')
                        ->on('users')
                        ->onDelete('cascade')
                        ->onUpdate('cascade')
                        ->name('fk_user_privacy_settings_userid');
                });
        }

        // user_consents.userid → users.userid (CASCADE)
        if ($this->canAddForeignKey('authserver.user_consents', 'userid', 'users', 'userid', 'fk_user_consents_userid')) {
            $this->schema('public')
                ->table('authserver.user_consents', function ($table) {
                    $table->foreign('userid')
                        ->references('userid')
                        ->on('users')
                        ->onDelete('cascade')
                        ->onUpdate('cascade')
                        ->name('fk_user_consents_userid');
                });
        }

        // data_processing_records.userid → users.userid (CASCADE)
        if ($this->canAddForeignKey('authserver.data_processing_records', 'userid', 'users', 'userid', 'fk_data_processing_records_userid')) {
            $this->schema('public')
                ->table('authserver.data_processing_records', function ($table) {
                    $table->foreign('userid')
                        ->references('userid')
                        ->on('users')
                        ->onDelete('cascade')
                        ->onUpdate('cascade')
                        ->name('fk_data_processing_records_userid');
                });
        }

        // gdpr_requests.userid → users.userid (CASCADE)
        if ($this->canAddForeignKey('authserver.gdpr_requests', 'userid', 'users', 'userid', 'fk_gdpr_requests_userid')) {
            $this->schema('public')
                ->table('authserver.gdpr_requests', function ($table) {
                    $table->foreign('userid')
                        ->references('userid')
                        ->on('users')
                        ->onDelete('cascade')
                        ->onUpdate('cascade')
                        ->name('fk_gdpr_requests_userid');
                });
        }

        // user_activity_log.userid → users.userid (CASCADE)
        // Note: user_activity_log is a hypertable, may need different approach
        if ($this->canAddForeignKey('authserver.user_activity_log', 'userid', 'users', 'userid', 'fk_user_activity_log_userid')) {
            $this->schema('public')
                ->table('authserver.user_activity_log', function ($table) {
                    $table->foreign('userid')
                        ->references('userid')
                        ->on('users')
                        ->onDelete('cascade')
                        ->onUpdate('cascade')
                        ->name('fk_user_activity_log_userid');
                });
        }
    }

    /**
     * Rollback the migration.
     *
     * @return void
     */
    public function down(): void
    {
        $db = $this->DB();

        // Drop all foreign keys added by this migration
        $constraints = [
            'usertokens' => ['fk_usertokens_parenttoken', 'fk_usertokens_applicationid'],
            'tokenactions' => ['fk_tokenactions_tokenid', 'fk_tokenactions_urlid'],
            'applications' => ['fk_applications_owner'],
            'users' => ['fk_users_locationid'],
            'authserver.user_privacy_settings' => ['fk_user_privacy_settings_userid'],
            'authserver.user_consents' => ['fk_user_consents_userid'],
            'authserver.data_processing_records' => ['fk_data_processing_records_userid'],
            'authserver.gdpr_requests' => ['fk_gdpr_requests_userid'],
            'authserver.user_activity_log' => ['fk_user_activity_log_userid'],
        ];

        foreach ($constraints as $table => $fks) {
            // `constraintDoesNotExist()` answers false for two different
            // situations — the constraint is there, and the table is not — which
            // is exactly what up() wants (skip either way) and exactly what
            // down() must not conflate: dropping from a missing table raises and
            // aborts the rollback. Ask about the table separately.
            if (!$this->schema('public')->hasTable($table)) {
                continue;
            }

            foreach ($fks as $fk) {
                if (!$this->constraintDoesNotExist($table, $fk)) {
                    $this->schema('public')
                        ->table($table, function ($table) use ($fk) {
                            $table->dropForeign([$fk]);
                        });
                }
            }
        }
    }

    /**
     * Is it safe to add this foreign key on this installation?
     *
     * `constraintDoesNotExist()` already refuses to touch a missing child table.
     * That is one level short: a table can exist and still not have the column
     * the constraint names, and a *referenced* table with a common name may
     * belong to the application and carry a completely different schema.
     *
     * `users.locationid → locations.locationid` is the case that bites. The
     * framework does not define `locations` — the comment beside that block says
     * so — yet the guard only asked whether some table by that name existed. An
     * application with its own `locations` keyed on `id`, and no `locationid` on
     * `users`, got:
     *
     *     ERROR: column "locationid" referenced in foreign key constraint does not exist
     *
     * and the migration then showed as failed on every later run, on an
     * installation that had done nothing wrong. `tokenactions.urlid → urls.urlid`
     * carries the same latent risk — `urls` is just as generic a name.
     *
     * So every side is verified before the ALTER: both tables, and both columns.
     * A skip is reported once, naming the constraint and what was missing,
     * because a silent skip is indistinguishable from success.
     *
     * @param  string $table       Child table the constraint is added to
     * @param  string $column      Child column the constraint is on
     * @param  string $references  Referenced table (may be schema-qualified)
     * @param  string $onColumn    Referenced column
     * @param  string $constraint  Constraint name
     * @return bool True when the constraint can be created
     */
    protected function canAddForeignKey($table, $column, $references, $onColumn, $constraint)
    {
        // Missing child table, or the constraint is already there.
        if (!$this->constraintDoesNotExist($table, $constraint)) {
            return false;
        }

        // "public.applications" → "applications": the schema is applied by the
        // builder itself, and information_schema wants the bare name.
        $referencedTable = str_contains($references, '.')
            ? substr($references, strrpos($references, '.') + 1)
            : $references;

        $schema = $this->schema('public');

        if (!$schema->hasColumn($table, $column)) {
            $this->skipForeignKey($constraint, "$table has no column '$column'");
            return false;
        }

        if (!$schema->hasTable($referencedTable)) {
            $this->skipForeignKey($constraint, "referenced table '$referencedTable' does not exist");
            return false;
        }

        if (!$schema->hasColumn($referencedTable, $onColumn)) {
            $this->skipForeignKey($constraint, "$referencedTable has no column '$onColumn'");
            return false;
        }

        $orphans = $this->orphanCount($table, $column, $referencedTable, $onColumn);

        if ($orphans > 0) {
            $this->skipForeignKey(
                $constraint,
                "$table has $orphans row(s) whose '$column' names no $referencedTable."
                . " Remove or repair them and run migrate again"
            );

            return false;
        }

        return true;
    }

    /**
     * How many rows would violate the constraint if it were added now.
     *
     * The three checks above ask whether the *shape* allows the key. This asks whether the
     * *data* does, and it was the missing one. `ALTER TABLE … ADD CONSTRAINT` validates every
     * existing row, so one orphan aborts the statement — and with it the whole batch, on every
     * later `migrate`, exactly as a mismatched `locations` column did before the guard above
     * existed.
     *
     * The installations this hurts are the ones the migration is for. A database that has been
     * running without these keys is precisely where a deleted user can have left an audit row
     * behind; a fresh one has nothing to orphan. So the migration would refuse to complete on old
     * data and succeed on new, which is the wrong way round.
     *
     * Skipped rather than fixed. Deleting rows is not a migration's decision to take on an
     * operator's behalf — an orphaned `user_activity_log` row is an audit record, and the audit
     * trail losing entries because an upgrade tidied up is worse than a missing constraint. The
     * log line says which constraint, how many rows, and that `migrate` will add it once they are
     * dealt with; the migration stays re-runnable, so it will.
     *
     * A `NULL` in the child column is not an orphan: a nullable foreign key means "no parent",
     * and every backend accepts it.
     *
     * @param  string $table           Child table, possibly schema-qualified
     * @param  string $column          Child column
     * @param  string $referencedTable Parent table, bare
     * @param  string $onColumn        Parent column
     * @return int    Rows that would violate the constraint; 0 when it is safe to add
     */
    protected function orphanCount($table, $column, $referencedTable, $onColumn)
    {
        $schema = $this->schema('public');

        try {
            $child  = $schema->quoteTable($table);
            $parent = $schema->quoteTable($referencedTable);

            $row = $this->DB()->selectOne(
                'SELECT COUNT(*) AS orphans FROM ' . $child . ' c'
                . ' LEFT JOIN ' . $parent . ' p ON c.' . $column . ' = p.' . $onColumn
                . ' WHERE c.' . $column . ' IS NOT NULL AND p.' . $onColumn . ' IS NULL'
            );
        } catch (\Throwable $exception) {
            /*
             * A count that cannot be taken is not evidence of orphans.
             *
             * Refusing the key here would make an unrelated failure — a permission, a view
             * standing in for a table — look like dirty data, and the message would send an
             * operator looking for rows that are not there. The `ALTER TABLE` below is the
             * check of last resort either way.
             */
            return 0;
        }

        if (is_array($row)) {
            return (int) ($row['orphans'] ?? reset($row));
        }

        if (is_object($row)) {
            return (int) ($row->orphans ?? 0);
        }

        return (int) $row;
    }

    /**
     * Record that a foreign key was skipped, and why.
     *
     * One line, so an operator reading the migration output can tell a
     * deliberate skip from a constraint that quietly never got created.
     *
     * @param string $constraint Constraint name
     * @param string $reason     What was missing
     */
    protected function skipForeignKey($constraint, $reason)
    {
        \Pramnos\Logs\Logger::log(
            "Skipping foreign key $constraint: $reason."
            . ' The referenced schema belongs to the application, not the framework.',
            'migrations'
        );
    }

    /**
     * Split a possibly schema-qualified table name.
     *
     * The framework's GDPR and consent tables live in the `authserver` schema,
     * not in `public`. Addressing them without their schema is why five of this
     * migration's foreign keys were never created on any installation: the
     * lookup found no such table in `public`, the guard skipped the block, and
     * the skip was indistinguishable from success.
     *
     * @param  string $table Logical name, qualified or not
     * @return array{0: string, 1: string} Schema, then bare table name
     */
    protected function splitQualified($table)
    {
        if (strpos($table, '.') !== false) {
            [$schema, $bare] = explode('.', $table, 2);

            return [$schema, $bare];
        }

        return ['public', $table];
    }

    /**
     * Check if a constraint exists in the database.
     *
     * This is a safe helper to avoid "constraint already exists" errors
     * when running migrations multiple times.
     *
     * @param string $table Table name (without schema)
     * @param string $constraintName Constraint name
     * @return bool
     */
    protected function constraintDoesNotExist($table, $constraintName)
    {
        $db = $this->DB();

        // If the target table does not exist (its feature is disabled, or it has
        // not been created yet), there is nothing to alter. Report the constraint
        // as already present so the caller SKIPS the block instead of issuing an
        // ALTER TABLE against a missing relation (which would abort the batch).
        if (!$this->schema('public')->hasTable($table)) {
            return false;
        }

        if ($db->getDriverName() === 'pgsql') {
            // information_schema addresses a table by schema and bare name, so a
            // qualified name has to be split — asking for
            // table_name = 'authserver.gdpr_requests' matches nothing, and
            // "nothing" reads as "the constraint is missing", which would make
            // this migration try to create it on every run.
            [$schema, $bare] = $this->splitQualified($table);

            $exists = $db->selectOne(
                "SELECT 1 FROM information_schema.table_constraints
                 WHERE table_schema = ? AND table_name = ? AND constraint_name = ?",
                [$schema, $bare, $constraintName]
            );
            return is_null($exists);
        } else {
            // MySQL has no schemas in this sense: the builder flattens
            // `authserver.x` to `authserver_x` inside the current database.
            $resolved = $this->schema('public')->resolveTableName($table);

            $exists = $db->selectOne(
                "SELECT 1 FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                 WHERE TABLE_NAME = ? AND CONSTRAINT_NAME = ?",
                [$resolved, $constraintName]
            );
            return is_null($exists);
        }
    }
}
