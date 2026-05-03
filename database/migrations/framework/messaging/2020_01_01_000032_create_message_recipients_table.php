<?php

namespace Pramnos\Framework\Migrations\Messaging;

use Pramnos\Database\Migration;

/**
 * Creates the message_recipients table.
 *
 * @package PramnosFramework
 */
class CreateMessageRecipientsTable extends Migration
{
    public string  $feature      = 'messaging';
    public string  $scope        = 'framework';
    public int     $priority     = 30;
    public array   $dependencies = ['create_messages_table'];
    public string  $description  = 'Creates the message_recipients table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('message_recipients')) {
            return;
        }

        $schema->createTable('message_recipients', function ($table) {
            $table->increments('id');
            $table->integer('messageid')->unsigned();
            $table->integer('user_id')->unsigned();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->unique(['messageid', 'user_id'], 'uq_message_recipient');
            $table->foreign('messageid')->references('messageid')->on('messages')->onDelete('CASCADE');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('message_recipients');
    }
}
