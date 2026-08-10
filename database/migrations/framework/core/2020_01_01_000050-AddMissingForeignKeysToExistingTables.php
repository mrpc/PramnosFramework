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
        if ($this->canAddForeignKey('user_privacy_settings', 'userid', 'users', 'userid', 'fk_user_privacy_settings_userid')) {
            $this->schema('public')
                ->table('user_privacy_settings', function ($table) {
                    $table->foreign('userid')
                        ->references('userid')
                        ->on('users')
                        ->onDelete('cascade')
                        ->onUpdate('cascade')
                        ->name('fk_user_privacy_settings_userid');
                });
        }

        // user_consents.userid → users.userid (CASCADE)
        if ($this->canAddForeignKey('user_consents', 'userid', 'users', 'userid', 'fk_user_consents_userid')) {
            $this->schema('public')
                ->table('user_consents', function ($table) {
                    $table->foreign('userid')
                        ->references('userid')
                        ->on('users')
                        ->onDelete('cascade')
                        ->onUpdate('cascade')
                        ->name('fk_user_consents_userid');
                });
        }

        // data_processing_records.userid → users.userid (CASCADE)
        if ($this->canAddForeignKey('data_processing_records', 'userid', 'users', 'userid', 'fk_data_processing_records_userid')) {
            $this->schema('public')
                ->table('data_processing_records', function ($table) {
                    $table->foreign('userid')
                        ->references('userid')
                        ->on('users')
                        ->onDelete('cascade')
                        ->onUpdate('cascade')
                        ->name('fk_data_processing_records_userid');
                });
        }

        // gdpr_requests.userid → users.userid (CASCADE)
        if ($this->canAddForeignKey('gdpr_requests', 'userid', 'users', 'userid', 'fk_gdpr_requests_userid')) {
            $this->schema('public')
                ->table('gdpr_requests', function ($table) {
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
        if ($this->canAddForeignKey('user_activity_log', 'userid', 'users', 'userid', 'fk_user_activity_log_userid')) {
            $this->schema('public')
                ->table('user_activity_log', function ($table) {
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
            'user_privacy_settings' => ['fk_user_privacy_settings_userid'],
            'user_consents' => ['fk_user_consents_userid'],
            'data_processing_records' => ['fk_data_processing_records_userid'],
            'gdpr_requests' => ['fk_gdpr_requests_userid'],
            'user_activity_log' => ['fk_user_activity_log_userid'],
        ];

        foreach ($constraints as $table => $fks) {
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

        return true;
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
            // PostgreSQL: check information_schema.table_constraints
            $exists = $db->selectOne(
                "SELECT 1 FROM information_schema.table_constraints 
                 WHERE table_name = ? AND constraint_name = ?",
                [$table, $constraintName]
            );
            return is_null($exists);
        } else {
            // MySQL: check INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            $exists = $db->selectOne(
                "SELECT 1 FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                 WHERE TABLE_NAME = ? AND CONSTRAINT_NAME = ?",
                [$table, $constraintName]
            );
            return is_null($exists);
        }
    }
}
