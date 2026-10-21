<?php

namespace Pramnos\Framework\Migrations\Messaging;

use Pramnos\Database\Migration;

/**
 * Adds an index on `mails(status, date)` where no index leads with `status`.
 *
 * Every minute `mail:flush` fails the queued messages that are past the outbox deadline:
 * `UPDATE mails … WHERE status = 2 AND date < ?`. The queued rows are a handful; the table is the
 * whole history. Without an index on `status` that statement reads every row, and on a log of 35
 * million rows it took nine seconds a minute with nothing queued at all.
 *
 * The framework's own `create_mails_table` has `idx_mails_status`, which is enough, and then this
 * does nothing. An installation whose `mails` predates the framework's migrations may have no such
 * index. The index is built over the whole table, which on a log of tens of millions of rows is a
 * maintenance window: such an installation can build it itself, under any name, and this sees it —
 * or leave this out with `'migrations' => ['exclude' => ['add_status_date_index_to_mails']]`.
 */
class AddStatusDateIndexToMails extends Migration
{
    public string  $feature      = 'messaging';
    public string  $scope        = 'framework';
    public int     $priority     = 51;
    public array   $dependencies = ['create_mails_table'];
    public $description  = 'Indexes mails by status and date, for the outbox deadline sweep';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('mails')
            || $schema->hasIndexOn('mails', ['status'])
            || $schema->hasIndexOn('mails', ['status', 'date'])) {
            return;
        }

        $schema->alterTable('mails', function ($table) {
            $table->index(['status', 'date'], 'idx_mails_status_date');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('mails') && $schema->hasIndex('mails', 'idx_mails_status_date')) {
            $schema->alterTable('mails', function ($table) {
                $table->dropIndex('idx_mails_status_date');
            });
        }
    }
}
