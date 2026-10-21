<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Migration;

/**
 * Creates consent_lists: what a send or a rule asks before it reaches somebody — "agreed to offers
 * by SMS", "the newsletter" — as a list an administrator names, not a field a rule names.
 *
 * Where the agreement is read from is a source the application declares in code (a filter over
 * people, and the column a new account's default is written to); the row chooses one, so a list
 * can be pointed at another source without touching the rules that use it.
 */
class CreateConsentListsTable extends Migration
{
    public string $feature     = 'core';
    public string $scope       = 'framework';
    public int    $priority    = 243;
    public $description = 'Creates consent_lists, the consent each send and rule asks for';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('consent_lists')) {
            return;
        }

        $schema->createTable('consent_lists', function ($table) {
            $table->comment('Consent a send or a rule asks for: a named list over a source the application declares');
            $table->increments('listid')->comment('Auto-increment list identifier');
            $table->string('name', 150)->comment('Shown to administrators choosing an audience');
            $table->string('channel', 16)->comment('email, sms, push, phone or site');
            $table->text('description')->nullable()->comment('What it means, in words a person reading their settings understands');
            $table->string('source', 64)->comment('The key of a source the application declared with ConsentLists::source()');
            $table->string('optoutlist', 64)->nullable()->comment('An email opt-out list (emailoptouts.list) whose leavers are out too; "all" always counts');
            $table->tinyInteger('defaulton')->default(0)->comment('1 when a new account starts in the list');
            $table->unsignedInteger('createdat')->comment('Unix time it was made');
            $table->unsignedInteger('updatedat')->comment('Unix time it was last changed');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('consent_lists');
    }
}
