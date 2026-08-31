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
        $schema->table('usertokens', function ($table) {
            $table->dropForeign('fk_usertokens_applicationid');
        });

        $schema->table('usertokens', function ($table) {
            $table->foreign('applicationid')
                ->references('appid')
                ->on('public.applications')
                ->onDelete('cascade')
                ->onUpdate('cascade')
                ->name('fk_usertokens_applicationid');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('usertokens')) {
            return;
        }

        $schema->table('usertokens', function ($table) {
            $table->dropForeign('fk_usertokens_applicationid');
        });

        $schema->table('usertokens', function ($table) {
            $table->foreign('applicationid')
                ->references('appid')
                ->on('public.applications')
                ->onDelete('set null')
                ->onUpdate('cascade')
                ->name('fk_usertokens_applicationid');
        });
    }
}
