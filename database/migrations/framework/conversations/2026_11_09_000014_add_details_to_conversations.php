<?php

namespace Pramnos\Framework\Migrations\Conversations;

use Pramnos\Database\Migration;

/**
 * A channel's details, as Slack keeps them: a description beside its topic, whether a mention of
 * somebody not in it offers to add them (`suggestmembers`), who manages its tabs (`tabsby`:
 * everyone or its admins), and the tabs themselves (`tabs`, JSON). State, not history: who changed
 * what goes to the changelog. After the move to `chat.*`.
 */
class AddDetailsToConversations extends Migration
{
    public string $feature     = 'conversations';
    public string $scope       = 'framework';
    public int    $priority    = 95;
    public $description = 'Adds conversations.description, suggestmembers, tabsby and tabs, a channel\'s details';

    /** Add the columns, where the table has none yet. */
    public function up(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('chat.conversations') || $schema->hasColumn('chat.conversations', 'description')) {
            return;
        }
        $schema->table('chat.conversations', function ($table) {
            $table->string('description', 250)->nullable()->comment('What the channel is for');
            $table->boolean('suggestmembers')->default(true)->comment('A mention of somebody not in it offers to add them');
            $table->string('tabsby', 10)->default('everyone')->comment('Who manages its tabs: everyone or admins');
            $table->text('tabs')->nullable()->comment('Its tabs, in order, as JSON; null for the built-in ones');
        });
    }

    /** Remove them again. */
    public function down(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('chat.conversations') || !$schema->hasColumn('chat.conversations', 'description')) {
            return;
        }
        $schema->table('chat.conversations', function ($table) {
            foreach (['description', 'suggestmembers', 'tabsby', 'tabs'] as $column) {
                $table->dropColumn($column);
            }
        });
    }
}
