<?php

namespace Pramnos\Framework\Migrations\Changelog;

use Pramnos\Database\Migration;

/**
 * An index for the question audiences ask of the application events: who did this event, since
 * when — "had it been on" in the rule editor, across everybody, over the last days.
 *
 * A person's own events are already reached by `(entity, itemid, created_at)`; across all people
 * nothing led with the event, so every row in the window was read to be discarded.
 */
class AddEventIndexToChangelogEvents extends Migration
{
    public string $feature     = 'changelog';
    public string $scope       = 'framework';
    public int    $priority    = 10;
    public $description = 'Indexes changelog_events on (entity, event, created_at)';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('pramnos.changelog_events') || $schema->hasIndex('pramnos.changelog_events', 'idx_changelog_events_event')) {
            return;
        }
        $schema->table('pramnos.changelog_events', function ($table) {
            $table->index(['entity', 'event', 'created_at'], 'idx_changelog_events_event');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasTable('pramnos.changelog_events') && $schema->hasIndex('pramnos.changelog_events', 'idx_changelog_events_event')) {
            $schema->table('pramnos.changelog_events', function ($table) {
                $table->dropIndex('idx_changelog_events_event');
            });
        }
    }
}
