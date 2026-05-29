<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the organizations table — generic organisation registry.
 *
 * Provides the FK target for authserver.user_organizations.organization_id.
 * Applications may extend this table with app-specific columns (e.g. VAT
 * number, address, contract details) in their own migrations.
 *
 * Applications that use domain-specific naming (e.g. UrbanWater calls
 * organisations "DEYAs") override via Settings:
 *   authserver_organization_table  => 'user_deyas'
 *   authserver_organization_column => 'deyaid'
 * In that case this table still exists but the FK target is the app's own
 * organisations table (e.g. deyas), added in an app-level migration.
 *
 * PostgreSQL and MySQL: lives in the public schema.
 *
 */
class CreateOrganizationsTable extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 16;
    public array  $dependencies = ['create_applications_table'];
    public $description  = 'Creates the organizations table (FK target for user_organizations)';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('organizations')) {
            return;
        }

        $schema->createTable('organizations', function ($table) {
            $table->comment('Generic organisation registry — FK target for user_organizations.organization_id');

            // Use signed integer (not UNSIGNED) so FK references from user_organizations
            // (which uses integer() → signed INT) are compatible on MySQL
            $table->integer('organization_id')->autoIncrement()->primary()
                ->comment('Auto-increment organisation identifier');
            $table->string('name', 255)
                ->comment('Organisation display name');
            $table->text('description')->nullable()
                ->comment('Optional description of the organisation');
            $table->string('org_type', 50)->nullable()
                ->comment('Organisation type classification (e.g. utility, enterprise, government)');
            $table->boolean('is_active')->default(true)
                ->comment('Whether this organisation is currently active');
            $table->timestamp('created_at')->useCurrent()
                ->comment('Timestamp when the organisation record was created');

            $table->index(['name'], 'idx_organizations_name');
            $table->index(['is_active'], 'idx_organizations_active');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('organizations');
    }
}
