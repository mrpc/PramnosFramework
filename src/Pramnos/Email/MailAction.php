<?php

declare(strict_types=1);

namespace Pramnos\Email;

/**
 * One-click actions from an email, and the tokens that authorise them.
 *
 * A Gmail `ConfirmAction` — the button that appears beside the subject in the message list —
 * requires a URL that does the thing **on the first request, with no confirmation page and no
 * sign-in**, because Gmail issues one POST and does not follow up. There was nowhere to point
 * such an action, so none was offered.
 *
 * The framework already had exactly one endpoint of this shape: RFC 8058 one-click unsubscribe.
 * This is that shape, generalised, so an application can add its own in three lines instead of
 * writing a controller, a token format and a signature check.
 *
 * ```php
 * // once, in a service provider
 * MailAction::register('confirm-order', function (array $claim): bool {
 *     return (new Order((int) $claim['order']))->confirm();
 * });
 *
 * // in the mail
 * $url = MailAction::url('confirm-order', ['order' => 42], 172800);
 * $mail->addStructuredData(Actions::confirm('Confirm order', $url));
 * ```
 *
 * **The token is the whole authorisation.** There is no session and no CSRF token, because the
 * caller is a mailbox provider's server and neither exists. That is not a weakness introduced
 * here — it is the same property a password-reset link has always had — but it decides
 * everything below: the token is signed, it expires, it names one action and one payload, and
 * it must never authorise something that cannot safely be done by whoever holds the message.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class MailAction
{
    /** Where the shared secret lives, alongside the unsubscribe one. */
    public const SECRET_SETTING = 'mailaction_secret';

    /** How long a token lasts when the caller does not say. Two days. */
    public const DEFAULT_TTL = 172800;

    /**
     * Registered handlers, by action name.
     *
     * @var array<string, array{handler: callable, get: bool, label: string}>
     */
    private static array $handlers = [];

    /**
     * Register a handler.
     *
     * The handler receives the payload the URL was built with and returns whether it worked.
     * `false` becomes a 500 for a machine — which is correct, because a mailbox provider retries
     * a 500, and the usual reason a handler fails is a database that was briefly away.
     *
     * **It must be idempotent.** Gmail retries, a reader may press the button twice, and a mail
     * client may prefetch. Confirming an already-confirmed thing has to be a success, not an
     * error and not a second confirmation.
     *
     * @param string   $action  A short name, `[a-z0-9-]`, that appears in the URL
     * @param callable $handler `fn(array $claim): bool`
     * @param bool     $actOnGet Whether a GET may perform it — see {@see dispatch()}
     * @param string   $label   What a browser is told happened, when a person follows the link
     */
    public static function register(
        string $action,
        callable $handler,
        bool $actOnGet = false,
        string $label = ''
    ): void {
        self::$handlers[self::normalise($action)] = [
            'handler' => $handler,
            'get'     => $actOnGet,
            'label'   => $label,
        ];
    }

    /** Forget every handler. For tests, and for a request that rebuilds the container. */
    public static function reset(): void
    {
        self::$handlers = [];
    }

    /**
     * The registered action names.
     *
     * @return list<string>
     */
    public static function registered(): array
    {
        return array_keys(self::$handlers);
    }

    /**
     * Is this action registered?
     */
    public static function has(string $action): bool
    {
        return isset(self::$handlers[self::normalise($action)]);
    }

    // ── Tokens ───────────────────────────────────────────────────────────────

    /**
     * A signed token naming one action, one payload and an expiry.
     *
     * The expiry is *inside* the signed material rather than beside it, which is the difference
     * between an expiry and a suggestion: a value outside the signature is one a holder can
     * change.
     *
     * @param  array<string, scalar> $claim  Whatever the handler needs. Not secret — it is
     *                                       readable by anybody holding the token.
     * @param  ?int                  $ttl    Seconds, or null for {@see DEFAULT_TTL}
     * @return string
     */
    public static function token(string $action, array $claim = [], ?int $ttl = null): string
    {
        $expires = time() + ($ttl ?? self::DEFAULT_TTL);
        $payload = self::normalise($action) . '|' . $expires . '|' . self::pack($claim);

        return self::encode($payload . '|' . self::signature($payload));
    }

    /**
     * What a token names, or null when it does not verify.
     *
     * Null for a bad signature, a malformed token *and* an expired one — deliberately one
     * answer, because a caller that distinguished them would tell somebody probing exactly how
     * close they are. The expiry is reported separately by {@see expired()} for the one caller
     * that has a legitimate reason to say "this link has expired" to a person.
     *
     * @return ?array{action: string, expires: int, claim: array<string, string>}
     */
    public static function verify(string $token): ?array
    {
        $decoded = self::decode(trim($token));

        if ($decoded === null) {
            return null;
        }

        $parts = explode('|', $decoded);

        if (count($parts) !== 4) {
            return null;
        }

        [$action, $expires, $packed, $signature] = $parts;

        // hash_equals: timing-safe, because the alternative leaks the signature one byte at a
        // time to anybody willing to ask often enough.
        if (!hash_equals(self::signature($action . '|' . $expires . '|' . $packed), $signature)) {
            return null;
        }

        if ((int) $expires < time()) {
            return null;
        }

        return [
            'action'  => $action,
            'expires' => (int) $expires,
            'claim'   => self::unpack($packed),
        ];
    }

    /**
     * Did this token verify but expire?
     *
     * For the page a person sees. "This link has expired, ask for another" is useful and safe
     * to say; the same sentence about a *forged* token would be a hint.
     */
    public static function expired(string $token): bool
    {
        $decoded = self::decode(trim($token));

        if ($decoded === null) {
            return false;
        }

        $parts = explode('|', $decoded);

        if (count($parts) !== 4) {
            return false;
        }

        [$action, $expires, $packed, $signature] = $parts;

        return hash_equals(self::signature($action . '|' . $expires . '|' . $packed), $signature)
            && (int) $expires < time();
    }

    /**
     * The URL for an action, ready to put in a message.
     *
     * @param array<string, scalar> $claim
     */
    public static function url(string $action, array $claim = [], ?int $ttl = null): string
    {
        $base = defined('sURL') ? rtrim((string) sURL, '/') : '';

        return $base . '/mailaction?a=' . urlencode(self::token($action, $claim, $ttl));
    }

    // ── Running one ──────────────────────────────────────────────────────────

    /**
     * Run the action a token names.
     *
     * The `$isPost` distinction is the safety property, and it is not ceremony. A GET is issued
     * by things nobody asked: a link scanner in a corporate mail gateway, a client prefetching
     * to render a preview, an antivirus proxy. If a GET performed the action, those would
     * perform it — so by default it does not, and a person following the visible link is shown a
     * page with a button instead. An action whose effect is safe to trigger that way opts in
     * with `$actOnGet` at registration.
     *
     * Gmail sends a POST, so the button works either way.
     *
     * @return array{status: int, message: string, action: string}
     */
    public static function dispatch(string $token, bool $isPost): array
    {
        $claim = self::verify($token);

        if ($claim === null) {
            return self::expired($token)
                ? [
                    'status'  => 410,
                    'message' => 'This link has expired. Ask for a new one.',
                    'action'  => '',
                ]
                : [
                    'status'  => 400,
                    'message' => 'This link is not valid.',
                    'action'  => '',
                ];
        }

        $action = $claim['action'];

        if (!isset(self::$handlers[$action])) {
            /*
             * A valid token for an action nothing handles. Almost always a handler registered in
             * a service provider that did not run — a feature switched off, a provider removed —
             * and reporting it as "not valid" would send somebody looking at the token instead
             * of at the registration.
             */
            return [
                'status'  => 501,
                'message' => 'Nothing here handles "' . $action . '". The handler is registered '
                    . 'in a service provider; check that it ran.',
                'action'  => $action,
            ];
        }

        $registered = self::$handlers[$action];

        if (!$isPost && !$registered['get']) {
            return [
                'status'  => 405,
                'message' => 'This action needs to be confirmed.',
                'action'  => $action,
            ];
        }

        try {
            $done = (bool) ($registered['handler'])($claim['claim']);
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'Mail action "' . $action . '" threw: ' . $exception->getMessage(),
                'mailaction'
            );

            // 500 so a provider retries: the usual cause is a database that was briefly away,
            // and that is exactly the case retrying fixes.
            return [
                'status'  => 500,
                'message' => 'Could not complete this. Please try again.',
                'action'  => $action,
            ];
        }

        if (!$done) {
            return [
                'status'  => 500,
                'message' => 'Could not complete this. Please try again.',
                'action'  => $action,
            ];
        }

        return [
            'status'  => 200,
            'message' => $registered['label'] !== '' ? $registered['label'] : 'Done.',
            'action'  => $action,
        ];
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * The signing key.
     *
     * Generated and stored on first use, like the unsubscribe secret. Rotating it invalidates
     * every outstanding link, which is the correct behaviour and worth knowing before you do it.
     */
    protected static function secret(): string
    {
        $stored = (string) (\Pramnos\Application\Settings::getSetting(self::SECRET_SETTING) ?? '');

        if ($stored !== '') {
            return $stored;
        }

        $secret = bin2hex(random_bytes(32));

        try {
            \Pramnos\Application\Settings::setSetting(self::SECRET_SETTING, $secret, true);
        } catch (\Throwable) {
            /*
             * No settings store yet — during an install, or in a test. Falling back to a
             * per-process value keeps `token()` and `verify()` agreeing with each other inside
             * one request, which is what the tests need, and the link simply does not survive
             * the process. Better than a fatal in the middle of sending mail.
             */
            static $ephemeral = null;

            return $ephemeral ??= bin2hex(random_bytes(32));
        }

        return $secret;
    }

    protected static function signature(string $payload): string
    {
        return substr(hash_hmac('sha256', $payload, self::secret()), 0, 32);
    }

    /** URL-safe base64: `+/=` do not survive being pasted out of a mail client. */
    protected static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected static function decode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * The payload as one string, without the separators the token format uses.
     *
     * @param array<string, scalar> $claim
     */
    protected static function pack(array $claim): string
    {
        $pairs = [];

        foreach ($claim as $key => $value) {
            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        return implode('&', $pairs);
    }

    /** @return array<string, string> */
    protected static function unpack(string $packed): array
    {
        $claim = [];

        foreach (explode('&', $packed) as $pair) {
            if ($pair === '') {
                continue;
            }

            $parts = explode('=', $pair, 2);
            $claim[rawurldecode($parts[0])] = rawurldecode($parts[1] ?? '');
        }

        return $claim;
    }

    protected static function normalise(string $action): string
    {
        return strtolower((string) preg_replace('~[^A-Za-z0-9._-]+~', '-', trim($action)));
    }
}
