<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `ai_task_subscriptions` — who receives a shared report besides its maker, and how.
 */
class CreateAiTaskSubscriptionsTable extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public $description = 'Creates ai_task_subscriptions, the readers of a shared AI report';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasTable('pramnos.ai_task_subscriptions')) {
            return;
        }

        $schema->createTable('pramnos.ai_task_subscriptions', function ($table) {
            $table->comment('Who receives a shared AI report, and whether by email too');
            $table->integer('routine_id');
            $table->integer('userid');
            $table->boolean('email')->default(false);
            $table->timestampTz('created_at')->nullable();
            $table->unique(['routine_id', 'userid'], 'idx_ai_task_subscriptions_pair');
            $table->index(['userid'], 'idx_ai_task_subscriptions_user');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('pramnos.ai_task_subscriptions');
    }
}
