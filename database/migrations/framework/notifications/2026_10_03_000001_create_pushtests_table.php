<?php

namespace Pramnos\Framework\Migrations\Notifications;

use Pramnos\Database\Migration;
use Pramnos\Push\TestPush;

/**
 * Test notifications, and whether the device said it received them.
 *
 * The push log records what the push service answered, and a `201` there means the service
 * accepted the message — not that a phone showed it. When somebody says "I got nothing", the
 * question is which of the two failed, and only the device can answer: its service worker
 * posts a receipt the moment the push arrives. One row per test per device holds that
 * receipt, so the screen can say "received at 07:06" rather than only "sent".
 *
 * A table rather than the cache: the receipt arrives as a request from the device, which may
 * land on a different web server from the one that sent the test.
 */
class CreatePushtestsTable extends Migration
{
    public string $feature      = 'notifications';
    public string $scope        = 'framework';
    public int    $priority     = 30;
    public array  $dependencies = ['create_pramnos_schema', 'create_pushsubscriptions_table'];

    public $description = 'Creates pramnos.pushtests, the receipts of test push notifications';

    public function up(): void
    {
        $schema = $this->schema();
        $schema->ensureSchema('pramnos');

        if ($schema->hasTable(TestPush::TABLE)) {
            return;
        }

        $schema->createTable(TestPush::TABLE, function ($table) {
            $table->comment('Test push notifications and the receipt each device sent back');

            // The token is the receipt's credential, so it is stored as its hash, like every
            // other token at rest: a dump of this table cannot be replayed as receipts.
            $table->char('token_hash', 64)->primary()->comment('SHA-256 of the token in the receipt address');
            $table->bigInteger('userid')->comment('The account the test was for');
            $table->char('endpoint_hash', 64)->comment('Which of its browsers');
            $table->integer('sent_at')->unsigned()->comment('Unix time the test was sent');
            $table->integer('received_at')->unsigned()->nullable()
                ->comment('Unix time the device said it arrived; null until it does');
            $table->integer('expires_at')->unsigned()->comment('After this a receipt is not accepted');

            $table->index(['userid', 'sent_at'], 'idx_pushtests_user');
        });
    }

    public function down(): void
    {
        $this->schema()->dropTableIfExists(TestPush::TABLE);
    }
}
