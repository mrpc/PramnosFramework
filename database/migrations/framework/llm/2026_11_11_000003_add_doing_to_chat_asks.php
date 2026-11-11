<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `chat_asks.doing`: what the answer is doing right now, while the worker writes it — the tool it
 * is about to run, and for a page it reads only the site — so the screen following the question
 * says "searching the web" rather than guessing from a timer. State: emptied when text arrives
 * and when the ask ends.
 */
class AddDoingToChatAsks extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public array   $dependencies = ['create_chat_asks_table'];
    public $description  = 'Adds doing to chat_asks';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('assist.chat_asks') || $schema->hasColumn('assist.chat_asks', 'doing')) {
            return;
        }

        $schema->alterTable('assist.chat_asks', function ($table) {
            $table->string('doing', 255)->nullable();
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasColumn('assist.chat_asks', 'doing')) {
            $schema->alterTable('assist.chat_asks', function ($table) {
                $table->dropColumn('doing');
            });
        }
    }
}
