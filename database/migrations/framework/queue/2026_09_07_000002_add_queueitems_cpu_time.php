<?php

namespace Pramnos\Framework\Migrations\Queue;

use Pramnos\Database\Migration;

/**
 * Records how much of a task's wall clock was actually computing.
 *
 * `execution_time` is wall clock around the handler, and that single number is why an
 * installation spent four hours on four wrong diagnoses. Every one of them fitted it — an
 * atomic claim, a missing index, TimescaleDB compression, a CPU-bound handler — because a
 * task that takes 0.9 seconds looks the same whether it is working or waiting.
 *
 * It was waiting: `/proc/<pid>/wchan` read `do_poll` in 148 of 150 samples with the workers
 * at **1.6% CPU**, on a cache invalidation doing a full redis keyspace scan per model save.
 *
 * Their own conclusion, and it is the right one: *"A queue that recorded CPU time beside wall
 * time per task would have said 'these tasks are not computing' in the first ten minutes."*
 * So it does. The pair is the diagnosis — neither number says anything alone.
 *
 * Nullable, because it is unknowable where `getrusage()` is not available, and a zero would
 * read as "this task did no work" rather than "nobody measured".
 */
class AddQueueitemsCpuTime extends Migration
{
    public string  $feature     = 'queue';
    public string  $scope       = 'framework';
    public int     $priority    = 10;
    public $description = 'Adds queueitems.cpu_time so waiting can be told from working';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('queueitems')) {
            return;
        }

        if ($schema->hasColumn('queueitems', 'cpu_time')) {
            return;
        }

        $schema->table('queueitems', function ($table) {
            $table->decimal('cpu_time', 10, 3)->nullable()
                ->comment('CPU seconds (user+system) the handler used; compare with '
                    . 'execution_time to tell waiting from working. Null when unmeasurable.');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('queueitems')
            || !$schema->hasColumn('queueitems', 'cpu_time')
        ) {
            return;
        }

        $schema->table('queueitems', function ($table) {
            $table->dropColumn('cpu_time');
        });
    }
}
