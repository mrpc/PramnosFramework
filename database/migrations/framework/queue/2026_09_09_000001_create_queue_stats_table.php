<?php

namespace Pramnos\Framework\Migrations\Queue;

use Pramnos\Database\Migration;

/**
 * Keeps the queue's own measurements, which the queue was deleting.
 *
 * The framework records `execution_time` and `cpu_time` per task, and `queue:cleanup`
 * deletes them an hour later. Nothing aggregated them first, so:
 *
 * - **`queue:health --window` gave a wrong answer past the retention, silently.**
 *   `throughput()` counts arrivals and completions among rows that are still there. Ask for
 *   a day — which the option invites — and it counts the ninety minutes that survived,
 *   reports the rate as if that were the day, and exits 0.
 * - **Wait time was recorded nowhere at all.** The most useful number a queue has — how
 *   long a task sat before a worker took it — existed only as `startedat - createdat` on
 *   rows about to be deleted.
 *
 * One installation found a nightly burst by running this aggregate over its purge set
 * before proposing it: at 01:00, 16,130 tasks at an average wait of 9.48 s and a maximum of
 * 40, against ~0.60 s for every other hour. **Queue latency fifteen times normal, invisible
 * that night and every night before it.**
 *
 * ## Why a plain table
 *
 * One row per task type per hour. Three types is ~72 rows a day, 26k a year — so no
 * hypertable, no compression, no retention policy, no spool. The change log needed all of
 * those because volume was the problem there; here there is none, and building for it would
 * be building for a problem that does not exist.
 *
 * ## Sums and maxima, never averages
 *
 * That is what makes the roll-up additive, and additive is what makes it safe: a second
 * cleanup inside the same hour adds to the bucket instead of overwriting it. Means are
 * derived at read.
 *
 * **Percentiles are deliberately absent.** `percentile_cont` does not merge across two
 * inserts into one bucket, so storing p95 would mean either a wrong number or a second pass
 * over rows that no longer exist. `max` and the derived mean find the problem — the burst
 * above shows in both.
 *
 * ## Status is broken out, and the framework's own retention is why
 *
 * `queue:cleanup` keeps warnings for `$hours * 10`, which on that installation made
 * `warning` the **dominant** population: 38,041 rows against 25,861 completed. A roll-up
 * that counted only "tasks" would describe the small half.
 */
class CreateQueueStatsTable extends Migration
{
    public string $feature     = 'queue';
    public string $scope       = 'framework';
    public int    $priority    = 11;
    public array  $dependencies = ['create_queueitems_table'];
    public $description = 'Creates queuestats, the hourly roll-up queue:cleanup writes before deleting';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('queuestats')) {
            return;
        }

        $schema->createTable('queuestats', function ($table) {
            $table->comment(
                'Hourly per-type roll-up of queueitems, written by QueueManager::purgeOldTasks() '
                . 'in the same transaction as the DELETE it summarises'
            );

            $table->string('type', 50)
                ->comment('Task type, as in queueitems.type');
            $table->timestamp('bucket')
                ->comment('The hour this row summarises, truncated from completedat');

            $table->integer('tasks')->default(0)
                ->comment('Rows summarised into this bucket, whatever their status');
            $table->integer('failed')->default(0)
                ->comment('Of those, status = failed');
            $table->integer('warning')->default(0)
                ->comment('Of those, status = warning — the dominant population where cleanup keeps warnings ten times longer');
            $table->integer('retried')->default(0)
                ->comment('Of those, attempts > 1: a task a worker had to take more than once');

            // Sums and maxima only. An average cannot be added to another average, and this
            // row is written more than once per bucket whenever cleanup runs twice in an hour.
            $table->decimal('sum_exec', 14, 3)->default(0)
                ->comment('Sum of execution_time in seconds; divide by tasks for the mean');
            $table->decimal('max_exec', 10, 3)->default(0)
                ->comment('Longest execution_time in the bucket');
            $table->decimal('sum_cpu', 14, 3)->nullable()
                ->comment('Sum of cpu_time; NULL where getrusage() was unavailable, which is not zero work');
            $table->decimal('sum_wait', 14, 3)->default(0)
                ->comment('Sum of startedat - createdat in seconds: how long tasks sat before a worker took them');
            $table->decimal('max_wait', 10, 3)->default(0)
                ->comment('Longest wait in the bucket — the number that made a 15x latency burst visible');

            // `(type, bucket)` and not a surrogate id: the roll-up is an upsert on exactly
            // this pair, and a key it cannot conflict on is a key that lets it insert twice.
            $table->primary(['type', 'bucket']);

            $table->index(['bucket'], 'idx_queuestats_bucket');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('queuestats');
    }
}
