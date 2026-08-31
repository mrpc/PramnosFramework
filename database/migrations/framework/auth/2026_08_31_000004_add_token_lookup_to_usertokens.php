<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;
use Pramnos\Security\Encrypter;

/**
 * Splits `usertokens.token` into something to match on and something to show.
 *
 * The column held the token itself and every lookup was `WHERE token = <presented>`.
 * Anyone who could read that table held live bearer credentials — usable until they
 * expired, without needing the client secret or anything else.
 *
 * Two columns solve it, because the two jobs pull in opposite directions:
 *
 *   - `token_lookup` — `sha256(token)`, 64 hex characters, unique and indexed. What
 *     the 15 authentication lookups match on.
 *   - `token` — the value, encrypted. What
 *     {@see \Pramnos\User\Token::reveal()} decrypts for the screens that offer a
 *     copy button.
 *
 * ## Why the lookup is an unkeyed digest
 *
 * A keyed HMAC is the reflex, and it would be the right one for a secret somebody
 * could guess. Every value in this column is 256 bits from `random_bytes()` or a
 * signed JWT, so there is no dictionary to attack and the key would buy nothing
 * measurable.
 *
 * It would cost something, though: keying the lookup makes `APP_KEY` load-bearing for
 * authentication, so rotating it would sign every session out at once. Unkeyed, a
 * rotation costs the ability to *reveal* a token and leaves authentication working.
 * That is the better failure to have.
 *
 * ## Backfill
 *
 * Both columns are filled from the plaintext already in the table, so no token is
 * invalidated and nobody is signed out. This is easier than the client-secret
 * conversion was, where the plaintext was gone by the time it mattered.
 *
 * Without an `APP_KEY` the value is left unencrypted and only the lookup is written —
 * authentication works either way, and the column converts itself when a key exists
 * and the row is next written.
 *
 * ## The index that was never there
 *
 * `token` is `TEXT`, which MySQL cannot index without a prefix length, so it never
 * was: every authentication lookup was a full table scan. `token_lookup` is
 * fixed-length hex, so the unique index this adds makes the auth path faster than it
 * was before any of this.
 */
class AddTokenLookupToUsertokens extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';

    // After create_usertokens_table (which has no explicit priority, so it takes the
    // default); high enough to run once the table and its indexes exist.
    public int     $priority     = 95;
    public array   $dependencies = ['create_usertokens_table'];
    public $description = 'Adds usertokens.token_lookup and encrypts the token value';

    public function up(): void
    {
        $db     = $this->application->database;
        $schema = $db->schema();

        if (!$schema->hasTable('usertokens')) {
            return;
        }

        if (!$schema->hasColumn('usertokens', 'token_lookup')) {
            $schema->alterTable('usertokens', function ($table) {
                $table->string('token_lookup', 64)->nullable()
                    ->comment('sha256 of the token — what authentication matches on');
            });
        }

        $this->backfill($db);

        // The index goes on after the backfill: a unique index on a column that is
        // NULL for every existing row would be satisfied by NULLs and then violated
        // the moment two of them were filled in within the same statement.
        if (!$schema->hasIndex('usertokens', 'idx_usertokens_token_lookup')) {
            $schema->alterTable('usertokens', function ($table) {
                $table->unique(['token_lookup'], 'idx_usertokens_token_lookup');
            });
        }
    }

    /**
     * Fill both columns from the plaintext currently in the table.
     *
     * Row by row rather than one UPDATE, because the encryption happens in PHP: there
     * is no SQL expression for it, and a set-based statement could only write the
     * lookup.
     *
     * A row whose `token` is already encrypted is skipped — the migration is
     * idempotent, and re-encrypting would change nothing but would cost a write per
     * row on every run.
     */
    private function backfill($db): void
    {
        $rows = $db->queryBuilder()
            ->table('usertokens')
            ->select(['tokenid', 'token', 'token_lookup'])
            ->get();

        while ($rows && $rows->fetch()) {
            $token = (string) ($rows->fields['token'] ?? '');

            if ($token === '' || Encrypter::isEncrypted($token)) {
                continue;
            }

            $update = ['token_lookup' => hash('sha256', $token)];

            if (Encrypter::isAvailable()) {
                $update['token'] = Encrypter::encrypt($token);
            }

            $db->queryBuilder()
                ->table('usertokens')
                ->where('tokenid', (int) $rows->fields['tokenid'])
                ->update($update);
        }
    }

    /**
     * Drops the lookup column, and leaves the token values encrypted.
     *
     * Decrypting them back would need `APP_KEY` to still be the one they were written
     * with, and a `down()` that silently leaves unreadable values behind is worse than
     * one that says it does not restore them. Rolling this back means restoring from a
     * backup, not running this.
     */
    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasColumn('usertokens', 'token_lookup')) {
            return;
        }

        $schema->alterTable('usertokens', function ($table) {
            $table->dropIndex('idx_usertokens_token_lookup');
            $table->dropColumn('token_lookup');
        });
    }
}
