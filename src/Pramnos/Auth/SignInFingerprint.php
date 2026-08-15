<?php

namespace Pramnos\Auth;

/**
 * A deliberately coarse identifier for "which browser, on which kind of machine".
 *
 * This exists to answer one question — *have we seen this person sign in like this
 * before?* — and the hard part is not telling two browsers apart. It is **not firing
 * constantly**, because a security notification people learn to ignore is worse than
 * no notification at all.
 *
 * ## What it deliberately does not use
 *
 * **The IP address.** Consumer connections are dynamic in most of the world; a
 * new-IP alarm fires on a router reboot, and by the second week nobody reads it.
 *
 * **The browser version.** Chrome ships a major version roughly every four weeks,
 * and Firefox every four. A fingerprint that includes the version is a monthly alarm
 * for every user — the dynamic-IP problem one step removed, and less obvious, which
 * is why it is worth stating rather than discovering.
 *
 * **The full user-agent string.** It carries the version, the platform build number
 * and sometimes the device model. It changes with every OS point release.
 *
 * ## What it uses
 *
 * The browser family and the platform family, and nothing else:
 *
 * ```
 * Mozilla/5.0 (Windows NT 10.0; Win64; x64) … Chrome/121.0.0.0 Safari/537.36
 *   → "chrome|windows"
 * Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) … Version/17.2 Mobile/… Safari/…
 *   → "safari|ios"
 * ```
 *
 * Those are stable for years, and they change exactly when a person would say *"I
 * signed in from somewhere new"*: a different browser, or a different kind of device.
 *
 * **The cost, stated plainly:** two Chrome-on-Windows machines are indistinguishable,
 * so signing in from a colleague's identical laptop raises nothing. That is the price
 * of an alarm that stays meaningful, and it is the right side of the trade for a
 * notification whose whole value is that it is rare.
 *
 * An application that wants finer granularity should not narrow this — it should add
 * a signed device cookie, which is precise, survives browser updates, and is the tool
 * built for the job.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class SignInFingerprint
{
    /**
     * Browser families, in matching order.
     *
     * Order matters and is the usual user-agent tar pit: Edge announces itself as
     * Chrome, Chrome announces itself as Safari, and every one of them says Mozilla.
     * The most specific token has to be tested first or everything collapses into
     * "safari".
     *
     * @var array<int, array{0: string, 1: string}> [needle, family]
     */
    private const BROWSERS = array(
        array('edg/', 'edge'),
        array('edge/', 'edge'),
        array('opr/', 'opera'),
        array('opera', 'opera'),
        array('samsungbrowser', 'samsung'),
        array('firefox/', 'firefox'),
        array('fxios/', 'firefox'),
        array('crios/', 'chrome'),
        array('chromium/', 'chrome'),
        array('chrome/', 'chrome'),
        array('safari/', 'safari'),
    );

    /**
     * Platform families, in matching order.
     *
     * `iphone`/`ipad` before `mac`, because iOS user agents name Mac OS X. `android`
     * before `linux`, for the same reason.
     *
     * @var array<int, array{0: string, 1: string}> [needle, family]
     */
    private const PLATFORMS = array(
        array('iphone', 'ios'),
        array('ipad', 'ios'),
        array('ipod', 'ios'),
        array('android', 'android'),
        array('windows', 'windows'),
        array('macintosh', 'mac'),
        array('mac os x', 'mac'),
        array('cros', 'chromeos'),
        array('linux', 'linux'),
    );

    /**
     * The fingerprint for a user-agent string.
     *
     * @param  string|null $userAgent The `User-Agent` header, or null when absent
     * @return string A stable key such as `chrome|windows`, or `unknown|unknown`
     */
    public static function fromUserAgent(?string $userAgent): string
    {
        $ua = strtolower(trim((string) $userAgent));

        if ($ua === '') {
            // A request with no user agent at all: a script, a stripped proxy, a
            // privacy tool. Grouped together rather than treated as new every time —
            // they are indistinguishable from each other, so calling each one new
            // would make this fire on every such sign-in forever.
            return 'unknown|unknown';
        }

        return self::match(self::BROWSERS, $ua) . '|' . self::match(self::PLATFORMS, $ua);
    }

    /**
     * The fingerprint for the request being served.
     *
     * @return string
     */
    public static function current(): string
    {
        return self::fromUserAgent($_SERVER['HTTP_USER_AGENT'] ?? null);
    }

    /**
     * A human-readable form, for the body of a notification.
     *
     * `chrome|windows` means nothing to the person reading the email; *"Chrome on
     * Windows"* is the whole point of sending it.
     *
     * @param  string $fingerprint As returned by {@see fromUserAgent()}
     * @return string
     */
    public static function describe(string $fingerprint): string
    {
        $labels = array(
            'edge' => 'Edge', 'opera' => 'Opera', 'samsung' => 'Samsung Internet',
            'firefox' => 'Firefox', 'chrome' => 'Chrome', 'safari' => 'Safari',
            'ios' => 'iPhone or iPad', 'android' => 'Android', 'windows' => 'Windows',
            'mac' => 'Mac', 'chromeos' => 'ChromeOS', 'linux' => 'Linux',
        );

        $parts   = explode('|', $fingerprint);
        $browser = $labels[$parts[0] ?? ''] ?? null;
        $platform = $labels[$parts[1] ?? ''] ?? null;

        if ($browser === null && $platform === null) {
            return 'an unrecognised browser';
        }
        if ($platform === null) {
            return $browser;
        }
        if ($browser === null) {
            return 'an unrecognised browser on ' . $platform;
        }

        return $browser . ' on ' . $platform;
    }

    /**
     * First matching family, or `unknown`.
     *
     * @param  array<int, array{0: string, 1: string}> $table Needle/family pairs
     * @param  string                                  $ua    Lower-cased user agent
     * @return string
     */
    private static function match(array $table, string $ua): string
    {
        foreach ($table as $entry) {
            if (strpos($ua, $entry[0]) !== false) {
                return $entry[1];
            }
        }

        return 'unknown';
    }
}
