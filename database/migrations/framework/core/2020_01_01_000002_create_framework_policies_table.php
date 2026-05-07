<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Migration;

/**
 * Creates the framework_policies table for scheduled database maintenance tasks.
 *
 * On PostgreSQL the table lives in the `pramnos` schema:
 *   pramnos.framework_policies
 *
 * On MySQL (no schema concept) it is created in the default database as:
 *   framework_policies
 *
 * On TimescaleDB this migration is a no-op because native TimescaleDB
 * policies (retention, compression, continuous aggregates) handle their
 * own scheduling.
 *
 * @package PramnosFramework
 */
class CreateFrameworkPoliciesTable extends Migration
{
    public string  $feature      = 'core';
    public string  $scope        = 'framework';
    public int     $priority     = 30;
    public array   $dependencies = ['create_pramnos_schema'];
    public $description  = 'Creates the pramnos.framework_policies table';

    public function up(): void
    {
        // TimescaleDB manages its own native policies.
        $db   = $this->application->database;
        $caps = $db->schema()->getCapabilities();

        if (!empty($db->timescale) || $db->type === 'timescaledb') {
            return;
        }

        $tableName = $caps->isPostgreSQL() ? 'pramnos.framework_policies' : 'framework_policies';

        $schema = $db->schema();

        if ($schema->hasTable($tableName)) {
            return;
        }

        $schema->createTable($tableName, function ($table) {
            $table->comment('Scheduled database maintenance policies executed by PolicyEngine (MySQL/plain PG only; no-op on TimescaleDB)');

            $table->increments('policyid')
                ->comment('Auto-increment primary key');
            $table->string('policy_type', 50)
                ->comment('Policy category: retention | aggregate_refresh | compression | cache_rebuild');
            $table->string('target', 255)
                ->comment('Target table or view name on which the policy operates');
            $table->json('config')
                ->comment('Type-specific configuration object (e.g. {interval:"24 months",time_column:"created_at"})');
            $table->boolean('enabled')->default(true)
                ->comment('Whether this policy is active and will be executed by the PolicyEngine');
            $table->timestamp('last_run')->nullable()
                ->comment('Timestamp of the most recent successful execution');
            $table->timestamp('next_run')->nullable()
                ->comment('Scheduled time for the next execution; NULL = run as soon as possible');
            $table->text('last_result')->nullable()
                ->comment('Human-readable summary of the last execution result');
            $table->text('last_error')->nullable()
                ->comment('Error message from the last failed execution; NULL if last run was successful');
            $table->timestamp('created_at')->useCurrent()
                ->comment('Row creation timestamp');

            $table->index(['policy_type', 'enabled'], 'idx_framework_policies_type_enabled');
            $table->index(['next_run'], 'idx_framework_policies_next_run');
        });
    }

    public function down(): void
    {
        $db   = $this->application->database;
        $caps = $db->schema()->getCapabilities();

        if (!empty($db->timescale) || $db->type === 'timescaledb') {
            return;
        }

        $tableName = $caps->isPostgreSQL() ? 'pramnos.framework_policies' : 'framework_policies';
        $db->schema()->dropTableIfExists($tableName);
    }
}
