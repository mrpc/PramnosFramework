<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;
use Pramnos\Database\DatabaseCapabilities;

/**
 * Creates the user_consents table — GDPR consent record log.
 *
 * Append-only consent audit trail. Every grant or withdrawal of consent is
 * recorded as a new row (immutable log). On TimescaleDB this is a hypertable:
 *   - 1-month chunks on granted_at
 *   - compression enabled; compress chunks older than 6 months
 *   - retention: drop chunks older than 7 years (GDPR retention requirement)
 *
 * No auto-increment PK — TimescaleDB uses granted_at as the time dimension.
 *
 * @package PramnosFramework
 */
class CreateUserConsentsTable extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 110;
    public array   $dependencies = ['create_authserver_schema'];
    public $description  = 'Creates the authserver.user_consents GDPR consent log (TimescaleDB hypertable when available)';

    public function up(): void: void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('authserver.user_consents')) {
            return;
        }

        $schema->createTable('authserver.user_consents', function ($table) {
            $table->comment('Append-only GDPR consent records; TimescaleDB hypertable with 7-year retention');

            $table->bigInteger('userid')
                ->comment('User ID whose consent state is being recorded');
            $table->string('consent_type', 100)
                ->comment('Type of consent being recorded (e.g. marketing_emails, share_usage_analytics, data_processing)');
            $table->tinyInteger('granted')->default(0)
                ->comment('1 = consent granted, 0 = consent withdrawn at this point in time');
            $table->timestampTz('granted_at')
                ->comment('Timestamp when this consent state was recorded — time dimension for hypertable');
            $table->string('legal_basis', 100)->default('consent')
                ->comment('GDPR legal basis for the processing (e.g. consent, legitimate_interest, contract)');
            $table->string('ip_address', 45)->nullable()
                ->comment('IP address from which the consent was submitted');

            $table->index(['userid', 'consent_type', 'granted_at'], 'idx_user_consents_userid_type');
            $table->index(['granted_at'], 'idx_user_consents_time');
        });

        $schema->ifCapable(
            DatabaseCapabilities::TIMESCALEDB,
            function () use ($schema) {
                $schema->createHypertable('authserver.user_consents', 'granted_at', [
                    'chunk_time_interval' => '1 month',
                ]);
                $schema->enableCompression('authserver.user_consents');
                $schema->addCompressionPolicy('authserver.user_consents', '6 months');
                $schema->addRetentionPolicy('authserver.user_consents', '7 years');
            }
        );
    }

    public function down(): void: void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.user_consents');
    }
}
