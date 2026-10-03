<?php

namespace Pramnos\Framework\Migrations\Changelog;

use Pramnos\Database\Migration;

/**
 * Adds changelog_events.scope — which tenant, organisation or account an event belongs to.
 *
 * A multi-tenant application asks "what happened in this organisation", and the rows had
 * nothing to answer it with but a key inside `details`, which on a compressed hypertable
 * decompresses every chunk in the window. A model fills the column through
 * {@see \Pramnos\Application\Model::changeScope()}; null for an application with no tenants.
 *
 * A column rather than a fourth table or the tenant as the row's entity: subject (`entity`),
 * actor (`userid`) and tenant are three different things, and only the tenant was missing.
 *
 * The index is partial — `WHERE scope IS NOT NULL` — so an application with no tenants has an
 * empty index and an always-null column, which costs it nothing. PostgreSQL only: MySQL has no
 * partial indexes, and a full one would be paid on every insert by applications that never
 * scope anything. Not added to the compression `segmentby` either: changing segmentby on a
 * live hypertable means decompressing every chunk first, a maintenance window, not a migration.
 */
class AddScopeToChangelogEvents extends Migration
{
    /** @var string The feature that owns the table */
    public string $feature = 'changelog';

    /** @var string Framework-level migration */
    public string $scope = 'framework';

    /** @var int After the tables it alters */
    public int $priority = 231;

    /** @var list<string> */
    public array $dependencies = ['create_changelog_tables'];

    /** @var string What this migration does */
    public $description = 'Adds pramnos.changelog_events.scope';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('pramnos.changelog_events')
            || $schema->hasColumn('pramnos.changelog_events', 'scope')
        ) {
            return;
        }

        $partialIndex = $this->application->database->getDriverName() === 'pgsql';

        $schema->table('pramnos.changelog_events', function ($table) use ($partialIndex) {
            $table->string('scope', 64)->nullable()
                ->comment('Tenant / organisation the event belongs to, from Model::changeScope(); NULL = unscoped');

            if ($partialIndex) {
                $table->index(['scope', 'created_at'], 'idx_changelog_events_scope')
                    ->where('scope IS NOT NULL');
            }
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasColumn('pramnos.changelog_events', 'scope')) {
            return;
        }

        // Explicitly: MySQL trims a composite index to its remaining columns instead of
        // dropping it with the column.
        $hasIndex = $schema->hasIndex('pramnos.changelog_events', 'idx_changelog_events_scope');
        $schema->table('pramnos.changelog_events', function ($table) use ($hasIndex) {
            if ($hasIndex) {
                $table->dropIndex('idx_changelog_events_scope');
            }
            $table->dropColumn('scope');
        });
    }
}
