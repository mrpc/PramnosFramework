<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Adminer;

/**
 * The gate and the rewriting on `/adminer` — 167 of 235 statements never executed.
 *
 * This route serves a database browser. Everything about it is a security decision, and three of
 * them are here because they are the ones a mistake would not announce:
 *
 *   - **who may open it**, which is two clauses that must both hold for a non-root account and
 *     one that must hold alone for root;
 *   - **which files it will send**, since it reads from a package directory with a caller-supplied
 *     path — the shape of every traversal bug ever written;
 *   - **what it strips from Adminer's own links**, so a page it serves cannot hand the browser a
 *     URL naming this installation's database, user and server.
 *
 * The alternative this route exists to replace is `adminer.php` dropped in the web root, reachable
 * by anybody who guesses the name. That is the standard to beat, and it is only beaten while these
 * hold.
 */
#[CoversClass(Adminer::class)]
class AdminerRouteTest extends TestCase
{
    /** The protected internals, reachable, with nothing else changed. */
    private function probe(): object
    {
        return new class extends Adminer {
            public function __construct()
            {
                // No parent::__construct(): it registers actions against an application this
                // test does not have, and nothing below reads one.
            }

            public array $notFounds = [];

            public function probeStrip(string $html): string
            {
                return $this->stripConnectionFromLinks($html);
            }

            public function probeAssets(string $html, string $base): string
            {
                return $this->rewriteAssetUrls($html, $base);
            }

            public function probeServeAsset(string $directory, string $path): void
            {
                $this->serveAsset($directory, $path);
            }

            public function probeChrome(): string
            {
                return $this->chrome();
            }

            public function probeInject(string $html): string
            {
                return $this->injectChrome($html);
            }

            public function probeRoutePath(): string
            {
                return $this->routePath();
            }

            public function probeRouteUrl(): string
            {
                return $this->routeUrl();
            }

            public function probeLocate(): ?string
            {
                return $this->locate();
            }

            /**
             * The line `audit()` writes, read back from an in-memory stream.
             *
             * `audit()` composes and logs in one step, so the only way to assert *what it says* is
             * to read what it wrote. `Logger` has a stream mode for exactly this; pointing it at
             * `php://memory` keeps the assertion honest — it is the real line, not a copy of the
             * composition — and touches no log file.
             */
            public function probeAuditLine(string $outcome): string
            {
                $stream = fopen('php://memory', 'r+');

                $mode = \Pramnos\Logs\Logger::getOutputMode();
                \Pramnos\Logs\Logger::setStreamTarget($stream);
                \Pramnos\Logs\Logger::setOutputMode(\Pramnos\Logs\Logger::OUTPUT_STREAM);

                try {
                    $this->audit($outcome);
                } finally {
                    \Pramnos\Logs\Logger::setOutputMode($mode);
                    \Pramnos\Logs\Logger::setStreamTarget(null);
                }

                rewind($stream);
                $written = (string) stream_get_contents($stream);
                fclose($stream);

                return $written;
            }

            protected function notFound(): void
            {
                $this->notFounds[] = 'notFound';
            }

            protected function terminate(): void
            {
                // A test does not exit.
            }
        };
    }

    // ── What it strips from Adminer's links ───────────────────────────────────

    /**
     * The connection is removed from every link the page carries.
     *
     * Adminer puts the server, the user and the database in its own URLs. Left in, every link on
     * a page this route served would name this installation's database host and account — in the
     * address bar, in the browser history, in a `Referer` header on the way to adminer.org.
     */
    public function testTheConnectionIsStrippedFromLinks(): void
    {
        // Arrange
        $html = '<a href="?server=db.internal&username=root&db=production&table=users">users</a>';

        // Act
        $stripped = $this->probe()->probeStrip($html);

        // Assert
        $this->assertStringNotContainsString('db.internal', $stripped);
        $this->assertStringNotContainsString('username=root', $stripped);
        $this->assertStringNotContainsString('db=production', $stripped);
        $this->assertStringContainsString('table=users', $stripped, 'the useful parameter is gone too');
    }

    /** Every driver key Adminer might use, not only `server`. */
    public function testEveryDriverKeyIsStripped(): void
    {
        // Arrange
        $probe = $this->probe();

        // Assert
        foreach (['server', 'pgsql', 'sqlite', 'mssql', 'oracle', 'mongo', 'elastic'] as $driver) {
            $stripped = $probe->probeStrip(
                '<a href="?' . $driver . '=secret.host&table=t">x</a>'
            );
            $this->assertStringNotContainsString(
                'secret.host',
                $stripped,
                $driver . ' is not stripped, so a ' . $driver . ' installation leaks its host'
            );
        }
    }

    /** A form's `action` is a link too. */
    public function testFormActionsAreStrippedAsWell(): void
    {
        // Act
        $stripped = $this->probe()->probeStrip(
            '<form action="?server=db.internal&db=x&sql=SELECT+1">'
        );

        // Assert
        $this->assertStringNotContainsString('db.internal', $stripped);
        $this->assertStringContainsString('sql=SELECT', $stripped);
    }

    /**
     * A link with nothing left keeps its path and loses the `?`.
     *
     * `href="?"` is not a link to the same page in every browser, and a trailing question mark
     * on every navigation is the kind of thing that turns into a duplicated history entry.
     */
    public function testALinkLeftWithNoParametersLosesItsQuestionMark(): void
    {
        // Act
        $stripped = $this->probe()->probeStrip('<a href="index.php?server=x&username=y&db=z">x</a>');

        // Assert
        $this->assertStringContainsString('href="index.php"', $stripped);
    }

    /**
     * Absolute URLs are left alone.
     *
     * They are Adminer's outbound links — its own site, the version check — and none of this
     * installation's parameters are in them. Rewriting them would corrupt somebody else's URL to
     * no purpose.
     */
    public function testAbsoluteUrlsAreUntouched(): void
    {
        // Arrange
        $html = '<a href="https://www.adminer.org/?version=4.8.1">Adminer</a>';

        // Act
        $stripped = $this->probe()->probeStrip($html);

        // Assert
        $this->assertSame($html, $stripped);
    }

    /** A link with no query string at all is untouched. */
    public function testALinkWithoutAQueryStringIsUntouched(): void
    {
        // Arrange
        $html = '<a href="index.php">home</a>';

        // Assert
        $this->assertSame($html, $this->probe()->probeStrip($html));
    }

    // ── Where it points the assets ────────────────────────────────────────────

    /**
     * All three spellings of an asset link are rewritten to this route.
     *
     * Adminer writes them three ways in one page, and handling only the one a given version
     * happens to use is how a stylesheet goes missing after an update — on a page that still
     * loads, so nothing looks broken until somebody notices the layout.
     */
    public function testAllThreeAssetSpellingsAreRewritten(): void
    {
        // Arrange
        $html = '<link href="./static/default.css">'
            . '<script src="static/editing.js"></script>'
            . '<link href="../adminer/static/theme.css">';

        // Act
        $rewritten = $this->probe()->probeAssets($html, '/adminer');

        // Assert
        $this->assertStringContainsString('/adminer?file=' . urlencode('static/default.css'), $rewritten);
        $this->assertStringContainsString('/adminer?file=' . urlencode('static/editing.js'), $rewritten);
        $this->assertStringContainsString('/adminer?file=' . urlencode('static/theme.css'), $rewritten);
        $this->assertStringNotContainsString('./static', $rewritten);
        $this->assertStringNotContainsString('../adminer/static', $rewritten);
    }

    /** An existing cache-buster on the asset URL is dropped rather than carried through. */
    public function testAnAssetsOwnQueryStringIsReplaced(): void
    {
        // Act
        $rewritten = $this->probe()->probeAssets(
            '<link href="static/default.css?ts=123">',
            '/adminer'
        );

        // Assert
        $this->assertStringContainsString('file=' . urlencode('static/default.css'), $rewritten);
        $this->assertStringNotContainsString('ts=123', $rewritten);
    }

    /** `externals/` is rewritten too, and an unrelated asset is not. */
    public function testExternalsAreRewrittenAndOtherAssetsAreNot(): void
    {
        // Act
        $rewritten = $this->probe()->probeAssets(
            '<script src="externals/jush/jush.js"></script><img src="/theme/logo.png">',
            '/adminer'
        );

        // Assert
        $this->assertStringContainsString('file=' . urlencode('externals/jush/jush.js'), $rewritten);
        $this->assertStringContainsString('src="/theme/logo.png"', $rewritten, 'an unrelated asset was rewritten');
    }

    // ── Which files it will send ──────────────────────────────────────────────

    /**
     * A traversal attempt is a 404, not a file.
     *
     * The path comes from a query parameter, and the directory it reads from sits inside
     * `vendor/`. Every one of these is refused by the whitelist before any filesystem call —
     * which is the order that matters, because a check that runs after a `realpath()` has
     * already told the caller whether a path exists.
     */
    public function testTraversalAttemptsAreRefused(): void
    {
        // Arrange
        $probe     = $this->probe();
        $directory = sys_get_temp_dir();

        $attempts = [
            '../../../../etc/passwd',
            'static/../../../../etc/passwd',
            'static/./../../composer.json',
            '/etc/passwd',
            'static/config.php',
            'static/default.css.php',
            'externals/../../.env',
            'static/' . str_repeat('../', 12) . 'etc/passwd',
        ];

        // Act & Assert
        foreach ($attempts as $attempt) {
            $probe->notFounds = [];
            $probe->probeServeAsset($directory, $attempt);
            $this->assertNotSame(
                [],
                $probe->notFounds,
                'this path was not refused: ' . $attempt
            );
        }
    }

    /**
     * And only the extensions a page needs are servable.
     *
     * The whitelist is what stops this being a general-purpose file reader over `vendor/`. A
     * `.php` under `static/` is the case worth naming: it matches the directory and would be
     * *executed* by nothing here, but read out verbatim — which is how a config file leaks.
     */
    public function testOnlyAssetExtensionsAreServable(): void
    {
        // Arrange
        $probe     = $this->probe();
        $directory = sys_get_temp_dir();

        // Act & Assert
        foreach (['static/x.php', 'static/x.env', 'static/x.ini', 'static/x', 'static/x.phtml'] as $path) {
            $probe->notFounds = [];
            $probe->probeServeAsset($directory, $path);
            $this->assertNotSame([], $probe->notFounds, $path . ' was not refused');
        }
    }

    /**
     * A path that passes the whitelist but names no file is still a 404.
     *
     * The whitelist says "this could be an asset"; the filesystem says whether it is. Both, in
     * that order.
     */
    public function testAWhitelistedPathThatDoesNotExistIsA404(): void
    {
        // Arrange
        $probe = $this->probe();

        // Act
        $probe->probeServeAsset(sys_get_temp_dir(), 'static/definitely-not-here.css');

        // Assert
        $this->assertNotSame([], $probe->notFounds);
    }

    /**
     * A real asset inside the directory is sent, with the right content type.
     *
     * The positive case, so the refusals above cannot be passing because everything is refused.
     */
    public function testARealAssetIsServed(): void
    {
        // Arrange
        $base = sys_get_temp_dir() . '/adminer-probe-' . bin2hex(random_bytes(4));
        mkdir($base . '/static', 0777, true);
        file_put_contents($base . '/static/default.css', 'body{color:red}');

        $probe = $this->probe();

        // Act
        ob_start();
        $probe->probeServeAsset($base, 'static/default.css');
        $body = (string) ob_get_clean();

        // Assert
        $this->assertSame([], $probe->notFounds, 'a real asset was refused');
        $this->assertSame('body{color:red}', $body);

        // Cleanup
        unlink($base . '/static/default.css');
        rmdir($base . '/static');
        rmdir($base);
    }

    // ── The floor ─────────────────────────────────────────────────────────────

    /**
     * The root floor is 99 unless the installation names its own.
     *
     * 99 rather than 90: this is the one route where the owner's own account is the intended
     * audience and an administrator's is not.
     */
    public function testTheRootFloorIsNinetyNine(): void
    {
        // Assert
        $this->assertSame(99, Adminer::ROOT_USERTYPE);
    }

    // ── The bar above Adminer's page ──────────────────────────────────────────

    /**
     * The chrome carries the site's name, the panel's tabs and a way back.
     *
     * Adminer is a whole application and every link it draws stays inside itself — it has no idea
     * this route exists. Without a Back link, a visitor who opened it directly has the browser
     * button and nothing else, and one who opened it from the DevPanel has left the panel with no
     * way to see that.
     */
    public function testTheChromeNamesTheSiteAndOffersAWayBack(): void
    {
        // Arrange
        $probe = $this->probe();

        // Act
        $bar = $probe->probeChrome();

        // Assert
        if ($bar === '') {
            $this->markTestSkipped('The devpanel feature is not installed in this checkout.');
        }

        $this->assertStringContainsString('id="pf-adminer-bar"', $bar);
        $this->assertStringContainsString('Back', $bar);
        $this->assertStringContainsString('<nav>', $bar, 'the tab strip is missing');
    }

    /**
     * The site name is escaped, because it is operator-supplied text.
     *
     * A setting somebody typed reaches a page that renders above a database browser. An
     * apostrophe in a company name would break the attribute; a `<script>` would not be a
     * cosmetic problem.
     */
    public function testTheSiteNameIsEscapedInTheChrome(): void
    {
        // Arrange
        $previous = \Pramnos\Application\Settings::getSetting('sitename');
        \Pramnos\Application\Settings::setSetting('sitename', '<script>x</script> & "Co"', false);

        try {
            // Act
            $bar = $this->probe()->probeChrome();

            if ($bar === '') {
                $this->markTestSkipped('The devpanel feature is not installed in this checkout.');
            }

            // Assert
            $this->assertStringNotContainsString('<script>x</script>', $bar);
            $this->assertStringContainsString('&lt;script&gt;', $bar);
        } finally {
            \Pramnos\Application\Settings::setSetting('sitename', $previous, false);
        }
    }

    /**
     * The bar goes immediately after `<body>`, and only when there is one.
     *
     * Adminer answers things that are not pages — a redirect, a download, a fragment for its own
     * JavaScript. Injecting a `<div>` and a stylesheet into any of those corrupts the response,
     * so anything without a `<body>` is returned untouched rather than guessed at.
     */
    public function testTheBarIsInjectedAfterBodyAndOnlyWhenThereIsOne(): void
    {
        // Arrange
        $probe = $this->probe();

        // Act
        $page     = $probe->probeInject('<html><head></head><body class="x">rows</body></html>');
        $fragment = $probe->probeInject('{"json":true}');
        $redirect = $probe->probeInject('');

        // Assert
        if ($probe->probeChrome() === '') {
            $this->markTestSkipped('The devpanel feature is not installed in this checkout.');
        }

        $this->assertStringContainsString('<body class="x">', $page, 'the body tag was rewritten');
        $this->assertLessThan(
            strpos($page, 'rows'),
            strpos($page, 'pf-adminer-bar'),
            'the bar is after the content rather than at the top of the body'
        );
        $this->assertSame('{"json":true}', $fragment, 'a JSON response was given a stylesheet');
        $this->assertSame('', $redirect, 'an empty response was given a body');
    }

    // ── Where the route lives ─────────────────────────────────────────────────

    /**
     * The route path is the application's own path plus `/adminer`, with no host.
     *
     * It is what Adminer is told its request URI is, so it has to be a path: a value with a
     * scheme and host in it makes every self-link Adminer builds absolute to the wrong thing.
     */
    public function testTheRoutePathIsAPathAndNotAUrl(): void
    {
        // Act
        $path = $this->probe()->probeRoutePath();

        // Assert
        $this->assertStringEndsWith('/adminer', $path);
        $this->assertStringNotContainsString('http', $path);
        $this->assertStringNotContainsString('//', $path, 'a doubled separator, so sURL had a trailing slash');
    }

    /** And the URL is the absolute form of the same thing. */
    public function testTheRouteUrlIsTheAbsoluteForm(): void
    {
        // Act
        $url = $this->probe()->probeRouteUrl();

        // Assert
        $this->assertStringEndsWith('/adminer', $url);
        $this->assertStringStartsWith(rtrim((string) sURL, '/'), $url);
    }

    // ── The package ───────────────────────────────────────────────────────────

    /**
     * With no package installed, there is no entry point — and that is not an error.
     *
     * `vrana/adminer` is a `suggest`, not a `require`: a framework that shipped a database
     * browser into every application's vendor directory would enlarge the attack surface of
     * applications that never asked for one, including the ones that do not read what a release
     * added.
     */
    public function testWithNoPackageThereIsNoEntryPoint(): void
    {
        // Act & Assert — this checkout has neither layout installed.
        $this->assertNull(
            $this->probe()->probeLocate(),
            'an Adminer package is installed in this checkout, so this test proves nothing'
        );
    }

    // ── The audit line ────────────────────────────────────────────────────────

    /**
     * A refusal names the visitor, the address and the URL.
     *
     * The page says nothing on purpose, so this line is the only trace. It has to answer *who,
     * from where, for what* — a run of them from one address is the shape of somebody trying the
     * door, and that pattern is invisible if the line says only "refused".
     */
    public function testTheAuditLineNamesWhoFromWhereAndForWhat(): void
    {
        // Arrange
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['REQUEST_URI'] = '/adminer?db=production';

        // Act
        $line = $this->probe()->probeAuditLine('refused');

        // Assert
        $this->assertStringContainsString('refused', $line);
        $this->assertStringContainsString('203.0.113.7', $line);
        $this->assertStringContainsString('/adminer?db=production', $line);
        $this->assertStringContainsString(
            'no session',
            $line,
            'an anonymous attempt is not described as such'
        );
    }
}
