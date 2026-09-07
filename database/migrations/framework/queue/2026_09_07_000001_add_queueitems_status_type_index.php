<?php

namespace Pramnos\Framework\Migrations\Queue;

use Pramnos\Database\Migration;

/**
 * An index for the one question a pool asks over and over: how deep is this queue?
 *
 * `count(*) WHERE status = ? AND type = ?` is what a backlog check runs, and what an
 * orchestrator deciding how many workers a type needs runs every reconcile cycle, per
 * profile. The table already has `(status, priority, createdat)` — which leads with
 * `status` and can serve the first predicate — but `type` is not in it, so every matching
 * row has to be fetched to be discarded.
 *
 * Measured on the installation that reported it, on a 175,000-row table: a parallel
 * sequential scan, **98 ms and 50,365 buffers**, five times a minute, for a number that
 * changes slowly.
 *
 * `(status, type)` rather than `(type, status)`: a queue is overwhelmingly `completed`
 * rows awaiting a purge, so `status` is the more selective of the two for every question
 * anybody asks here — pending depth, processing depth, failures.
 *
 * Added rather than replacing anything: `(status, priority, createdat)` is what
 * `getNextTask()` orders by, and dropping it to save an index would trade a fast count
 * for a slow claim.
 */
class AddQueueitemsStatusTypeIndex extends Migration
{
    public string  $feature     = 'queue';
    public string  $scope       = 'framework';
    public int     $priority    = 10;
    public $description = 'Indexes queueitems on (status, type) for backlog counts';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        // An installation that predates the queue has nothing to index; the create
        // migration beside this one will not have run either.
        if (!$schema->hasTable('queueitems')) {
            return;
        }

        if ($schema->hasIndex('queueitems', 'idx_queueitems_status_type')) {
            return;
        }

        $schema->table('queueitems', function ($table) {
            $table->index(['status', 'type'], 'idx_queueitems_status_type');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('queueitems')) {
            return;
        }

        $schema->table('queueitems', function ($table) {
            $table->dropIndex('idx_queueitems_status_type');
        });
    }
}
