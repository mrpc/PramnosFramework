<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Migration;

/**
 * Creates person_events and person_event_log: what each person did, summed for fast conditions,
 * and one row per occurrence for "N times in the last M days".
 *
 * `person_events` is the summary — per person and event, how many times, the first and the last —
 * and is kept for good: "ever paid", "first registered before…" read it. `person_event_log` is the
 * detail, one row per occurrence with its properties, pruned after the retention an application
 * chooses (`audience.event_log_days`), so a windowed count reaches back as far as the log does.
 * Times are Unix seconds.
 */
class CreatePersonEventsTables extends Migration
{
    public string $feature     = 'core';
    public string $scope       = 'framework';
    public int    $priority    = 244;
    public $description = 'Creates person_events and person_event_log, what each person did';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('person_events')) {
            $schema->createTable('person_events', function ($table) {
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

        if (!$schema->hasTable('person_event_log')) {
            $schema->createTable('person_event_log', function ($table) {
                $table->comment('Each occurrence of a person event, with its properties; pruned after the retention chosen');
                $table->bigIncrements('id')->comment('Auto-increment row identifier');
                $table->bigInteger('personid')->comment('users.userid of the person');
                $table->string('event', 100)->comment('The event, by name');
                $table->unsignedInteger('at')->comment('Unix time it happened');
                $table->text('properties')->nullable()->comment('Its properties as JSON — an amount, a package, a page');
                $table->index(['personid', 'event', 'at'], 'idx_person_event_log_person');
                $table->index(['event', 'at'], 'idx_person_event_log_event');
            });
        }
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        $schema->dropTableIfExists('person_event_log');
        $schema->dropTableIfExists('person_events');
    }
}
