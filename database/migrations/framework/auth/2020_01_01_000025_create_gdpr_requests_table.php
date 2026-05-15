<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;
use Pramnos\Database\DatabaseCapabilities;

/**
 * Creates the gdpr_requests table — GDPR rights exercise request tracker.
 *
 * Records each GDPR request submitted by a user (right-to-erasure, right-to-access,
 * data portability). Requests have a lifecycle: pending → processing → completed/rejected.
 *
 * On TimescaleDB this is a hypertable:
 *   - 1-month chunks on requested_at
 *   - compression enabled; compress chunks older than 1 year
 *   - retention: drop chunks older than 7 years (GDPR compliance requirement)
 *
 * @package PramnosFramework
 */
class CreateGdprRequestsTable extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 120;
    public array   $dependencies = ['create_authserver_schema'];
    public $description  = 'Creates the authserver.gdpr_requests GDPR rights request table (TimescaleDB hypertable when available)';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('authserver.gdpr_requests')) {
            return;
        }

        $schema->createTable('authserver.gdpr_requests', function ($table) {
            $table->comment('GDPR rights exercise requests (erasure, access, portability); TimescaleDB hypertable with 7-year retention');

            $table->bigInteger('userid')
                ->comment('User ID who submitted the request');
            $table->string('request_type', 50)
                ->comment('Type of GDPR right exercised: erasure | access | portability | rectification | restriction');
            $table->string('status', 50)->default('pending')
                ->comment('Request lifecycle status: pending | processing | completed | rejected');
            $table->timestampTz('requested_at')
                ->comment('Timestamp when the request was submitted — time dimension for hypertable');
            $table->timestampTz('completed_at')->nullable()
                ->comment('Timestamp when the request was fulfilled or rejected; NULL while pending/processing');
            $table->text('notes')->nullable()
                ->comment('Internal processing notes, rejection reason, or completion details');
            $table->string('ip_address', 45)->nullable()
                ->comment('IP address from which the request was submitted');

            $table->index(['userid', 'requested_at'], 'idx_gdpr_requests_userid');
            $table->index(['status', 'requested_at'], 'idx_gdpr_requests_status');
        });

        $schema->ifCapable(
            DatabaseCapabilities::TIMESCALEDB,
            function () use ($schema) {
                $schema->createHypertable('authserver.gdpr_requests', 'requested_at', [
                    'chunk_time_interval' => '1 month',
                ]);
                $schema->enableCompression('authserver.gdpr_requests');
                $schema->addCompressionPolicy('authserver.gdpr_requests', '1 year');
                $schema->addRetentionPolicy('authserver.gdpr_requests', '7 years');
            }
        );
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.gdpr_requests');
    }
}
