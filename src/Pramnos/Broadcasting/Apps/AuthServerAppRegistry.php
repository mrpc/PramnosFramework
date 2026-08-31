<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Apps;

/**
 * Apps read from the `applications` table the AuthServer already maintains.
 *
 * The realtime edge needs exactly what an OAuth2 client registry already stores —
 * a unique public key, a rotatable secret, an active flag — so there is no second
 * table and no second admin screen. `apikey` is already UNIQUE, `apisecret` is
 * already 32 random bytes written in the clear (so it can serve as an HMAC key),
 * and `ApplicationsController::rotate()` already rotates it.
 *
 * What that buys over a config file is everything else on the row: an `owner`, a
 * `scope`, a `trusted` flag, an audit log, and `user_app_authorizations` — so a
 * user can revoke one application's realtime access without touching the others.
 *
 * ## Two deliberate decisions
 *
 * **`broadcast_secret` is the only source.** A long-running WebSocket
 * daemon holds every app's secret in memory for the life of the process, which is
 * a different exposure profile from an OAuth2 token exchange that reads one and
 * returns. Sharing one secret between them means a core dump or a crash log from
 * the daemon leaks OAuth2 client credentials too. The dedicated column keeps the
 * blast radii separate — and it is now the only source, because `apisecret` is
 * hashed and a password digest cannot serve as an HMAC key. The
 * split_broadcast_secret_from_apisecret migration fills the column for
 * installations that were relying on the old fallback, keeping the value they had.
 *
 * The column is encrypted at rest ({@see \Pramnos\Security\Encrypter}), because
 * unlike the client secret it has to be read back.
 *
 * **Lookups are cached with a TTL.** The daemon is a single-threaded
 * `stream_select()` loop: a query per handshake blocks every other connection for
 * its duration, and after a deploy every client reconnects at once. The cache is
 * read-through and small — one entry per app that actually connects.
 *
 * It does **not** cache negatives beyond the same TTL, so revoking an app takes
 * effect within one TTL rather than at the next restart.
 */
class AuthServerAppRegistry implements AppRegistryInterface
{
    /** @var array<string, array{app:?BroadcastApp, at:int}> */
    private array $cache = [];

    /** @var callable():int */
    private $clock;

    /**
     * @param int $ttl Seconds to trust a cached lookup. Zero disables caching,
     *                 which is right for a web request (one lookup, then exit)
     *                 and wrong for a daemon.
     * @param callable():int|null $clock Test seam for the TTL.
     */
    public function __construct(
        private readonly int $ttl = 60,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function findByKey(string $key): ?BroadcastApp
    {
        if ($key === '') {
            return null;
        }

        $now = ($this->clock)();

        if ($this->ttl > 0 && isset($this->cache[$key])) {
            if ($now - $this->cache[$key]['at'] < $this->ttl) {
                return $this->cache[$key]['app'];
            }
            unset($this->cache[$key]);
        }

        $app = $this->buildApp($key);

        if ($this->ttl > 0) {
            // Negative results are cached too, for the same TTL: without that, an
            // unknown key hammers the database once per connection attempt, which
            // is exactly what an attacker probing keys would produce.
            $this->cache[$key] = ['app' => $app, 'at' => $now];
        }

        return $app;
    }

    /**
     * There is no default app in a multi-tenant registry: every connection must
     * name the app it means.
     */
    public function defaultApp(): ?BroadcastApp
    {
        return null;
    }

    /**
     * Drop cached lookups — for a daemon told to reload, or a test.
     */
    public function flush(): void
    {
        $this->cache = [];
    }

    private function buildApp(string $key): ?BroadcastApp
    {
        $row = $this->loadRow($key);

        if ($row === null) {
            return null;
        }

        // `broadcast_secret` only. There used to be a fallback to `apisecret` for
        // installations predating the column, and it had to go when the OAuth2
        // client secret started being hashed: a bcrypt digest is not an HMAC key,
        // so the fallback would have signed every channel with a value the
        // subscriber cannot derive — authorization failing everywhere, for a reason
        // visible nowhere. The split_broadcast_secret_from_apisecret migration
        // fills the column in for those installations.
        //
        // array_key_exists rather than ?? because a NULL column and an absent one
        // mean the same thing here but read differently from a driver returning
        // either.
        $secret = '';
        if (array_key_exists('broadcast_secret', $row)
            && (string) $row['broadcast_secret'] !== ''
        ) {
            try {
                $secret = \Pramnos\Security\Encrypter::maybeDecrypt(
                    (string) $row['broadcast_secret']
                );
            } catch (\RuntimeException) {
                // APP_KEY rotated or the column truncated. An unusable key is worse
                // than none: signing with ciphertext produces authorizations the
                // subscriber rejects, and the operator would look at the daemon.
                $secret = '';
            }
        }

        return new BroadcastApp(
            key:    (string) ($row['apikey'] ?? $key),
            secret: $secret,
            id:     (string) ($row['appid'] ?? ''),
            name:   (string) ($row['name'] ?? ''),
        );
    }

    /**
     * The database to query, or null when there is none.
     *
     * Separated from {@see loadRow()} so the query and its guards can be tested
     * without a live connection — the guards are the interesting part, because each
     * one is a way an installation can be half-configured.
     */
    protected function database(): ?object
    {
        // Thin wrapper around a static factory; overridden in tests.
        // @codeCoverageIgnoreStart
        try {
            $database = \Pramnos\Framework\Factory::getDatabase();
        } catch (\Throwable) {
            return null;
        }

        return is_object($database) ? $database : null;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Fetch the active application row for $key, or null.
     *
     * @return array<string,mixed>|null
     */
    protected function loadRow(string $key): ?array
    {
        $database = $this->database();

        if ($database === null || !method_exists($database, 'queryBuilder')) {
            return null;
        }

        try {
            // Query builder rather than hand-built SQL: it resolves the table per
            // dialect (a schema on PostgreSQL, a prefix on MySQL) and binds the
            // key instead of interpolating it.
            $row = $database->queryBuilder()
                ->table('#PREFIX#applications')
                ->where('apikey', $key)
                ->where('status', 1)
                ->first();
        } catch (\Throwable) {
            // A missing table (authserver migrations not run) must not take the
            // edge down: no app is found, the connection is refused, and the
            // reason is a configuration one the operator can see elsewhere.
            return null;
        }

        if ($row === null || $row === false) {
            return null;
        }

        return is_array($row) ? $row : (array) $row;
    }
}
