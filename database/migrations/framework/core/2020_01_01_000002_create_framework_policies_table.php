<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Migration;

/**
 * Creates the framework_policies table used by the Policy Engine daemon.
 *
 * @package PramnosFramework
 */
class CreateFrameworkPoliciesTable extends Migration
{
    public string $feature     = 'core';
    public string $scope       = 'framework';
    public int    $priority    = 10;
    public string $description = 'Creates the framework_policies table for the Policy Engine';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('framework_policies')) {
            return;
        }

        $schema->createTable('framework_policies', function ($table) {
            $table->increments('policyid');
            $table->string('policy_type', 50);
            $table->string('target', 255);
            $table->json('config');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_run')->nullable();
            $table->timestamp('next_run')->nullable();
            $table->text('last_result')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['policy_type', 'enabled'], 'idx_framework_policies_type_enabled');
            $table->index(['next_run'], 'idx_framework_policies_next_run');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('framework_policies');
    }
}
