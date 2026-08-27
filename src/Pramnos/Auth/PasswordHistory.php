<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * The passwords an account has already used, so "change it" cannot mean "type it again".
 *
 * Only useful where there is a reason to change — a suspected leak, a policy, an operator
 * reset. In those situations a person's first instinct is the password they already know,
 * which produces the appearance of a change and none of the effect.
 *
 * Off unless an application sets `auth.security.password_history` to how many to remember.
 * It has a real cost: somebody who genuinely wants their old password back cannot have it,
 * and support cannot give it to them either.
 *
 * ## Hashes, compared the way the login compares them
 *
 * A previous password is stored exactly as `users.password` stored it, and checked with
 * {@see PasswordHash::verify()} — the same call the login makes. That is not a shortcut:
 * a second comparison written here would be a second thing to get wrong, and this one is
 * already right about every scheme the framework can read.
 *
 * It also means the check costs one bcrypt verification per remembered hash. With five
 * remembered that is five, on a password change and nowhere else, which is the one place
 * where a fifth of a second does not matter.
 */
class PasswordHistory
{
    /** @var \Pramnos\Database\Database */
    private $database;

    public function __construct($database = null)
    {
        $this->database = $database ?: \Pramnos\Framework\Factory::getDatabase();
    }

    /**
     * Has this account used this password before?
     *
     * False when the feature is off, when the account has no history, or when the table is
     * absent — a reuse check that cannot run must not refuse the change, because the
     * change is the thing somebody is trying to do for a reason.
     */
    public function wasUsedBefore(int $userId, string $plainPassword): bool
    {
        $keep = SecurityPolicy::passwordHistory();
        if ($keep < 1 || $userId < 2 || $plainPassword === '') {
            return false;
        }

        foreach ($this->recentHashes($userId, $keep) as $hash) {
            if (PasswordHash::verify($plainPassword, $hash, $userId) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remember the hash an account is moving away from, and forget the oldest.
     *
     * Called with the hash that was in `users.password` *before* the change: the point is
     * the list of what may not be reused, and the new password is in `users` already.
     */
    public function remember(int $userId, string $previousHash): void
    {
        $keep = SecurityPolicy::passwordHistory();
        if ($keep < 1 || $userId < 2 || $previousHash === '') {
            return;
        }

        try {
            $this->database->queryBuilder()
                ->table('authserver.password_history')
                ->insert([
                    'userid'        => $userId,
                    'password_hash' => $previousHash,
                    'created_at'    => time(),
                ]);

            $this->prune($userId, $keep);
        } catch (\Throwable $exception) {
            // Not fatal: the password has already changed, and failing here would report a
            // successful change as a failure.
            \Pramnos\Logs\Logger::log(
                'PasswordHistory could not record a hash for ' . $userId . ': '
                . $exception->getMessage(),
                'auth'
            );
        }
    }

    /**
     * Forget everything about an account, for a deletion or an export erasure.
     */
    public function forget(int $userId): void
    {
        try {
            $this->database->queryBuilder()
                ->table('authserver.password_history')
                ->where('userid', $userId)
                ->delete();
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'PasswordHistory could not clear ' . $userId . ': ' . $exception->getMessage(),
                'auth'
            );
        }
    }

    /**
     * The most recent hashes, newest first.
     *
     * @return list<string>
     */
    private function recentHashes(int $userId, int $limit): array
    {
        try {
            $result = $this->database->queryBuilder()
                ->table('authserver.password_history')
                ->select(['password_hash'])
                ->where('userid', $userId)
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();
        } catch (\Throwable $exception) {
            return array();
        }

        $hashes = array();
        if ($result === null) {
            return $hashes;
        }

        // `fetch()` is the cursor here — it returns the row and null at the end. There is
        // no `MoveNext()` on this Result; a `while (!$eof) { …; MoveNext(); }` loop reads
        // the first row for ever, which is a hang rather than a wrong answer.
        while (($row = $result->fetch()) !== null) {
            $hash = (string) ($row['password_hash'] ?? '');
            if ($hash !== '') {
                $hashes[] = $hash;
            }
        }

        return $hashes;
    }

    /**
     * Keep the newest `$keep` and delete the rest.
     *
     * Pruned on write rather than by a scheduled job: the table only grows when somebody
     * changes a password, so the moment of growth is the cheapest moment to trim it, and
     * there is nothing left for an operator to remember to run.
     */
    private function prune(int $userId, int $keep): void
    {
        $hashes = $this->recentHashes($userId, $keep + 50);
        if (count($hashes) <= $keep) {
            return;
        }

        try {
            $cutoff = $this->database->queryBuilder()
                ->table('authserver.password_history')
                ->select(['created_at'])
                ->where('userid', $userId)
                ->orderBy('created_at', 'desc')
                ->limit(1)
                ->offset($keep - 1)
                ->first();

            if ($cutoff === null || ($cutoff->numRows ?? 0) === 0) {
                return;
            }

            $this->database->queryBuilder()
                ->table('authserver.password_history')
                ->where('userid', $userId)
                ->where('created_at', '<', (int) $cutoff->fields['created_at'])
                ->delete();
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'PasswordHistory could not prune ' . $userId . ': ' . $exception->getMessage(),
                'auth'
            );
        }
    }
}
