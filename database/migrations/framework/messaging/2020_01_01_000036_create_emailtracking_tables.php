<?php

namespace Pramnos\Framework\Migrations\Messaging;

use Pramnos\Database\Migration;

/**
 * Creates the email tracking tables — opens and clicks, for mail that consented to it.
 *
 * The framework has had `Email::enableTracking()` for a long time and it has never worked:
 * there was no migration for the table it writes to and no route for the pixel it embeds, so
 * the insert failed into a `catch` and the pixel pointed at a 404. This is the missing half.
 *
 * Two tables rather than one, because they answer different questions. `emailtracking` is one
 * row per tracked message — was it opened, how often, was it a proxy. `emailtrackingclicks` is
 * one row per link followed, which is the only question worth asking of a click: *which* link.
 *
 * On **why the open count is not the number of people who read it**, see the guide. Briefly:
 * Apple Mail fetches every remote image on delivery whether or not anybody opens the message,
 * so `proxy_opens` is separated from `opens` rather than added to it.
 */
class CreateEmailTrackingTables extends Migration
{
    public string $feature     = 'messaging';
    public string $scope       = 'framework';
    public int    $priority    = 11;
    public $description = 'Creates the email open/click tracking tables';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('emailtracking')) {
            $schema->createTable('emailtracking', function ($table) {
                $table->comment(
                    'One row per tracked message. `opens` counts what looks like a person; '
                    . '`proxy_opens` counts what a mailbox provider fetched on delivery — they '
                    . 'are separate because adding them together is how a 70% open rate is '
                    . 'reported for a message nobody read.'
                );

                $table->increments('id')
                    ->comment('Auto-increment identifier');
                $table->string('tracking_id', 64)
                    ->comment('The opaque id carried by the pixel and the click links');
                $table->integer('mailid')->nullable()
                    ->comment('The `mails` row this message was recorded as, when there is one');
                $table->string('recipient', 190)->default('')
                    ->comment('Who it was sent to, for the one question the audit log answers');
                $table->string('list', 64)->default('')
                    ->comment('The list this belonged to. Tracking is only for list mail.');
                $table->string('subject', 255)->default('')
                    ->comment('Denormalised, so a report needs no join to be readable');
                $table->integer('sent_at')->default(0)
                    ->comment('Unix time the message was sent');
                $table->integer('opens')->default(0)
                    ->comment('Fetches that did not come from a known mailbox proxy');
                $table->integer('proxy_opens')->default(0)
                    ->comment('Fetches from Apple Mail Privacy Protection, Gmail and the like');
                $table->integer('first_open_at')->nullable()
                    ->comment('Unix time of the first non-proxy fetch');
                $table->integer('last_open_at')->nullable()
                    ->comment('Unix time of the most recent non-proxy fetch');
                $table->integer('clicks')->default(0)
                    ->comment('Links followed. A click is a deliberate act; an open is not.');
                $table->integer('first_click_at')->nullable();
                $table->integer('last_click_at')->nullable();

                $table->unique(['tracking_id'], 'uniq_emailtracking_tracking_id');
                $table->index(['list'], 'idx_emailtracking_list');
                $table->index(['sent_at'], 'idx_emailtracking_sent_at');
                $table->index(['mailid'], 'idx_emailtracking_mailid');
            });
        }

        if ($schema->hasTable('emailtrackingclicks')) {
            return;
        }

        $schema->createTable('emailtrackingclicks', function ($table) {
            $table->comment('One row per link followed from a tracked message');

            $table->increments('id');
            $table->string('tracking_id', 64)
                ->comment('The message the link was in');
            $table->string('url', 500)->default('')
                ->comment('Where it went — the destination, not the wrapped link');
            $table->integer('clicked_at')->default(0);

            $table->index(['tracking_id'], 'idx_emailtrackingclicks_tracking_id');
            $table->index(['clicked_at'], 'idx_emailtrackingclicks_clicked_at');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        $schema->dropTableIfExists('emailtrackingclicks');
        $schema->dropTableIfExists('emailtracking');
    }
}
