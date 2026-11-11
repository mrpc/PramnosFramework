<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `chat_conversations.gist` and `gist_upto`: what a conversation was about, in a sentence or two,
 * written once and again only when it has new messages (up to `gist_upto`) — what a parent reads
 * of a child's conversations before opening one.
 */
class AddGistToChatConversations extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 54;
    public $description  = 'Adds gist and gist_upto to chat_conversations';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('assist.chat_conversations') || $schema->hasColumn('assist.chat_conversations', 'gist')) {
            return;
        }
        $schema->alterTable('assist.chat_conversations', function ($table) {
            $table->text('gist')->nullable();
            $table->integer('gist_upto')->nullable();
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasColumn('assist.chat_conversations', 'gist')) {
            $schema->alterTable('assist.chat_conversations', function ($table) {
                $table->dropColumn('gist');
                $table->dropColumn('gist_upto');
            });
        }
    }
}
