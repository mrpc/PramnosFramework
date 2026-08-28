<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

/**
 * `/adminer` — Adminer, served behind this installation's own gate.
 *
 * Adminer is the database tool most people already use, and the usual way to have it on a
 * server is a PHP file dropped in the web root. That file is then reachable by anybody who
 * guesses its name, protected by whatever the database password happens to be, and forgotten
 * about after the afternoon it was needed. This route replaces that: the same tool, reached
 * through the application, refused to everybody the application refuses.
 *
 * ## Who may open it
 *
 * Either of:
 *
 * - **usertype ≥ 99 (`Root`)** — on any deployment including production, and **with or without
 *   the `devpanel` feature**: this is the owner's tool, not a part of that panel. That is the
 *   half asked for deliberately: fixing data on a live server is a real thing an owner does,
 *   and telling them to use a tool that only works in development means they use `psql` in a
 *   terminal with no undo, or leave `adminer.php` in the web root for ever.
 * - **a development environment**, subject to the DevPanel's own usertype floor — the same gate
 *   as the panel this sits beside.
 *
 * Anything else gets a **404**, not a 403: a 403 confirms the route exists, and this is the one
 * URL on the site where that is worth withholding.
 *
 * ## It is not installed by default
 *
 * `vrana/adminer` is a `suggest`, not a `require`. A framework that shipped a database browser
 * into every application's `vendor/` would be enlarging the attack surface of applications that
 * never asked for one — including the ones that never look at what a release added. An
 * installation opts in:
 *
 * ```
 * composer require vrana/adminer
 * ```
 *
 * With the package absent, this route answers 404 like any other unknown address. Nothing is
 * half-present.
 *
 * ## Its own login stays
 *
 * Adminer asks for the database credentials, and this deliberately does not fill them in.
 * Auto-login would make "may this browser reach a URL" the only thing between somebody and
 * every row in the database — and the accounts that can reach it are exactly the ones whose
 * sessions are worth stealing. Two locks, and the second one is not ours to remove.
 *
 * ## Assets
 *
 * Adminer's source layout links its stylesheets as `../adminer/static/default.css`, which
 * resolves against wherever the script is served from — and the script is in `vendor/`, which
 * the web root does not expose. So the output is rewritten to point back at this route with a
 * `?file=` parameter, and those requests are served from the package. The same trick Adminer's
 * own single-file build uses.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class Adminer extends \Pramnos\Application\Controller
{
    public $actions = ['display'];

    /**
     * Usertype that may open this on any deployment, development or not.
     *
     * 99, which is `Root` in {@see \Pramnos\User\UserTypes::DEFAULTS} — the top of the ladder,
     * the only rung whose capability list is `['*']`. It was 100 here, a number no account has,
     * so the one person this route exists for was refused by it on production while everybody
     * else got a 404 and no way to tell the two apart.
     *
     * An installation with its own scale overrides the floor in `app.php`:
     *
     * ```php
     * 'devpanel' => ['adminer_min_usertype' => 95],
     * ```
     */
    public const ROOT_USERTYPE = 99;

    /**
     * Where the bar's Back link points, captured before the session is handed to Adminer.
     */
    protected string $returnUrl = '';

    /**
     * The asset paths this route will serve out of the package.
     *
     * A whitelist rather than a sanitised path: everything Adminer asks for lives under
     * `static/` or `externals/` with an ordinary name, and the alternative — accepting a path
     * and cleaning it — is the shape every directory-traversal bug has.
     */
    private const ASSET_PATTERN = '~^(static|externals)/[\w./-]+\.(css|js|png|gif|svg|woff2?)$~';

    public function display(array $args = []): void
    {
        if (!$this->mayOpen()) {
            // Recorded before the 404, because the refusal is the interesting half: the page
            // says nothing on purpose, so the log is the only place it is visible. A run of
            // these from one address is the shape of somebody trying the door.
            $this->audit('refused');
            $this->notFound();

            return;
        }

        $entryPoint = $this->locate();

        if ($entryPoint === null) {
            // Installed nowhere, so as far as the site is concerned this address does not
            // exist. Said in the log, because "404 on /adminer" with the package missing is a
            // different problem from "404 because you are not allowed".
            \Pramnos\Logs\Logger::log(
                'The /adminer route was reached but no Adminer package is installed. '
                . 'composer require vrana/adminer',
                'devpanel'
            );
            $this->notFound();

            return;
        }

        $request = new \Pramnos\Http\Request();
        $file    = (string) $request->get('file', '', 'get');

        if ($file !== '') {
            $this->serveAsset(dirname($entryPoint), $file);

            return;
        }

        $this->serveAdminer($entryPoint);
    }

    /**
     * Root anywhere, or the DevPanel's own gate in a development environment.
     */
    /**
     * Would this route serve the visitor of the current request?
     *
     * Asked by the DevPanel, which draws a tab for it, and by the debug toolbar, which draws a
     * link. Both would rather show nothing than an entry that answers 404 — a tool that appears
     * and refuses reads as broken rather than absent.
     */
    public static function isAvailable(): bool
    {
        $probe = new static(null);

        return $probe->locate() !== null && $probe->mayOpen();
    }

    protected function mayOpen(): bool
    {
        $user     = \Pramnos\User\User::getCurrentUser();
        $usertype = (int) ($user->usertype ?? 0);

        if ($user === null || !\Pramnos\Http\Session::staticIsLogged()) {
            return false;
        }

        if ($usertype >= static::rootFloor()) {
            return true;
        }

        if (!\Pramnos\Application\Application::isDeveloperEnvironment()) {
            return false;
        }

        /*
         * The development fallback borrows the DevPanel's floor, so it needs the DevPanel.
         *
         * Root does not: this route works with the `devpanel` feature switched off, on any
         * deployment, because it is the owner's tool and not a part of that panel. What cannot
         * work without it is *this* clause — a floor configured for a panel the installation
         * does not have is a number with nothing behind it, and letting a usertype-90 account
         * through on the strength of it would be a gate configured by accident.
         */
        if (!\Pramnos\Application\FeatureRegistry::isEnabled('devpanel')) {
            return false;
        }

        $floor = (int) \Pramnos\DevPanel\DevPanelController::config('min_usertype', 90);

        return $usertype >= ($floor > 0 ? $floor : 90);
    }

    /**
     * The usertype that may open this anywhere — the constant, or the installation's own.
     */
    protected static function rootFloor(): int
    {
        $configured = \Pramnos\DevPanel\DevPanelController::config('adminer_min_usertype', 0);

        return (int) $configured > 0 ? (int) $configured : static::ROOT_USERTYPE;
    }

    /**
     * The Adminer entry point, or null when no package is installed.
     *
     * Both layouts are accepted: the official package's source tree, and a build that ships a
     * single compiled file. Whichever is there is the one an installation chose.
     */
    protected function locate(): ?string
    {
        $root = defined('ROOT') ? ROOT : getcwd();

        $candidates = [
            $root . '/vendor/vrana/adminer/adminer/index.php',
            $root . '/vendor/dg/adminer-custom/adminer.php',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Run Adminer, with its asset URLs pointed back at this route.
     */
    protected function serveAdminer(string $entryPoint): void
    {
        $directory = dirname($entryPoint);
        $base      = $this->routeUrl();

        /*
         * Adminer's own CSP, sent before it can write anything.
         *
         * The framework's policy is nonce-based, and Adminer emits inline scripts and styles
         * of its own — under the site's policy the page arrives with no styling and a console
         * full of refusals, which reads as a broken tool. It gets its own header instead of a
         * relaxation of the site's: the loosening applies to this URL and nothing else.
         */
        if (!headers_sent()) {
            header(
                "Content-Security-Policy: default-src 'self'; "
                . "script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
                . "style-src 'self' 'unsafe-inline'; img-src 'self' data:; "
                . "frame-ancestors 'none'"
            );
            header('X-Robots-Tag: noindex, nofollow');

            /*
             * `no-referrer`, because Adminer's URLs describe the database.
             *
             * It puts the driver, the host, the username and the database name in the query
             * string of every link it draws — that is how it identifies a connection, not
             * something this route chose. What *is* chosen here is who else gets to read them:
             * the version check in its footer points at adminer.org, and without this header the
             * `Referer` sent there would carry the lot.
             */
            header('Referrer-Policy: no-referrer');
        }

        /*
         * Our session is closed before Adminer sees the request, and both halves of that
         * matter.
         *
         * Adminer sets a handful of `session.*` ini values and then starts a session of its
         * own, named `adminer_sid`, **only if none is active** — so with ours still open it
         * printed «Session ini settings cannot be changed when a session is active» at the top
         * of the page and then wrote its own keys into our namespace. One of those is
         * `$_SESSION["token"]`, which this framework also uses: Adminer's CSRF token is
         * `rand() ^ $_SESSION["token"]`, our value is a hex string, and the result was
         * «A non-numeric value encountered» twice per page and a CSRF check that could not
         * work.
         *
         * Closing it costs nothing here. The request has already been authorised, and this
         * response ends the request.
         */
        /*
         * Nothing the request POSTs can choose a connection.
         *
         * `auth.inc.php` processes `$_POST['auth']` — driver, server, username, password,
         * database — before anything else, and acts on it. Removing Adminer's login form took
         * away the *page* that submits that, not the ability to submit it: a hand-made POST, or
         * a form on another site aimed at this URL, would have logged this Adminer into any host
         * reachable from the server with any credentials the sender knew. The gate on this route
         * is a permission to read *this* database, not a general-purpose client.
         *
         * `permanent` goes with it — a field of that array which asks Adminer to write an
         * encrypted copy of the password into a cookie.
         */
        unset($_POST['auth'], $_POST['logout']);

        /*
         * Every open is recorded.
         *
         * On a public server this is the question that matters afterwards: who opened the
         * database tool, when, and from where. Adminer keeps no such record, and the web
         * server's access log says a URL was fetched rather than which account fetched it.
         */
        $this->audit('opened');

        /*
         * Where "Back" goes, worked out **before** the session is closed and emptied.
         *
         * It reads the remembered referrer out of `$_SESSION`, and two lines below there is no
         * `$_SESSION` left to read. Computed afterwards it always answered "the site root",
         * which is a Back button that goes somewhere nobody asked for.
         */
        $this->returnUrl = class_exists('Pramnos\\DevPanel\\DevPanelController')
            ? \Pramnos\DevPanel\DevPanelController::returnUrlFor()
            : (defined('sURL') ? (string) sURL : '/');

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        /*
         * And emptied, not only closed.
         *
         * `session_write_close()` writes the data and closes the handle; `$_SESSION` keeps its
         * contents in memory. If Adminer then decides not to start a session of its own — it
         * only does so when none is active, and there are ways for one to be — it reads and
         * writes *our* keys. One of them is `token`, which this framework uses for its own
         * CSRF: Adminer's is `rand() ^ $_SESSION["token"]`, ours is a hex string, and the result
         * is «A non-numeric value encountered» twice per page and a CSRF check that cannot work.
         *
         * Ours is already saved and this response ends the request, so there is nothing to lose
         * by handing Adminer an empty array.
         */
        $_SESSION = array();

        // And repair a session already poisoned by the first version of this route, which left
        // ours open for Adminer to write into. See AdminerBridge::repairSession().
        \Pramnos\DevPanel\AdminerBridge::repairSession();

        if (!$this->prepareLogin()) {
            // Sent to the canonical URL instead. See prepareLogin().
            return;
        }

        // Its includes are relative to the script's own directory, which is what `chdir()` is
        // for. Restored afterwards for the sake of anything that runs later in the request —
        // in practice this response ends it, but a changed working directory is not the kind
        // of thing to leave behind on the strength of that.
        $previous = getcwd();
        chdir($directory);

        /*
         * A buffer *callback*, not `ob_get_clean()` afterwards.
         *
         * Adminer is a script and a script ends with `exit` — several of its paths do, the
         * login page among them. So the code after the include never ran: the rewrite was
         * skipped, PHP flushed the buffer at shutdown, and the page went out with Adminer's own
         * `./static/default.css` links. Served from `/adminer`, those resolve to `/static/…`,
         * which is nothing, so the page arrived with no stylesheet and a broken logo — and
         * looked like a broken tool rather than a missing rewrite.
         *
         * A callback is invoked by the buffer's own flush, `exit` included, so it cannot be
         * skipped by anything the included script decides to do.
         */
        ob_start(function (string $buffer) use ($base): string {
            return $this->injectChrome(
                $this->stripConnectionFromLinks($this->rewriteAssetUrls($buffer, $base))
            );
        });

        try {
            include $entryPoint;
        } catch (\Throwable $exception) {
            ob_end_clean();
            chdir((string) $previous);
            \Pramnos\Logs\Logger::log(
                'Adminer failed to run: ' . $exception->getMessage(),
                'devpanel'
            );
            $this->notFound();

            return;
        }

        chdir((string) $previous);
        $this->terminate();

        return;
    }

    /**
     * Hand Adminer the connection this application is already using, unless told not to.
     *
     * Adminer asks for a server, a username, a password and a database, and the application
     * knows all four. Asking the operator to retype them is asking them to keep the production
     * database password somewhere they can copy it from — and a sticky note is what actually
     * happens.
     *
     * Off with:
     *
     * ```php
     * 'devpanel' => ['adminer_autologin' => false],
     * ```
     *
     * in which case Adminer shows its own login form exactly as it would on its own.
     */
    protected function prepareLogin(): bool
    {
        $enabled = \Pramnos\DevPanel\DevPanelController::config('adminer_autologin', true);

        if ($enabled === false || $enabled === 'false' || $enabled === '0' || $enabled === 0) {
            return true;
        }

        $connection = \Pramnos\DevPanel\AdminerBridge::applicationConnection();

        if ($connection === []) {
            // Nothing useful to hand over — the login form is the honest answer, rather than a
            // half-filled one that fails with "Invalid credentials".
            return true;
        }

        \Pramnos\DevPanel\AdminerBridge::remember($connection);

        // The global `adminer_object()` Adminer looks for. Its own file, because Adminer asks
        // `function_exists('adminer_object')` — a plain string, resolved in the global
        // namespace, so it cannot be a method or a closure.
        require_once __DIR__ . '/../../DevPanel/adminer-object.php';

        if (\Pramnos\DevPanel\AdminerBridge::urlNamesConnection($connection)) {
            return true;
        }

        /*
         * The connection is put where Adminer looks — `$_GET` **and** the request URI, together.
         *
         * Both, and that is the whole lesson of two failed attempts:
         *
         *  - `$_GET` alone gave `ERR_TOO_MANY_REDIRECTS`. Adminer builds its self-links from
         *    `$_SERVER['REQUEST_URI']` (`ME`, `relative_uri()`, `remove_from_uri()`), so with
         *    the parameters in `$_GET` and absent from the URI its idea of "the canonical address
         *    of this page" was the bare route — which it redirected to, arriving back here, where
         *    they were injected again.
         *  - A real 302 to that canonical URL fixed the loop and put the driver, the host, the
         *    username and the database name into the address bar, the browser history and every
         *    access log on the way. The password was never in it, and the rest is still more than
         *    a URL should say about somebody's database.
         *
         * Aligning the URI Adminer *reads* leaves the visitor's address bar at `/adminer` while
         * giving Adminer a consistent view of the world. Its own links still carry the connection
         * — that is how it identifies one, and not something this route can change — but the
         * entry point does not, and `Referrer-Policy: no-referrer` keeps those links from leaving
         * the browser.
         *
         * Scoped to this request, which ends with the response.
         */
        \Pramnos\DevPanel\AdminerBridge::alignRequestUri($connection, $this->routePath());

        return true;
    }

    /**
     * This route's path, without the scheme and host — what a request URI is made of.
     */
    protected function routePath(): string
    {
        $base = defined('sURL') ? (string) sURL : '/';
        $path = parse_url($base, PHP_URL_PATH);

        return rtrim(is_string($path) ? $path : '/', '/') . '/adminer';
    }

    /**
     * This installation's own bar, above Adminer's page.
     *
     * Adminer is a whole application and it is not going to be rewritten to look like the
     * DevPanel — its data grid is the reason it is here. What can be shared is the chrome: the
     * panel's tab strip with Adminer marked active, the site's name, and a way back to the
     * screen the visitor came from.
     *
     * That answers the two ways in. Opened from the DevPanel it reads as one of its tabs;
     * opened directly it has a Back button, which Adminer itself has no idea about — every link
     * it draws stays inside itself.
     *
     * Styled here rather than by importing the panel's stylesheet: this page belongs to Adminer,
     * whose CSS owns everything below the bar, and dragging a second full stylesheet in would
     * be a fight over the same selectors.
     */
    protected function chrome(): string
    {
        $panel = '\Pramnos\DevPanel\DevPanelController';

        if (!class_exists($panel)) {
            return '';
        }

        $back = $this->returnUrl !== '' ? $this->returnUrl : $panel::returnUrlFor();
        $tabs = $panel::tabStrip('adminer');
        $site = (string) \Pramnos\Application\Settings::getSetting('sitename');
        $site = is_scalar($site) ? trim((string) $site) : '';

        return '<div id="pf-adminer-bar">'
            . '<span class="pf-logo">&#9881; ' . htmlspecialchars($site !== '' ? $site : 'DevPanel', ENT_QUOTES) . '</span>'
            . '<nav>' . $tabs . '</nav>'
            . '<a class="pf-back" href="' . htmlspecialchars($back, ENT_QUOTES) . '">&#8592; Back</a>'
            . '</div>'
            . '<style>'
            /*
             * `fixed`, not `sticky`.
             *
             * Adminer's pages are wider than the viewport whenever a table has many columns or a
             * long comment, so the page scrolls sideways — and a `sticky` bar scrolls with it,
             * taking Back off the screen. `sticky` only pins the vertical axis. `fixed` stays put
             * in both, which costs a `padding-top` on the body to keep the first row from hiding
             * underneath.
             */
            . '#pf-adminer-bar{position:fixed;top:0;left:0;right:0;z-index:9999;display:flex;'
            . 'align-items:center;gap:14px;padding:8px 14px;background:#111827;color:#e5e7eb;'
            . 'font:13px/1.4 -apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;'
            . 'box-shadow:0 1px 0 #374151;}'
            . 'body{padding-top:42px;}'
            /*
             * And let the long text wrap, which is what made the page wide in the first place.
             *
             * Table and column comments are prose in a cell Adminer does not wrap. One sentence
             * in a comment pushed every page sideways — the sideways scroll was the symptom, the
             * comment was the cause. Scoped to comment cells so the data grid's own alignment,
             * which relies on `nowrap` in places, is left alone.
             */
            . '#content td.comment,#content th.comment,#content td[id^="comment"]{'
            . 'white-space:normal!important;word-break:break-word;max-width:32em;}'
            . '#pf-adminer-bar .pf-logo{font-weight:600;white-space:nowrap;}'
            . '#pf-adminer-bar nav{display:flex;gap:2px;flex:1;overflow-x:auto;}'
            . '#pf-adminer-bar nav a{color:#9ca3af;text-decoration:none;padding:4px 10px;'
            . 'border-radius:4px;white-space:nowrap;}'
            . '#pf-adminer-bar nav a:hover{color:#e5e7eb;background:#1f2937;}'
            . '#pf-adminer-bar nav a.active{color:#111827;background:#e5e7eb;}'
            . '#pf-adminer-bar .pf-back{color:#e5e7eb;text-decoration:none;white-space:nowrap;'
            . 'padding:4px 10px;border:1px solid #374151;border-radius:4px;}'
            . '#pf-adminer-bar .pf-back:hover{background:#1f2937;}'
            . '</style>';
    }

    /**
     * Put the bar at the top of Adminer's page.
     *
     * After the opening `<body>` tag, which Adminer writes itself and which is therefore the one
     * anchor in its output that is guaranteed to exist. A page with no `<body>` — an asset, an
     * error, a download — is returned untouched rather than guessed at.
     */
    protected function injectChrome(string $html): string
    {
        $chrome = $this->chrome();

        if ($chrome === '' || !preg_match('~<body[^>]*>~i', $html, $match)) {
            return $html;
        }

        $position = (int) strpos($html, $match[0]) + strlen($match[0]);

        return substr($html, 0, $position) . $chrome . substr($html, $position);
    }

    /**
     * Take the connection out of Adminer's own links.
     *
     * Adminer identifies a connection in the query string — the driver key, `username`, `db` —
     * so every link it draws published the host, the account and the database name into the
     * address bar, the browser history and any log in between. The password was never there,
     * and the rest is still more than a URL should say about somebody's database.
     *
     * Stripping them is safe *because* of how this route works: the connection comes from the
     * configuration and is put back server-side on every request, so a link without it lands on
     * the same page. The same mechanism that stopped the redirect loop.
     *
     * Only those three. `table=`, `select=`, `sql=`, `ns=` and the rest are navigation, and a
     * link that lost them would go somewhere else.
     */
    protected function stripConnectionFromLinks(string $html): string
    {
        $drivers = ['server', 'pgsql', 'sqlite', 'mssql', 'oracle', 'mongo', 'elastic'];
        $remove  = array_merge($drivers, ['username', 'db']);

        return (string) preg_replace_callback(
            '~(href|action)=([\'"])([^\'"]*\?[^\'"]*)\2~i',
            static function (array $match) use ($remove): string {
                // Absolute URLs are Adminer's outbound links — adminer.org, the version check —
                // and nothing of ours is in them.
                if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $match[3]) === 1) {
                    return $match[0];
                }

                [$path, $query] = explode('?', $match[3], 2);
                parse_str(html_entity_decode($query), $parameters);

                foreach ($remove as $key) {
                    unset($parameters[$key]);
                }

                $rebuilt = http_build_query($parameters, '', '&amp;');

                return $match[1] . '=' . $match[2] . $path
                    . ($rebuilt !== '' ? '?' . $rebuilt : '') . $match[2];
            },
            $html
        );
    }

    /**
     * Point Adminer's own asset links back at this route.
     *
     * It spells them three ways in one page — `./static/default.css`,
     * `static/editing.js`, and `../adminer/static/…` in older layouts — so all three are
     * matched and reduced to the path inside the package. Handling only the one a version
     * happens to use is how a stylesheet goes missing after an update, on a page that still
     * loads.
     */
    protected function rewriteAssetUrls(string $html, string $base): string
    {
        return (string) preg_replace_callback(
            '~(href|src)=([\'"])((?:\.{1,2}/)*(?:adminer/)?(?:static|externals)/[^\'"?]+)(\?[^\'"]*)?\2~',
            static function (array $match) use ($base): string {
                $path = preg_replace('~^(?:\.{1,2}/)*(?:adminer/)?~', '', $match[3]);

                return $match[1] . '=' . $match[2] . $base . '?file=' . urlencode((string) $path)
                    . $match[2];
            },
            $html
        );
    }

    /**
     * Send one of Adminer's own asset files.
     */
    protected function serveAsset(string $directory, string $path): void
    {
        // Checked against the whitelist *and* resolved, because a whitelist that allows dots
        // is a whitelist somebody will find a way through with `a/./../../`.
        $parent = dirname($directory);

        if (!preg_match(self::ASSET_PATTERN, $path)) {
            $this->notFound();

            return;
        }

        $candidates = [$directory . '/' . $path, $parent . '/' . $path];
        $resolved   = null;

        foreach ($candidates as $candidate) {
            $real = realpath($candidate);

            if ($real !== false
                && is_file($real)
                && (str_starts_with($real, (string) realpath($directory))
                    || str_starts_with($real, (string) realpath($parent)))
            ) {
                $resolved = $real;
                break;
            }
        }

        if ($resolved === null) {
            $this->notFound();

            return;
        }

        $types = [
            'css'   => 'text/css',
            'js'    => 'text/javascript',
            'png'   => 'image/png',
            'gif'   => 'image/gif',
            'svg'   => 'image/svg+xml',
            'woff'  => 'font/woff',
            'woff2' => 'font/woff2',
        ];
        $extension = strtolower((string) pathinfo($resolved, PATHINFO_EXTENSION));

        if (!headers_sent()) {
            header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
            header('Cache-Control: public, max-age=604800');
        }

        readfile($resolved);
        $this->terminate();
    }

    /**
     * The URL this route is served from, for the rewritten asset links.
     */
    protected function routeUrl(): string
    {
        $base = defined('sURL') ? rtrim((string) sURL, '/') : '';

        return $base . '/adminer';
    }

    /**
     * Record who reached this route, and how it went.
     *
     * `auth` rather than `devpanel`: this belongs with the sign-ins and the password changes,
     * which is where somebody looks when they are reconstructing what happened.
     */
    protected function audit(string $outcome): void
    {
        $user = \Pramnos\User\User::getCurrentUser();
        $who  = $user !== null && (int) ($user->userid ?? 0) > 0
            ? ($user->username ?? '?') . ' (' . (int) $user->userid . ', usertype '
                . (int) ($user->usertype ?? 0) . ')'
            : 'a visitor with no session';

        \Pramnos\Logs\Logger::log(
            'Adminer ' . $outcome . ': ' . $who
            . ' from ' . (string) ($_SERVER['REMOTE_ADDR'] ?? '?')
            . ' for ' . (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            'auth'
        );
    }

    /**
     * Not here, as far as anybody asking is concerned — through the site's own 404.
     *
     * `Application::notFound()`, not a line of plain text. A refused request has to be
     * indistinguishable from an address that does not exist, and «Not found» in Courier is not:
     * it is a page nothing else on the site produces, which tells whoever is looking that
     * something *is* here and that they were turned away. The site's own 404 says nothing.
     *
     * It is also what a person sees, and a person reaching this URL is usually an administrator
     * who forgot they are signed out.
     */
    protected function notFound(): void
    {
        $application = $this->application ?? \Pramnos\Application\Application::currentInstance();

        if ($application !== null && method_exists($application, 'notFound')) {
            $application->notFound();

            // `notFound()` ends the request itself. Reached only under PHPUnit, where
            // `closeWithStatus()` throws instead of exiting — and where returning is what lets
            // the test carry on.
            return;
        }

        // No application to ask: still a 404, and still not a description of what happened.
        if (!headers_sent()) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
        }

        $this->terminate();
    }

    /**
     * End the request here.
     *
     * `exit`, because what has just been written is a complete HTML document — Adminer's own —
     * and letting the request continue would render the site's theme after it. The framework's
     * `raw` document type is the usual way to say that, but it also injects CSP nonces, and
     * Adminer is full of inline `onclick` handlers that a nonce policy blocks whatever the
     * nonces say. This route sends its own policy instead, and the only way that policy
     * survives is for nothing else to run.
     *
     * Under PHPUnit it returns instead: `exit` there takes the test runner with it, and an
     * integration test asking whether this route resolves at all is worth having. The same
     * accommodation `LogController` makes for the same reason.
     */
    protected function terminate(): void
    {
        if (defined('PHPUNIT_COMPOSER_INSTALL') || defined('__PHPUNIT_PHAR__')) {
            return;
        }

        exit;
    }
}
