<?php

namespace Pramnos\Framework\Migrations\Conversations;

use Pramnos\Database\Migration;

/**
 * Adds the desktop application's own report to `pramnos.conversation_clients`: its id (`app`),
 * when it last reported (`appseenat`) and the system's idle seconds it said (`appidle`, a year
 * when the screen is locked). Kept apart from the last client, which a tab replaces, so that while
 * the application runs it notifies — and no web push goes to the browser beside it — whichever
 * client was used last ({@see \Pramnos\Conversations\Clients::appRunning()}).
 *
 * State, not history (rule 14): the application's last report, replaced at each one.
 */
class AddAppToConversationClients extends Migration
{
    public string $feature     = 'conversations';
    public string $scope       = 'framework';
    public int    $priority    = 96;
    public $description = 'Adds conversation_clients.app, appseenat and appidle, the desktop application\'s last report';

    /** Add the columns, where the table has none yet. */
    public function up(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('pramnos.conversation_clients') || $schema->hasColumn('pramnos.conversation_clients', 'app')) {
            return;
        }
        $schema->table('pramnos.conversation_clients', function ($table) {
            $table->string('app', 64)->default('')->comment('The desktop application\'s id, as it reports itself; empty for none');
            $table->integer('appseenat')->default(0)->comment('When the application last reported (unix)');
            $table->integer('appidle')->default(0)->comment('The system\'s idle seconds it reported; a year when locked');
        });
    }

    /** Remove them again. */
    public function down(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('pramnos.conversation_clients') || !$schema->hasColumn('pramnos.conversation_clients', 'app')) {
            return;
        }
        $schema->table('pramnos.conversation_clients', function ($table) {
            $table->dropColumn('app');
            $table->dropColumn('appseenat');
            $table->dropColumn('appidle');
        });
    }
}
