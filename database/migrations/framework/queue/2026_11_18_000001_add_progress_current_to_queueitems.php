<?php

namespace Pramnos\Framework\Migrations\Queue;

use Pramnos\Database\Migration;

/**
 * Lets a long-running task report how far it has gotten, while it is still running.
 *
 * `QueueManager::updateProgress()` writes it; `queue:status`-style polling (the background
 * export modal's own, for one) reads it to show something better than a static "Exporting…"
 * for a task whose own batches take minutes. State, not history — true only while the task is
 * running, and meaningless once it is not — so it belongs on the row itself, not in any of the
 * four logs (CLAUDE.md rule 14): none of them are a place to read back "what is true right now".
 *
 * No `progress_total` beside it: a cheap upfront COUNT only exists for some task types, over
 * some sources, and nothing reads a total yet. Add it when something actually needs a
 * percentage rather than "N rows so far".
 */
class AddProgressCurrentToQueueitems extends Migration
{
    public string  $feature     = 'queue';
    public string  $scope       = 'framework';
    public int     $priority    = 10;
    public $description = 'Adds queueitems.progress_current, so a running task can report how far it has gotten';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('queueitems') || $schema->hasColumn('queueitems', 'progress_current')) {
            return;
        }

        $schema->table('queueitems', function ($table) {
            $table->integer('progress_current')->nullable()
                ->comment('Units of work done so far (e.g. rows written), while still running; null = not reported');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('queueitems') || !$schema->hasColumn('queueitems', 'progress_current')) {
            return;
        }

        $schema->table('queueitems', function ($table) {
            $table->dropColumn('progress_current');
        });
    }
}
