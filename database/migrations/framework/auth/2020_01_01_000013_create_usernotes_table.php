<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;

/**
 * Creates the usernotes table — internal admin notes attached to user accounts.
 *
 * Allows administrators to attach free-form notes to a user's account that are
 * visible only in the admin panel. Not exposed to the end user.
 *
 */
class CreateUsernotesTable extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 40;
    public array   $dependencies = ['create_users_table'];
    public $description  = 'Creates the usernotes admin notes table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('usernotes')) {
            return;
        }

        $schema->createTable('usernotes', function ($table) {
            $table->comment('Admin-only notes attached to user accounts; not visible to the end user');

            $table->bigInteger('userid')
                ->comment('FK to users.userid — the user this note is about');
            $table->bigInteger('admin')->nullable()
                ->comment('FK to users.userid of the administrator who wrote the note; NULL for system-generated notes');
            $table->text('note')
                ->comment('Note content (plain text or basic HTML)');
            $table->integer('date')
                ->comment('Unix timestamp when the note was written');

            $table->index(['userid'], 'idx_usernotes_userid');
            $table->index(['date'], 'idx_usernotes_date');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('usernotes');
    }
}
