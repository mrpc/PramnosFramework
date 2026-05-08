<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;

/**
 * Adds GDPR-related columns to the users table.
 *
 * These columns track top-level GDPR consent state and pending data rights
 * requests directly on the user account row. Detailed audit history is stored
 * in the dedicated user_consents and gdpr_requests hypertables.
 *
 * All timestamps are Unix integers for cross-database portability.
 * The migration is idempotent: each column is added only if it does not exist.
 *
 * @package PramnosFramework
 */
class AddGdprColumnsToUsers extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 130;
    public array   $dependencies = ['create_users_table'];
    public $description  = 'Adds GDPR consent and data-rights columns to the users table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('users')) {
            return;
        }

        $schema->alterTable('users', function ($table) use ($schema) {
            if (!$schema->hasColumn('users', 'gdpr_consent')) {
                $table->tinyInteger('gdpr_consent')->default(0)
                    ->comment('1 = user has given explicit GDPR consent to data processing');
            }
            if (!$schema->hasColumn('users', 'gdpr_consent_date')) {
                $table->integer('gdpr_consent_date')->default(0)
                    ->comment('Unix timestamp when GDPR consent was last given or withdrawn (0 = never)');
            }
            if (!$schema->hasColumn('users', 'gdpr_data_export_requested')) {
                $table->tinyInteger('gdpr_data_export_requested')->default(0)
                    ->comment('1 = user has an open data portability (export) request pending');
            }
            if (!$schema->hasColumn('users', 'gdpr_deletion_requested')) {
                $table->tinyInteger('gdpr_deletion_requested')->default(0)
                    ->comment('1 = user has submitted a right-to-erasure (deletion) request');
            }
            if (!$schema->hasColumn('users', 'gdpr_deletion_date')) {
                $table->integer('gdpr_deletion_date')->default(0)
                    ->comment('Unix timestamp when account deletion was completed (0 = not deleted)');
            }
        });
    }

    public function down(): void
    {
        // Column removal is intentionally not implemented — removing GDPR audit
        // columns from the users table could destroy compliance records.
        // To roll back, restore the users table from a pre-migration backup.
    }
}
