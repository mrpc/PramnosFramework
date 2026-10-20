<?php

declare(strict_types=1);

namespace Pramnos\Auth;

use Pramnos\Database\Database;

/**
 * The applications a person has let act for them, and taking that back — the one place both are
 * done, for the person's own screen and for an administrator acting on their account.
 *
 * ```php
 * $apps = new AuthorizedApplications();
 * $apps->forUser($userId);                          // what holds a token, or a consent
 * $apps->revoke($userId, $appId, byUserId: $admin); // tokens revoked, consent gone, recorded
 * ```
 *
 * An application is listed when it holds a live token for the person **or** a consent from them:
 * a consent whose tokens have all expired still approves the next authorization without asking,
 * so it has to be visible to be withdrawn. A deactivated application is listed, and revocable,
 * as well: its refresh tokens are as live as anybody's.
 */
final class AuthorizedApplications
{
    /** A token that is live. */
    private const ACTIVE = 1;

    /** A token revoked by its owner or for them — kept, for the audit trail. */
    public const REVOKED = 3;

    public function __construct(private ?Database $database = null)
    {
    }

    /**
     * The applications a person has authorized: each with its name, client id, description and
     * address, whether it is still active, its live tokens and when one was last used, and the
     * consent — when given, for what — where there is one.
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(int $userId): array
    {
        $db   = $this->db();
        $apps = [];

        $tokens = $db->queryBuilder()->table('#PREFIX#usertokens')
            ->select(['applicationid', 'MAX(GREATEST(lastused, created)) AS last_used', 'COUNT(tokenid) AS token_count'])
            ->where('userid', $userId)
            ->where('status', self::ACTIVE)
            ->where('applicationid', '>', 0)
            ->where(static function ($q): void {
                $q->where('expires', 0)->orWhere('expires', '>', time());
            })
            ->groupBy(['applicationid'])
            ->get();
        while ($tokens && $tokens->fetch()) {
            $apps[(int) $tokens->fields['applicationid']] = [
                'last_used'   => (int) $tokens->fields['last_used'],
                'token_count' => (int) $tokens->fields['token_count'],
            ];
        }

        $consents = $db->queryBuilder()->table('authserver.oauth2_user_consents')
            ->select(['applicationid', 'scope', 'created_at'])
            ->where('userid', $userId)
            ->get();
        while ($consents && $consents->fetch()) {
            $appId          = (int) $consents->fields['applicationid'];
            $apps[$appId] ??= ['last_used' => 0, 'token_count' => 0];
            $apps[$appId]  += ['consented_at' => $consents->fields['created_at'], 'scope' => (string) $consents->fields['scope']];
        }
        if ($apps === []) {
            return [];
        }

        $details = $db->queryBuilder()->table('#PREFIX#applications')
            ->select(['appid', 'name', 'apikey', 'description', 'url', 'status'])
            ->whereIn('appid', array_keys($apps))
            ->get();
        $list = [];
        while ($details && $details->fetch()) {
            $appId  = (int) $details->fields['appid'];
            $list[] = [
                'appid'        => $appId,
                'name'         => (string) $details->fields['name'],
                'apikey'       => (string) $details->fields['apikey'],
                'description'  => $details->fields['description'],
                'website_url'  => $details->fields['url'],
                'active'       => (int) $details->fields['status'] === 1,
                'last_used'    => $apps[$appId]['last_used'],
                'token_count'  => $apps[$appId]['token_count'],
                'consented_at' => $apps[$appId]['consented_at'] ?? null,
                'scope'        => $apps[$appId]['scope'] ?? null,
            ];
        }
        usort($list, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $list;
    }

    /**
     * The application with this client id, active or not — a deactivated one is still revocable.
     *
     * @return array{appid: int, name: string}|null
     */
    public function byClientId(string $clientId): ?array
    {
        $row = $this->db()->queryBuilder()->table('#PREFIX#applications')
            ->select(['appid', 'name'])
            ->where('apikey', $clientId)
            ->first();

        return $row && $row->numRows > 0 ? ['appid' => (int) $row->fields['appid'], 'name' => (string) $row->fields['name']] : null;
    }

    /**
     * Take back what a person let an application do: every live token it holds for them revoked
     * (status 3, kept for the audit trail) — access and refresh alike — and their consent
     * withdrawn, so the next authorization asks again. Recorded in the person's activity, with
     * who did it when it was not them. Returns how many tokens were revoked.
     */
    public function revoke(int $userId, int $appId, ?int $byUserId = null): int
    {
        $db     = $this->db();
        $tokens = static fn () => $db->queryBuilder()->table('#PREFIX#usertokens')
            ->where('userid', $userId)->where('applicationid', $appId)->where('status', self::ACTIVE);
        $count = (int) $tokens()->count();
        if ($count > 0) {
            $tokens()->update(['status' => self::REVOKED, 'removedate' => time()]);
        }
        $db->queryBuilder()->table('authserver.oauth2_user_consents')
            ->where('userid', $userId)
            ->where('applicationid', $appId)
            ->delete();

        $name = $db->queryBuilder()->table('#PREFIX#applications')->select(['name'])->where('appid', $appId)->first();
        ActivityLog::record($userId, 'application_revoked', [
            'application_id'   => $appId,
            'application_name' => $name && $name->numRows > 0 ? (string) $name->fields['name'] : '',
            'tokens_revoked'   => $count,
        ] + ($byUserId !== null && $byUserId !== $userId ? ['revoked_by' => $byUserId] : []));

        // To that application only: the others still hold their access.
        try {
            (new WebhookService($db))->queueUserEvent('user_deauthorized', $userId, [
                'application_id' => $appId,
                'tokens_revoked' => $count,
                'revoked_by'     => $byUserId ?? $userId,
            ], [$appId]);
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::logError('user_deauthorized could not be queued', $exception);
        }

        return $count;
    }

    private function db(): Database
    {
        return $this->database ?? \Pramnos\Framework\Factory::getDatabase();
    }
}
