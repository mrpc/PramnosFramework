<?php

namespace Pramnos\Framework\Migrations\Messaging;

use Pramnos\Database\Migration;

/**
 * Creates the massmessagerecipients table — per-user delivery status for broadcasts.
 *
 * Tracks which users a mass message was dispatched to and whether delivery
 * succeeded. One row per (massmessage, user) pair. The status column mirrors
 * the messages.type states so that delivery and read tracking are consistent.
 *
 */
class CreateMassmessagerecepientsTable extends Migration
{
    public string  $feature      = 'messaging';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public array   $dependencies = ['create_massmessages_table'];
    public $description  = 'Creates the massmessagerecipients delivery tracking table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('massmessagerecipients')) {
            return;
        }

        $schema->createTable('massmessagerecipients', function ($table) {
            $table->comment('Per-user delivery status for broadcast messages — one row per (massmessage, recipient) pair');

            $table->increments('recipientid')
                ->comment('Auto-increment delivery record identifier');
            $table->unsignedInteger('messageid')
                ->comment('FK to massmessages.messageid — the broadcast this delivery belongs to');
            $table->bigInteger('userid')
                ->comment('FK to users.userid — the recipient user');
            $table->integer('status')->default(0)
                ->comment('Delivery status mirroring messages.type: 0 = pending, 1 = delivered, 2 = failed');

            $table->index(['messageid'], 'idx_massmessagerecipients_messageid');
            $table->index(['userid'], 'idx_massmessagerecipients_userid');
            $table->index(['messageid', 'userid'], 'idx_massmessagerecipients_pair');

            $table->foreign('messageid')
                ->references('messageid')
                ->on('massmessages')
                ->onDelete('CASCADE');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('massmessagerecipients');
    }
}
