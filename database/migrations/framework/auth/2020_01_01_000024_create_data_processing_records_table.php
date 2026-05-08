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
    public array   $dependencies = [];
    public $description  = 'Creates the data_processing_records GDPR Art.30 log (TimescaleDB hypertable when available)';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('data_processing_records')) {
            return;
        }

        $schema->createTable('data_processing_records', function ($table) {
            $table->comment('GDPR Article 30 data processing activity log; TimescaleDB hypertable with 36-month retention');

            $table->bigInteger('userid')
                ->comment('User ID whose data is being processed');
            $table->string('operation', 100)
                ->comment('Type of processing operation (e.g. data_export, profile_update, account_deletion)');
            $table->string('data_category', 100)
                ->comment('Category of personal data processed (e.g. profile, financial, health)');
            $table->string('legal_basis', 100)->default('consent')
                ->comment('GDPR legal basis for the processing (e.g. consent, legitimate_interest, contract, legal_obligation)');
            $table->string('processor', 100)->nullable()
                ->comment('Identity of the data processor (e.g. internal, aws-ses, stripe); NULL for internal operations');
            $table->text('details')->nullable()
                ->comment('JSON-encoded additional context for the processing record');
            $table->timestampTz('processed_at')
                ->comment('Timestamp when the processing occurred — time dimension for hypertable');

            $table->index(['userid', 'processed_at'], 'idx_data_processing_userid');
            $table->index(['operation', 'processed_at'], 'idx_data_processing_operation');
        });

        $schema->ifCapable(
            DatabaseCapabilities::TIMESCALEDB,
            function () use ($schema) {
                $schema->createHypertable('data_processing_records', 'processed_at', [
                    'chunk_time_interval' => '1 week',
                ]);
                $schema->enableCompression('data_processing_records');
                $schema->addCompressionPolicy('data_processing_records', '90 days');
                $schema->addRetentionPolicy('data_processing_records', '36 months');
            }
        );
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('data_processing_records');
    }
}
