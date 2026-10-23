<?php

namespace Pramnos\Framework\Migrations\Conversations;

use Pramnos\Database\Migration;

/**
 * Adds `conversation_messages.unfurls`: the links of a message that get a preview card, in order,
 * and which of them its writer took the card away from — `[{"url": …, "hidden": false}]`. What
 * each link shows is kept once per link, in `pramnos.link_previews`.
 */
class AddUnfurlsToConversationMessages extends Migration
{
    public string $feature     = 'conversations';
    public string $scope       = 'framework';
    public int    $priority    = 18;
    public $description = 'Adds conversation_messages.unfurls, the links of a message that get a preview';

    /** Add the column, where the table has none yet. */
    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('conversation_messages') || $schema->hasColumn('conversation_messages', 'unfurls')) {
            return;
        }

        $schema->table('conversation_messages', function ($table) {
            $table->text('unfurls')->nullable()->comment('The links that get a preview, in order, and which its writer hid: JSON');
        });
    }

    /** Remove it again. */
    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('conversation_messages') || !$schema->hasColumn('conversation_messages', 'unfurls')) {
            return;
        }

        $schema->table('conversation_messages', function ($table) {
            $table->dropColumn('unfurls');
        });
    }
}
