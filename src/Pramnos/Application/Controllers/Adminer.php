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
 * - **usertype ≥ 100** — the root account, on any deployment including production. That is the
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

    /** Usertype that may open this on any deployment, development or not. */
    public const ROOT_USERTYPE = 100;

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
    protected function mayOpen(): bool
    {
        $user     = \Pramnos\User\User::getCurrentUser();
        $usertype = (int) ($user->usertype ?? 0);

        if ($user === null || !\Pramnos\Http\Session::staticIsLogged()) {
            return false;
        }

        if ($usertype >= static::ROOT_USERTYPE) {
            return true;
        }

        if (!\Pramnos\Application\Application::isDeveloperEnvironment()) {
            return false;
        }

        $floor = (int) \Pramnos\DevPanel\DevPanelController::config('min_usertype', 90);

        return $usertype >= ($floor > 0 ? $floor : 90);
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
        }

        // Its includes are relative to the script's own directory, which is what `chdir()` is
        // for. Restored afterwards for the sake of anything that runs later in the request —
        // in practice `terminate()` ends it, but a changed working directory is not the kind
        // of thing to leave behind on the strength of that.
        $previous = getcwd();
        chdir($directory);

        ob_start();

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

        $html = (string) ob_get_clean();
        chdir((string) $previous);

        echo $this->rewriteAssetUrls($html, $base);
        $this->terminate();

        return;
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
     * Not here, as far as anybody asking is concerned.
     */
    protected function notFound(): void
    {
        if (!headers_sent()) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
        }

        echo "Not found\n";
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
