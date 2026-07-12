<?php

declare(strict_types=1);

namespace Pramnos\Application\Statistics;

/**
 * Queries the sessions table to count authenticated (non-guest) active users
 * across multiple time windows.
 *
 * The sessions table (`#PREFIX#sessions`) is written by the Addon\System\Session
 * addon on every page request. The `guest` column is 0 for authenticated users and
 * 1 for anonymous visitors. The `time` column holds a Unix timestamp of last activity.
 *
 */
class ActiveUsersService
{
    public const WINDOW_NOW  = 300;
    public const WINDOW_1H   = 3600;
    public const WINDOW_24H  = 86400;
    public const WINDOW_7D   = 604800;
    public const WINDOW_30D  = 2592000;

    private \Pramnos\Database\Database $db;

    public function __construct(?\Pramnos\Database\Database $db = null)
    {
        $this->db = $db ?? \Pramnos\Framework\Factory::getDatabase();
    }

    /**
     * Returns authenticated-user counts for all standard time windows.
     *
     * @return array{now: int, last_1h: int, last_24h: int, last_7d: int, last_30d: int}
     */
    public function getCounts(): array
    {
        $now = time();

        return [
            'now'      => $this->countSince($now - self::WINDOW_NOW),
            'last_1h'  => $this->countSince($now - self::WINDOW_1H),
            'last_24h' => $this->countSince($now - self::WINDOW_24H),
            'last_7d'  => $this->countSince($now - self::WINDOW_7D),
            'last_30d' => $this->countSince($now - self::WINDOW_30D),
        ];
    }

    /**
     * Returns the count of authenticated sessions active since the given Unix timestamp.
     * Only counts rows where guest=0 (authenticated users).
     */
    public function countSince(int $since): int
    {
        return $this->db->queryBuilder()
            ->table('#PREFIX#sessions')
            ->where('guest', 0)
            ->where('time', '>=', $since)
            ->count();
    }

    /**
     * Returns the count of all sessions (including guests) active since the given timestamp.
     * Useful for measuring total traffic, not just authenticated users.
     */
    public function countAllSince(int $since): int
    {
        return $this->db->queryBuilder()
            ->table('#PREFIX#sessions')
            ->where('time', '>=', $since)
            ->count();
    }
}
