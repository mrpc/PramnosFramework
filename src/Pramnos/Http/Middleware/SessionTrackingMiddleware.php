<?php

declare(strict_types=1);

namespace Pramnos\Http\Middleware;

use Pramnos\Framework\Factory;
use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\Request;

/**
 * DB-backed session tracker — opt-in replacement for Addon\System\Session.
 *
 * Extracted from Addon\System\Session::onAppInit() so it becomes an explicit,
 * opt-in middleware rather than an invisible side-effect of the addon boot.
 *
 * What it does on every request:
 *   1. Deletes expired session rows (time < now-300)
 *   2. Detects bot user-agents via BotDetector
 *   3. Manages visitorid cookie and lastseen tracking
 *   4. Resolves real IP (Cloudflare CF-Connecting-IP aware)
 *   5. Force-logout: if sessions.logout=1 for this visitor, clears session + auth
 *   6. Upserts a row in the sessions table (INSERT … ON DUPLICATE KEY UPDATE)
 *
 * Opt in via app.php:
 *   'middleware' => [
 *       \Pramnos\Http\Middleware\SessionTrackingMiddleware::class,
 *   ],
 *
 * Or register on the pipeline directly:
 *   $app->middleware()->add(new SessionTrackingMiddleware());
 *
 *
 * @see Pramnos\Addon\System\Session  (deprecated — kept for BC)
 */
class SessionTrackingMiddleware implements MiddlewareInterface
{
    private BotDetector $botDetector;

    public function __construct(?BotDetector $botDetector = null)
    {
        $this->botDetector = $botDetector ?? new BotDetector();
    }

    public function handle(Request $request, callable $next): mixed
    {
        $this->track($request);
        return $next($request);
    }

    /**
     * Perform the session tracking side-effects.
     *
     * Kept separate from handle() so tests can call it directly without going
     * through the middleware pipeline.
     */
    public function track(Request $request): void
    {
        $database = Factory::getDatabase();
        $session  = Factory::getSession();
        $auth     = Factory::getAuth();

        // 1. Purge stale session rows
        $past = time() - 300;
        try {
            $database->query(
                $database->prepareQuery(
                    "DELETE FROM `#PREFIX#sessions` WHERE `time` < %d",
                    $past
                )
            );
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log($e->getMessage());
        }

        // 2. Collect request context
        $agent    = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $remoteip = $_SERVER['REMOTE_ADDR'] ?? '';

        $cloudflareip = '';
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $cloudflareip = $remoteip;
            $remoteip     = $_SERVER['HTTP_CF_CONNECTING_IP'];
        }

        $country = '';
        if (isset($_SERVER['HTTP_CF_IPCOUNTRY'])
            && is_string($_SERVER['HTTP_CF_IPCOUNTRY'])) {
            $country = strip_tags($_SERVER['HTTP_CF_IPCOUNTRY']);
        }

        $language = '';
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $parts    = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
            $language = $parts[0];
        }

        // For localhost dev: resolve real IP via ipify
        if ($remoteip === '192.168.2.1'
            && (!defined('UNITTESTING') || UNITTESTING === false)) {
            try {
                $ctx      = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5]]);
                $resolved = @file_get_contents('https://api.ipify.org', false, $ctx);
                if ($resolved !== false) {
                    $remoteip = $resolved;
                }
            } catch (\Exception $e) {
                \Pramnos\Logs\Logger::log($e->getMessage());
            }
        }

        // 3. Visitor ID cookie
        if ($request->cookieget('visitorid') !== null
            && $request->cookieget('visitorid') !== '') {
            $visitorid = (string) $request->cookieget('visitorid');
        } else {
            $visitorid = substr(md5($remoteip . $agent . $country . $language), 0, 16);
            $request->cookieset('visitorid', $visitorid);
        }
        $_SESSION['visitorid'] = $visitorid;

        // 4. Lastseen tracking
        $lastseen = (int) ($request->cookieget('lastseen') ?? 0);
        $request->cookieset('lastseen', (string) time());

        // 5. Determine session identity
        $uid   = 'NULL';
        $guest = 1;
        $uname = 'Anonymous';

        $url = $request->getURL(false);
        $sid = md5(session_id());

        if (isset($_SESSION['logged']) && $_SESSION['logged'] === true) {
            $guest = 0;
            $uid   = $_SESSION['uid'] ?? 'NULL';
            $uname = $_SESSION['username'] ?? 'anonymous';

            if ($request->cookieget('auth') !== null
                && $request->cookieget('username') !== null
                && isset($_SESSION['uid']) && (int) $_SESSION['uid'] > 1) {
                $request->cookieset('logged',   true);
                $request->cookieset('uid',      $_SESSION['uid']);
                $request->cookieset('username', $_SESSION['username'] ?? '');
                $request->cookieset('auth',     $_SESSION['auth'] ?? '');
            }
        } elseif ($request->cookieget('auth') !== null
            && $request->cookieget('username') !== null) {
            $auth->authCheck();
        } else {
            if (!isset($_SESSION['logged']))   { $_SESSION['logged']   = false; }
            if (!isset($_SESSION['uid']))      { $_SESSION['uid']      = 1; }
            if (!isset($_SESSION['username'])) { $_SESSION['username'] = ''; }
            if (!isset($_SESSION['cookie']))   { $_SESSION['cookie']   = 0; }
            if (!isset($_SESSION['remember'])) { $_SESSION['remember'] = false; }
            if (!isset($_SESSION['language'])) { $_SESSION['language'] = 'english'; }

            // Bot detection — only for non-authenticated guests
            $botName = $this->botDetector->botName($agent);
            if ($botName !== '') {
                $uname = $botName;
            }
        }

        if (strlen($uname) > 128) {
            $uname = substr($uname, 0, 128);
        }
        if ((int) $uid === 1) {
            $uid = 'NULL';
        }

        // 6. Force-logout check
        try {
            $checkSql = $database->prepareQuery(
                "SELECT `logout` FROM `#PREFIX#sessions` WHERE `visitorid` = %s",
                base64_encode(hex2bin($visitorid))
            );
            $checkResult = $database->query($checkSql);
            if ($checkResult->numRows !== 0 && $checkResult->fields['logout'] == '1') {
                $session->reset();
                $auth->logout();
                $guest = 1;
                $uname = 'Kicked Out';
            }
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log($e->getMessage());
        }

        // 7. Upsert session row
        try {
            if ($database->type === 'postgresql') {
                $sql = $database->prepareQuery(
                    "INSERT INTO `#PREFIX#sessions`
                    (`visitorid`, `uname`, `time`, `host_addr`, `guest`, `agent`,
                    `userid`, `url`, `logout`, `sid`, `history`)
                    VALUES (%s, %s, %d, %s, %d, %s, $uid, %s, %d, %s, '')
                    ON CONFLICT (visitorid) DO UPDATE SET
                    `uname` = %s, `time` = %d, `guest` = %d,
                    `userid` = $uid, `url` = %s, `logout` = %d",
                    base64_encode(hex2bin($visitorid)),
                    $uname, time(), $remoteip, $guest, $agent, $url,
                    0, $sid, $uname, time(), $guest, $url, 0
                );
            } else {
                $sql = $database->prepareQuery(
                    "INSERT INTO `#PREFIX#sessions`
                    (`visitorid`, `uname`, `time`, `host_addr`, `guest`, `agent`,
                    `userid`, `url`, `logout`, `sid`, `history`)
                    VALUES (%s, %s, %d, %s, %d, %s, $uid, %s, %d, %s, '')
                    ON DUPLICATE KEY UPDATE
                    `uname` = %s, `time` = %d, `guest` = %d,
                    `userid` = $uid, `url` = %s, `logout` = %d",
                    base64_encode(hex2bin($visitorid)),
                    $uname, time(), $remoteip, $guest, $agent, $url,
                    0, $sid, $uname, time(), $guest, $url, 0
                );
            }
            $database->query($sql);
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log($e->getMessage());
            $session->reset();
            $auth->logout();
        }
    }
}
