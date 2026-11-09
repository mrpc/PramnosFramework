<?php

namespace Pramnos\Framework\Migrations\Conversations;

use Pramnos\Database\Migration;

/**
 * `pramnos.conversation_clients`: where each person is — the client (a tab, the desktop
 * application) they last did something in, when, and how idle their system said it was — so a
 * message's notification goes to that client alone, and to every one once they are away
 * ({@see \Pramnos\Conversations\Clients}). Beside it, their choice of how long away means away.
 *
 * State, not history (rule 14): one row per person, replaced at each report; nothing of where they
 * were before is kept. In `pramnos.*` (rule 15): the engine's routing, not the application's data.
 */
class CreateConversationClientsTable extends Migration
{
    public string $feature     = 'conversations';
    public string $scope       = 'framework';
    public int    $priority    = 17;
    public $description = 'Creates pramnos.conversation_clients, the client each person was last active in';

    /** Create it. */
    public function up(): void
    {
        $schema = $this->application->database->schema();
        $schema->ensureSchema('pramnos');
        if ($schema->hasTable('pramnos.conversation_clients')) {
            return;
        }
        $schema->createTable('pramnos.conversation_clients', function ($table) {
            $table->comment('The client each person was last active in, for where a notification goes');
            $table->bigInteger('userid')->primary();
            $table->string('client', 64)->default('')->comment('The random id the tab or application keeps for itself');
            $table->integer('seenat')->default(0)->comment('When it last reported something done (unix)');
            $table->integer('idle')->default(0)->comment('The system\'s idle seconds it reported; a year when locked');
            $table->smallInteger('front')->default(1)->comment('1 when it was in front: a visible tab, a window not minimised');
            $table->smallInteger('awayafter')->nullable()->comment('Their minutes away before every client is told; null: the default');
        });
    }

    /** Drop it. */
    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('pramnos.conversation_clients');
    }
}
