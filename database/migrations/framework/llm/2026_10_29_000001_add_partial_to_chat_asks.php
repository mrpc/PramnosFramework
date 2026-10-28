<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `chat_asks.partial`: the answer's text so far while the worker is still writing it, so the
 * screen following the question shows it arriving. Emptied when the ask is answered or fails —
 * the answer itself is kept with the conversation.
 */
class AddPartialToChatAsks extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public array   $dependencies = ['create_chat_asks_table'];
    public $description  = 'Adds partial to chat_asks';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('chat_asks') || $schema->hasColumn('chat_asks', 'partial')) {
            return;
        }

        $schema->alterTable('chat_asks', function ($table) {
            $table->text('partial')->nullable();
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasColumn('chat_asks', 'partial')) {
            $schema->alterTable('chat_asks', function ($table) {
                $table->dropColumn('partial');
            });
        }
    }
}
