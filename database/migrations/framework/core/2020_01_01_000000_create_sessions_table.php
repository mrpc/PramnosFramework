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
            // `text`, not `varchar(255)`: a User-Agent has no documented ceiling, and the
            // row this is in is a tracking row — refusing it is a worse outcome than
            // storing a long string. See the widening migration for what the ceiling cost.
            $table->text('agent')
                ->comment('HTTP User-Agent header value');
            $table->bigInteger('userid')->nullable()
                ->comment('FK to users.userid; NULL for guests');
            // `text` for the same reason, and this is the column that actually overflowed:
            // an OAuth callback carrying three scopes is over 400 characters, and so is a
            // long search query or a UTM-laden campaign link.
            $table->text('url')
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
