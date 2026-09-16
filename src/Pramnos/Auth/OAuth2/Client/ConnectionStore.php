<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2\Client;

use Pramnos\Database\Database;
use Pramnos\Security\Encrypter;

/**
 * Where connections live, and the one place a refresh is written down.
 *
 * Two jobs that belong together. Persistence is obvious. The other is
 * {@see refresh()}: a refresh is a request *and* a write, and separating them produced the
 * bug this is shaped to avoid — a provider rotates the refresh token, the response is
 * used, the row is not updated, and the next refresh presents a token the provider has
 * already retired. So the request and the write are one call, and the merge that decides
 * what to keep is {@see TokenSet::mergedInto()}.
 *
 * ```php
 * $store = new ConnectionStore();
 *
 * // After a successful exchange:
 * $store->save($userId, $provider->name, $tokens, accountId: $me['id'], accountName: $me['name']);
 *
 * // When something needs to call the API:
 * $connection = $store->find($userId, 'google');
 * if ($connection && $connection->needsRefresh()) {
 *     $connection = $store->refresh($connection, new OAuthClient($provider));
 * }
 * ```
 *
 * **Tokens are encrypted on the way in and decrypted on the way out**, through
 * `Security\Encrypter` — and this subsystem **requires `APP_KEY`**. Other callers of the
 * Encrypter are offered `isAvailable()` so they can degrade to plaintext with a warning;
 * this one does not take that option. A third-party refresh token is a long-lived
 * credential for an account on somebody else's service, held on behalf of a user who
 * authorised an application rather than a database: a dump that leaks one is a breach of
 * an account nobody here owns and nobody here can revoke. {@see save()} says so before it
 * writes, rather than letting `Encrypter::encrypt()` throw its own message at the end of a
 * successful authorisation the user will have to repeat.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
class ConnectionStore
{
    private const TABLE = 'oauthconnections';

    public function __construct(private ?Database $database = null)
    {
    }

    /** The connection for this user and provider, or null. */
    public function find(int $userId, string $provider, string $accountId = ''): ?Connection
    {
        $result = $this->db()->queryBuilder()
            ->table(self::TABLE)
            ->where('userid', $userId)
            ->where('provider', $provider)
            ->where('account_id', $accountId)
            ->first();

        // `first()` answers with a result set, not a row, and an empty one is not null —
        // `numRows` is the only thing that distinguishes "no such connection" from one
        // whose every column happens to be falsy.
        return $result && $result->numRows > 0 ? $this->hydrate($result->fields) : null;
    }

    /**
     * Every connection this user has with a provider.
     *
     * Plural because one user may have authorised two accounts on the same platform —
     * two pages, two channels, two advertising accounts — and an interface that offers to
     * post somewhere has to list them rather than pick.
     *
     * @return list<Connection>
     */
    public function forUser(int $userId, string $provider = ''): array
    {
        $query = $this->db()->queryBuilder()
            ->table(self::TABLE)
            ->where('userid', $userId);

        if ($provider !== '') {
            $query->where('provider', $provider);
        }

        return $this->hydrateAll($query->get());
    }

    /**
     * Connections that should be refreshed now.
     *
     * "Now" is generous on purpose — `$within` defaults to an hour rather than to
     * {@see Connection::REFRESH_WINDOW} — because this is read by a job that runs on a
     * schedule, and a token whose window opens two minutes after the run has to be caught
     * by *this* run or it expires before the next one. Refreshing slightly early costs a
     * request; refreshing slightly late costs an outage between two cron ticks.
     *
     * Dead connections are excluded. They are not coming back, and retrying a revoked
     * grant every quarter of an hour for ever is how a provider starts rate-limiting an
     * application for its live traffic as well.
     *
     * @return list<Connection>
     */
    public function dueForRefresh(int $within = 3600, ?int $now = null): array
    {
        return $this->hydrateAll(
            $this->db()->queryBuilder()
                ->table(self::TABLE)
                ->where('status', Connection::ACTIVE)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', ($now ?? time()) + $within)
                ->get()
        );
    }

    /**
     * Write a connection, replacing whatever was there for this user, provider and account.
     *
     * An upsert rather than a check-then-insert: authorising twice in quick succession —
     * a double-clicked button, a retried callback — would otherwise race two inserts
     * against the unique key and the second would be an error out of a screen that had
     * every reason to work.
     */
    public function save(
        int $userId,
        string $provider,
        TokenSet $tokens,
        string $accountId = '',
        string $accountName = ''
    ): void {
        $now = time();

        $values = [
            'userid'             => $userId,
            'provider'           => $provider,
            'account_id'         => $accountId,
            'account_name'       => $accountName,
            'access_token'       => $this->protect($tokens->accessToken),
            'refresh_token'      => $tokens->refreshToken === '' ? null : $this->protect($tokens->refreshToken),
            'expires_at'         => $tokens->expiresAt,
            'refresh_expires_at' => $tokens->refreshExpiresAt,
            'scopes'             => implode(' ', $tokens->scopes),
            // A successful save revives a connection that had been marked dead: the user
            // has just authorised it again, which is the only thing that can.
            'status'             => Connection::ACTIVE,
            'dead_reason'        => null,
            'dead_at'            => null,
            'created_at'         => $now,
            'updated_at'         => $now,
        ];

        $update = $values;
        // The moment it was first authorised, not the moment it was last re-authorised.
        unset($update['created_at']);

        $this->db()->queryBuilder()
            ->table(self::TABLE)
            ->upsert($values, ['userid', 'provider', 'account_id'], $update);
    }

    /**
     * Refresh a connection and persist the result.
     *
     * The write happens whether or not the provider sent a new refresh token, because
     * {@see TokenSet::mergedInto()} has already decided what to keep. A terminal failure —
     * a revoked grant, an expired refresh token — marks the connection dead and rethrows:
     * the caller decides whether that is an error or a fact to report, and the row records
     * it either way.
     *
     * @throws OAuthClientException
     */
    public function refresh(Connection $connection, OAuthClient $client): Connection
    {
        if (!$connection->isRefreshable()) {
            /*
             * Not an attempt that will fail — an attempt that cannot be made. Marked dead
             * without a request, because sending an empty or expired refresh token to a
             * provider is a guaranteed error and some of them count it against a rate
             * limit that the live connections share.
             */
            $reason = $connection->refreshToken === ''
                ? 'No refresh token was ever issued'
                : 'The refresh token expired before it was used';
            $this->markDead($connection, $reason);

            throw new OAuthClientException(
                $connection->provider . ': ' . $reason,
                'invalid_grant',
                $connection->provider
            );
        }

        try {
            $tokens = $client->refresh($connection->refreshToken)->mergedInto($connection->toTokenSet());
        } catch (OAuthClientException $exception) {
            if ($exception->isTerminal()) {
                $this->markDead($connection, $exception->getMessage());
            }

            throw $exception;
        }

        $this->save(
            $connection->userId,
            $connection->provider,
            $tokens,
            $connection->accountId,
            $connection->accountName
        );

        return new Connection(
            id: $connection->id,
            userId: $connection->userId,
            provider: $connection->provider,
            accountId: $connection->accountId,
            accountName: $connection->accountName,
            accessToken: $tokens->accessToken,
            refreshToken: $tokens->refreshToken,
            expiresAt: $tokens->expiresAt,
            refreshExpiresAt: $tokens->refreshExpiresAt,
            scopes: $tokens->scopes,
        );
    }

    /**
     * Record that a connection can no longer be refreshed.
     *
     * The row is kept rather than deleted. Deleting it loses the two things anybody asks
     * afterwards — what stopped working, and when — and leaves an interface unable to
     * distinguish "never connected" from "disconnected in July", which are different
     * sentences to show a user.
     *
     * The reason is truncated to the column's width here rather than by the database,
     * because MySQL in a non-strict mode truncates silently and PostgreSQL raises, so the
     * same provider message would be a stored row on one and an exception on the other.
     */
    public function markDead(Connection $connection, string $reason): void
    {
        $this->db()->queryBuilder()
            ->table(self::TABLE)
            ->where('id', $connection->id)
            ->update([
                'status'      => Connection::DEAD,
                'dead_reason' => mb_substr($reason, 0, 190),
                'dead_at'     => time(),
                'updated_at'  => time(),
            ]);
    }

    /** Forget a connection entirely — what "disconnect" means when a user asks for it. */
    public function forget(int $userId, string $provider, string $accountId = ''): void
    {
        $this->db()->queryBuilder()
            ->table(self::TABLE)
            ->where('userid', $userId)
            ->where('provider', $provider)
            ->where('account_id', $accountId)
            ->delete();
    }

    /**
     * Encrypt a token, refusing clearly when the installation cannot.
     *
     * `Encrypter::encrypt()` throws its own message without `APP_KEY`, and that message is
     * correct but arrives at the worst moment — after the user has completed an
     * authorisation they will now have to repeat. Checked first so the sentence names the
     * subsystem and the command, and so a caller that wants to offer the connect button
     * only when it will work can ask `Encrypter::isAvailable()` itself.
     *
     * `maybeDecrypt()` on the way out reads either form, which is what lets an
     * installation that sets a key later keep reading rows written before it — each is
     * re-encrypted the next time it is saved.
     */
    private function protect(string $value): string
    {
        if (!Encrypter::isAvailable()) {
            throw new OAuthClientException(
                'Refusing to store a third-party OAuth token without APP_KEY — it is a '
                . 'credential for an account this application does not own. '
                . 'Run: php pramnos key:generate',
                'no_app_key'
            );
        }

        return Encrypter::encrypt($value);
    }

    /**
     * Every row of a result set, as connections.
     *
     * @return list<Connection>
     */
    private function hydrateAll(mixed $result): array
    {
        $connections = [];

        while ($result && $result->fetch()) {
            $connections[] = $this->hydrate($result->fields);
        }

        return $connections;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Connection
    {
        $row = (object) $row;

        $scopes = trim((string) ($row->scopes ?? ''));

        return new Connection(
            id: (int) $row->id,
            userId: (int) $row->userid,
            provider: (string) $row->provider,
            accountId: (string) ($row->account_id ?? ''),
            accountName: (string) ($row->account_name ?? ''),
            accessToken: Encrypter::maybeDecrypt((string) $row->access_token),
            refreshToken: Encrypter::maybeDecrypt((string) ($row->refresh_token ?? '')),
            expiresAt: isset($row->expires_at) ? (int) $row->expires_at : null,
            refreshExpiresAt: isset($row->refresh_expires_at) ? (int) $row->refresh_expires_at : null,
            scopes: $scopes === '' ? [] : preg_split('/\s+/', $scopes, -1, PREG_SPLIT_NO_EMPTY),
            status: (string) ($row->status ?? Connection::ACTIVE),
            deadReason: (string) ($row->dead_reason ?? ''),
            deadAt: isset($row->dead_at) ? (int) $row->dead_at : null,
        );
    }

    private function db(): Database
    {
        return $this->database ??= Database::getInstance();
    }
}
