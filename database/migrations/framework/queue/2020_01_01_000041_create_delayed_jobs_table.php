<?php

namespace Pramnos\Framework\Migrations\Queue;

use Pramnos\Database\Migration;

/**
 * Creates the delayed_jobs table — the database backend for the delayed-queue
 * capability (see {@see \Pramnos\Queue\Drivers\DatabaseQueueDriver}).
 *
 * Only needed when a delayed queue is configured with the 'database' driver
 * (typically where Redis is unavailable, or where scheduled jobs must survive a
 * cache flush). It is a distinct, simpler shape from the durable `queueitems`
 * work queue: a delayed queue is claim-and-remove, so a claimed row is deleted
 * rather than transitioned through a status lifecycle. There is therefore no
 * status/priority/lock machinery here — only what a due-time dispatcher needs.
 *
 * Times are stored as integer Unix timestamps (not SQL timestamps) so the driver
 * is timezone-independent and its due-time comparisons are identical on MySQL and
 * PostgreSQL, matching the Redis driver's `time()`-based scoring.
 *
 *   job_id   opaque id returned by push() and echoed on the claimed ReservedJob
 *   run_at   Unix second the job becomes due (indexed — the poll access path)
 */
class CreateDelayedJobsTable extends Migration
{
    public string $feature     = 'queue';
    public string $scope       = 'framework';
    public int    $priority    = 11;
    public        $description  = 'Creates the delayed_jobs table for the database delayed-queue driver';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('delayed_jobs')) {
            return;
        }

        $schema->createTable('delayed_jobs', function ($table) {
            $table->comment('Delayed-queue backend (database driver) — claim-and-remove jobs scheduled by run-at time');

            $table->bigIncrements('id')
                ->comment('Auto-increment row id — used as the atomic claim key (DELETE ... WHERE id)');
            $table->string('job_id', 32)
                ->comment('Opaque job identifier returned by push() and carried on the claimed ReservedJob');
            $table->string('type', 191)
                ->comment('Job type name — the handler selector');
            // Stored as TEXT (not a JSON column) so the encoded payload round-trips
            // byte-for-byte — including key order — identically to the Redis driver,
            // which keeps the raw JSON string. A delayed queue never queries by
            // payload, so the JSON column type buys nothing here.
            $table->text('payload')
                ->comment('Job payload as a JSON string — decoded back to an array on claim');
            $table->integer('attempts')->default(0)
                ->comment('Attempts already made (>0 when this row is a re-scheduled retry)');
            $table->bigInteger('run_at')
                ->comment('Unix timestamp the job becomes due; claimDue() returns rows with run_at <= now');
            $table->bigInteger('created_at')
                ->comment('Unix timestamp the job was pushed');

            // Primary poll access path: the soonest due jobs.
            $table->index(['run_at'], 'idx_delayed_jobs_run_at');
            $table->unique(['job_id'], 'uq_delayed_jobs_job_id');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('delayed_jobs');
    }
}
