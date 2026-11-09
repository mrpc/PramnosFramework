<?php

namespace Pramnos\Framework\Migrations\Notifications;

use Pramnos\Database\Migration;

/**
 * Adds pushsubscriptions.client — the tab a browser's subscription was made in, by the random id
 * the tab keeps for itself — so a conversation's push goes to the client its reader is in, not to
 * every browser ({@see \Pramnos\Conversations\Clients}). NULL for a subscription from a page
 * that sends none: it is told as before.
 */
class AddClientToPushSubscriptions extends Migration
{
    public string $feature      = 'notifications';
    public string $scope        = 'framework';
    public int    $priority     = 61;
    public array  $dependencies = ['create_pushsubscriptions_table'];
    public $description = 'Adds pramnos.pushsubscriptions.client';

    /** Add the column, where the table has none yet. */
    public function up(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('pramnos.pushsubscriptions') || $schema->hasColumn('pramnos.pushsubscriptions', 'client')) {
            return;
        }
        $schema->alterTable('pramnos.pushsubscriptions', function ($table) {
            $table->string('client', 64)->nullable()->comment('The tab\'s own random id; NULL = a page that sends none');
        });
    }

    /** Remove it again. */
    public function down(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasColumn('pramnos.pushsubscriptions', 'client')) {
            $schema->alterTable('pramnos.pushsubscriptions', function ($table) {
                $table->dropColumn('client');
            });
        }
    }
}
