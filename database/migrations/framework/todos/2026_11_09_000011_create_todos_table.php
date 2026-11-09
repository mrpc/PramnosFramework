<?php

namespace Pramnos\Framework\Migrations\Todos;

use Pramnos\Database\Migration;

/**
 * Creates `pramnos.todos`: what is to be done now — a person's own items, and those the
 * application's sources keep on the list ({@see \Pramnos\Todos\Todos}).
 *
 * State, not history (rule 14): an item is what is to be done now, and one closed more than a week
 * goes from every list. Who changed what goes to the change log, as `todo` events.
 */
class CreateTodosTable extends Migration
{
    public string $feature     = 'todos';
    public string $scope       = 'framework';
    public int    $priority    = 60;
    public $description = 'Creates pramnos.todos, the to-do list kept in step with the application\'s sources';

    /** Create it. */
    public function up(): void
    {
        $schema = $this->application->database->schema();
        $schema->ensureSchema('pramnos');
        if ($schema->hasTable('pramnos.todos')) {
            return;
        }
        $schema->createTable('pramnos.todos', function ($table) {
            $table->comment('The to-do list: own items, and those the sources keep on it');
            $table->increments('todoid');
            $table->bigInteger('tenant')->default(0)->comment('The organisation; 0 for none');
            $table->string('kind', 40)->default('own')->comment('own, or the source\'s kind');
            $table->string('source', 191)->nullable()->comment('What a source\'s item stands for, unique per tenant');
            $table->text('title');
            $table->string('link', 500)->nullable();
            $table->string('status', 12)->default('open')->comment('open, in_progress, done, dismissed');
            $table->integer('assigned_to')->nullable();
            $table->boolean('assigned_by_hand')->default(false)->comment('A person chose the assignee: the source no longer does');
            $table->integer('due_at')->nullable()->comment('The end of its day in the tenant\'s zone');
            $table->integer('remind_at')->nullable()->comment('Cleared once sent');
            $table->integer('due_told_at')->nullable();
            $table->boolean('private')->default(false);
            $table->integer('sort')->default(0);
            $table->bigInteger('messageid')->nullable();
            $table->bigInteger('conversationid')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('created_at')->default(0);
            $table->integer('updated_at')->default(0);
            $table->integer('closed_at')->nullable();
            $table->unique(['tenant', 'source'], 'uq_todos_source');
            $table->index(['tenant', 'status'], 'idx_todos_tenant');
            $table->index(['assigned_to', 'status'], 'idx_todos_assigned');
            $table->index(['remind_at'], 'idx_todos_remind');
        });
    }

    /** Drop it. */
    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('pramnos.todos');
    }
}
