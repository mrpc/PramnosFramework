<?php

declare(strict_types=1);

namespace Pramnos\Application;

/**
 * How much of what a visitor does gets written down.
 *
 * The framework records one row per request in `tokenactions`: the URL, the
 * method, the parameters, the status, how long it took. That is an audit log,
 * and for an application that needs one it is exactly right — who called what,
 * when, and what came back.
 *
 * For an application that does not, it is a table that grows by one row per
 * request for ever, holding a copy of every request body that was ever posted.
 * A page that loads and then makes ten API calls writes eleven rows. Nobody
 * chose that; it was simply the only behaviour there was.
 *
 * So it is a setting now, and the default keeps what installations have today.
 *
 * ## The settings
 *
 * ```php
 * // app/settings/settings.php, or the settings table
 * 'visit_log' => 'all',          // every request (the default)
 * 'visit_log' => 'navigations',  // only pages a visitor actually opened
 * 'visit_log' => 'pages',        // every web request, including a page's XHR
 * 'visit_log' => 'api',          // only the API
 * 'visit_log' => 'none',         // nothing at all
 * ```
 *
 * `true` and `false` are accepted too, meaning `all` and `none`, so an
 * installation can switch it off without learning the vocabulary.
 *
 * ## Which is which
 *
 * `pages` and `api` split on **who handled the request** — the web front
 * controller or the API subsystem. That is a fact about the request, not a
 * guess, and it is stable.
 *
 * `navigations` is narrower than `pages`: it excludes the calls a page makes
 * after it has rendered. On a datatable-heavy admin panel that is most of the
 * traffic, and none of it is a visitor going anywhere — the same distinction
 * the session tracker draws, and for the same reason.
 *
 * ## What it does not change
 *
 * Nothing here affects authentication, rate limiting, or the token's own
 * `lastused`. A request that is not written down is still authenticated,
 * counted and served in exactly the same way; only the audit row is skipped.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class VisitLogPolicy
{
    /** @var string Every request, whoever handled it — the default */
    public const ALL = 'all';

    /** @var string Only pages a visitor actually opened */
    public const NAVIGATIONS = 'navigations';

    /** @var string Every web request, including the XHR a page makes */
    public const PAGES = 'pages';

    /** @var string Only requests handled by the API subsystem */
    public const API = 'api';

    /** @var string Nothing */
    public const NONE = 'none';

    /** @var string A request the web front controller handled */
    public const CONTEXT_WEB = 'web';

    /** @var string A request the API subsystem handled */
    public const CONTEXT_API = 'api';

    /**
     * Should this request be written to the visit log?
     *
     * @param  string $context One of the CONTEXT_* constants
     * @return bool
     */
    public static function shouldLog(string $context): bool
    {
        $mode = static::mode();

        if ($mode === static::NONE) {
            return false;
        }

        if ($mode === static::ALL) {
            return true;
        }

        if ($mode === static::API) {
            return $context === static::CONTEXT_API;
        }

        if ($mode === static::PAGES) {
            return $context === static::CONTEXT_WEB;
        }

        // navigations: a web request, and only if the visitor went somewhere.
        return $context === static::CONTEXT_WEB && static::isNavigation();
    }

    /**
     * The configured mode, normalised.
     *
     * An unrecognised value falls back to `all` rather than to `none`: a typo
     * in a settings table must not silently switch off an audit log that
     * somebody may be relying on for exactly the request they are about to go
     * looking for.
     *
     * @return string One of the mode constants
     */
    public static function mode(): string
    {
        $configured = Settings::getSetting('visit_log');

        if ($configured === false || $configured === null) {
            return static::ALL;
        }

        if ($configured === true) {
            return static::ALL;
        }

        if (!is_string($configured)) {
            return static::ALL;
        }

        $configured = strtolower(trim($configured));

        // The spellings somebody would reasonably write for off.
        if (in_array($configured, ['none', 'off', 'no', 'false', '0', ''], true)) {
            return static::NONE;
        }

        if (in_array($configured, ['all', 'yes', 'true', '1'], true)) {
            return static::ALL;
        }

        if (in_array($configured, [static::NAVIGATIONS, 'navigation'], true)) {
            return static::NAVIGATIONS;
        }

        if (in_array($configured, [static::PAGES, 'page', 'web'], true)) {
            return static::PAGES;
        }

        if (in_array($configured, [static::API], true)) {
            return static::API;
        }

        return static::ALL;
    }

    /**
     * Did the visitor navigate here, or is this the page talking to the server?
     *
     * `Sec-Fetch-Dest` is the reliable answer and every current browser sends
     * it. The fallbacks are for older clients and for anything that is not a
     * browser: `X-Requested-With`, which jQuery and DataTables set, and the
     * `Accept` header, since a navigation asks for HTML and an API call does
     * not.
     *
     * When nothing says otherwise the answer is *navigation*, so a client that
     * sends none of these is logged rather than silently dropped.
     *
     * @return bool
     */
    public static function isNavigation(): bool
    {
        $dest = $_SERVER['HTTP_SEC_FETCH_DEST'] ?? '';

        if (is_string($dest) && $dest !== '') {
            return $dest === 'document' || $dest === 'iframe';
        }

        $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';

        if (is_string($requestedWith)
            && strcasecmp($requestedWith, 'XMLHttpRequest') === 0) {
            return false;
        }

        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';

        if (is_string($accept) && $accept !== '' && !str_contains($accept, '*/*')) {
            return str_contains($accept, 'text/html')
                || str_contains($accept, 'application/xhtml');
        }

        return true;
    }
}
