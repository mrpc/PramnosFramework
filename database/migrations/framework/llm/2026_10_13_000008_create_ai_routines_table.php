<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `ai_routines` — questions asked again on a schedule, as their owner, into their own conversation.
 *
 * A table that already exists — an application that kept these under the same name — is left
 * as it is. `organization_id` is nullable and has no foreign key: an application without
 * organisations writes none, and the framework does not assume one.
 */
class CreateAiRoutinesTable extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public $description  = 'Creates ai_routines';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('pramnos.ai_routines')) {
            return;
        }

        $schema->createTable('pramnos.ai_routines', function ($table) {
            $table->increments('routine_id');
            $table->integer('organization_id')->nullable();
            $table->integer('userid');
            $table->string('title', 120);
            $table->text('prompt');
            $table->string('template', 32)->nullable();
            $table->string('cadence', 16);
            $table->string('at', 5);
            $table->integer('day')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('next_run_at')->nullable();
            $table->timestampTz('last_run_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->integer('conversation_id')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'next_run_at']);
            $table->index(['organization_id', 'userid']);
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('pramnos.ai_routines');
    }
}
