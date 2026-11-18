<?php

namespace Pramnos\Framework\Migrations\Queue;

use Pramnos\Database\Migration;

/**
 * Records the last time somebody actively polled a task's status.
 *
 * `QueueManager::recordPoll()` writes it on every successful status poll;
 * `QueueManager::recentlyPolled()` reads it back to answer "is somebody watching
 * this right now" — the background export modal's own use: skipping a push
 * notification for a result the requester is already looking at on screen,
 * without the task needing a real-time channel to the browser to know that.
 *
 * State, not history (CLAUDE.md rule 14): true only for a short window after the
 * last poll, meaningless once stale, so it lives on the row itself rather than in
 * any of the four logs. Generic — any polled task type can use it, not only
 * `datatable.export`.
 */
class AddLastPolledAtToQueueitems extends Migration
{
    public string  $feature     = 'queue';
    public string  $scope       = 'framework';
    public int     $priority    = 10;
    public $description = 'Adds queueitems.last_polled_at, so a task can tell whether its status is actively being watched';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('queueitems') || $schema->hasColumn('queueitems', 'last_polled_at')) {
            return;
        }

        $schema->table('queueitems', function ($table) {
            $table->timestamp('last_polled_at')->nullable()
                ->comment('Last time a status poll for this task succeeded; null = never polled');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('queueitems') || !$schema->hasColumn('queueitems', 'last_polled_at')) {
            return;
        }

        $schema->table('queueitems', function ($table) {
            $table->dropColumn('last_polled_at');
        });
    }
}
