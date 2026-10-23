<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Migration;

/**
 * Creates person_events: what each person did, summed for fast conditions. Each occurrence, with
 * its properties, is kept in the change log's application events (entity `user`).
 *
 * `person_events` is the summary — per person and event, how many times, the first and the last —
 * and is kept for good: "ever paid", "first registered before…" read it. "N times in the last M
 * days" reads the change log, as far back as it keeps. Times are Unix seconds.
 */
class CreatePersonEventsTables extends Migration
{
    public string $feature     = 'core';
    public string $scope       = 'framework';
    public int    $priority    = 244;
    public $description = 'Creates person_events, what each person did';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('pramnos.person_events')) {
            $schema->createTable('pramnos.person_events', function ($table) {
                $table->comment('What each person did, summed: how many times, the first and the last');
                $table->bigInteger('personid')->comment('users.userid of the person');
                $table->string('event', 100)->comment('The event, by name — segment.entered, payment.made…');
                $table->integer('occurrences')->default(0)->comment('How many times');
                $table->unsignedInteger('first_at')->comment('Unix time of the first');
                $table->unsignedInteger('last_at')->comment('Unix time of the last');
                $table->primary(['personid', 'event']);
                $table->index(['event', 'last_at'], 'idx_person_events_event_last');
            });
        }

    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        $schema->dropTableIfExists('pramnos.person_events');
    }
}
