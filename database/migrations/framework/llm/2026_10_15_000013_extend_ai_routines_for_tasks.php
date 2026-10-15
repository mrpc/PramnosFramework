<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `ai_routines` becomes the table of every scheduled AI task: a question asked again (`ask`, what
 * every existing row is), a reminder (`reminder`, no model call), or a report (`report`, run
 * without a conversation for its subscribers).
 *
 * Only added columns, each with a default that is what an existing row already did — kind `ask`,
 * owner `user`, notified always, no failures, unlocked — so the rows an installation has run as
 * before.
 */
class ExtendAiRoutinesForTasks extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public $description = 'Extends ai_routines for reminders, reports, organisation tasks and run locking';

    /** Each added column, and how it is declared. */
    private function columns(): array
    {
        return [
            'kind'          => fn ($t) => $t->string('kind', 16)->default('ask')->comment('ask, reminder or report'),
            'owner'         => fn ($t) => $t->string('owner', 16)->default('user')->comment('user, organization or global; userid is the one who made it'),
            'resource_type' => fn ($t) => $t->string('resource_type', 32)->nullable()->comment('What the task is about, as the application names it: property, channel…'),
            'resource_id'   => fn ($t) => $t->integer('resource_id')->nullable(),
            'run_at'        => fn ($t) => $t->timestampTz('run_at')->nullable()->comment('cadence once: when'),
            'nth'           => fn ($t) => $t->integer('nth')->nullable()->comment('cadence monthly_weekday: 1–4, or 5 for the last'),
            'notify'        => fn ($t) => $t->string('notify', 16)->default('always')->comment('always, or relevant: only when the run finds something'),
            'failures'      => fn ($t) => $t->integer('failures')->default(0)->comment('Failed runs in a row; paused at the limit'),
            'locked_until'  => fn ($t) => $t->timestampTz('locked_until')->nullable()->comment('Claimed by a run until then'),
        ];
    }

    public function up(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('ai_routines')) {
            return;
        }

        foreach ($this->columns() as $name => $add) {
            if (!$schema->hasColumn('ai_routines', $name)) {
                $schema->table('ai_routines', fn ($table) => $add($table));
            }
        }
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('ai_routines')) {
            return;
        }

        $present = array_values(array_filter(array_keys($this->columns()), fn (string $c): bool => $schema->hasColumn('ai_routines', $c)));
        if ($present !== []) {
            $schema->table('ai_routines', fn ($table) => $table->dropColumn($present));
        }
    }
}
