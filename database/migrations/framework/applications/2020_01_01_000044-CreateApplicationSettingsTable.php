<?php

namespace Pramnos\Database\Migrations;

use Pramnos\Database\Migration;

/**
 * CreateApplicationSettingsTable migration.
 *
 * Creates the applications.application_settings table for storing per-application
 * configuration settings including rate limiting, CORS, pagination, and IP lock features.
 *
 * Dependent on: CreateApplicationsTable (000025)
 */
class CreateApplicationSettingsTable extends Migration
{
    /**
     * Run the migration.
     *
     * @return void
     */
    public function up()
    {
        $this->schema('applications')
            ->create('application_settings', function ($table) {
                $table->increments('id');
                $table->integer('appid')->unsigned()->notNull();

                // Rate limiting settings
                $table->integer('rate_limit_requests')
                    ->unsigned()
                    ->default(1000)
                    ->comment('Requests per time window');
                $table->integer('rate_limit_window_seconds')
                    ->unsigned()
                    ->default(3600)
                    ->comment('Time window in seconds (default: 1 hour)');
                $table->integer('rate_limit_burst')
                    ->unsigned()
                    ->default(100)
                    ->comment('Burst capacity');

                // Pagination settings
                $table->boolean('enforce_pagination')->default(true);
                $table->integer('max_page_size')->unsigned()->default(100);
                $table->integer('default_page_size')->unsigned()->default(20);

                // IP lock settings (PostgreSQL-specific: INET[] arrays)
                if ($this->DB()->getDriverName() === 'pgsql') {
                    $table->addColumn('inet[]', 'allowed_ips')->nullable();
                    $table->addColumn('inet[]', 'blocked_ips')->nullable();
                } else {
                    // MySQL: use JSON arrays
                    $table->json('allowed_ips')->nullable();
                    $table->json('blocked_ips')->nullable();
                }
                $table->boolean('ip_lock_enabled')->default(false);

                // HTTPS & CORS settings
                $table->boolean('require_https')->default(true);
                $table->boolean('cors_enabled')->default(false);
                
                // PostgreSQL: TEXT[] array; MySQL: JSON array
                if ($this->DB()->getDriverName() === 'pgsql') {
                    $table->addColumn('text[]', 'cors_origins')->nullable();
                } else {
                    $table->json('cors_origins')->nullable();
                }

                // Timestamps
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent();

                // Indexes
                $table->unique('appid', 'idx_application_settings_appid');
                $table->index('updated_at', 'idx_application_settings_updated_at');

                // Foreign keys
                $table->foreign('appid')
                    ->references('appid')
                    ->on('public.applications')
                    ->onDelete('cascade')
                    ->onUpdate('cascade');
            });

        // Create trigger for updated_at (PostgreSQL only)
        if ($this->DB()->getDriverName() === 'pgsql') {
            $this->DB()->statement("
                CREATE FUNCTION applications.update_application_settings_timestamp()
                RETURNS TRIGGER AS \$\$
                BEGIN
                    NEW.updated_at = CURRENT_TIMESTAMP;
                    RETURN NEW;
                END;
                \$\$ LANGUAGE plpgsql;
            ");

            $this->DB()->statement("
                CREATE TRIGGER trg_update_application_settings_timestamp
                BEFORE UPDATE ON applications.application_settings
                FOR EACH ROW
                EXECUTE FUNCTION applications.update_application_settings_timestamp();
            ");
        }

        // MySQL: Use ON UPDATE CURRENT_TIMESTAMP instead
        if ($this->DB()->getDriverName() === 'mysql') {
            $this->DB()->statement("
                ALTER TABLE applications.application_settings
                MODIFY updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
            ");
        }
    }

    /**
     * Rollback the migration.
     *
     * @return void
     */
    public function down()
    {
        // Drop trigger (PostgreSQL)
        if ($this->DB()->getDriverName() === 'pgsql') {
            $this->DB()->statement("
                DROP TRIGGER IF EXISTS trg_update_application_settings_timestamp 
                ON applications.application_settings;
            ");
            $this->DB()->statement("
                DROP FUNCTION IF EXISTS applications.update_application_settings_timestamp();
            ");
        }

        // Drop table
        $this->schema('applications')
            ->dropIfExists('application_settings');
    }
}
