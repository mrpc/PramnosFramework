<?php

namespace Pramnos\Framework\Migrations\Queue;

use Pramnos\Database\Migration;

/**
 * `stopped` as a status of a queue task: an administrator's "do not run this", from the queue
 * screen.
 *
 * `status` is a `varchar` on every database, so MySQL takes the new value as it is. PostgreSQL
 * holds it to `chk_queueitems_status`, which the create migration added with the six statuses
 * it knew, and the unused `queue_status` type lists them too: both learn `stopped`.
 */
class AddStoppedToQueueitemsStatus extends Migration
{
    public string  $feature     = 'queue';
    public string  $scope       = 'framework';
    public int     $priority    = 10;
    public $description = "Lets a queue task be 'stopped' on PostgreSQL";

    /** The statuses the constraint allows, `stopped` among them. */
    private const STATUSES = "'pending','processing','completed','failed','warning','deleted','stopped'";

    public function up(): void
    {
        $this->constrain(self::STATUSES, true);
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->getCapabilities()->isPostgreSQL() || !$schema->hasTable('queueitems')) {
            return;
        }
        // A stopped task is one nobody is to run: deleted is the nearest the old statuses have.
        $this->application->database->queryBuilder()->table('#PREFIX#queueitems')
            ->where('status', 'stopped')->update(['status' => 'deleted']);
        $this->constrain("'pending','processing','completed','failed','warning','deleted'", false);
    }

    /** The CHECK constraint over these statuses, replacing the one there; and the type, going up. */
    private function constrain(string $statuses, bool $up): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->getCapabilities()->isPostgreSQL() || !$schema->hasTable('queueitems')) {
            return;
        }
        $db    = $this->application->database;
        $table = $schema->quoteTable('queueitems');
        // Raw: a named CHECK and an enum value, which the schema builder does not express.
        $db->query("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS chk_queueitems_status");
        $db->query("ALTER TABLE {$table} ADD CONSTRAINT chk_queueitems_status CHECK (\"status\" IN ({$statuses}))");
        if ($up) {
            $db->query("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_type WHERE typname = 'queue_status') THEN ALTER TYPE queue_status ADD VALUE IF NOT EXISTS 'stopped'; END IF; END \$\$");
        }
    }
}
