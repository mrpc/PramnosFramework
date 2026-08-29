<?php

namespace Pramnos\Framework\Migrations\Notifications;

use Pramnos\Database\Migration;

/**
 * Creates the web-push subscription table.
 *
 * A subscription is not notification content — it is a **credential for a delivery address**,
 * closer to a session or a passkey than to a message. Whoever holds the endpoint can send a
 * notification to that browser, so it is stored, indexed and pruned like a credential: never
 * logged, never returned by an API, and deleted the moment the push service says it is gone.
 */
class CreatePushSubscriptionsTable extends Migration
{
    public string $feature     = 'notifications';
    public string $scope       = 'framework';
    public int    $priority    = 20;
    public $description = 'Creates the web-push subscriptions table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('pushsubscriptions')) {
            return;
        }

        $schema->createTable('pushsubscriptions', function ($table) {
            $table->comment(
                'One row per browser that agreed to receive notifications. The endpoint is a '
                . 'secret: anybody holding it can push to that browser.'
            );

            $table->increments('id');
            $table->bigInteger('userid')
                ->comment('Who the browser is signed in as');
            $table->text('endpoint')
                ->comment('The push service URL. Too long for a unique index, hence the hash.');
            $table->char('endpoint_hash', 64)
                ->comment('sha256(endpoint) — what makes "this browser again" recognisable');
            $table->string('p256dh', 255)->default('')
                ->comment("The browser's public key, base64url");
            $table->string('auth_secret', 64)->default('')
                ->comment('The shared secret from the browser, base64url');
            $table->string('content_encoding', 16)->default('aes128gcm');
            $table->string('user_agent', 255)->default('')
                ->comment('So a person can recognise which device they are revoking');
            $table->integer('created_at')->default(0);
            $table->integer('last_success_at')->nullable();
            $table->smallInteger('failure_count')->default(0)
                ->comment('Consecutive failures. A 404/410 deletes the row instead.');

            /*
             * One row per browser, not one per subscribe() call.
             *
             * A page that calls `subscribe()` on every load — which is the normal shape, since
             * the browser answers instantly when permission is already granted — would otherwise
             * add a row every time, and every notification would be delivered to the same device
             * a hundred times.
             */
            $table->unique(['userid', 'endpoint_hash'], 'uniq_pushsubscriptions_user_endpoint');
            $table->index(['userid'], 'idx_pushsubscriptions_userid');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('pushsubscriptions');
    }
}
