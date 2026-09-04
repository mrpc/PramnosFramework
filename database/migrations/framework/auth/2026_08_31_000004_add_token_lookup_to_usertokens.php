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
     * How many rows to encrypt per round trip when there is a key.
     *
     * Small enough that the buffer stays flat regardless of table size, large
     * enough that the round trips do not dominate.
     */
    private const ENCRYPT_BATCH = 2000;

    /**
     * Fill the lookup, and encrypt the value when there is a key to do it with.
     *
     * ## Scope: only rows that can still authenticate
     *
     * The column exists so authentication can find a token by value. A token that
     * is revoked or expired is never found that way again, so computing its
     * digest is work for an answer nobody asks for — and on a long-lived
     * installation those rows are most of the table, because `usertokens` is an
     * audit trail as much as a credential store: `cleanupAllAuthTokens()` marks
     * old tokens and keeps them, and there is a screen for listing the dead ones.
     *
     * Safe because nothing brings a token back: no path in the framework sets a
     * row's `status` to active again, and every path that matches on
     * `token_lookup` and then acts on the row checks `status` and `expires` too.
     * The paths that do not filter treat a missing row as invalid, which is the
     * same answer a dead row gives — `isAuthCodeRevoked()` returns true when
     * nothing is found, and `introspect()` answers `{"active": false}` for a
     * missing row and an inactive one alike, which is what RFC 7662 requires.
     *
     * **What this means, stated rather than left to be discovered:** rows outside
     * the scope keep their token value as it is — plaintext, on an installation
     * where the rest of the column is encrypted. They cannot authenticate and
     * every lookup filters in SQL, so the exposure is smaller than it sounds, but
     * it is a real difference from «the column is encrypted». An installation
     * that wants the stronger claim wants a sweep it can run when it chooses, not
     * a migration holding a deploy open to encrypt credentials that stopped
     * working years ago.
     *
     * ## Two paths, because only one of them needs PHP
     *
     * The digest is `sha256` of the value, which both backends compute
     * themselves. Measured by the reporter on 50 000 rows: 5 617 ms row by row in
     * PHP, 130 ms as one statement — and the shape matters more than the ratio,
     * because the row-by-row version also read the whole table into memory first
     * (48 MB at 50 000 rows, so around a gigabyte at a million). That is what
     * turns «slow» into «the deploy died».
     *
     * Encryption genuinely needs PHP — `Encrypter::encrypt()` uses a fresh nonce
     * per value, so there is no SQL expression for it. But it only applies when
     * `APP_KEY` is set, so **an installation without a key does the whole backfill
     * in one statement**, and that includes every installation that has not run
     * `key:generate` yet.
     *
     * Both paths take only rows whose `token_lookup` is still empty, so a second
     * deploy costs one statement that matches nothing rather than the whole table
     * again.
     */
    private function backfill($db): void
    {
        $caps = $db->schema()->getCapabilities();

        if (!\Pramnos\Security\Encrypter::isAvailable()) {
            $this->backfillLookupsInSql($db, $caps);

            return;
        }

        $this->backfillInBatches($db);
    }

    /**
     * The whole backfill as one statement, for an installation with no `APP_KEY`.
     *
     * @param object $db
     * @param object $caps
     */
    private function backfillLookupsInSql($db, $caps): void
    {
        $digest = $caps->isPostgreSQL()
            ? "encode(sha256(token::bytea), 'hex')"
            : 'LOWER(SHA2(token, 256))';

        $table = $db->prefix . 'usertokens';
        $quote = $caps->isPostgreSQL() ? '"' : '`';

        $db->query(
            'UPDATE ' . $quote . $table . $quote
            . ' SET token_lookup = ' . $digest
            . ' WHERE token_lookup IS NULL'
            . "   AND token IS NOT NULL AND token <> ''"
            // A value already encrypted has no plaintext to digest. It cannot
            // happen without a key, but a key that was removed leaves rows behind.
            . "   AND token NOT LIKE '" . \Pramnos\Security\Encrypter::PREFIX . "%'"
            . '   AND ' . $this->liveTokenPredicate()
        );
    }

    /**
     * Encrypt and digest the live rows, a bounded number at a time.
     *
     * A keyset cursor on `tokenid` rather than `LIMIT`/`OFFSET`: the rows being
     * read are the rows being written, and an offset walk over a table that is
     * changing underneath skips rows. It also stays flat — the buffer holds one
     * batch, not the table.
     *
     * @param object $db
     */
    private function backfillInBatches($db): void
    {
        $after = 0;

        while (true) {
            $rows = $db->queryBuilder()
                ->table('usertokens')
                ->select(['tokenid', 'token'])
                ->whereNull('token_lookup')
                ->where('tokenid', '>', $after)
                ->whereRaw($this->liveTokenPredicate())
                ->orderBy('tokenid')
                ->limit(self::ENCRYPT_BATCH)
                ->get();

            $seen = 0;

            while ($rows && $rows->fetch()) {
                $seen++;
                $after = (int) $rows->fields['tokenid'];
                $token = (string) ($rows->fields['token'] ?? '');

                if ($token === '' || \Pramnos\Security\Encrypter::isEncrypted($token)) {
                    continue;
                }

                $db->queryBuilder()
                    ->table('usertokens')
                    ->where('tokenid', $after)
                    ->update(array(
                        'token_lookup' => \Pramnos\User\Token::lookup($token),
                        'token'        => \Pramnos\Security\Encrypter::encrypt($token),
                    ));
            }

            if ($seen < self::ENCRYPT_BATCH) {
                return;
            }
        }
    }

    /**
     * The rows a lookup could still have to find.
     *
     * `expires` is a unix timestamp, `0` means «never», and `Token::save()` writes
     * `NULL` for that case — so all three shapes are the same thing and all three
     * have to be matched.
     *
     * @return string
     */
    private function liveTokenPredicate(): string
    {
        return '(status = 1 AND (expires IS NULL OR expires = 0 OR expires > '
            . time() . '))';
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
