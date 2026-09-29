<?php

namespace Pramnos\Framework\Migrations\Messaging;

use Pramnos\Database\Migration;

/**
 * Creates `pramnos.mailing_list_subscribers` — who asked to receive an opt-in list.
 *
 * `emailoptouts` records who **left**; that is enough for mail a person receives unless they
 * say stop. A newsletter is the other way round: it goes only to those who agreed, and GDPR and
 * ePrivacy expect the agreement to be provable. So each row carries the sentence the person
 * agreed to, where they agreed, from which address, and when they confirmed.
 *
 * Keyed by `(list, email)` rather than by account, because the people waiting for registration
 * to open have no account yet; `userid` is filled in when the address belongs to one.
 */
class CreateMailingListSubscribersTable extends Migration
{
    public string  $feature      = 'messaging';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public array   $dependencies = ['create_pramnos_schema'];
    public $description  = 'Creates the mailing_list_subscribers table — opt-in list membership with its consent trail';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        $schema->ensureSchema('pramnos');

        if ($schema->hasTable('pramnos.mailing_list_subscribers')) {
            return;
        }

        $schema->createTable('pramnos.mailing_list_subscribers', function ($table) {
            $table->comment(
                'Opt-in list membership — one row per (list, address). Only a confirmed row '
                . 'receives a list whose mail type is opt-in.'
            );

            $table->increments('subscriberid')
                ->comment('Auto-increment record identifier');
            $table->string('list', 64)
                ->comment('The list, as named by its MailType');
            $table->string('email', 255)
                ->comment('Recipient address, lowercased');
            $table->bigInteger('userid')->nullable()
                ->comment('The account the address belongs to, when there is one');
            $table->string('status', 16)->default('pending')
                ->comment('pending (asked, not confirmed), confirmed, or unsubscribed');
            $table->string('source', 32)->default('')
                ->comment('Where the request came from: form, account, wordpress, import…');
            $table->text('consent_text')->nullable()
                ->comment('The sentence the person agreed to, as it was shown');
            $table->string('language', 10)->default('')
                ->comment('The language to write to them in');
            $table->string('ip', 45)->default('')
                ->comment('Address the request came from');
            $table->integer('created_at')->default(0)
                ->comment('Unix timestamp of the first request');
            $table->integer('confirmed_at')->nullable()
                ->comment('Unix timestamp the address was confirmed');
            $table->integer('unsubscribed_at')->nullable()
                ->comment('Unix timestamp the address left the list');
            $table->integer('confirmation_sent_at')->nullable()
                ->comment('Unix timestamp the last confirmation mail went out — the resend throttle');

            $table->unique(['list', 'email'], 'uq_mailing_list_subscribers_pair');
            $table->index(['email'], 'idx_mailing_list_subscribers_email');
            $table->index(['userid'], 'idx_mailing_list_subscribers_userid');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('pramnos.mailing_list_subscribers');
    }
}
