<?php

declare(strict_types=1);

namespace Pramnos\Email;

/**
 * Opens and clicks, for mail the reader agreed to receive.
 *
 * **Off unless three things are true**, and that is the design rather than a precaution:
 *
 * 1. `email.tracking` is on in `app.php`. Absent means off.
 * 2. The message belongs to a **list** — it has an unsubscribe list, which is what "the reader
 *    agreed to this" means here. Transactional mail is never tracked: nobody consents to a
 *    password reset, and a pixel in one is a pixel in the most sensitive message you send.
 * 3. The caller asked, per message, with {@see Email::enableTracking()}.
 *
 * ### What an open actually tells you
 *
 * Less than it used to, and the numbers here are shaped to say so rather than to flatter.
 *
 * **Apple Mail Privacy Protection** — on by default since iOS 15 — fetches every remote image
 * through Apple's proxy the moment a message *arrives*, whether or not anybody ever opens it.
 * Left uncorrected that reports an open for every Apple recipient, minutes after delivery.
 * **Gmail** proxies and caches images, so the fetch comes from Google, later opens may never
 * reach you, and the IP tells you nothing about the reader. And plenty of clients block remote
 * images entirely, so a real open records nothing at all.
 *
 * So `opens` and `proxy_opens` are counted **separately** and never added together. A column
 * that mixes them is how a message nobody read is reported at a 70% open rate.
 *
 * A **click** has none of these problems. No proxy follows a link and no scanner submits one on
 * a reader's behalf — a click is a deliberate act by a person. It is the signal worth having.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class Tracking
{
    /** Where the pixel is served. */
    public const PIXEL_PATH = '/emailpixel';

    /** Where a wrapped link is served. */
    public const CLICK_PATH = '/emailclick';

    /**
     * User-agent fragments that mean "a mailbox provider fetched this", not "somebody read it".
     *
     * Matched on the user agent rather than on IP ranges: the ranges change without notice and
     * a stale list quietly turns proxy fetches back into opens, which is the failure this whole
     * distinction exists to prevent. A proxy that stops identifying itself will be counted as a
     * reader — that is the honest limit, and it is recorded in the guide.
     *
     * @var list<string>
     */
    private const PROXIES = [
        'GoogleImageProxy',
        'YahooMailProxy',
        'Barracuda',
        'ProofpointURLDefense',
        'Mimecast',
        // Apple Mail Privacy Protection fetches identify as Safari on macOS, from Apple's
        // network, with no Accept-Language. The user agent alone cannot name them, so the IP
        // check below carries that one.
    ];

    /**
     * Networks that fetch on delivery rather than on reading.
     *
     * Only the two that matter and only as a prefix match, because a full CIDR implementation
     * for two ranges is more code than the question deserves.
     *
     * @var list<string>
     */
    private const PROXY_NETWORKS = ['17.', '66.102.', '66.249.', '64.233.', '72.14.', '209.85.'];

    /**
     * Is tracking switched on for this installation?
     */
    public static function enabled(): bool
    {
        $configured = \Pramnos\Application\Application::currentInstance()
            ?->applicationInfo['email']['tracking'] ?? null;

        return $configured === true;
    }

    /**
     * May this message be tracked?
     *
     * The gate that matters. A message with no list is transactional, and transactional mail is
     * not tracked at any setting — the reader did not agree to anything, and the messages in
     * question are password resets and second-factor codes.
     */
    public static function allowed(string $list): bool
    {
        return self::enabled() && trim($list) !== '';
    }

    /**
     * Start tracking one message, and return its id.
     *
     * @param  string $recipient Who it went to
     * @param  string $list      The list it belongs to
     * @param  string $subject   For a report that can be read without a join
     * @param  ?int   $mailId    The `mails` row, when the send was recorded
     * @param  string $trackingId The id to record it under; one is generated when empty
     * @return bool   Whether a row was written — and therefore whether anything is measurable
     */
    public static function begin(
        string $recipient,
        string $list,
        string $subject = '',
        ?int $mailId = null,
        string $trackingId = ''
    ): bool {
        if (!self::allowed($list)) {
            return false;
        }

        if (trim($trackingId) === '') {
            $trackingId = bin2hex(random_bytes(16));
        }

        try {
            \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('pramnos.emailtracking')
                ->insert([
                    'tracking_id' => $trackingId,
                    'mailid'      => $mailId,
                    'recipient'   => substr($recipient, 0, 190),
                    'list'        => substr($list, 0, 64),
                    'subject'     => substr($subject, 0, 255),
                    'sent_at'     => time(),
                ]);
        } catch (\Throwable $exception) {
            // A message that cannot be tracked is still a message that must be sent.
            \Pramnos\Logs\Logger::log(
                'Could not start email tracking: ' . $exception->getMessage(),
                'email'
            );

            return false;
        }

        return true;
    }

    /**
     * The pixel's markup for a tracking id.
     *
     * `width="1" height="1"` and an empty `alt`, so a client that blocks images shows nothing
     * rather than a broken-image icon in the middle of the message.
     */
    public static function pixel(string $trackingId): string
    {
        if ($trackingId === '') {
            return '';
        }

        return '<img src="' . htmlspecialchars(self::pixelUrl($trackingId), ENT_QUOTES)
            . '" alt="" width="1" height="1" style="display:none;border:0" />';
    }

    /** The absolute URL the pixel points at. */
    public static function pixelUrl(string $trackingId): string
    {
        return self::base() . self::PIXEL_PATH . '?t=' . urlencode($trackingId);
    }

    /**
     * A link, wrapped so that following it is recorded.
     *
     * The destination is **inside the signed token**, not a query parameter. A tracker that takes
     * its destination from the URL is an open redirect, and an open redirect on a domain that
     * sends mail is a phishing kit somebody else gets to use.
     */
    public static function link(string $trackingId, string $destination): string
    {
        $destination = trim($destination);

        if ($trackingId === '' || $destination === '') {
            return $destination;
        }

        $token = MailAction::token('click', ['t' => $trackingId, 'u' => $destination], 2592000);

        return self::base() . self::CLICK_PATH . '?c=' . urlencode($token);
    }

    /**
     * Rewrite every `http(s)` link in a message body so clicks are recorded.
     *
     * Left alone: `mailto:`, `tel:`, in-page anchors, and **the unsubscribe link**. That last one
     * is not an oversight — a reader unsubscribing is exercising a right, and routing it through
     * a tracker is both distasteful and a way to break the one link a mailbox provider tests.
     */
    public static function wrapLinks(string $html, string $trackingId, string $unsubscribeUrl = ''): string
    {
        if ($trackingId === '' || trim($html) === '') {
            return $html;
        }

        return (string) preg_replace_callback(
            '~href="(https?://[^"]+)"~i',
            static function (array $match) use ($trackingId, $unsubscribeUrl): string {
                $url = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');

                if ($unsubscribeUrl !== '' && str_starts_with($url, $unsubscribeUrl)) {
                    return $match[0];
                }

                // Already wrapped — a body rendered twice, or a template that called this itself.
                if (str_contains($url, self::CLICK_PATH . '?c=')) {
                    return $match[0];
                }

                return 'href="' . htmlspecialchars(self::link($trackingId, $url), ENT_QUOTES) . '"';
            },
            $html
        );
    }

    // ── Recording ────────────────────────────────────────────────────────────

    /**
     * Record a fetch of the pixel.
     *
     * Returns whether it was counted as a person. A proxy fetch is recorded too — knowing that
     * a message reached an Apple mailbox is worth something — but in its own column.
     */
    public static function recordOpen(string $trackingId, string $userAgent = '', string $ip = ''): bool
    {
        if ($trackingId === '') {
            return false;
        }

        $isProxy = self::looksLikeAProxy($userAgent, $ip);
        $now     = time();

        try {
            $builder = \Pramnos\Framework\Factory::getDatabase()->queryBuilder();

            if ($isProxy) {
                $builder->table('pramnos.emailtracking')
                    ->where('tracking_id', $trackingId)
                    ->update(['proxy_opens' => new \Pramnos\Database\Expression('proxy_opens + 1')]);

                return false;
            }

            $builder->table('pramnos.emailtracking')
                ->where('tracking_id', $trackingId)
                ->update([
                    'opens'        => new \Pramnos\Database\Expression('opens + 1'),
                    'last_open_at' => $now,
                    'first_open_at' => new \Pramnos\Database\Expression(
                        'COALESCE(first_open_at, ' . $now . ')'
                    ),
                ]);
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'Could not record an email open: ' . $exception->getMessage(),
                'email'
            );

            return false;
        }

        return true;
    }

    /**
     * Record a click, and answer with where to send them.
     *
     * @return string The destination, or '' when the token does not verify
     */
    public static function recordClick(string $token): string
    {
        $claim = MailAction::verify($token);

        if ($claim === null || $claim['action'] !== 'click') {
            return '';
        }

        $trackingId  = (string) ($claim['claim']['t'] ?? '');
        $destination = (string) ($claim['claim']['u'] ?? '');

        if ($destination === '' || !preg_match('~^https?://~i', $destination)) {
            return '';
        }

        $now = time();

        try {
            $db = \Pramnos\Framework\Factory::getDatabase();

            $db->queryBuilder()->table('pramnos.emailtracking')
                ->where('tracking_id', $trackingId)
                ->update([
                    'clicks'         => new \Pramnos\Database\Expression('clicks + 1'),
                    'last_click_at'  => $now,
                    'first_click_at' => new \Pramnos\Database\Expression(
                        'COALESCE(first_click_at, ' . $now . ')'
                    ),
                ]);

            $db->queryBuilder()->table('pramnos.emailtrackingclicks')->insert([
                'tracking_id' => $trackingId,
                'url'         => substr($destination, 0, 500),
                'clicked_at'  => $now,
            ]);
        } catch (\Throwable $exception) {
            /*
             * Recorded or not, the reader is going where they meant to go. A tracker that can
             * break a link is worse than no tracker: the click is the thing that mattered, and
             * the measurement is the thing that did not.
             */
            \Pramnos\Logs\Logger::log(
                'Could not record an email click: ' . $exception->getMessage(),
                'email'
            );
        }

        return $destination;
    }

    /**
     * Does this fetch look like a mailbox provider rather than a person?
     */
    public static function looksLikeAProxy(string $userAgent, string $ip): bool
    {
        foreach (self::PROXIES as $needle) {
            if (stripos($userAgent, $needle) !== false) {
                return true;
            }
        }

        foreach (self::PROXY_NETWORKS as $prefix) {
            if (str_starts_with($ip, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function base(): string
    {
        $configured = (string) \Pramnos\Application\Settings::getSetting('site_url');

        return rtrim($configured !== '' ? $configured : (defined('sURL') ? (string) sURL : ''), '/');
    }
}
