<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;
use Pramnos\Security\Encrypter;

/**
 * Gives every application its own `broadcast_secret`, so the OAuth2 client secret
 * can stop doubling as one.
 *
 * `broadcast_secret` was added on 2026-08-20 precisely so the realtime HMAC key and
 * the OAuth2 client secret would stop sharing a value — that migration's own words:
 * *"the exposure profiles differ"*. It is nullable, with `apisecret` as the
 * documented fallback, and **nothing ever wrote it**: no controller, no backfill. So
 * in practice every application's realtime key still *is* its `apisecret`, and the
 * separation existed only as a column.
 *
 * That matters now because `apisecret` is being hashed. An HMAC key cannot be
 * hashed — the sender needs the actual bytes to sign with — so hashing without
 * splitting first would break channel authorization for every application at once.
 *
 * ## What this does
 *
 * Copies `apisecret` into `broadcast_secret` wherever the latter is empty, so the
 * realtime key keeps its current value and no channel authorization changes. Nothing
 * has to be re-issued and no subscriber notices.
 *
 * The copy is encrypted with {@see Encrypter} when an APP_KEY is configured, matching
 * how the column is read. Without a key it is stored as-is and converts itself on the
 * next write, the same degradation the rest of the encrypted columns use — an
 * installation that never ran `key:generate` must still have working realtime.
 *
 * Rows whose `apisecret` is already hashed (an installation running this after the
 * hashing change, somehow) are skipped: a bcrypt digest is not a usable HMAC key, and
 * copying one in would be worse than leaving the column empty, which at least fails
 * loudly.
 *
 * ## What it deliberately does not do
 *
 * It does not generate a *fresh* key. A new value would invalidate every subscriber's
 * current authorization the moment it ran — a migration is the wrong place to rotate
 * a credential. Operators who want distinct values can rotate from the applications
 * screen afterwards, which is a decision with a person behind it.
 */
class SplitBroadcastSecretFromApisecret extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';

    // After add_broadcast_secret_to_applications, which creates the column.
    public int    $priority     = 70;
    public array  $dependencies = ['add_broadcast_secret_to_applications'];
    public $description = 'Copies apisecret into broadcast_secret so the client secret can be hashed';

    public function up(): void
    {
        $db     = $this->application->database;
        $schema = $db->schema();

        if (!$schema->hasTable('applications')
            || !$schema->hasColumn('applications', 'broadcast_secret')
        ) {
            return;
        }

        $rows = $db->queryBuilder()
            ->table('applications')
            ->select(['appid', 'apisecret', 'broadcast_secret'])
            ->get();

        while ($rows && $rows->fetch()) {
            $existing = (string) ($rows->fields['broadcast_secret'] ?? '');
            if ($existing !== '') {
                continue;
            }

            $apisecret = (string) ($rows->fields['apisecret'] ?? '');
            if ($apisecret === '' || $this->looksHashed($apisecret)) {
                continue;
            }

            $db->queryBuilder()
                ->table('applications')
                ->where('appid', (int) $rows->fields['appid'])
                ->update([
                    'broadcast_secret' => Encrypter::isAvailable()
                        ? Encrypter::encrypt($apisecret)
                        : $apisecret,
                ]);
        }
    }

    /**
     * Is this value a password hash rather than a secret?
     *
     * `password_get_info()` rather than a prefix check, so it recognises whatever
     * algorithm the installation's PasswordHash is configured for rather than only
     * the bcrypt shape.
     */
    private function looksHashed(string $value): bool
    {
        return (password_get_info($value)['algo'] ?? null) !== null;
    }

    /**
     * Nothing to undo.
     *
     * Emptying `broadcast_secret` would take the realtime key away from every
     * application that has been using it since this ran, and the value it held is not
     * recoverable from `apisecret` once that is hashed. A column carrying a working
     * key costs nothing.
     */
    public function down(): void
    {
    }
}
