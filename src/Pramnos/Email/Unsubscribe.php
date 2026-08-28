<?php

declare(strict_types=1);

namespace Pramnos\Email;

use Pramnos\Application\Settings;

/**
 * Unsubscribe links, the two headers a mailbox provider looks for, and the record they write.
 *
 * Gmail and Yahoo require this of anyone sending in volume, and they are not asking for a
 * gesture: a bulk message has to carry `List-Unsubscribe` **and** `List-Unsubscribe-Post`, the
 * one-click endpoint has to work without a login and without a confirmation step, and the
 * request has to be honoured within two days. A sender who fails that is not blocked with an
 * error — the mail is quietly filed as spam, including the mail people actually wanted.
 *
 * ## The token is signed, not stored
 *
 * ```php
 * $token = Unsubscribe::token('someone@example.com', 'marketing');
 * Unsubscribe::url($token);      // https://site/unsubscribe?u=…
 * Unsubscribe::mailto($token);   // mailto:…?subject=unsubscribe:…
 * ```
 *
 * Nothing is written when a message is sent — a million-recipient send would otherwise write a
 * million rows for links most people never open. The address and the list travel inside the
 * token, signed with the installation's key, so a forged or edited one fails verification and
 * nobody can unsubscribe a stranger by editing a URL.
 *
 * There is no expiry, on purpose. People unsubscribe from a message they found six months
 * later, and «this link has expired» is a sender making its own problem the reader's.
 *
 * ## What a list is
 *
 * A short name the application chooses — `marketing`, `newsletter` — plus the reserved `all`,
 * which suppresses everything that carries a link. **Transactional mail is not on a list**: a
 * password reset, a second-factor code, a receipt. Nobody unsubscribes from being able to sign
 * in, mailbox providers do not ask you to offer it, and an unsubscribe link on such a message
 * teaches people that the link does nothing.
 *
 * A list may also be backed by a preference the person can see — the framework's `newsignin`
 * alerts are — in which case honouring the unsubscribe means flipping *that*, so the checkbox
 * on their profile matches what actually happens. {@see applyOptOut()}.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class Unsubscribe
{
    /** Suppresses every list that carries an unsubscribe link. */
    public const LIST_ALL = 'all';

    /** Where the signing key is kept when the installation has no `securitySalt`. */
    public const SECRET_SETTING = 'unsubscribe_secret';

    /**
     * Application-registered handlers, by list name.
     *
     * @var array<string, callable(string, string): void>
     */
    protected static array $handlers = [];

    /**
     * Register what "unsubscribe from this list" should do.
     *
     * For a list backed by a preference the person can see, flipping that preference is the
     * whole job — a row in `emailoptouts` the profile screen does not know about would stop
     * the mail while the checkbox still said it was on.
     *
     * ```php
     * Unsubscribe::handle('digest', function (string $email, string $list) {
     *     Digest::disableFor($email);
     * });
     * ```
     *
     * @param string $list
     * @param callable(string, string): void $handler Receives the address and the list name
     */
    public static function handle(string $list, callable $handler): void
    {
        static::$handlers[$list] = $handler;
    }

    /** Forget every registered handler. For tests. */
    public static function reset(): void
    {
        static::$handlers = [];
    }

    // ── Tokens ───────────────────────────────────────────────────────────────

    /**
     * A signed token identifying one address and one list.
     */
    public static function token(string $email, string $list = self::LIST_ALL): string
    {
        $email   = static::normalise($email);
        $list    = static::normaliseList($list);
        $payload = $email . '|' . $list;

        return static::encode($payload . '|' . static::signature($payload));
    }

    /**
     * The address and list a token names, or null when it does not verify.
     *
     * @return ?array{email: string, list: string}
     */
    public static function verify(string $token): ?array
    {
        $decoded = static::decode(trim($token));

        if ($decoded === null) {
            return null;
        }

        $parts = explode('|', $decoded);

        if (count($parts) !== 3) {
            return null;
        }

        [$email, $list, $signature] = $parts;

        // hash_equals: a timing-safe comparison, because the alternative leaks the signature
        // one byte at a time to anybody willing to ask enough times.
        if (!hash_equals(static::signature($email . '|' . $list), $signature)) {
            return null;
        }

        return ['email' => $email, 'list' => $list];
    }

    /**
     * The one-click endpoint's URL for a token.
     */
    public static function url(string $token): string
    {
        $base = defined('sURL') ? (string) sURL : '';

        return rtrim($base, '/') . '/unsubscribe?u=' . urlencode($token);
    }

    /**
     * The `mailto:` alternative, for a client that offers no HTTP.
     *
     * Both go in the header. A provider picks whichever it supports, and the mailto is what
     * keeps the header useful in a mail client from before RFC 8058.
     */
    public static function mailto(string $token): string
    {
        $address = (string) (Settings::getSetting('admin_replymail')
            ?: Settings::getSetting('admin_mail') ?: '');

        if ($address === '') {
            return '';
        }

        return 'mailto:' . $address . '?subject=' . rawurlencode('unsubscribe:' . $token);
    }

    // ── The record ───────────────────────────────────────────────────────────

    /**
     * Record an unsubscribe, and run whatever the list says it means.
     *
     * The row is written first and the handler second, because the row is what
     * {@see isOptedOut()} reads: a handler that throws must not leave the request unrecorded.
     *
     * @param  string $email
     * @param  string $list
     * @param  string $source `one_click`, `page`, `admin` or `import`
     * @return bool   False only when the record could not be written
     */
    public static function optOut(string $email, string $list = self::LIST_ALL, string $source = 'page'): bool
    {
        $email = static::normalise($email);
        $list  = static::normaliseList($list);

        if ($email === '') {
            return false;
        }

        $recorded = static::record($email, $list, $source);
        static::applyOptOut($email, $list);

        return $recorded;
    }

    /**
     * Has this address asked to stop receiving this list?
     *
     * True for a row naming the list, and for one naming `all`.
     *
     * **Answers true when it cannot tell**, which is the opposite of how most of this
     * framework fails. Sending to somebody who unsubscribed is the one mistake a mailbox
     * provider counts against every future message, including the transactional mail this
     * method is never asked about. A message not sent during a database outage is a message
     * the next run sends.
     */
    public static function isOptedOut(string $email, string $list = self::LIST_ALL): bool
    {
        $email = static::normalise($email);

        if ($email === '') {
            return false;
        }

        try {
            return (bool) \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('#PREFIX#emailoptouts')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->whereIn('list', [static::normaliseList($list), self::LIST_ALL])
                ->exists();
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'Could not read email opt-outs (' . $exception->getMessage()
                . '). Treating the address as unsubscribed, because sending to somebody who '
                . 'asked us not to is the more expensive mistake.',
                'email'
            );

            return true;
        }
    }

    /**
     * Undo an opt-out — an address asking to hear from us again.
     */
    public static function optIn(string $email, string $list = self::LIST_ALL): bool
    {
        $email = static::normalise($email);

        if ($email === '') {
            return false;
        }

        try {
            \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('#PREFIX#emailoptouts')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->whereIn('list', [static::normaliseList($list), self::LIST_ALL])
                ->delete();

            return true;
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'Could not clear an email opt-out: ' . $exception->getMessage(),
                'email'
            );

            return false;
        }
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * What unsubscribing from this list does beyond the record.
     *
     * The framework's own `newsignin` list is backed by a checkbox on the account's privacy
     * screen, so honouring the request means turning *that* off. Leaving it on while a row
     * elsewhere suppressed the mail would show somebody a switch that lies to them.
     */
    protected static function applyOptOut(string $email, string $list): void
    {
        if (isset(static::$handlers[$list])) {
            try {
                (static::$handlers[$list])($email, $list);
            } catch (\Throwable $exception) {
                \Pramnos\Logs\Logger::log(
                    'An unsubscribe handler for "' . $list . '" failed: '
                    . $exception->getMessage(),
                    'email'
                );
            }

            return;
        }

        if ($list === 'newsignin' || $list === self::LIST_ALL) {
            static::disableNewSignInAlerts($email);
        }
    }

    /**
     * Turn the account's new-sign-in alert preference off, if there is an account.
     */
    protected static function disableNewSignInAlerts(string $email): void
    {
        try {
            $userId = \Pramnos\User\User::getuserid($email, 'email');

            if ($userId !== false && (int) $userId > 0) {
                \Pramnos\Auth\NewSignInAlert::setEnabledFor((int) $userId, false);
            }
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'Could not turn off new-sign-in alerts for an unsubscribe: '
                . $exception->getMessage(),
                'email'
            );
        }
    }

    /**
     * Write the row, ignoring a repeat.
     */
    protected static function record(string $email, string $list, string $source): bool
    {
        try {
            $db = \Pramnos\Framework\Factory::getDatabase();

            $exists = $db->queryBuilder()
                ->table('#PREFIX#emailoptouts')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->where('list', $list)
                ->exists();

            if ($exists) {
                // Already recorded. Not an error and not worth a second row: the answer to
                // "may we mail this address" does not become more true when asked twice.
                return true;
            }

            $db->queryBuilder()->table('#PREFIX#emailoptouts')->insert([
                'email'      => $email,
                'list'       => $list,
                'source'     => substr($source, 0, 32),
                'created_at' => time(),
            ]);

            return true;
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'Could not record an unsubscribe for ' . $email . ': ' . $exception->getMessage(),
                'email'
            );

            return false;
        }
    }

    private static function normalise(string $email): string
    {
        return strtolower(trim($email));
    }

    private static function normaliseList(string $list): string
    {
        $list = strtolower(trim($list));

        return $list === '' ? self::LIST_ALL : $list;
    }

    private static function signature(string $payload): string
    {
        return substr(hash_hmac('sha256', $payload, static::secret()), 0, 32);
    }

    /**
     * URL-safe base64, so a token survives being pasted, wrapped and re-linked.
     */
    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * The signing key: the installation's security salt, or a generated one kept in settings.
     *
     * The same arrangement `HumanCheck` uses, and for the same reason — a per-request random
     * key would sign tokens nothing can verify afterwards, which for an unsubscribe link means
     * every one of them stops working the moment it is clicked.
     */
    protected static function secret(): string
    {
        $salt = Settings::getSetting('securitySalt');

        if (is_string($salt) && $salt !== '') {
            return $salt;
        }

        $stored = Settings::getSetting(self::SECRET_SETTING);

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $generated = bin2hex(random_bytes(32));

        try {
            Settings::setSetting(self::SECRET_SETTING, $generated);
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'Unsubscribe could not store a signing key (' . $exception->getMessage()
                . '): links already sent will stop verifying until this installation has a '
                . 'securitySalt or a ' . self::SECRET_SETTING . ' setting.',
                'email'
            );
        }

        return $generated;
    }
}
