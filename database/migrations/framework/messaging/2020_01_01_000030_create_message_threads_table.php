<?php

namespace Pramnos\Framework\Migrations\Messaging;

use Pramnos\Database\Migration;

/**
 * Creates the message_threads table.
 *
 * @package PramnosFramework
 */
class CreateMessageThreadsTable extends Migration
{
    public string  $feature      = 'messaging';
    public string  $scope        = 'framework';
    public int     $priority     = 10;
    public string  $description  = 'Creates the message_threads table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('message_threads')) {
            return;
        }

        $schema->createTable('message_threads', function ($table) {
            $table->increments('threadid');
            $table->string('subject', 255)->default('');
            $table->boolean('closed')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('message_threads');
    }
}
