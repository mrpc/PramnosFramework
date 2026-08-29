<?php

namespace Pramnos\Framework\Migrations\Notifications;

use Pramnos\Database\Migration;

/**
 * Creates the push audit log.
 *
 * Email had `mails` from the beginning, and it is the table that answers *why did they not get
 * it* — what was sent, when, to whom, and what the transport said. Push had nothing of the kind.
 * `pushsubscriptions` knows when a browser was last reached successfully and how many failures
 * it has since, which answers a question about the **browser**, not about a message; and the
 * mass-send path writes `massmessagerecipients`, which covers one path out of two.
 *
 * Everything a `notify()` sent — the ordinary path, and the one every application uses — left no
 * trace at all. The only way to find out whether a notification had gone out was to ask the
 * person it was sent to.
 *
 * ### Why not one of the tables that already exist
 *
 * Asked directly, and worth answering in the file rather than in a commit message:
 *
 * - **`notifications`** is the in-app inbox, written by `DatabaseChannel` and only when a
 *   notification declares `database` in its `via()`. A push-only notification writes nothing
 *   there, `read_at` means *the person read it*, not *the service accepted it*, and one row per
 *   notification cannot hold one answer per browser.
 * - **`messages`** is the account's own mailbox. A row there is something the person *sees* —
 *   so logging a push into it would show them every notification twice, and «no browser on this
 *   account is subscribed» is not a message to put in somebody's inbox.
 * - **`pushsubscriptions`** is a credential store with one row per browser. "Last reached
 *   successfully" is a fact about the browser, not about a message.
 * - **`massmessagerecipients`** needs a `massmessages` header, which would turn every sign-in
 *   alert into a campaign on the administration screens.
 *
 * The pairing is the framework's own, not a new pattern: the mass-message dispatcher writes the
 * inbox row to `messages` **and** the delivery record to `massmessagerecipients`, two tables,
 * deliberately. This is that same pair for the push channel.
 *
 * ### One row per attempt on one subscription
 *
 * Because that is the granularity at which the answer differs: an account with three browsers
 * gets three answers, and "delivered" for two of them and `410` for the third is the shape of
 * the real question. A send that was refused before any subscription was reached — no key pair,
 * no library, nothing subscribed — is one row with no endpoint and the reason in `error`, which
 * is exactly the case somebody is investigating when they ask.
 *
 * ### The endpoint is not stored here
 *
 * It is a credential: whoever holds it can push to that browser. The hash is enough to join a
 * log row to the subscription it was for, and to say "the same browser again" — which is all
 * this table is asked.
 */
class CreatePushLogTable extends Migration
{
    public string $feature     = 'notifications';
    public string $scope       = 'framework';
    public int    $priority    = 30;
    public array  $dependencies = ['create_pushsubscriptions_table'];
    public $description = 'Creates the push notification audit log';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('pushlog')) {
            return;
        }

        $schema->createTable('pushlog', function ($table) {
            $table->comment(
                'One row per push delivery attempt. The equivalent of `mails` for notifications: '
                . 'what was sent, to whom, and what the push service said about it.'
            );

            $table->increments('pushid');
            $table->bigInteger('userid')->default(0)
                ->comment('The account the notification was for');
            $table->char('endpoint_hash', 64)->default('')
                ->comment('sha256 of the subscription endpoint. Empty when the send never '
                    . 'reached a subscription at all — see `error`.');
            $table->string('notification', 160)->default('')
                ->comment('The notification class, so "what kind" is answerable without '
                    . 'reading every title');
            $table->string('title', 200)->default('');
            $table->string('body', 500)->default('');
            $table->string('url', 500)->default('');
            $table->string('tag', 80)->default('')
                ->comment('Two notifications sharing a tag replace one another on the device');
            $table->smallInteger('status')->default(0)
                ->comment('What the push service answered. 201/200 delivered, 404/410 the '
                    . 'subscription is gone, 429/5xx busy, 0 it never reached a server.');
            $table->string('error', 255)->default('')
                ->comment('Why nothing was sent, when nothing was');
            $table->integer('sent')->default(0)
                ->comment('Unix timestamp');

            // The three questions this table is asked, in the order they are asked.
            $table->index(['sent'], 'idx_pushlog_sent');
            $table->index(['userid', 'sent'], 'idx_pushlog_user');
            $table->index(['status'], 'idx_pushlog_status');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('pushlog');
    }
}
