<?php

namespace Pramnos\Framework\Migrations\Messaging;

use Pramnos\Database\Migration;

/**
 * Adds `userid` to `mails`: the account a message was for, so an account's mail is its own and
 * not only what went to its current address — an address changed, and everything sent before it
 * vanished from the account's page.
 *
 * Nullable: a message to an address no account has, or logged before this column, has none. The
 * column is added instantly on MySQL 8 and MariaDB; the index `(userid, date)` is built over the
 * whole table, which on a log of tens of millions of rows is a maintenance window. Such an
 * installation can leave this out — `'migrations' => ['exclude' => ['add_userid_to_mails']]` —
 * and the framework keeps matching by address until it runs.
 */
class AddUseridToMails extends Migration
{
    public string  $feature      = 'messaging';
    public string  $scope        = 'framework';
    public int     $priority     = 51;
    public array   $dependencies = ['create_mails_table'];
    public $description  = 'Adds userid to mails, the account each message was for';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('mails') || $schema->hasColumn('mails', 'userid')) {
            return;
        }

        $schema->alterTable('mails', function ($table) {
            $table->unsignedInteger('userid')->nullable()
                ->comment('The account the message was for, where it was sent for one; null otherwise');
            $table->index(['userid', 'date'], 'idx_mails_userid_date');
        });
        \Pramnos\Email\MailLog::reset();
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasColumn('mails', 'userid')) {
            $schema->alterTable('mails', function ($table) {
                $table->dropIndex('idx_mails_userid_date');
                $table->dropColumn('userid');
            });
        }
        \Pramnos\Email\MailLog::reset();
    }
}
