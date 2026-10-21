<?php

declare(strict_types=1);

namespace Pramnos\Email;

/**
 * The mail log (`mails`) and the account each message was for.
 *
 * The log was keyed by address alone, so "this account's mail" was a match on the account's
 * *current* address, and everything sent before an address change vanished from the account's
 * page. With `userid` — written by {@see Email::forUser()} and by a notification's mail — the
 * account's mail is its own, whatever address it went to.
 *
 * The column is optional: an installation whose log is too large to index now can leave the
 * migration out (`'migrations' => ['exclude' => ['add_userid_to_mails']]`) and everything here
 * falls back to the address.
 */
final class MailLog
{
    /** @var array<string, bool> Which optional columns `mails` has, asked once per process */
    private static array $columns = [];

    /** Whether the log records the account a message was for. */
    public static function recordsUser(): bool
    {
        return self::has('userid');
    }

    /** Whether `mails` has an optional column, asked once per process. */
    private static function has(string $column): bool
    {
        if (!isset(self::$columns[$column])) {
            try {
                self::$columns[$column] = \Pramnos\Framework\Factory::getDatabase()->schema()->hasColumn('mails', $column);
            } catch (\Throwable) {
                return false;
            }
        }

        return self::$columns[$column];
    }

    /** Forget the answers — after a migration, or between tests. */
    public static function reset(): void
    {
        self::$columns = [];
    }

    /**
     * One account's mail on `$query` (a builder on `mails`): what was recorded as theirs, and
     * what went to their current address — a message logged before the column existed has no
     * `userid`. By the address alone where the log records no account.
     */
    public static function ofUser(\Pramnos\Database\QueryBuilder $query, int $userId, string $address): \Pramnos\Database\QueryBuilder
    {
        $address = strtolower(trim($address));
        if ($userId > 0 && self::recordsUser()) {
            return $query->where(static function ($q) use ($userId, $address): void {
                $q->where('userid', $userId);
                if ($address !== '') {
                    $q->whereRaw('LOWER(tomail) = ?', [$address], 'or');
                }
            });
        }

        return $query->whereRaw('LOWER(tomail) = ?', [$address]);
    }
}
