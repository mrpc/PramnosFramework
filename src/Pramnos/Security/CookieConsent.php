<?php

declare(strict_types=1);

namespace Pramnos\Security;

use Pramnos\Application\Settings;

/**
 * EU cookie consent: the server half of `assets/js/pf-consent.js`.
 *
 * The browser owns the decision. The script shows the banner, keeps the choice in
 * the `pf_consent` cookie and releases the scripts a visitor agreed to. This
 * class does the three things that need the server:
 *
 * - {@see tag()} emits the `<script>` element with the site's configuration, so a
 *   theme footer is one line;
 * - {@see allows()} answers "may I set this cookie?" for PHP code, from the same
 *   cookie the script wrote;
 * - {@see record()} writes a signed-in visitor's choice to
 *   `authserver.user_consents`, the framework's append-only consent trail.
 *
 * **Why the banner is decided in the browser.** The page cache keys on the URL and
 * not on cookies, so markup that differs per visitor would be served to the wrong
 * one. The tag is identical for everybody; the script reads the cookie and decides.
 * For the same reason the server never sets the cookie itself — `PageCache` refuses
 * any response carrying `Set-Cookie`.
 */
final class CookieConsent
{
    /** The cookie the script writes and {@see allows()} reads. */
    public const COOKIE = 'pf_consent';

    /** Always allowed and never asked about: the cookies the site cannot work without. */
    public const NECESSARY = 'necessary';

    /** The optional categories a site may ask about, in the order they are shown. */
    public const KNOWN_CATEGORIES = ['preferences', 'analytics', 'marketing'];

    /** `1` shows the banner (the default), `0` turns the feature off. */
    public const ENABLED_SETTING = 'cookie_consent_enabled';

    /** Comma-separated subset of {@see KNOWN_CATEGORIES} this site asks about. */
    public const CATEGORIES_SETTING = 'cookie_consent_categories';

    /** Where the banner's "cookie policy" link points; empty for no link. */
    public const POLICY_URL_SETTING = 'cookie_consent_policy_url';

    /**
     * The policy's version. Changing it asks everybody again — a choice made
     * under one policy is not consent to the next.
     */
    public const VERSION_SETTING = 'cookie_consent_version';

    /** How long a choice lasts. 180 days is the CNIL's recommendation; the EDPB cites it too. */
    public const LIFETIME_DAYS = 180;

    /**
     * Whether the banner is on for this site.
     *
     * Off when `app/app.php` says `'cookie_consent' => false`, whatever the setting says;
     * otherwise the setting decides, and it defaults to on.
     */
    public static function enabled(): bool
    {
        if (self::offInApp()) {
            return false;
        }

        return (string) Settings::getSetting(self::ENABLED_SETTING, '1') !== '0';
    }

    /**
     * Whether the application turned the feature off in `app/app.php`.
     *
     * A project that answered "no" to the question in `init` has
     * `'cookie_consent' => false`. That is a property of the deployment, versioned with
     * the code, so the settings screen shows it and cannot override it — a switch on a
     * live server should not be able to add a banner to a site whose theme was never
     * reviewed for one.
     */
    public static function offInApp(): bool
    {
        $application = \Pramnos\Application\Application::currentInstance();

        return is_object($application)
            && ($application->applicationInfo['cookie_consent'] ?? true) === false;
    }

    /**
     * The optional categories this site asks about.
     *
     * Unknown names are dropped rather than shown. A category is a promise to a
     * visitor about what a switch controls, and a typo in the settings screen
     * should not become a switch that controls nothing.
     *
     * @return list<string>
     */
    public static function categories(): array
    {
        $configured = (string) Settings::getSetting(
            self::CATEGORIES_SETTING,
            implode(',', self::KNOWN_CATEGORIES)
        );

        $wanted = array_map('trim', explode(',', strtolower($configured)));

        return array_values(array_intersect(self::KNOWN_CATEGORIES, $wanted));
    }

    /**
     * The current policy version.
     */
    public static function version(): string
    {
        $version = trim((string) Settings::getSetting(self::VERSION_SETTING, '1'));

        return $version === '' ? '1' : $version;
    }

    /**
     * Everything the script needs, translated for the current language.
     *
     * @param string $baseUrl The site's root URL, with a trailing slash; the
     *                        record endpoint hangs off it.
     * @return array<string, mixed>
     */
    public static function config(string $baseUrl = ''): array
    {
        $lang = \Pramnos\Framework\Factory::getLanguage();
        $t    = static fn (string $s): string => (string) $lang->_($s);

        $labels = [
            self::NECESSARY => [
                $t('Strictly necessary'),
                $t('Needed for the site to work, such as keeping you signed in. Always on.'),
            ],
            'preferences' => [
                $t('Preferences'),
                $t('Remember choices you make, such as language or layout.'),
            ],
            'analytics' => [
                $t('Analytics'),
                $t('Help us understand how the site is used, so we can improve it.'),
            ],
            'marketing' => [
                $t('Marketing'),
                $t('Used to show you relevant advertising on this and other sites.'),
            ],
        ];

        $categories = [];
        foreach (array_merge([self::NECESSARY], self::categories()) as $name) {
            $categories[] = [
                'name'        => $name,
                'label'       => $labels[$name][0],
                'description' => $labels[$name][1],
            ];
        }

        return [
            'version'    => self::version(),
            'days'       => self::LIFETIME_DAYS,
            'policyUrl'  => (string) Settings::getSetting(self::POLICY_URL_SETTING, ''),
            'recordUrl'  => $baseUrl === '' ? '' : rtrim($baseUrl, '/') . '/cookieconsent/record',
            'categories' => $categories,
            'text'       => [
                'title'     => $t('We use cookies'),
                'body'      => $t('We use cookies to make this site work. With your permission we would also use them for the purposes below. You can change your mind at any time from the "Cookie settings" link.'),
                'acceptAll' => $t('Accept all'),
                'rejectAll' => $t('Reject all'),
                'customise' => $t('Customise'),
                'save'      => $t('Save choices'),
                'policy'    => $t('Cookie policy'),
                'settings'  => $t('Cookie settings'),
            ],
        ];
    }

    /**
     * The `<script>` element a page includes, or '' when the feature is off.
     *
     * An external file carrying its configuration as an attribute: no inline
     * script, so no CSP nonce in the body and nothing standing between the page
     * and the page cache.
     *
     * @param string $baseUrl The site's root URL (`sURL` in a theme)
     */
    public static function tag(string $baseUrl = ''): string
    {
        if (!self::enabled()) {
            return '';
        }

        $base = $baseUrl === '' ? '' : rtrim($baseUrl, '/') . '/';
        $json = (string) json_encode(self::config($base), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return '<script src="' . htmlspecialchars($base . 'assets/js/pf-consent.js', ENT_QUOTES)
            . '" data-config="' . htmlspecialchars($json, ENT_QUOTES) . '" defer></script>';
    }

    /**
     * The categories the visitor agreed to, from the cookie.
     *
     * A cookie written under an older policy version counts as no choice at
     * all — the banner is asking again, and until it is answered nothing
     * optional is allowed.
     *
     * @param array<string, mixed>|null $cookies Defaults to `$_COOKIE`
     * @return list<string> Always includes {@see NECESSARY}
     */
    public static function granted(?array $cookies = null): array
    {
        $raw = ($cookies ?? $_COOKIE)[self::COOKIE] ?? '';

        if (!is_string($raw) || $raw === '') {
            return [self::NECESSARY];
        }

        parse_str($raw, $parsed);

        if ((string) ($parsed['v'] ?? '') !== self::version()) {
            return [self::NECESSARY];
        }

        $chosen = explode('.', (string) ($parsed['c'] ?? ''));

        return array_merge(
            [self::NECESSARY],
            array_values(array_intersect(self::KNOWN_CATEGORIES, $chosen))
        );
    }

    /**
     * Whether PHP may do what a category covers — set an analytics cookie,
     * render a tracking pixel.
     *
     * With the feature off this is always true: a site that turned the banner
     * off has taken the decision elsewhere, and gating on a cookie nobody is
     * ever asked to set would silently switch everything off.
     *
     * **Never use this to vary cached markup.** The page cache ignores cookies;
     * see the class comment. Gate in the browser with `data-consent` instead.
     *
     * @param array<string, mixed>|null $cookies Defaults to `$_COOKIE`
     */
    public static function allows(string $category, ?array $cookies = null): bool
    {
        if (!self::enabled()) {
            return true;
        }

        return in_array(strtolower($category), self::granted($cookies), true);
    }

    /**
     * Append a signed-in visitor's choice to `authserver.user_consents`.
     *
     * One row per optional category the site asks about, granted or not: a
     * refusal is as much a part of the record as an agreement, and "what did
     * they say about analytics on that day" has to be answerable either way.
     * The policy version rides in `consent_type` (`cookie:analytics@v3`),
     * because the question is always which policy was agreed to.
     *
     * An anonymous visitor has no row to write — the table is keyed by user —
     * and the cookie is their record.
     *
     * @param list<string> $granted Categories agreed to; unknown names are ignored
     * @return int Rows written
     * @throws \RuntimeException When a row could not be written
     */
    public static function record(
        \Pramnos\Database\Database $database,
        int $userId,
        array $granted,
        string $ipAddress = ''
    ): int {
        $now  = date('Y-m-d H:i:s');
        $rows = 0;

        foreach (self::categories() as $category) {
            $yes = in_array($category, $granted, true);

            // `insert()` answers false rather than throwing on some drivers; a trail that
            // silently was not written is the one outcome this method must not report as done.
            $written = $database->queryBuilder()
                ->table('authserver.user_consents')
                ->insert([
                    'userid'       => $userId,
                    'consent_type' => substr('cookie:' . $category . '@v' . self::version(), 0, 100),
                    'granted'      => $yes ? 1 : 0,
                    'granted_at'   => $now,
                    'revoked_at'   => $yes ? null : $now,
                    'legal_basis'  => 'consent',
                    'ip_address'   => substr($ipAddress, 0, 45),
                ]);
            if ($written === false) {
                throw new \RuntimeException('authserver.user_consents refused the ' . $category . ' row');
            }
            $rows++;
        }

        return $rows;
    }
}
