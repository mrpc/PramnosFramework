<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;
use Pramnos\Database\DatabaseCapabilities;

/**
 * Creates the user_activity_log table — GDPR-compliant user action audit trail.
 *
 * On TimescaleDB this is a hypertable with:
 *   - 1-day chunks on created_at
 *   - compression enabled; compress chunks older than 30 days
 *   - retention: drop chunks older than 24 months
 *   - continuous aggregate `daily_activity_summary` created separately (migration 000026)
 *
 * On MySQL and plain PostgreSQL it is a regular table. The table has no
 * auto-increment PK so that TimescaleDB can use created_at as the sole
 * time dimension without a composite PK requirement.
 *
 * @package PramnosFramework
 */
class CreateUserActivityLogTable extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 100;
    public array   $dependencies = ['create_authserver_schema'];
    public $description  = 'Creates the authserver.user_activity_log GDPR audit table (TimescaleDB hypertable when available)';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('authserver.user_activity_log')) {
            return;
        }

        $schema->createTable('authserver.user_activity_log', function ($table) {
            $table->comment('GDPR-compliant user action audit trail; TimescaleDB hypertable on capable backends');

            $table->bigInteger('userid')
                ->comment('User ID whose action is being logged');
            $table->string('action', 100)
                ->comment('Action identifier (e.g. login, logout, data_export_requested, privacy_settings_updated)');
            $table->text('details')->nullable()
                ->comment('JSON-encoded context details for the action');
            $table->string('ip_address', 45)->nullable()
                ->comment('IPv4 or IPv6 address of the user agent at the time of the action');
            $table->text('user_agent')->nullable()
                ->comment('HTTP User-Agent string from the request');
            $table->timestampTz('created_at')
                ->comment('Timestamp of the action — TIMESTAMPTZ on PostgreSQL/TimescaleDB, DATETIME on MySQL; time dimension for hypertable');

            $table->index(['userid', 'created_at'], 'idx_user_activity_log_userid');
            $table->index(['action', 'created_at'], 'idx_user_activity_log_action');
        });

        $schema->ifCapable(
            DatabaseCapabilities::TIMESCALEDB,
            function () use ($schema) {
                $schema->createHypertable('authserver.user_activity_log', 'created_at', [
                    'chunk_time_interval' => '1 day',
                ]);
                $schema->enableCompression('authserver.user_activity_log');
                $schema->addCompressionPolicy('authserver.user_activity_log', '30 days');
                $schema->addRetentionPolicy('authserver.user_activity_log', '24 months');
            }
        );
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.user_activity_log');
    }
}
