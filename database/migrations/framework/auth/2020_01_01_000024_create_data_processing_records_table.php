<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;
use Pramnos\Database\DatabaseCapabilities;

/**
 * Creates the data_processing_records table — GDPR data processing audit log.
 *
 * Records each personal data processing operation with its legal basis.
 * Used for GDPR Article 30 Records of Processing Activities compliance.
 *
 * On TimescaleDB this is a hypertable:
 *   - 1-week chunks on processed_at
 *   - compression enabled; compress chunks older than 90 days
 *   - retention: drop chunks older than 36 months (3 years)
 *
 * @package PramnosFramework
 */
class CreateDataProcessingRecordsTable extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 115;
    public array   $dependencies = ['create_authserver_schema'];
    public $description  = 'Creates the authserver.data_processing_records GDPR Art.30 log (TimescaleDB hypertable when available)';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('authserver.data_processing_records')) {
            return;
        }

        $schema->createTable('authserver.data_processing_records', function ($table) {
            $table->comment('GDPR Article 30 data processing activity log; TimescaleDB hypertable with 36-month retention');

            $table->bigIncrements('id')
                ->comment('Surrogate auto-increment key; part of composite PK with processed_at for TimescaleDB compatibility');
            $table->bigInteger('userid')
                ->comment('User ID whose data is being processed');
            $table->string('operation', 100)
                ->comment('Type of processing operation (e.g. data_export, profile_update, account_deletion)');
            $table->string('data_category', 100)
                ->comment('Category of personal data processed (e.g. profile, financial, health)');
            $table->string('legal_basis', 100)->default('consent')
                ->comment('GDPR legal basis for the processing (e.g. consent, legitimate_interest, contract, legal_obligation)');
            $table->text('purpose')->nullable()
                ->comment('Human-readable description of the processing purpose (GDPR Art.5 transparency requirement)');
            $table->integer('retention_period')->nullable()
                ->comment('Expected retention period in days; NULL = uses the hypertable default retention policy');
            $table->string('client_id', 255)->nullable()
                ->comment('OAuth2 client_id (apikey) if the processing was triggered via an OAuth flow; NULL for internal operations');
            $table->string('processor', 100)->nullable()
                ->comment('Identity of the data processor (e.g. internal, aws-ses, stripe); NULL for internal operations');
            $table->text('details')->nullable()
                ->comment('JSON-encoded additional context for the processing record');
            $table->timestampTz('processed_at')
                ->comment('Timestamp when the processing occurred — time dimension for hypertable');

            // Composite PK: TimescaleDB requires the partition key (processed_at) in every unique/primary constraint.
            $table->primary(['id', 'processed_at']);

            $table->index(['userid', 'processed_at'], 'idx_data_processing_userid');
            $table->index(['operation', 'processed_at'], 'idx_data_processing_operation');
        });

        $schema->ifCapable(
            DatabaseCapabilities::TIMESCALEDB,
            function () use ($schema) {
                $schema->createHypertable('authserver.data_processing_records', 'processed_at', [
                    'chunk_time_interval' => '1 week',
                ]);
                $schema->enableCompression('authserver.data_processing_records');
                $schema->addCompressionPolicy('authserver.data_processing_records', '90 days');
                $schema->addRetentionPolicy('authserver.data_processing_records', '36 months');
            }
        );
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.data_processing_records');
    }
}
