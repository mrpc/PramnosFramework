<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;

/**
 * Creates the userlog table — audit trail for user account events.
 *
 * Records administrative and system actions performed on or by a user account
 * (e.g. password changes, activation/deactivation, admin notes). Distinct from
 * the tokenactions hypertable which tracks API call history.
 *
 * @package PramnosFramework
 */
class CreateUserlogTable extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 30;
    public array   $dependencies = ['create_users_table'];
    public $description  = 'Creates the userlog audit table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('userlog')) {
            return;
        }

        $schema->createTable('userlog', function ($table) {
            $table->comment('Audit trail for user account events — password changes, activations, admin actions');

            $table->increments('logid')
                ->comment('Auto-increment log entry identifier');
            $table->bigInteger('userid')
                ->comment('FK to users.userid — the user this log entry refers to');
            $table->integer('date')
                ->comment('Unix timestamp when the event occurred');
            $table->string('log', 255)->nullable()
                ->comment('Short summary of the event (may be NULL for legacy entries)');
            $table->tinyInteger('logtype')
                ->comment('Numeric event category (application-defined; e.g. 1=login, 2=password change)');
            $table->text('details')
                ->comment('Full event details — may contain JSON, HTML, or plain text depending on logtype');

            $table->index(['userid'], 'idx_userlog_userid');
            $table->index(['date'], 'idx_userlog_date');
            $table->index(['userid', 'logtype'], 'idx_userlog_userid_logtype');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('userlog');
    }
}
