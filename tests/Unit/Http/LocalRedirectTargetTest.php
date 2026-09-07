<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Http\Request;

/**
 * Where a validation failure sends the browser, and who gets to choose.
 *
 * `Application::exec()` redirected to `$_SERVER['HTTP_REFERER']` when a controller threw
 * `ValidationException`. **A referer is a request header**: whoever made the request picks
 * it, so on a `POST` that fails validation the sender chose where the framework sent the
 * user.
 *
 * Two things made that worse than an ordinary open redirect.
 *
 * **Nothing opted in.** Every controller that throws `ValidationException` reaches those
 * lines, so an application that has never written a redirect of its own still had this one.
 *
 * **The redirect carried a session it should not.** The two lines above it write
 * `_validation_errors` and `_old_input` — `allCurrent()`, the whole submitted request — so
 * the user arrives at the sender's chosen destination having just posted a form, with their
 * session holding a copy of what they typed. On a login or a payment form that is the
 * interesting part. The destination cannot read the session across origins; it does control
 * the page the user believes they were returned to.
 */
#[CoversClass(Request::class)]
#[CoversClass(Application::class)]
class LocalRedirectTargetTest extends TestCase
{
    /**
     * `Application::localPathOf()`, reachable without booting a request.
     */
    private function application(): object
    {
        return new class extends Application {
            public function __construct()
            {
                // The real constructor boots a database and a session.
            }

            public function where(string $referer): string
            {
                return $this->localPathOf($referer);
            }
        };
    }

    /**
     * Every shape that looks like a local path and is not.
     *
     * Each of these has been an open redirect somewhere, and the last is not a redirect at
     * all: a newline in a `Location` header ends the header and starts another, so the sender
     * writes the rest of the response.
     */
    public function testTheShapesThatOnlyLookLikeAPath(): void
    {
        foreach (array(
            'https://evil.example/x'     => 'another origin, said plainly',
            '//evil.example/x'           => 'protocol-relative: a browser reads another host',
            '/\\evil.example/x'          => 'the same trick with a backslash',
            'javascript:alert(1)'        => 'a scheme needs no slashes',
            'data:text/html,<script>'    => 'the same, with a payload',
            '/x' . "\r\n" . 'Set-Cookie: a=b' => 'response splitting, not navigation',
            '/x' . "\n" . 'X: y'         => 'a bare newline is enough',
            'evil.example/x'             => 'no leading slash at all',
            ''                           => 'nothing',
        ) as $hostile => $why) {
            // Assert
            $this->assertFalse(
                Request::isLocalPath((string) $hostile),
                'accepted as a local path (' . $why . '): ' . var_export($hostile, true)
            );
        }
    }

    /**
     * And the paths that are paths.
     *
     * The control: a check that refused everything would send every validation failure to the
     * front page and lose the form the user was on, which is a worse experience than the bug
     * and would be reported as one.
     */
    public function testARealPathIsAccepted(): void
    {
        foreach (array(
            '/',
            '/account/login',
            '/account/login?next=/orders',
            '/orders/42',
            /*
             * A colon in a path is a path.
             *
             * The filing asked for a rule rejecting a `:` before the first `/`, and once a
             * leading slash is required it cannot help: `javascript:` has no leading slash
             * and is refused above, and `/https://x` is resolved by a browser against this
             * origin. What such a rule *does* is refuse this, and send the user to the site
             * root — the same silent misdirection the check exists to prevent.
             */
            '/media/urn:isbn:978000000000',
            '/a:b',
        ) as $path) {
            $this->assertTrue(Request::isLocalPath($path), 'refused a local path: ' . $path);
        }
    }

    /**
     * A referer on this site is reduced to its path, query and all.
     *
     * A browser sends an **absolute** referer, so refusing everything that is not already a
     * path would mean every validation failure lands on the front page — and the point of the
     * redirect is to put the user back on the form they were filling in.
     */
    public function testASameSiteRefererKeepsThePageTheUserWasOn(): void
    {
        // Arrange
        $base = rtrim((string) sURL, '/');

        // Act
        $where = $this->application()->where($base . '/account/login?step=2');

        // Assert
        $this->assertSame('/account/login?step=2', $where);
    }

    /**
     * A scheme, a `www.` or a port that has moved is still this site.
     *
     * Compared by origin rather than by string prefix, because behind a proxy `sURL` and the
     * referer routinely disagree about all three — and a prefix comparison refuses each of
     * them. That exact mistake sent the debug-bar switch to the front page on an installation
     * behind a proxy, and repeating it here would lose the user's form for the same reason.
     */
    public function testAProxyDoesNotLoseTheUsersPage(): void
    {
        // Arrange
        $host = (string) parse_url((string) sURL, PHP_URL_HOST);

        // Assert — the scheme differs
        $this->assertSame('/orders', $this->application()->where('http://' . $host . '/orders'));

        // a `www.` appears
        $this->assertSame('/orders', $this->application()->where('https://www.' . $host . '/orders'));
    }

    /**
     * An off-site referer falls back to the site root.
     *
     * Which is already what happens when there is no referer at all, so the failure mode is
     * one the framework accepts rather than a new one.
     */
    public function testAnOffSiteRefererFallsBackToTheSiteRoot(): void
    {
        // Arrange
        $application = $this->application();
        $siteRoot    = (string) sURL;

        // Assert
        $this->assertSame($siteRoot, $application->where('https://evil.example/x'));
        $this->assertSame($siteRoot, $application->where(''));
        $this->assertSame($siteRoot, $application->where('not a url at all'));
    }

    /**
     * A same-site referer whose path is hostile still falls back.
     *
     * The belt: the host matched, so the origin check passed, and the path is then checked on
     * its own — because `https://host//evil.example` has this site's host and a
     * protocol-relative path.
     */
    public function testASameSiteRefererWithAHostilePathFallsBack(): void
    {
        // Arrange
        $host = (string) parse_url((string) sURL, PHP_URL_HOST);

        // Act
        $where = $this->application()->where('https://' . $host . '//evil.example/x');

        // Assert
        $this->assertSame((string) sURL, $where, 'a protocol-relative path passed the origin check');
    }
}
