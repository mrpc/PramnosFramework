<?php

namespace Pramnos\Framework\Migrations\Todos;

use Pramnos\Database\Migration;

/**
 * Adds to `pramnos.todos` what a repeating item needs: `repeat` (daily, weekdays, weekly,
 * monthly, yearly), `repeat_from` (the first occurrence, which a month's or a year's day is
 * counted from) and `reminded_at` (when its reminder was sent, so the next one counts from it).
 * State, as the rest of the row: the next occurrence is the next row, not a history.
 */
class AddRepeatToTodos extends Migration
{
    public string $feature     = 'todos';
    public string $scope       = 'framework';
    public int    $priority    = 61;
    public $description = 'Adds repeat, repeat_from and reminded_at to pramnos.todos';

    /** Add them. */
    public function up(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('pramnos.todos') || $schema->hasColumn('pramnos.todos', 'repeat')) {
            return;
        }
        $schema->table('pramnos.todos', function ($table) {
            $table->string('repeat', 12)->nullable()->comment('daily, weekdays, weekly, monthly, yearly; null once');
            $table->integer('repeat_from')->nullable()->comment('The first occurrence: a month\'s day is counted from it');
            $table->integer('reminded_at')->nullable()->comment('When its reminder was sent');
        });
    }

    /** Drop them. */
    public function down(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasColumn('pramnos.todos', 'repeat')) {
            $schema->table('pramnos.todos', function ($table) {
                $table->dropColumn(['repeat', 'repeat_from', 'reminded_at']);
            });
        }
    }
}
