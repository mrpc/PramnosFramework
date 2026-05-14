<?php

namespace Pramnos\Framework\Migrations\Messaging;

use Pramnos\Database\Migration;

/**
 * Creates the massmessages table — broadcast message headers.
 *
 * A massmessage is a single authored message dispatched to many recipients.
 * The header row (this table) stores content and targeting criteria; the
 * individual recipient delivery records are in massmessagerecipients and
 * individual copies in the messages table (via massid FK).
 *
 * @package PramnosFramework
 */
class CreateMassmessagesTable extends Migration
{
    public string  $feature      = 'messaging';
    public string  $scope        = 'framework';
    public int     $priority     = 40;
    public $description  = 'Creates the massmessages broadcast header table';

    public function up(): void: void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('massmessages')) {
            return;
        }

        $schema->createTable('massmessages', function ($table) {
            $table->comment('Broadcast message headers — one row per mass-send campaign; individual copies live in messages (massid FK)');

            $table->increments('messageid')
                ->comment('Auto-increment mass message identifier');
            $table->string('subject', 255)->default('')
                ->comment('Subject line sent to all recipients');
            $table->text('message')
                ->comment('Message body (same content sent to all recipients)');
            $table->integer('type')->default(1)
                ->comment('Delivery channel: 0 = Email, 1 = Internal message, 2 = Push notification');
            $table->bigInteger('sender')->nullable()
                ->comment('FK to users.userid of the administrator who authored the broadcast');
            $table->integer('status')->default(0)->nullable()
                ->comment('Dispatch status: 0 = not sent, 1 = sent, 2 = scheduled for future delivery');
            $table->integer('created')->default(0)->nullable()
                ->comment('Unix timestamp when the broadcast was created');
            $table->integer('scheduled')->default(0)->nullable()
                ->comment('Unix timestamp for scheduled delivery; 0 = send immediately');
            $table->integer('totalrecipients')->default(0)
                ->comment('Total number of recipients this broadcast was dispatched to');
            $table->json('request')->nullable()
                ->comment('Full originating API request payload in JSON — used for audit and re-send capability');

            $table->index(['status'], 'idx_massmessages_status');
            $table->index(['sender'], 'idx_massmessages_sender');
            $table->index(['created'], 'idx_massmessages_created');
        });
    }

    public function down(): void: void
    {
        $this->application->database->schema()->dropTableIfExists('massmessages');
    }
}
