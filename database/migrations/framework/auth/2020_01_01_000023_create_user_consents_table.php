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
 * The composite PK (id, granted_at) satisfies TimescaleDB's requirement that
 * the partition key (granted_at) be part of every unique/primary constraint.
 *
 * OAuth-aware fields (client_id, scope) allow recording per-client consent
 * decisions. expires_at / revoked_at model time-bounded and explicitly revoked
 * consents respectively; NULL expires_at means the consent does not expire.
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

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('authserver.user_consents')) {
            return;
        }

        $schema->createTable('authserver.user_consents', function ($table) {
            $table->comment('Append-only GDPR consent records; TimescaleDB hypertable with 7-year retention');

            $table->bigIncrements('id')
                ->comment('Surrogate auto-increment key; part of composite PK with granted_at for TimescaleDB compatibility');
            $table->bigInteger('userid')
                ->comment('User ID whose consent state is being recorded');
            $table->string('consent_type', 100)
                ->comment('Type of consent being recorded (e.g. marketing_emails, share_usage_analytics, data_processing)');
            $table->tinyInteger('granted')->default(0)
                ->comment('1 = consent granted, 0 = consent withdrawn at this point in time');
            $table->string('client_id', 255)->nullable()
                ->comment('OAuth2 client_id (apikey) when consent was recorded in an OAuth flow; NULL for non-OAuth consent');
            $table->text('scope')->nullable()
                ->comment('Space-separated OAuth2 scopes covered by this consent record; NULL for non-OAuth consent');
            $table->timestampTz('granted_at')
                ->comment('Timestamp when this consent state was recorded — time dimension for hypertable');
            $table->timestampTz('expires_at')->nullable()
                ->comment('When this consent record expires; NULL = does not expire (consent is permanent until revoked)');
            $table->timestampTz('revoked_at')->nullable()
                ->comment('Timestamp of explicit revocation; NULL = consent has not been revoked');
            $table->string('legal_basis', 100)->nullable()->default('consent')
                ->comment('GDPR legal basis for the processing (e.g. consent, legitimate_interest, contract)');
            $table->string('ip_address', 45)->nullable()
                ->comment('IP address from which the consent was submitted');

            // Composite PK: TimescaleDB requires the partition key (granted_at) in every unique/primary constraint.
            $table->primary(['id', 'granted_at']);

            $table->index(['userid', 'granted_at'], 'idx_user_consents_userid');
            $table->index(['consent_type', 'granted_at'], 'idx_user_consents_type');
            $table->index(['client_id', 'granted_at'], 'idx_user_consents_client_id');
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

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.user_consents');
    }
}
