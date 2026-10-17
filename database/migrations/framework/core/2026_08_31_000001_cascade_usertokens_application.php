<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Migration;

/**
 * A token outlives the application that issued it, and keeps working.
 *
 * `fk_usertokens_applicationid` was created with `ON DELETE SET NULL`. Delete an OAuth client and
 * its tokens are not removed — their `applicationid` becomes `NULL`, and a token with a null
 * `applicationid` is exactly what a token issued outside OAuth looks like. So every one of them
 * silently changes category from "issued by this client" to "not an OAuth token at all", and
 * carries on authenticating.
 *
 * Found on a working installation: **507 of 522 tokens had a null `applicationid`, and thirteen
 * of those were still active and unexpired.** Thirteen live credentials belonging to clients that
 * had been deleted.
 *
 * ### Why `SET NULL` is the wrong rule here specifically
 *
 * It is a reasonable default for a column that merely *annotates* a row. `applicationid` does not
 * annotate — it is the answer to *who may use this token and on whose behalf*. Removing the
 * answer does not make the question go away; it makes it unanswerable while leaving the token
 * valid. Deleting a client is the one action an operator takes precisely to stop it having access.
 *
 * `CASCADE` is what the neighbouring constraints already do for the same reason:
 * `fk_tokenactions_tokenid` cascades from `usertokens`, and `fk_usertokens_userid` cascades from
 * `users` — delete the account and its tokens go. The application is the same kind of parent.
 *
 * ### What this does to rows that are already detached
 *
 * Nothing, and deliberately. A token whose `applicationid` is already `NULL` cannot be traced
 * back to a client — the reference was destroyed by the old rule, not recorded elsewhere. There
 * is no query that separates "was issued by a client that is gone" from "was never an OAuth
 * token", so a sweep would have to guess, and guessing here revokes working credentials.
 *
 * They are reported rather than removed: {@see \Pramnos\Auth\Controllers\TokensController} and
 * `pramnos token:audit` can list them, and an operator decides. This migration only stops more
 * being made.
 */
class CascadeUsertokensApplication extends Migration
{
    public string $feature = 'core';

    public string $scope = 'framework';

    /**
     * After the migration that created the constraint, and after anything that touches
     * `usertokens` or `applications`.
     */
    public int $priority = 240;

    public array $dependencies = ['add_missing_foreign_keys_to_existing_tables'];

    public $description = 'Deleting an OAuth client now removes its tokens instead of detaching them';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('usertokens') || !$schema->hasTable('applications')) {
            return;
        }

        /*
         * Dropped and recreated, because that is the only way to change a delete rule.
         *
         * Neither PostgreSQL nor MySQL can alter an existing foreign key's `ON DELETE` action —
         * there is no `ALTER CONSTRAINT` for it. The window between the two statements is inside
         * one migration on a table that is not being written to by a deploy, and the constraint
         * only governs deletes of a parent row.
         */
        // Only a key that is there and does not cascade yet. With none, the migration that adds
        // it skipped it and said why (orphans, a type mismatch), and adding it here would fail
        // for the same reason; one that already cascades needs nothing — and re-creating it
        // rebuilt the whole of usertokens on MySQL every time this ran.
        $rule = $this->deleteRule('fk_usertokens_applicationid');
        if ($rule === null || $rule === 'CASCADE') {
            return;
        }

        $this->replaceKey('cascade');
    }

    /**
     * Drop the key and add it again with this delete rule.
     *
     * On MySQL and MariaDB with the checks on, adding a foreign key copies the table; the rows
     * already satisfied the key being replaced, so the add skips the check and is done in place.
     */
    private function replaceKey(string $onDelete): void
    {
        $db     = $this->application->database;
        $schema = $db->schema();
        $mysql  = $db->getDriverName() !== 'pgsql';

        $schema->table('usertokens', function ($table) {
            $table->dropForeign('fk_usertokens_applicationid');
        });
        if ($mysql) {
            $db->query('SET foreign_key_checks = 0');
        }
        try {
            $schema->table('usertokens', function ($table) use ($onDelete) {
                $table->foreign('applicationid')
                    ->references('appid')
                    ->on('applications')
                    ->onDelete($onDelete)
                    ->onUpdate('cascade')
                    ->name('fk_usertokens_applicationid');
            });
        } finally {
            if ($mysql) {
                $db->query('SET foreign_key_checks = 1');
            }
        }
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('usertokens')) {
            return;
        }

        if ($this->deleteRule('fk_usertokens_applicationid') === 'CASCADE') {
            $this->replaceKey('set null');
        }
    }

    /**
     * The named foreign key's delete rule on `usertokens` (`CASCADE`, `SET NULL`…), or null when
     * there is no such key — `add_missing_foreign_keys_to_existing_tables` skips it where the
     * columns' types differ or rows are orphaned.
     */
    private function deleteRule(string $name): ?string
    {
        $db       = $this->application->database;
        $resolved = $db->schema()->resolveTableName('usertokens');

        // Introspection: the constraint catalogue is not something the query builder reaches.
        $row = $db->getDriverName() === 'pgsql'
            ? $db->selectOne(
                'SELECT r.delete_rule AS rule FROM information_schema.referential_constraints r'
                . ' JOIN information_schema.table_constraints t ON t.constraint_name = r.constraint_name AND t.constraint_schema = r.constraint_schema'
                . ' WHERE t.table_name = ? AND r.constraint_name = ?',
                [$resolved, $name]
            )
            : $db->selectOne(
                'SELECT DELETE_RULE AS rule FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS'
                . ' WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
                [$resolved, $name]
            );

        return $row === null ? null : strtoupper((string) (is_array($row) ? ($row['rule'] ?? '') : ($row->rule ?? '')));
    }
}
