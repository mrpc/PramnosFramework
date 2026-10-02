<?php

namespace Pramnos\Framework\Migrations\Queue;

use Pramnos\Database\Migration;

/**
 * Lets a failed task wait before it is tried again.
 *
 * A task that failed went straight back to `pending`, so the next claim — often by the same
 * worker, a moment later — ran it again under exactly the conditions that had just failed it.
 * That is how a unit queued while a deploy landed kept failing: the worker that claimed it
 * still ran the old code, and it claimed the retry too. `availableat` is when a retry may be
 * claimed; until then the task is pending but not offered.
 *
 * Nullable: a task that has not failed is available at once.
 */
class AddAvailableatToQueueitems extends Migration
{
    public string  $feature     = 'queue';
    public string  $scope       = 'framework';
    public int     $priority    = 10;
    public $description = 'Adds queueitems.availableat, so a retry waits instead of running at once';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('queueitems') || $schema->hasColumn('queueitems', 'availableat')) {
            return;
        }

        $schema->table('queueitems', function ($table) {
            $table->timestamp('availableat')->nullable()
                ->comment('Earliest time a retried task may be claimed; null = now');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('queueitems') || !$schema->hasColumn('queueitems', 'availableat')) {
            return;
        }

        $schema->table('queueitems', function ($table) {
            $table->dropColumn('availableat');
        });
    }
}
