<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;

/**
 * Creates the userdetails table — EAV (Entity-Attribute-Value) extension storage.
 *
 * Allows arbitrary key/value pairs to be attached to a user without altering the
 * users table schema. Used by applications to store domain-specific profile data
 * that doesn't belong in the core users table.
 *
 * @package PramnosFramework
 */
class CreateUserdetailsTable extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 20;
    public array   $dependencies = ['create_users_table'];
    public $description  = 'Creates the userdetails EAV table';

    public function up(): void: void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('userdetails')) {
            return;
        }

        $schema->createTable('userdetails', function ($table) {
            $table->comment('EAV extension store for user profiles — arbitrary key/value pairs per user');

            $table->bigInteger('userid')
                ->comment('FK to users.userid — identifies the owning user');
            $table->string('fieldname', 35)
                ->comment('Attribute name / field identifier (max 35 chars)');
            $table->text('value')
                ->comment('Attribute value — may be a plain string, JSON, or serialised data');

            $table->primary(['userid', 'fieldname']);

            $table->index(['userid'], 'idx_userdetails_userid');

            $table->foreign('userid')
                ->references('userid')
                ->on('users')
                ->onDelete('CASCADE');
        });
    }

    public function down(): void: void
    {
        $this->application->database->schema()->dropTableIfExists('userdetails');
    }
}
