<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * A second factor delivered by email: issue a code, mail it, verify it once.
 *
 * The weakest of the second factors here, and offered anyway — deliberately. An
 * authenticator app is stronger and a passkey stronger still, but both require the
 * person to have set something up *before* the day they need it, and most people have
 * not. An account with a password and nothing else is the case this exists for: mail is
 * a channel every account already has, so it is the only second factor that can be
 * turned on for everybody.
 *
 * What that means for how it is treated: it is **not** a replacement for TOTP and never
 * ranks above it. When an account has both, the login asks for the app and offers mail as
 * the fallback — the reverse would quietly downgrade every account that had done the
 * stronger thing.
 *
 * ## Two switches, two questions
 *
 *   - **The application** decides the method exists: `'auth' => ['twofactor_methods' =>
 *     ['totp', 'email']]`. The default is `['totp']`, so an installation that does not
 *     ask for this gets exactly what it had.
 *   - **The account** decides it wants it: `user_twofactor.email_enabled`.
 *
 * Both must be true. An application can therefore withdraw the method without touching a
 * single account row, and turning it back on restores each account's own answer.
 *
 * ## What makes a six-digit code safe enough
 *
 * Not the hashing — 10^6 possibilities is nothing to a KDF. It is the three limits, and
 * all three are enforced here rather than left to the caller:
 *
 *   - **ten minutes**, after which the code is refused;
 *   - **five attempts**, after which it is destroyed rather than merely refused, so an
 *     attacker cannot keep guessing against a code the owner is still holding;
 *   - **single use** — a correct verification deletes the row, so a code intercepted
 *     after the fact is spent.
 *
 * A new code replaces the old one, so "send it again" never leaves two live codes.
 *
 * The code is stored as an HMAC keyed by the installation secret *and* the user id: a
 * leaked table hands out no live codes, and a row copied onto another account verifies
 * nothing.
 */
class EmailSecondFactor
{
    /** The name this method answers to in a step-up. */
    public const METHOD = 'email';

    /** How long a code lives, in seconds. */
    public const TTL = 600;

    /** Failed attempts before the code is destroyed. */
    public const MAX_ATTEMPTS = 5;

    /**
     * The shortest gap between two sends, in seconds.
     *
     * "Send another code" with nothing behind it sends one mail per click. Ten clicks is
     * ten mails — to somebody who may not have asked for any of them, since the button
     * sits on a step-up that anybody holding a correct password can reach. That is both a
     * way to flood a mailbox and, at scale, a way to spend an installation's send quota.
     *
     * A gap rather than a hard daily cap: the honest case is a person who did not receive
     * the first one, and they should be able to try again shortly rather than be locked
     * out of their own login for an hour.
     */
    public const RESEND_INTERVAL = 60;

    /** How many sends are allowed inside {@see SEND_WINDOW}. */
    public const MAX_SENDS = 5;

    /** The window the send count applies to, in seconds. */
    public const SEND_WINDOW = 900;

    /** The purpose a login step-up uses. */
    public const PURPOSE_LOGIN = 'login';

    /**
     * The purpose used when an account is *taking* the factor.
     *
     * Separate from `login` deliberately. Enrolment and authentication are different
     * permissions, and one store row per purpose means a code mailed to prove "this
     * mailbox works" cannot be typed into a login step-up, or the reverse. Sharing one
     * purpose would make an enrolment code a live credential.
     */
    public const PURPOSE_ENROL = 'enrol';

    /** @var \Pramnos\Database\Database */
    private $database;

    public function __construct($database = null)
    {
        $this->database = $database ?: \Pramnos\Framework\Factory::getDatabase();
    }

    // ── Availability ──────────────────────────────────────────────────────────

    /**
     * Does this installation offer the email factor at all?
     *
     * Read from `auth.twofactor_methods`, which names every method the application
     * allows. Absent means the historical set — TOTP only — so an application that has
     * never heard of this key is unaffected by its existence.
     */
    public static function isAvailable(): bool
    {
        return in_array(self::METHOD, self::allowedMethods(), true);
    }

    /**
     * The second-factor methods this application allows, lower-cased.
     *
     * `totp` is always in the list: an application cannot switch off the method its
     * existing accounts are already enrolled in by adding a config key, which is what
     * omitting it from the list would otherwise mean.
     *
     * @return list<string>
     */
    public static function allowedMethods(): array
    {
        $application = \Pramnos\Application\Application::currentInstance();
        $configured = is_object($application)
            ? ($application->applicationInfo['auth']['twofactor_methods'] ?? null)
            : null;

        if (!is_array($configured)) {
            return ['totp'];
        }

        $methods = [];
        foreach ($configured as $method) {
            if (is_string($method) && $method !== '') {
                $methods[] = strtolower($method);
            }
        }

        if (!in_array('totp', $methods, true)) {
            $methods[] = 'totp';
        }

        return array_values(array_unique($methods));
    }

    /**
     * Has this account got the email factor, and may it be used?
     *
     * False whenever the application has withdrawn the method, whatever the row says —
     * the account's choice is remembered, not honoured, in that state.
     */
    public function isEnabledFor(int $userId): bool
    {
        if ($userId < 2 || !self::isAvailable()) {
            return false;
        }

        try {
            $row = $this->database->queryBuilder()
                ->table('authserver.user_twofactor')
                ->select('email_enabled')
                ->where('userid', $userId)
                ->first();
        } catch (\Throwable $exception) {
            // No table, or a column an installation has not migrated yet. Absent means
            // off: a second factor that cannot be read must not become one that cannot
            // be satisfied, which would lock the account out of its own login.
            return false;
        }

        return $row !== null && (int) ($row->fields['email_enabled'] ?? 0) === 1;
    }

    /**
     * Turn the email factor on or off for one account.
     *
     * Writes the row when the account has none: an account may want a code by mail
     * without ever having enrolled in TOTP, and that is the main case this method has.
     */
    public function setEnabledFor(int $userId, bool $enabled): bool
    {
        if ($userId < 2) {
            return false;
        }

        $now = time();

        try {
            $existing = $this->database->queryBuilder()
                ->table('authserver.user_twofactor')
                ->select('userid')
                ->where('userid', $userId)
                ->first();

            if ($existing !== null && ($existing->numRows ?? 0) > 0) {
                $this->database->queryBuilder()
                    ->table('authserver.user_twofactor')
                    ->where('userid', $userId)
                    ->update([
                        'email_enabled' => $enabled ? 1 : 0,
                        'updated_at'    => $now,
                    ]);
            } else {
                $this->database->queryBuilder()
                    ->table('authserver.user_twofactor')
                    ->insert([
                        'userid'        => $userId,
                        'enabled'       => 0,
                        'email_enabled' => $enabled ? 1 : 0,
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]);
            }

            ActivityLog::record(
                $userId,
                $enabled ? 'twofactor_email_enabled' : 'twofactor_email_disabled'
            );

            return true;
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'EmailSecondFactor::setEnabledFor failed for ' . $userId . ': '
                . $exception->getMessage(),
                'auth'
            );

            return false;
        }
    }

    // ── Issuing ───────────────────────────────────────────────────────────────

    /**
     * Issue a code and mail it. Returns false when there was nothing to send to.
     *
     * The previous code for this purpose is deleted first, so a person who asks twice
     * holds one live code rather than two.
     */
    public function send(int $userId, string $purpose = self::PURPOSE_LOGIN): bool
    {
        if ($userId < 2) {
            return false;
        }

        $user = new \Pramnos\User\User($userId);
        $address = (string) ($user->email ?? '');
        if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        // Refused before anything is generated, so a refused send does not invalidate the
        // code the person is already holding — which is what made repeated clicking worse
        // than useless rather than merely wasteful.
        if (!$this->maySend($userId, $purpose)) {
            return false;
        }

        // Six digits, from the CSPRNG, keeping leading zeros — `random_int` then
        // padding, rather than a string built digit by digit, so the distribution is
        // uniform and obviously so.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $now  = time();

        try {
            $this->database->queryBuilder()
                ->table('authserver.twofactor_email_codes')
                ->where('userid', $userId)
                ->where('purpose', $purpose)
                ->delete();

            $this->database->queryBuilder()
                ->table('authserver.twofactor_email_codes')
                ->insert([
                    'userid'     => $userId,
                    'purpose'    => $purpose,
                    'code_hash'  => $this->hash($code, $userId),
                    'expires_at' => $now + self::TTL,
                    'attempts'   => 0,
                    'created_at' => $now,
                ]);
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'EmailSecondFactor::send could not store a code for ' . $userId . ': '
                . $exception->getMessage(),
                'auth'
            );

            return false;
        }

        // Mailed after the code is stored, never before: a code that reaches somebody
        // and cannot be verified is worse than one that was never sent.
        try {
            (new \Pramnos\Notification\Notifier())->sendNow(
                $user,
                new Notifications\SecondFactorCodeNotification($code, self::TTL)
            );
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'EmailSecondFactor::send could not mail a code to ' . $userId . ': '
                . $exception->getMessage(),
                'auth'
            );

            return false;
        }

        // This row is both the audit trail and the accounting {@see maySend()} reads —
        // the same record on purpose, so the two cannot drift apart. Written after the
        // mail, so a send that failed does not consume the allowance.
        ActivityLog::record($userId, 'twofactor_email_code_sent', ['purpose' => $purpose]);

        return true;
    }

    /**
     * Is there a live code outstanding for this account?
     *
     * For a screen that has to choose between "enter the code we sent" and "send me a
     * code" without sending another one to find out.
     */
    public function hasLiveCode(int $userId, string $purpose = self::PURPOSE_LOGIN): bool
    {
        return $this->liveCode($userId, $purpose) !== null;
    }

    // ── Verifying ─────────────────────────────────────────────────────────────

    /**
     * Verify a code, once.
     *
     * A correct code is deleted before this returns true, so the same code cannot
     * complete two logins. A wrong one counts against the attempt cap, and the code that
     * reaches the cap is deleted rather than left to be guessed at.
     */
    public function verify(int $userId, string $code, string $purpose = self::PURPOSE_LOGIN): bool
    {
        $code = trim($code);
        if ($userId < 2 || $code === '') {
            return false;
        }

        $row = $this->liveCode($userId, $purpose);
        if ($row === null) {
            $this->logAttempt($userId, false);

            return false;
        }

        if (hash_equals((string) $row['code_hash'], $this->hash($code, $userId))) {
            $this->forget((int) $row['id']);
            $this->logAttempt($userId, true);

            return true;
        }

        $attempts = (int) $row['attempts'] + 1;
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->forget((int) $row['id']);
        } else {
            try {
                $this->database->queryBuilder()
                    ->table('authserver.twofactor_email_codes')
                    ->where('id', (int) $row['id'])
                    ->update(['attempts' => $attempts]);
            } catch (\Throwable $exception) {
                // Not fatal: the code still expires. Recorded because a counter that
                // cannot be written turns the cap into a suggestion.
                \Pramnos\Logs\Logger::log(
                    'EmailSecondFactor could not record an attempt for ' . $userId . ': '
                    . $exception->getMessage(),
                    'auth'
                );
            }
        }

        $this->logAttempt($userId, false);

        return false;
    }

    /**
     * Delete every expired code, whoever it belongs to.
     *
     * For the scheduled cleanup that already prunes the setup rows. Codes expire by
     * timestamp whether or not this runs — this only stops the table growing.
     */
    public function cleanupExpired(): void
    {
        try {
            $this->database->queryBuilder()
                ->table('authserver.twofactor_email_codes')
                ->where('expires_at', '<', time())
                ->delete();
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'EmailSecondFactor::cleanupExpired failed: ' . $exception->getMessage(),
                'auth'
            );
        }
    }

    // ── How often a code may be sent ──────────────────────────────────────────

    /**
     * Is another send allowed right now?
     *
     * Two limits, because they answer different abuses: a minimum gap stops a person (or a
     * script) clicking "send another" repeatedly, and a count per window stops the same
     * thing done patiently. Both are per account and per purpose, so an enrolment and a
     * login step-up do not consume each other's allowance.
     *
     * The record lives in the activity log rather than in a table of its own. It is the
     * same question that log exists for — what has been done to this account — and it is
     * the place an operator investigating "why did I get eleven codes" would look.
     */
    public function maySend(int $userId, string $purpose = self::PURPOSE_LOGIN): bool
    {
        $sends = $this->recentSends($userId, $purpose);

        if ($sends === []) {
            return true;
        }

        if ((time() - $sends[0]) < self::RESEND_INTERVAL) {
            return false;
        }

        return count($sends) < self::MAX_SENDS;
    }

    /**
     * Seconds until another send is allowed, or 0 when one is allowed now.
     *
     * For a screen that would rather say "you can ask again in 40 seconds" than refuse
     * without explanation — a refusal with no reason reads as a broken button, and the
     * person clicks it more.
     */
    public function secondsUntilResend(int $userId, string $purpose = self::PURPOSE_LOGIN): int
    {
        $sends = $this->recentSends($userId, $purpose);
        if ($sends === []) {
            return 0;
        }

        $sinceLast = time() - $sends[0];
        if ($sinceLast < self::RESEND_INTERVAL) {
            return self::RESEND_INTERVAL - $sinceLast;
        }

        if (count($sends) < self::MAX_SENDS) {
            return 0;
        }

        // The window is full: the next send becomes possible when the oldest one in it
        // ages out.
        $oldest = $sends[count($sends) - 1];

        return max(1, self::SEND_WINDOW - (time() - $oldest));
    }

    /**
     * Timestamps of recent sends, newest first.
     *
     * @return list<int>
     */
    private function recentSends(int $userId, string $purpose): array
    {
        try {
            // No time filter in SQL, and that is deliberate. `ActivityLog` writes
            // `created_at` with `date('c')` — an ISO-8601 string carrying an offset — while
            // the column is a datetime, and a string comparison between the two formats is
            // right by accident on some rows and wrong on others. Taking the newest few for
            // this action and filtering by a parsed timestamp needs no assumption about
            // either format or the server's timezone.
            $result = $this->database->queryBuilder()
                ->table('authserver.user_activity_log')
                ->select(['details', 'created_at'])
                ->where('userid', $userId)
                ->where('action', 'twofactor_email_code_sent')
                ->orderBy('id', 'desc')
                ->limit(self::MAX_SENDS + 20)
                ->get();
        } catch (\Throwable $exception) {
            // No log, no accounting. Allowing the send is the right failure: the limit
            // exists to prevent nuisance, and refusing every code because a log table is
            // missing would prevent sign-in.
            return array();
        }

        $sends = array();
        if ($result === null) {
            return $sends;
        }

        while (($row = $result->fetch()) !== null) {
            $details = json_decode((string) ($row['details'] ?? ''), true);
            if (is_array($details) && ($details['purpose'] ?? self::PURPOSE_LOGIN) !== $purpose) {
                continue;
            }

            $when = (int) strtotime((string) ($row['created_at'] ?? ''));
            if ($when > 0 && $when > time() - self::SEND_WINDOW) {
                $sends[] = $when;
            }
        }

        return $sends;
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * The live, unexpired row for this account and purpose, or null.
     *
     * @return array<string,mixed>|null
     */
    private function liveCode(int $userId, string $purpose): ?array
    {
        try {
            $row = $this->database->queryBuilder()
                ->table('authserver.twofactor_email_codes')
                ->where('userid', $userId)
                ->where('purpose', $purpose)
                ->where('expires_at', '>=', time())
                ->orderBy('id', 'desc')
                ->first();
        } catch (\Throwable $exception) {
            return null;
        }

        if ($row === null || ($row->numRows ?? 0) === 0) {
            return null;
        }

        return $row->fields;
    }

    private function forget(int $id): void
    {
        try {
            $this->database->queryBuilder()
                ->table('authserver.twofactor_email_codes')
                ->where('id', $id)
                ->delete();
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'EmailSecondFactor could not delete a spent code: ' . $exception->getMessage(),
                'auth'
            );
        }
    }

    /**
     * Keyed by the installation secret and the user id — see the class docblock.
     */
    private function hash(string $code, int $userId): string
    {
        $key = (string) \Pramnos\Application\Settings::getSetting('securitySalt') . '|' . $userId;

        return hash_hmac('sha256', $code, $key);
    }

    /**
     * Record the attempt in the same log the TOTP path uses.
     *
     * The same question — "what has been tried against this account" — so the same
     * table, rather than a second place an investigation has to know about. Best-effort:
     * the log is not worth failing a verification for.
     */
    private function logAttempt(int $userId, bool $success): void
    {
        try {
            $this->database->queryBuilder()
                ->table('authserver.twofactor_attempts')
                ->insert([
                    'userid'       => $userId,
                    'success'      => $success,
                    'ip_address'   => \Pramnos\Http\Request::clientIp() ?: null,
                    // The TOTP path stores a CRC of the code so an attempt can be
                    // recognised without the code being readable. There is nothing to
                    // recognise here — one method, one shape — so the column names the
                    // method instead, which is what an investigation actually reads.
                    'code_used'    => 'EMAIL',
                    'user_agent'   => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                    'attempt_time' => gmdate('Y-m-d H:i:s', time()),
                ]);
        } catch (\Throwable $exception) {
            // Deliberately silent beyond the log: see the docblock.
            \Pramnos\Logs\Logger::log(
                'EmailSecondFactor could not log an attempt: ' . $exception->getMessage(),
                'auth'
            );
        }
    }
}
