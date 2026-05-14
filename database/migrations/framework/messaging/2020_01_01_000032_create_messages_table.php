<?php

namespace Pramnos\Framework\Migrations\Messaging;

use Pramnos\Database\Migration;

/**
 * Creates the messages table — internal private messages and notifications.
 *
 * A single table stores all inbox/outbox/archive/notification states via the
 * `type` column. This avoids separate tables per state and keeps queries simple.
 * Each delivery creates two rows: one for the sender (type=2, sent box) and one
 * for each recipient (type=1 initially, transitions as the recipient interacts).
 *
 * Notifications use types 8 (unread) and 9 (read) and have no fromuserid.
 *
 * @package PramnosFramework
 */
class CreateMessagesTable extends Migration
{
    public string  $feature      = 'messaging';
    public string  $scope        = 'framework';
    public int     $priority     = 30;
    public $description  = 'Creates the messages internal messaging table';

    public function up(): void: void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('messages')) {
            return;
        }

        $schema->createTable('messages', function ($table) {
            $table->comment('Internal private messages and notifications — type column encodes inbox/outbox/archive/notification state');

            $table->increments('messageid')
                ->comment('Auto-increment message record identifier');
            $table->integer('massid')->nullable()
                ->comment('FK to massmessages.messageid — populated when this is a recipient copy of a mass message; NULL for direct messages');
            $table->tinyInteger('type')->default(0)
                ->comment('Message state: 0=read(sent copy) 1=new(inbox) 2=sent 3=inbox-archive 4=outbox-archive 5=unread 6=marked-read 7=deleted 8=notification-new 9=notification-read');
            $table->string('subject', 255)->default('0')
                ->comment('Message subject line (default "0" for legacy compatibility — check for empty string in application code)');
            $table->text('text')
                ->comment('Message body content');
            $table->string('url', 255)->default('')
                ->comment('Optional action URL associated with the message (e.g. deep link for notifications)');
            $table->string('urlcaption', 255)->default('')
                ->comment('Display text for the action URL button');
            $table->text('attachmenttext')
                ->comment('Serialised attachment metadata (filename, path, size); empty string if no attachments');
            $table->string('image', 255)->default('')
                ->comment('Path to an image shown alongside the message (notifications, rich messages)');
            $table->string('securitycode', 10)->default('')
                ->comment('Short random code used for unsubscribe / one-time action links');
            $table->bigInteger('fromuserid')->nullable()
                ->comment('FK to users.userid of the sender; NULL for system-generated notifications');
            $table->bigInteger('touserid')->nullable()
                ->comment('FK to users.userid of the recipient for this specific row');
            $table->integer('date')->default(0)
                ->comment('Unix timestamp when the message was sent');
            $table->string('ip', 15)->default('')
                ->comment('IPv4 address of the sender at send time (15 chars = max IPv4 length)');
            $table->tinyInteger('bbcode')->default(1)
                ->comment('1 = body contains BBCode that should be rendered; 0 = plain text');
            $table->tinyInteger('html')->default(0)
                ->comment('1 = body is raw HTML (trust flag); 0 = must be escaped before output');
            $table->tinyInteger('smilies')->default(1)
                ->comment('1 = replace emoticon text patterns with image smilies; 0 = leave as-is');
            $table->tinyInteger('signature')->default(1)
                ->comment('1 = append sender\'s signature to the displayed message; 0 = suppress signature');
            $table->tinyInteger('attachment')->default(0)
                ->comment('1 = message has one or more file attachments; 0 = no attachments');

            $table->index(['touserid', 'type'], 'idx_messages_touserid_type');
            $table->index(['fromuserid'], 'idx_messages_fromuserid');
            $table->index(['massid'], 'idx_messages_massid');
            $table->index(['date'], 'idx_messages_date');
        });
    }

    public function down(): void: void
    {
        $this->application->database->schema()->dropTableIfExists('messages');
    }
}
