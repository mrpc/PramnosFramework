<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `chat_calendar`: a person's own appointments and tasks, as the assistant keeps them for them —
 * an `event` from `starts_at` to `ends_at`, a `task` by `due_at`, done or not.
 *
 * State, not history: a row is an appointment or task that exists now; one deleted is gone.
 * `alerted_at` is state as well — whether the reminder before an event was dealt with, so it is
 * once: when it went, or the first moment there is (1970-01-01 00:00:01) for one held back by the
 * person's quiet hours, the day's limit or being busy, which therefore never counts as sent.
 * Beside the other `chat_*` tables, in the application's schema, with the same scope columns.
 */
class CreateChatCalendarTable extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public $description  = 'Creates chat_calendar';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('chat_calendar')) {
            return;
        }

        $schema->createTable('chat_calendar', function ($table) {
            $table->increments('item_id');
            $table->integer('organization_id')->nullable();
            $table->integer('userid');
            $table->string('space', 32)->default('chat');
            $table->string('kind', 8);
            $table->string('title', 200);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->timestampTz('due_at')->nullable();
            $table->boolean('done')->default(false);
            $table->text('notes')->nullable();
            $table->timestampTz('alerted_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'userid', 'space', 'kind', 'starts_at']);
            $table->index(['kind', 'starts_at', 'alerted_at']);
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('chat_calendar');
    }
}
