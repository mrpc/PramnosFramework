<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Migration;

/**
 * Creates the sessions table used by the framework for visitor session tracking.
 *
 * Each row represents an active or recent visitor session. The primary key is
 * a text token (visitorid) rather than an auto-increment integer, allowing the
 * application to generate session IDs in PHP before writing to the database.
 *
 * @package PramnosFramework
 */
class CreateSessionsTable extends Migration
{
    public string  $feature      = 'core';
    public string  $scope        = 'framework';
    public int     $priority     = 10;
    public $description  = 'Creates the sessions table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('sessions')) {
            return;
        }

        $schema->createTable('sessions', function ($table) {
            $table->comment('Visitor session tracking — one row per active session');

            $table->string('visitorid', 255)
                ->comment('Session identifier token (generated in PHP, not auto-increment)')
                ->primary();
            $table->string('uname', 128)->default('')
                ->comment('Username cached from the session (empty for guests)');
            $table->integer('time')->unsigned()
                ->comment('Unix timestamp of last activity');
            $table->string('host_addr', 39)->default('')
                ->comment('IPv4 or IPv6 address of the visitor (max 39 chars for IPv6)');
            $table->tinyInteger('guest')->default(0)
                ->comment('1 = unauthenticated guest, 0 = logged-in user');
            $table->string('agent', 255)
                ->comment('HTTP User-Agent header value');
            $table->bigInteger('userid')->nullable()
                ->comment('FK to users.userid; NULL for guests');
            $table->string('url', 255)
                ->comment('Last URL visited by this session');
            $table->text('history')
                ->comment('Navigation history (serialised/JSON array of recent URLs)');
            $table->tinyInteger('logout')->default(0)
                ->comment('1 = session has been explicitly terminated');
            $table->string('sid', 32)
                ->comment('PHP session_id() value at the time the session was created');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('sessions');
    }
}
