<?php

namespace Pramnos\Database\Migrations;

use Pramnos\Database\Migration;

/**
 * AddMissingForeignKeysToExistingTables migration.
 *
 * Adds missing foreign key constraints to existing tables that are present in
 * UrbanWater but were not included in the original framework migrations.
 *
 * This ensures referential integrity and matches the UrbanWater schema exactly.
 * 
 * Backward compatible: Uses ALTER TABLE with IF NOT EXISTS style checks.
 */
class AddMissingForeignKeysToExistingTables extends Migration
{
    /**
     * Run the migration.
     *
     * @return void
     */
    public function up(): void: void
    {
        $db = $this->DB();

        // ===== usertokens table =====
        
        // Add FK: parentToken → usertokens.tokenid (SET NULL)
        // This allows tokens to reference a parent token (e.g., refresh token chains)
        if ($this->constraintDoesNotExist('usertokens', 'fk_usertokens_parenttoken')) {
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
        if ($this->constraintDoesNotExist('usertokens', 'fk_usertokens_applicationid')) {
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
        if ($this->constraintDoesNotExist('tokenactions', 'fk_tokenactions_tokenid')) {
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
        if ($this->constraintDoesNotExist('tokenactions', 'fk_tokenactions_urlid')) {
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
        if ($this->constraintDoesNotExist('applications', 'fk_applications_owner')) {
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

        // ===== GDPR tables (add explicit FKs to users table) =====

        // user_privacy_settings.userid → users.userid (CASCADE)
        if ($this->constraintDoesNotExist('user_privacy_settings', 'fk_user_privacy_settings_userid')) {
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
        if ($this->constraintDoesNotExist('user_consents', 'fk_user_consents_userid')) {
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
        if ($this->constraintDoesNotExist('data_processing_records', 'fk_data_processing_records_userid')) {
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
        if ($this->constraintDoesNotExist('gdpr_requests', 'fk_gdpr_requests_userid')) {
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
        if ($this->constraintDoesNotExist('user_activity_log', 'fk_user_activity_log_userid')) {
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
    public function down(): void: void
    {
        $db = $this->DB();

        // Drop all foreign keys added by this migration
        $constraints = [
            'usertokens' => ['fk_usertokens_parenttoken', 'fk_usertokens_applicationid'],
            'tokenactions' => ['fk_tokenactions_tokenid', 'fk_tokenactions_urlid'],
            'applications' => ['fk_applications_owner'],
            'user_privacy_settings' => ['fk_user_privacy_settings_userid'],
            'user_consents' => ['fk_user_consents_userid'],
            'data_processing_records' => ['fk_data_processing_records_userid'],
            'gdpr_requests' => ['fk_gdpr_requests_userid'],
            'user_activity_log' => ['fk_user_activity_log_userid'],
        ];

        foreach ($constraints as $table => $fks) {
            foreach ($fks as $fk) {
                $this->schema('public')
                    ->table($table, function ($table) use ($fk) {
                        $table->dropForeign([$fk]);
                    });
            }
        }
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
