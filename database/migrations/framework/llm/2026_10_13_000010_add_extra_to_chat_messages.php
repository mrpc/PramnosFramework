<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `chat_messages.extra`, on a table that existed before the framework created it: the
 * application's own data about a message, as JSON.
 */
class AddExtraToChatMessages extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public array   $dependencies = ['create_chat_messages_table'];
    public $description  = 'Adds extra to chat_messages';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('chat_messages') || $schema->hasColumn('chat_messages', 'extra')) {
            return;
        }

        $schema->alterTable('chat_messages', function ($table) {
            $table->text('extra')->nullable();
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasColumn('chat_messages', 'extra')) {
            $schema->alterTable('chat_messages', function ($table) {
                $table->dropColumn('extra');
            });
        }
    }
}
