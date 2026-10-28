<?php

namespace Pramnos\Framework\Migrations\Queue;

use Pramnos\Database\Migration;

/**
 * `stopped` as a status of a queue task: an administrator's "do not run this", from the queue
 * screen.
 *
 * On MySQL `status` is a `varchar` and takes the new value as it is. On PostgreSQL the create
 * migration made it a `varchar` held to `chk_queueitems_status`, with the six statuses it knew,
 * and an unused `queue_status` type listing them too: both learn `stopped`.
 *
 * A table moved over from MySQL can instead have `status` *of* an enum type — `queue_status`
 * with only the labels its own application used, often without `deleted`. A CHECK naming a
 * label the enum lacks is a cast error, so there the enum learns every missing status and no
 * constraint is added: the type already refuses anything else. Labels cannot be taken out of a
 * PostgreSQL enum, so down() leaves such a column as it is.
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
        if (!$schema->getCapabilities()->isPostgreSQL() || !$schema->hasTable('queueitems')
            || $this->statusEnum() !== null) {
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
        $enum  = $this->statusEnum();
        if ($enum !== null) {
            if ($up) {
                // Raw: an enum label, which the schema builder does not express. IF NOT EXISTS
                // makes a second run harmless; the labels are used only by later transactions.
                foreach (explode(',', $statuses) as $status) {
                    $db->query('ALTER TYPE "' . str_replace('"', '""', $enum) . "\" ADD VALUE IF NOT EXISTS {$status}");
                }
            }
            return;
        }
        // Raw: a named CHECK and an enum value, which the schema builder does not express.
        $db->query("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS chk_queueitems_status");
        $db->query("ALTER TABLE {$table} ADD CONSTRAINT chk_queueitems_status CHECK (\"status\" IN ({$statuses}))");
        if ($up) {
            $db->query("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_type WHERE typname = 'queue_status') THEN ALTER TYPE queue_status ADD VALUE IF NOT EXISTS 'stopped'; END IF; END \$\$");
        }
    }

    /**
     * The enum type `queueitems.status` is declared as, or null for a plain `varchar`.
     *
     * Catalogue introspection, so raw SQL: the query builder does not reach `pg_attribute`.
     */
    private function statusEnum(): ?string
    {
        $db     = $this->application->database;
        $result = $db->query($db->prepareQuery(
            "SELECT t.typname FROM pg_attribute a JOIN pg_type t ON t.oid = a.atttypid"
            . " WHERE a.attrelid = %s::regclass AND a.attname = 'status' AND t.typtype = 'e'",
            $db->schema()->quoteTable('queueitems')
        ));

        return $result && $result->numRows ? (string) $result->fields['typname'] : null;
    }
}
