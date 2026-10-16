<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * The audit trail of what was done, by whom, to what: `authserver.audit_log`.
 *
 * ```php
 * // an event about an account
 * AuditLog::record('role.granted', $adminId, $userId, ['role' => 'editor']);
 *
 * // a record changed through the administration
 * AuditLog::change('settings.updated', $adminId, 'setting', 'smtp_host', ['value' => 'old'], ['value' => 'new']);
 *
 * // the admin screen
 * $rows = AuditLog::query(['event' => 'role.granted', 'since' => strtotime('-7 days')], 50);
 * ```
 *
 * The activity log ({@see ActivityLog}) is a person's own history, shown to them; this is the
 * operators' record of actions, read by an administrator or an auditor. Context, old and new
 * values pass through {@see redact()} first: a key that names a secret keeps its place and loses
 * its value, so the record says a password changed without holding it.
 *
 * Like the activity log it never throws into the caller and writes wherever the table exists: an
 * audit that breaks the action it audits gets turned off. Nothing prunes it unless asked —
 * `audit:prune --days=N`, or `'audit' => ['retention_days' => N]` for the scheduled run — because
 * how long an audit trail is kept is a decision of law or policy, not of the framework.
 */
final class AuditLog
{
    /** The table, as the schema builder names it on both backends. */
    public const TABLE = 'authserver.audit_log';

    /** What a redacted value becomes. */
    public const REDACTED = '[redacted]';

    /** The most rows {@see query()} returns at once. */
    public const MAX_ROWS = 500;

    /** Words that make a key a secret's: `password`, `client_secret`, `accessToken`, `otp`… */
    private const SECRET_WORDS = ['pass', 'password', 'passwd', 'passphrase', 'secret', 'token', 'otp', 'totp', 'pin', 'cookie', 'authorization', 'credential', 'credentials', 'apikey'];

    private static ?bool $tableAvailable = null;

    /**
     * An event, by an actor, about an account.
     *
     * @param string               $event   What happened: `role.granted`, `user.deleted`, `mcp.query`…
     * @param int|null             $actor   Who did it; null for the system (a scheduled job, the CLI)
     * @param int|null             $target     The account it was done to, if one
     * @param array<string, mixed> $context    What else an auditor needs, redacted
     * @param string|null          $objectType What it was done through or to, beside the account:
     *                                         a screen, a record (`'page'`, `'application'`)
     * @param string|int|null      $objectId   Which one
     */
    public static function record(string $event, ?int $actor, ?int $target = null, array $context = [], ?string $objectType = null, string|int|null $objectId = null): void
    {
        self::write($event, $actor, [
            'target_type' => $target !== null ? 'user' : null,
            'target_id'   => $target !== null ? (string) $target : null,
            'object_type' => $objectType !== null ? substr($objectType, 0, 50) : null,
            'object_id'   => $objectId !== null ? substr((string) $objectId, 0, 100) : null,
            'metadata'    => $context,
        ]);
    }

    /**
     * A record changed: what it was and what it became, each redacted — and, when the record
     * belongs to an account, which one.
     *
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @param array<string, mixed> $context
     */
    public static function change(string $event, ?int $actor, string $objectType, string|int $objectId, array $old, array $new, array $context = [], ?int $target = null): void
    {
        self::write($event, $actor, [
            'target_type' => $target !== null ? 'user' : null,
            'target_id'   => $target !== null ? (string) $target : null,
            'object_type' => substr($objectType, 0, 50),
            'object_id'   => substr((string) $objectId, 0, 100),
            'old_values'  => $old,
            'new_values'  => $new,
            'metadata'    => $context,
        ]);
    }

    /**
     * The trail, newest first, for an admin screen.
     *
     * Filters: `event` (exact, or a prefix ending in `*`: `role.*`), `not_event` (a list of the
     * same, left out: `['admin.page']`), `actor`, `target` (a userid), `object_type`, `object_id`,
     * `since` and `until` (Unix times), `before` (an `auditid`, for the next page). The JSON
     * columns come back decoded, and `at` is the moment as a Unix time — `event_timestamp` is as
     * the database returns it, in its session's zone (MySQL) or with an offset (PostgreSQL).
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public static function query(array $filters = [], int $limit = 50): array
    {
        try {
            $database = \Pramnos\Framework\Factory::getDatabase();
            if (!self::tableAvailable($database)) {
                return [];
            }
            $query = $database->queryBuilder()->table(self::TABLE)
                ->select(['*', \Pramnos\Database\Instant::unix($database, 'event_timestamp', 'at')]);
            $event = (string) ($filters['event'] ?? '');
            if ($event !== '') {
                self::matching($query, $event, false);
            }
            foreach ((array) ($filters['not_event'] ?? []) as $excluded) {
                if (is_string($excluded) && $excluded !== '') {
                    self::matching($query, $excluded, true);
                }
            }
            if (isset($filters['actor'])) {
                $query->where('actor_userid', (int) $filters['actor']);
            }
            if (isset($filters['target'])) {
                $query->where('target_type', 'user')->where('target_id', (string) (int) $filters['target']);
            }
            foreach (['object_type', 'object_id'] as $column) {
                if (isset($filters[$column])) {
                    $query->where($column, (string) $filters[$column]);
                }
            }
            if (isset($filters['since'])) {
                $query->where('event_timestamp', '>=', \Pramnos\Database\Instant::at($database, (int) $filters['since']));
            }
            if (isset($filters['until'])) {
                $query->where('event_timestamp', '<', \Pramnos\Database\Instant::at($database, (int) $filters['until']));
            }
            if (isset($filters['before'])) {
                $query->where('auditid', '<', (int) $filters['before']);
            }
            $result = $query->orderBy('auditid', 'desc')->limit(max(1, min(self::MAX_ROWS, $limit)))->get();
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('AuditLog::query failed: ' . $e->getMessage(), 'auth');

            return [];
        }

        $rows = [];
        while (($row = $result->fetch()) !== null) {
            foreach (['old_values', 'new_values', 'metadata'] as $json) {
                $row[$json] = is_string($row[$json] ?? null) ? json_decode($row[$json], true) : ($row[$json] ?? null);
            }
            $row['at'] = (int) ($row['at'] ?? 0);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Delete what is older than its retention. Returns how many rows went, or null when there is
     * no table.
     *
     * `$days` is one retention for everything, or one per event: `['admin.page' => 180,
     * 'role.*' => 3650, '*' => 730]` — an exact event or a prefix, and `*` for every event no
     * other entry names. An event that matches no entry, with no `*`, is kept.
     *
     * @param int|array<string, int> $days
     */
    public static function prune(int|array $days): ?int
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        if (!self::tableAvailable($database)) {
            return null;
        }
        $days    = is_int($days) ? ['*' => $days] : $days;
        $named   = array_values(array_filter(array_map('strval', array_keys($days)), static fn (string $p): bool => $p !== '*' && $p !== ''));
        $deleted = 0;
        foreach ($days as $pattern => $keep) {
            $pattern = (string) $pattern;
            if ($pattern === '' || (int) $keep <= 0) {
                continue;
            }
            $query = $database->queryBuilder()->table(self::TABLE)
                ->where('event_timestamp', '<', \Pramnos\Database\Instant::at($database, time() - (int) $keep * 86400));
            if ($pattern === '*') {
                foreach ($named as $other) {
                    self::matching($query, $other, true);
                }
            } else {
                self::matching($query, $pattern, false);
            }
            $result   = $query->delete();
            $deleted += $result instanceof \Pramnos\Database\Result ? (int) $result->getAffectedRows() : 0;
        }

        return $deleted;
    }

    /**
     * Narrow a query to the events a pattern names, or to the rest: an exact event, or a prefix
     * ending in `*`.
     */
    private static function matching(\Pramnos\Database\QueryBuilder $query, string $pattern, bool $not): void
    {
        if (str_ends_with($pattern, '*')) {
            $query->where('event_type', $not ? 'not like' : 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], substr($pattern, 0, -1)) . '%');
        } else {
            $query->where('event_type', $not ? '!=' : '=', $pattern);
        }
    }

    /**
     * Context with every secret-named key's value replaced, at any depth. A number or a boolean
     * under such a key is kept — a count of tokens is not a token — and so is a list's order.
     *
     * @param array<mixed> $context
     * @return array<mixed>
     */
    public static function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = self::redact($value);
            } elseif (is_string($key) && self::namesASecret($key) && !is_int($value) && !is_float($value) && !is_bool($value) && $value !== null) {
                $context[$key] = self::REDACTED;
            }
        }

        return $context;
    }

    /**
     * Whether a key names a secret, by its words: `new_password`, `clientSecret`, `api-key`,
     * `Authorization` do; `tokens_issued` as a count does by name and is kept by {@see redact()},
     * and `spin`, `monkey` or `keyword` do not — a word, never a part of one.
     */
    private static function namesASecret(string $key): bool
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key))) ?: [];
        foreach ($words as $at => $word) {
            if (in_array($word, self::SECRET_WORDS, true) || ($word === 'key' && in_array($words[$at - 1] ?? '', ['api', 'private', 'secret', 'access'], true))) {
                return true;
            }
        }

        return false;
    }

    /** Forget whether the table exists: for a test that builds or drops it. */
    public static function resetTableCache(): void
    {
        self::$tableAvailable = null;
    }

    /** @param array<string, mixed> $columns */
    private static function write(string $event, ?int $actor, array $columns): void
    {
        if ($event === '') {
            return;
        }
        try {
            $database = \Pramnos\Framework\Factory::getDatabase();
            if (!self::tableAvailable($database)) {
                return;
            }
            foreach (['old_values', 'new_values', 'metadata'] as $json) {
                if (array_key_exists($json, $columns)) {
                    $columns[$json] = $columns[$json] === [] ? null : json_encode(self::redact($columns[$json]), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                }
            }
            $stored = $database->queryBuilder()->table(self::TABLE)->insert($columns + [
                'event_type'      => substr($event, 0, 50),
                'actor_userid'    => $actor,
                'actor_type'      => $actor !== null ? 'user' : 'system',
                'event_timestamp' => \Pramnos\Database\Instant::at($database, time()),
            ]);
            // Its answer, not only an exception: PostgreSQL reports a refused insert as false.
            if ($stored === false) {
                throw new \RuntimeException('the insert was refused');
            }
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('AuditLog: "' . $event . '" not recorded: ' . $e->getMessage(), 'auth');
        }
    }

    private static function tableAvailable(\Pramnos\Database\Database $database): bool
    {
        if (self::$tableAvailable === null) {
            try {
                self::$tableAvailable = $database->schema()->hasTable(self::TABLE);
            } catch (\Throwable) {
                self::$tableAvailable = false;
            }
        }

        return self::$tableAvailable;
    }
}
