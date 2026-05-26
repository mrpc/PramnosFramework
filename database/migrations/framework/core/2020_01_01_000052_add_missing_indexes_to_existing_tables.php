<?php

namespace Pramnos\Database\Migrations;

use Pramnos\Database\Migration;

/**
 * AddMissingIndexesToExistingTables migration.
 *
 * Adds indexes present in the UrbanWater production schema that were not
 * included in the original framework CREATE TABLE migrations.
 *
 * Tables covered:
 *  - sessions: userid + time lookup indexes
 *  - users: photo index
 *  - usertokens: token lookup and parentToken indexes
 *  - tokenactions: simple single-column and composite method indexes
 *
 * All operations use existence guards (IF NOT EXISTS on PostgreSQL,
 * INFORMATION_SCHEMA check on MySQL) so the migration is idempotent.
 */
class AddMissingIndexesToExistingTables extends Migration
{
    /**
     * Run the migration.
     *
     * @return void
     */
    public function up(): void
    {
        $db = $this->DB();
        $isPgsql = ($db->getDriverName() === 'pgsql');

        $schema = $this->schema('public');

        // ===== sessions =====
        // Basic lookup indexes absent from the original sessions migration.
        if ($schema->hasTable('sessions')) {
            $this->addIndex('sessions', 'idx_sessions_userid', ['userid']);
            $this->addIndex('sessions', 'idx_sessions_time', ['time']);
        }

        // ===== users =====
        // idx_users_photo — present in UrbanWater, missing from framework migration.
        // Only add when the photo column actually exists (some installations omit it).
        if ($schema->hasTable('users') && $schema->hasColumn('users', 'photo')) {
            $this->addIndex('users', 'idx_users_photo', ['photo']);
        }

        // ===== usertokens =====
        if ($schema->hasTable('usertokens')) {
            // parentToken index — simple integer, safe on all backends.
            if ($schema->hasColumn('usertokens', 'parentToken')) {
                $this->addIndex('usertokens', 'idx_usertokens_parentToken', ['"parentToken"']);
            }

            // token lookup index — TEXT column.
            //  PostgreSQL: TEXT is indexable directly.
            //  MySQL: use prefix length 255 so VARCHAR/TEXT truncation does not prevent indexing.
            if ($schema->hasColumn('usertokens', 'token')
                && !$this->indexExists('usertokens', 'idx_usertokens_token')
            ) {
                if ($isPgsql) {
                    $db->query(
                        'CREATE INDEX IF NOT EXISTS idx_usertokens_token'
                        . ' ON public.usertokens (token)'
                    );
                } else {
                    $db->query(
                        'CREATE INDEX idx_usertokens_token ON usertokens (token(255))'
                    );
                }
            }
        }

        // ===== tokenactions =====
        if ($schema->hasTable('tokenactions')) {
            // Simple single-column indexes from original UrbanWater schema.
            $this->addIndex('tokenactions', 'idx_tokenactions_tokenid', ['tokenid']);
            $this->addIndex('tokenactions', 'idx_tokenactions_urlid', ['urlid']);

            // return_status and execution_time_ms added in a later UrbanWater migration.
            if ($schema->hasColumn('tokenactions', 'return_status')) {
                $this->addIndex('tokenactions', 'idx_tokenactions_return_status', ['return_status']);
            }
            if ($schema->hasColumn('tokenactions', 'execution_time_ms')) {
                $this->addIndex('tokenactions', 'idx_tokenactions_execution_time', ['execution_time_ms']);
            }

            // Composite (action_time, method) — mirrors UrbanWater idx_tokenactions_time_method.
            // Uses raw SQL because action_time may be the TimescaleDB partition key and DESC
            // ordering is not expressible via SchemaBuilder $table->index().
            if ($schema->hasColumn('tokenactions', 'action_time')
                && !$this->indexExists('tokenactions', 'idx_tokenactions_time_method')
            ) {
                if ($isPgsql) {
                    $db->query(
                        'CREATE INDEX IF NOT EXISTS idx_tokenactions_time_method'
                        . ' ON public.tokenactions (action_time DESC, method)'
                    );
                } else {
                    $db->query(
                        'CREATE INDEX idx_tokenactions_time_method'
                        . ' ON tokenactions (action_time DESC, method)'
                    );
                }
            }
        }
    }

    /**
     * Rollback the migration.
     *
     * @return void
     */
    public function down(): void
    {
        $db = $this->DB();
        $isPgsql = ($db->getDriverName() === 'pgsql');

        $indexMap = [
            'sessions'     => ['idx_sessions_userid', 'idx_sessions_time'],
            'users'        => ['idx_users_photo'],
            'usertokens'   => ['idx_usertokens_parentToken', 'idx_usertokens_token'],
            'tokenactions' => [
                'idx_tokenactions_tokenid',
                'idx_tokenactions_urlid',
                'idx_tokenactions_return_status',
                'idx_tokenactions_execution_time',
                'idx_tokenactions_time_method',
            ],
        ];

        foreach ($indexMap as $table => $indexes) {
            foreach ($indexes as $index) {
                if (!$this->indexExists($table, $index)) {
                    continue;
                }
                if ($isPgsql) {
                    $db->query("DROP INDEX IF EXISTS \"{$index}\"");
                } else {
                    $db->query("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
                }
            }
        }
    }

    /**
     * Add an index to a table if it does not already exist.
     *
     * Uses CREATE INDEX IF NOT EXISTS on PostgreSQL.
     * Uses INFORMATION_SCHEMA pre-check on MySQL (no IF NOT EXISTS for CREATE INDEX).
     *
     * @param string   $table   Table name
     * @param string   $index   Index name
     * @param string[] $columns Column list (already-quoted where needed)
     * @return void
     */
    protected function addIndex(string $table, string $index, array $columns): void
    {
        if ($this->indexExists($table, $index)) {
            return;
        }

        $db = $this->DB();
        $cols = implode(', ', $columns);

        if ($db->getDriverName() === 'pgsql') {
            $db->query(
                "CREATE INDEX IF NOT EXISTS \"{$index}\" ON public.\"{$table}\" ({$cols})"
            );
        } else {
            $db->query(
                "CREATE INDEX `{$index}` ON `{$table}` ({$cols})"
            );
        }
    }

    /**
     * Check whether an index exists on the given table.
     *
     * @param string $table     Table name (without schema prefix)
     * @param string $indexName Index name
     * @return bool
     */
    protected function indexExists(string $table, string $indexName): bool
    {
        $db = $this->DB();

        if ($db->getDriverName() === 'pgsql') {
            $row = $db->selectOne(
                'SELECT 1 FROM pg_indexes'
                . ' WHERE tablename = ? AND indexname = ?',
                [$table, $indexName]
            );
        } else {
            $row = $db->selectOne(
                'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS'
                . ' WHERE TABLE_SCHEMA = DATABASE()'
                . '   AND TABLE_NAME = ? AND INDEX_NAME = ?',
                [$table, $indexName]
            );
        }

        return !is_null($row);
    }
}
