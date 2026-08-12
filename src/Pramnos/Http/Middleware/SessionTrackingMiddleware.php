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

        // 1. Purge stale session rows — occasionally, not on every request.
        //
        // Rows go stale five minutes after their last request, so how promptly
        // they are removed does not matter to anything: nothing reads a stale
        // row, and the table is a live-visitor list rather than a record. What
        // did matter is that the DELETE ran on every request — including every
        // XHR a page makes — so a page with ten API calls issued ten of them,
        // each scanning the same rows to find nothing.
        //
        // One in `session_gc_divisor` requests does the sweep. At the default,
        // a site with any traffic sweeps every few seconds and a quiet one
        // sweeps when somebody arrives; both are far more often than the five
        // minutes the data is allowed to be stale.
        if ($this->shouldCollectGarbage()) {
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
        }

        // 2. Collect request context
        $agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        // The peer that connected, and who the peer says the client is. The
        // second is only honoured when the first is a configured trusted proxy
        // — `CF-Connecting-IP` is a request header like any other, and without
        // that check anyone can write whatever address they like into the
        // session record.
        $peer     = $_SERVER['REMOTE_ADDR'] ?? '';
        $remoteip = Request::clientIp();

        $cloudflareip = ($remoteip !== '' && $remoteip !== $peer) ? $peer : '';

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

        // 6 + 7. Learn whether this session was kicked out, and record the
        // visit — in one statement where the database can do it.
        //
        // These used to be two round trips on every request: a SELECT of the
        // `logout` flag, and then the upsert that records the visit. The flag
        // is almost never set, so the SELECT existed to read a zero.
        //
        // PostgreSQL can answer both at once. The upsert no longer clears
        // `logout` blindly; instead it returns the value the row had, and the
        // rare request that finds a 1 pays one extra statement to clear it.
        // That turns the common case from two statements into one.
        $encodedVisitor = base64_encode(hex2bin($visitorid));
        $kickedOut      = false;

        // Nothing here needs doing twice in the same minute.
        //
        // The row records who is online and what they are looking at. A page
        // that loads and then calls its own API writes it twice, a second
        // apart, with the same values but for the URL — and a page making ten
        // XHR calls writes it ten times.
        //
        // The cost of skipping is that a visitor drops off the online list up
        // to a minute later than they might have, and that a forced logout
        // takes up to a minute to be noticed. Neither is a promise anything
        // makes: the row is already five minutes stale before it is swept.
        //
        // Only a navigation says where somebody is. An XHR is the page talking
        // to the server, not the visitor moving — and because it runs *after*
        // the page that made it, recording its URL would leave the session row
        // permanently showing `/users/data` for a visitor who is looking at
        // `/users`.
        $isNavigation = $this->isNavigation($request);

        if (!$this->shouldRecordVisit($url, $isNavigation)) {
            return;
        }

        if ($database->type === 'postgresql') {
            try {
                // A background call refreshes the timestamp and leaves `url`
                // alone, so the row keeps naming the page the visitor is on.
                $sql = $isNavigation
                    ? $database->prepareQuery(
                        "INSERT INTO `#PREFIX#sessions`
                    (`visitorid`, `uname`, `time`, `host_addr`, `guest`, `agent`,
                    `userid`, `url`, `logout`, `sid`, `history`)
                    VALUES (%s, %s, %d, %s, %d, %s, $uid, %s, %d, %s, '')
                    ON CONFLICT (visitorid) DO UPDATE SET
                    `uname` = %s, `time` = %d, `guest` = %d,
                    `userid` = $uid, `url` = %s
                    RETURNING `logout`",
                        $encodedVisitor,
                        $uname, time(), $remoteip, $guest, $agent, $url,
                        0, $sid, $uname, time(), $guest, $url
                    )
                    : $database->prepareQuery(
                        "INSERT INTO `#PREFIX#sessions`
                    (`visitorid`, `uname`, `time`, `host_addr`, `guest`, `agent`,
                    `userid`, `url`, `logout`, `sid`, `history`)
                    VALUES (%s, %s, %d, %s, %d, %s, $uid, %s, %d, %s, '')
                    ON CONFLICT (visitorid) DO UPDATE SET
                    `uname` = %s, `time` = %d, `guest` = %d,
                    `userid` = $uid
                    RETURNING `logout`",
                        $encodedVisitor,
                        $uname, time(), $remoteip, $guest, $agent, $url,
                        0, $sid, $uname, time(), $guest
                    );
                $result = $database->query($sql);

                // The row as it was before this request touched it: the upsert
                // deliberately leaves `logout` alone, so RETURNING reports the
                // flag rather than the zero it used to be overwritten with.
                $kickedOut = $result && $result->numRows > 0
                    && (string) ($result->fields['logout'] ?? '0') === '1';

                if ($kickedOut) {
                    $session->reset();
                    $auth->logout();
                    $guest = 1;
                    $uname = 'Kicked Out';

                    // Clear it, so the next request is a normal one. Only the
                    // request that was actually kicked out pays for this.
                    $database->query($database->prepareQuery(
                        "UPDATE `#PREFIX#sessions` SET `logout` = 0, `uname` = %s,"
                        . " `guest` = 1 WHERE `visitorid` = %s",
                        $uname,
                        $encodedVisitor
                    ));
                }
            } catch (\Exception $e) {
                \Pramnos\Logs\Logger::log($e->getMessage());
                $session->reset();
                $auth->logout();
            }

            return;
        }

        // MySQL has no RETURNING on an upsert, so it keeps the two statements.
        try {
            $checkSql = $database->prepareQuery(
                "SELECT `logout` FROM `#PREFIX#sessions` WHERE `visitorid` = %s",
                $encodedVisitor
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

        try {
            $sql = $isNavigation
                ? $database->prepareQuery(
                    "INSERT INTO `#PREFIX#sessions`
                (`visitorid`, `uname`, `time`, `host_addr`, `guest`, `agent`,
                `userid`, `url`, `logout`, `sid`, `history`)
                VALUES (%s, %s, %d, %s, %d, %s, $uid, %s, %d, %s, '')
                ON DUPLICATE KEY UPDATE
                `uname` = %s, `time` = %d, `guest` = %d,
                `userid` = $uid, `url` = %s, `logout` = %d",
                    $encodedVisitor,
                    $uname, time(), $remoteip, $guest, $agent, $url,
                    0, $sid, $uname, time(), $guest, $url, 0
                )
                : $database->prepareQuery(
                    "INSERT INTO `#PREFIX#sessions`
                (`visitorid`, `uname`, `time`, `host_addr`, `guest`, `agent`,
                `userid`, `url`, `logout`, `sid`, `history`)
                VALUES (%s, %s, %d, %s, %d, %s, $uid, %s, %d, %s, '')
                ON DUPLICATE KEY UPDATE
                `uname` = %s, `time` = %d, `guest` = %d,
                `userid` = $uid, `logout` = %d",
                    $encodedVisitor,
                    $uname, time(), $remoteip, $guest, $agent, $url,
                    0, $sid, $uname, time(), $guest, 0
                );
            $database->query($sql);
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log($e->getMessage());
            $session->reset();
            $auth->logout();
        }
    }

    /**
     * Has enough happened since the last write to be worth another one?
     *
     * The marker lives in the session rather than in a property, because a
     * middleware instance lasts one request and the question spans several.
     *
     * Two rules:
     *
     * - A **navigation** to a page the row does not already name writes at
     *   once. That is the visitor moving, and where they are is the field
     *   somebody watches.
     * - Anything else — an XHR, an API call, a second navigation to the same
     *   page — writes at most once per `session_write_interval` seconds
     *   (default 60; `0` writes every time, as this did before).
     *
     * A page that loads and then calls its own API therefore writes once: the
     * navigation writes, and the XHR a second later has nothing to add. Before
     * the split it wrote twice, and the second write — being the later one —
     * left the row naming the XHR's URL rather than the page's.
     *
     * @param  string $url          The URL of this request
     * @param  bool   $isNavigation Whether the visitor navigated here
     * @return bool
     */
    protected function shouldRecordVisit(string $url, bool $isNavigation = true): bool
    {
        $interval = \Pramnos\Application\Settings::getSetting('session_write_interval');
        $interval = is_numeric($interval) ? (int) $interval : 60;

        if ($interval <= 0) {
            return true;
        }

        $last    = $_SESSION['_session_written'] ?? null;
        $lastAt  = is_array($last) ? (int) ($last['at'] ?? 0) : 0;
        $lastUrl = is_array($last) ? ($last['url'] ?? null) : null;

        // The visitor moved to a page the row does not name yet.
        if ($isNavigation && $lastUrl !== $url) {
            $_SESSION['_session_written'] = ['at' => time(), 'url' => $url];

            return true;
        }

        if ((time() - $lastAt) >= $interval) {
            // A background call refreshes the clock without claiming to be the
            // page the visitor is on — so the recorded URL is left as it was.
            $_SESSION['_session_written'] = [
                'at'  => time(),
                'url' => $isNavigation ? $url : $lastUrl,
            ];

            return true;
        }

        return false;
    }

    /**
     * Did the visitor navigate here, or is this the page talking to the server?
     *
     * `Sec-Fetch-Dest` is the reliable answer and every current browser sends
     * it: `document` for a navigation, `empty` for a fetch or XHR. The two
     * fallbacks are for older clients and for anything that is not a browser —
     * `X-Requested-With`, which jQuery and DataTables set, and the `Accept`
     * header, since a navigation asks for HTML and an API call does not.
     *
     * The default when nothing says otherwise is *navigation*, so a client that
     * sends none of these keeps the old behaviour rather than quietly dropping
     * out of the online list.
     *
     * @param  Request $request
     * @return bool
     */
    protected function isNavigation(Request $request): bool
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

    /**
     * Should this request sweep the stale session rows?
     *
     * One request in `session_gc_divisor` does, chosen at random. The point of
     * the divisor is not to sweep rarely — it is to stop *every* request paying
     * for a DELETE that finds nothing, which on a page making ten API calls was
     * ten identical scans.
     *
     * Rows are stale five minutes after their last request and nothing reads a
     * stale row, so the sweep being late costs nothing at all. At the default of
     * 100, a site with any traffic still sweeps every few seconds.
     *
     * Set `session_gc_divisor` to 1 to sweep on every request (the old
     * behaviour), or to 0 to never sweep from the request path — which is the
     * right setting when something else, a scheduled task, does it instead.
     *
     * @return bool
     */
    protected function shouldCollectGarbage(): bool
    {
        $divisor = \Pramnos\Application\Settings::getSetting('session_gc_divisor');
        $divisor = is_numeric($divisor) ? (int) $divisor : 100;

        if ($divisor <= 0) {
            return false;
        }

        if ($divisor === 1) {
            return true;
        }

        return random_int(1, $divisor) === 1;
    }
}
