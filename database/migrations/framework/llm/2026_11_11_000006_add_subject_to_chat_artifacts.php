<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `chat_artifacts.subject`: the record an artifact is about, as `entity:id` (`user:123`,
 * `ticket:88`), so a record's own screen can show what the assistant made about it.
 */
class AddSubjectToChatArtifacts extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 51;
    public $description  = 'Adds subject to chat_artifacts';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('assist.chat_artifacts') || $schema->hasColumn('assist.chat_artifacts', 'subject')) {
            return;
        }
        $schema->alterTable('assist.chat_artifacts', function ($table) {
            $table->string('subject', 96)->nullable();
            $table->index(['subject']);
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasTable('assist.chat_artifacts') && $schema->hasColumn('assist.chat_artifacts', 'subject')) {
            // The column's index goes with it.
            $schema->alterTable('assist.chat_artifacts', function ($table) {
                $table->dropColumn('subject');
            });
        }
    }
}
