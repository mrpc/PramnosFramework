<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\HypertableRegistry;
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

            $table->bigIncrements('id')
                ->comment('Surrogate auto-increment key; part of composite PK with requested_at for TimescaleDB compatibility');
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
            $table->text('request_details')->nullable()
                ->comment('Detailed description of the request (e.g. specific data categories for access requests, rectification details)');
            $table->text('response_data')->nullable()
                ->comment('Data package returned for access or portability requests; may be a URL or JSON payload');
            // `processing_notes`, not `notes`: the production schema this table
            // was modelled on names it that way, and a fresh install that
            // disagreed would have to be reconciled by hand the first time the
            // two met. 2026_08_10_000001 renames the column on installations
            // created before this was corrected.
            $table->text('processing_notes')->nullable()
                ->comment('Internal processing notes, rejection reason, or completion details');
            $table->bigInteger('processed_by')->nullable()
                ->comment('userid of the admin or system that processed this request; NULL if not yet processed');
            $table->string('ip_address', 45)->nullable()
                ->comment('IP address from which the request was submitted');

            // Composite PK: TimescaleDB requires the partition key (requested_at) in every unique/primary constraint.
            $table->primary(['id', 'requested_at']);

            $table->index(['userid', 'requested_at'], 'idx_gdpr_requests_userid');
            $table->index(['status', 'requested_at'], 'idx_gdpr_requests_status');
        });

        // The parameters live in HypertableRegistry, which this and
        // timescale:ensure both read — see that class for why they are not
        // written out here.
        $schema->ifCapable(
            DatabaseCapabilities::TIMESCALEDB,
            function () use ($schema) {
                HypertableRegistry::apply($schema, 'authserver.gdpr_requests');
            }
        );
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.gdpr_requests');
    }
}
