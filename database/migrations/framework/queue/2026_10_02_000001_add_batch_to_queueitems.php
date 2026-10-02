<?php

namespace Pramnos\Framework\Migrations\Queue;

use Pramnos\Database\Migration;

/**
 * Lets a set of tasks queued together be read back as one: a batch.
 *
 * A scheduled pass that fans out — one task per channel, per feed, per site — had no way to
 * know when its units were all done, so `withoutOverlapping()` guarded only the enqueueing
 * and the next pass could queue on top of one still running. `batchid` names one call of
 * `QueueManager::addMany()`; `batchname` names the pass, so "is the last one still going" is
 * one indexed read.
 *
 * Both nullable: a task queued on its own belongs to no batch.
 */
class AddBatchToQueueitems extends Migration
{
    public string  $feature     = 'queue';
    public string  $scope       = 'framework';
    public int     $priority    = 10;
    public $description = 'Adds queueitems.batchid and batchname, so a fanned-out pass can be followed';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('queueitems') || $schema->hasColumn('queueitems', 'batchid')) {
            return;
        }

        $schema->table('queueitems', function ($table) {
            $table->string('batchid', 32)->nullable()
                ->comment('One QueueManager::addMany() call; null for a task queued on its own');
            $table->string('batchname', 100)->nullable()
                ->comment('What the batch is for, e.g. channels.collect — read by batchInProgress()');

            $table->index(['batchid', 'status'], 'idx_queueitems_batch');
            $table->index(['batchname', 'status'], 'idx_queueitems_batchname');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('queueitems') || !$schema->hasColumn('queueitems', 'batchid')) {
            return;
        }

        $schema->table('queueitems', function ($table) {
            $table->dropIndex('idx_queueitems_batch');
            $table->dropIndex('idx_queueitems_batchname');
            $table->dropColumn('batchid');
            $table->dropColumn('batchname');
        });
    }
}
