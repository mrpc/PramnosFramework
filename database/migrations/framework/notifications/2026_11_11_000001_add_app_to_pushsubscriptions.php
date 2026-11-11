<?php

namespace Pramnos\Framework\Migrations\Notifications;

use Pramnos\Database\Migration;

/**
 * Adds pushsubscriptions.app — the application a subscription was made from, where it is not the
 * site itself: an assistant's own app on another origin, subscribed through the conversation API
 * ({@see \Pramnos\Llm\Chat\Api\ChatController::pushSubscribe()}). A notification goes to the
 * subscriptions of the app it names (`pushApp()`), NULL — the site — when it names none, so the
 * site's notifications do not reach the app's devices, nor the other way round.
 */
class AddAppToPushSubscriptions extends Migration
{
    public string $feature      = 'notifications';
    public string $scope        = 'framework';
    public int    $priority     = 62;
    public array  $dependencies = ['create_pushsubscriptions_table'];
    public $description = 'Adds pramnos.pushsubscriptions.app';

    /** Add the column, where the table has none yet. */
    public function up(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('pramnos.pushsubscriptions') || $schema->hasColumn('pramnos.pushsubscriptions', 'app')) {
            return;
        }
        $schema->alterTable('pramnos.pushsubscriptions', function ($table) {
            $table->string('app', 32)->nullable()->comment('The app it was made from; NULL = the site itself');
        });
    }

    /** Remove it again. */
    public function down(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasColumn('pramnos.pushsubscriptions', 'app')) {
            $schema->alterTable('pramnos.pushsubscriptions', function ($table) {
                $table->dropColumn('app');
            });
        }
    }
}
