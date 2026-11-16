<?php

declare(strict_types=1);

namespace Pramnos\Metrics;

/**
 * A signed, expiring, revocable link that opens one saved report without an account — BI phase
 * 7f, `docs/Metrics-BI-Design.md` §15.1.3. Read with the scope of whoever made it: the link keeps
 * only who made it, never a snapshot of what they may see, so a maker whose access narrows later
 * narrows what the link shows too — the same `Reports::find()` an ordinary signed-in read uses.
 *
 * ```php
 * $token = SharedLinks::create($user, $reportId, 7);              // a week
 * $row   = SharedLinks::resolve($token);                          // null: wrong, expired, revoked
 * SharedLinks::revoke($token, $user);
 * ```
 */
final class SharedLinks
{
    public const TABLE = 'pramnos.metric_links';

    /**
     * A new link for a report the person may open — `null` where they may not, or the report
     * does not exist. `$days` null never expires.
     */
    public static function create(object $user, string $reportId, ?int $days = null): ?string
    {
        if ((new Reports())->find($reportId, $user) === null) {
            return null;
        }
        $token = bin2hex(random_bytes(24));
        self::db()->queryBuilder()->table(self::TABLE)->insert([
            'token'      => $token,
            'report_id'  => $reportId,
            'made_by'    => (int) ($user->userid ?? 0),
            'created_at' => time(),
            'expires_at' => $days !== null ? time() + max(1, $days) * 86400 : null,
            'revoked_at' => null,
        ]);

        return $token;
    }

    /**
     * The row behind a token — null where there is none, it was revoked, or it has expired.
     *
     * @return array{token: string, report_id: string, made_by: int, created_at: int, expires_at: ?int, revoked_at: ?int}|null
     */
    public static function resolve(string $token): ?array
    {
        if ($token === '' || !self::db()->schema()->hasTable(self::TABLE)) {
            return null;
        }
        // first() always answers a Result, never null — numRows says whether it matched.
        $found = self::db()->queryBuilder()->table(self::TABLE)->where('token', $token)->first();
        $row   = $found->numRows > 0 ? $found->fields : null;
        if ($row === null || $row['revoked_at'] !== null || ($row['expires_at'] !== null && (int) $row['expires_at'] <= time())) {
            return null;
        }

        return [
            'token' => (string) $row['token'], 'report_id' => (string) $row['report_id'], 'made_by' => (int) $row['made_by'],
            'created_at' => (int) $row['created_at'], 'expires_at' => $row['expires_at'] !== null ? (int) $row['expires_at'] : null,
            'revoked_at' => null,
        ];
    }

    /** Takes a link away — only its own maker, or an administrator. */
    public static function revoke(string $token, object $user): bool
    {
        $found = self::db()->queryBuilder()->table(self::TABLE)->where('token', $token)->first();
        $row   = $found->numRows > 0 ? $found->fields : null;
        if ($row === null || ((int) $row['made_by'] !== (int) ($user->userid ?? 0) && (int) ($user->usertype ?? 0) < 97)) {
            return false;
        }

        return self::db()->queryBuilder()->table(self::TABLE)->where('token', $token)->update(['revoked_at' => time()])->getAffectedRows() > 0;
    }

    private static function db(): \Pramnos\Database\Database
    {
        return \Pramnos\Database\Database::getInstance();
    }
}
