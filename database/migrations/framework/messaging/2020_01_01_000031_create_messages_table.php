<?php

namespace Pramnos\Framework\Migrations\Messaging;

use Pramnos\Database\Migration;

/**
 * Creates the messages table.
 *
 * @package PramnosFramework
 */
class CreateMessagesTable extends Migration
{
    public string  $feature      = 'messaging';
    public string  $scope        = 'framework';
    public int     $priority     = 20;
    public array   $dependencies = ['create_message_threads_table'];
    public string  $description  = 'Creates the messages table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('messages')) {
            return;
        }

        $schema->createTable('messages', function ($table) {
            $table->increments('messageid');
            $table->integer('threadid')->unsigned();
            $table->integer('sender_id')->unsigned()->nullable();
            $table->text('body');
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['threadid'], 'idx_messages_thread');
            $table->index(['sender_id'], 'idx_messages_sender');
            $table->foreign('threadid')->references('threadid')->on('message_threads')->onDelete('CASCADE');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('messages');
    }
}
