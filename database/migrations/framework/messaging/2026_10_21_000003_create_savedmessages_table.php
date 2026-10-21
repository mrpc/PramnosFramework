<?php

namespace Pramnos\Framework\Migrations\Messaging;

use Pramnos\Database\Migration;

/**
 * Creates `savedmessages`: messages an administrator keeps to send again — a subject and a body
 * with their placeholders as written — each an account's own, or shared (`userid` null).
 *
 * Not the mail templates: those are the notifications the application sends, by category. These
 * are what a person writes by hand and wants again, offered above a message form by scope
 * (`user-message`, `mass-message`, an application's own).
 */
class CreateSavedmessagesTable extends Migration
{
    public string  $feature      = 'messaging';
    public string  $scope        = 'framework';
    public int     $priority     = 52;
    public $description  = 'Creates savedmessages, messages kept to send again';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('savedmessages')) {
            return;
        }

        $schema->createTable('savedmessages', function ($table) {
            $table->comment('Messages kept to send again: an account\'s own, or shared (userid null)');
            $table->increments('id');
            $table->string('name', 150)->comment('What the list calls it');
            $table->string('subject', 255)->default('')->comment('The subject, placeholders as written');
            $table->text('body')->nullable()->comment('The body (HTML), placeholders as written');
            $table->unsignedInteger('userid')->nullable()->comment('The account it is kept for; null when shared');
            $table->string('scope', 60)->default('')->comment('Which form offers it: user-message, mass-message, …');
            $table->unsignedInteger('created_at')->comment('Unix time');
            $table->unsignedInteger('updated_at')->comment('Unix time');
            $table->index(['scope', 'userid'], 'idx_savedmessages_scope');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('savedmessages');
    }
}
