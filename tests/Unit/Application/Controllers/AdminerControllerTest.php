<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Adminer;

/**
 * The `/adminer` route: who it answers, and how it points at Adminer's own files.
 *
 * Not the rendering. Adminer is a script and a script ends with `exit`, so including it inside
 * a test runner takes the runner with it — a fact worth writing down, because the obvious
 * missing test is one nobody can add.
 *
 * What is here is everything around it, and all of it is the kind of thing that fails quietly:
 * a floor that drifts, a 403 where a 404 was meant, an asset path that resolves outside the
 * package, and a URL rewrite that silently stops matching after an Adminer update — leaving a
 * page that loads with no stylesheet, which reads as a broken tool.
 */
#[CoversClass(Adminer::class)]
class AdminerControllerTest extends TestCase
{
    private function probe(): object
    {
        return new class extends Adminer {
            public array $sent = [];

            public int $status = 0;

            public function __construct()
            {
                // No parent::__construct(): it registers actions against an application this
                // test does not have.
            }

            public function rewrite(string $html, string $base): string
            {
                return $this->rewriteAssetUrls($html, $base);
            }

            public function asset(string $directory, string $path): void
            {
                $this->serveAsset($directory, $path);
            }

            protected function notFound(): void
            {
                $this->status = 404;
            }

            protected function terminate(): void
            {
            }
        };
    }

    /**
     * Every spelling Adminer uses for its own assets is rewritten.
     *
     * It uses three in one page: `./static/default.css`, `static/editing.js`, and
     * `../adminer/static/…` in older layouts. Matching only the one a given version happens to
     * emit is how a stylesheet goes missing after an update, on a page that still loads and
     * still works — so it looks like the tool, not the rewrite.
     */
    public function testEverySpellingOfAnAssetPathIsRewritten(): void
    {
        // Arrange
        $html = '<link href="./static/default.css">'
            . '<script src="static/editing.js"></script>'
            . '<img src="../adminer/static/logo.png">'
            . '<link href="./static/jush/jush.css">';

        // Act
        $rewritten = $this->probe()->rewrite($html, 'https://site/adminer');

        // Assert
        $this->assertSame(
            '<link href="https://site/adminer?file=static%2Fdefault.css">'
            . '<script src="https://site/adminer?file=static%2Fediting.js"></script>'
            . '<img src="https://site/adminer?file=static%2Flogo.png">'
            . '<link href="https://site/adminer?file=static%2Fjush%2Fjush.css">',
            $rewritten
        );
    }

    /**
     * Links that are not Adminer's assets are left alone.
     *
     * A rewrite that caught everything would break the version check, the documentation links
     * and any URL a query string happens to contain.
     */
    public function testOtherLinksAreUntouched(): void
    {
        // Arrange
        $html = '<a href="https://www.adminer.org/">Adminer</a>'
            . '<form action="?db=test&amp;table=users">';

        // Act & Assert
        $this->assertSame($html, $this->probe()->rewrite($html, 'https://site/adminer'));
    }

    /**
     * A path that climbs out of the package is refused.
     *
     * The whitelist allows dots, because Adminer's filenames contain them — so the resolution
     * check behind it is what has to hold. `static/../../../app/config/settings.php` is the
     * request that matters: it is a real path, it exists, and it holds the database password.
     */
    public function testAPathThatClimbsOutIsRefused(): void
    {
        // Arrange
        $probe     = $this->probe();
        $directory = sys_get_temp_dir();

        // Act
        $probe->asset($directory, 'static/../../../../etc/passwd');
        $probe->asset($directory, '/etc/passwd');
        $probe->asset($directory, 'static/../../composer.json');

        // Assert
        $this->assertSame(404, $probe->status);
    }

    /**
     * A file that is not there is a 404, not an empty 200.
     */
    public function testAMissingAssetIsNotFound(): void
    {
        // Arrange
        $probe = $this->probe();

        // Act
        $probe->asset(sys_get_temp_dir(), 'static/nothing-here.css');

        // Assert
        $this->assertSame(404, $probe->status);
    }

    /**
     * An asset that is there is served with its own content type.
     */
    public function testAnAssetIsServed(): void
    {
        // Arrange
        $directory = sys_get_temp_dir() . '/pf-adminer-' . bin2hex(random_bytes(4));
        mkdir($directory . '/static', 0777, true);
        file_put_contents($directory . '/static/default.css', 'body{color:red}');
        $probe = $this->probe();

        try {
            // Act
            ob_start();
            $probe->asset($directory, 'static/default.css');
            $served = (string) ob_get_clean();

            // Assert
            $this->assertSame('body{color:red}', $served);
            $this->assertSame(0, $probe->status, 'a file that is there is not a 404');
        } finally {
            @unlink($directory . '/static/default.css');
            @rmdir($directory . '/static');
            @rmdir($directory);
        }
    }

    /**
     * The floor is the usertype `Root` actually is.
     *
     * It was 100 — a number no account has, because `UserTypes::DEFAULTS` tops out at 99 — so
     * the one person this route exists for was refused on production, with the same 404
     * everybody else gets and no way to tell the two apart.
     *
     * Asserted against `UserTypes` rather than repeated as a literal: a scale that gains a rung
     * must not leave this behind, and the two numbers agreeing by coincidence is exactly how
     * this went wrong.
     */
    public function testTheFloorIsTheUsertypeRootActuallyIs(): void
    {
        // Arrange — the highest rung the framework ships, ignoring the machine account
        $rungs = array_diff(
            array_keys(\Pramnos\User\UserTypes::DEFAULTS),
            \Pramnos\User\UserTypes::EXACT
        );

        // Act & Assert
        $this->assertSame(max($rungs), Adminer::ROOT_USERTYPE);
        $this->assertSame('Root', \Pramnos\User\UserTypes::DEFAULTS[Adminer::ROOT_USERTYPE]);

        // …and an administrator is below it, which is the whole point of the floor
        $this->assertGreaterThan(90, Adminer::ROOT_USERTYPE);
    }

    /**
     * Root does not need the DevPanel feature; the development fallback does.
     *
     * Asked directly: «if the devpanel is not enabled, do I have Adminer?» Yes, if you are root —
     * this is the owner's tool and not a part of that panel, and an installation may well ship
     * without it. What must *not* survive the feature being off is the other clause, which
     * borrows the panel's usertype floor: a floor configured for a panel that is not installed
     * is a number with nothing behind it, and letting a usertype-90 account through on the
     * strength of it would be a gate configured by accident.
     *
     * Asserted on the source of the gate rather than by standing up two applications and a
     * session: the two branches and the order they are in are the whole behaviour.
     */
    public function testRootNeedsNoDevPanelFeatureButTheFallbackDoes(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/src/Pramnos/Application/Controllers/Adminer.php'
        );

        $gate = substr(
            $source,
            (int) strpos($source, 'protected function mayOpen'),
            (int) strpos($source, 'protected static function rootFloor')
                - (int) strpos($source, 'protected function mayOpen')
        );

        // Assert — the root check comes first and asks nothing about the feature
        $rootAt    = strpos($gate, 'static::rootFloor()');
        $featureAt = strpos($gate, "isEnabled('devpanel')");

        $this->assertIsInt($rootAt);
        $this->assertIsInt($featureAt);
        $this->assertLessThan(
            $featureAt,
            $rootAt,
            'a root account must be answered before anything about the DevPanel is asked'
        );

        // …and the fallback is behind the feature
        $this->assertStringContainsString("isEnabled('devpanel')", $gate);
    }

    /**
     * Adminer's own links stop carrying the database username.
     *
     * Adminer identifies a connection in the query string — driver, host, `username`, `db` — so
     * every link it drew published them into the address bar, the browser history and any log in
     * between. The password was never there; the rest is still more than a URL should say about
     * somebody's database.
     *
     * Safe to strip *because* of how this route works: the connection comes from the
     * configuration and is supplied again server-side on every request, so a link without it
     * lands on the same page. What must survive is navigation — a link that lost `table=` goes
     * somewhere else — and that is the other half of what this asserts.
     */
    public function testTheConnectionIsStrippedFromAdminersOwnLinks(): void
    {
        // Arrange
        $probe = new class extends Adminer {
            public function __construct()
            {
            }

            public function strip(string $html): string
            {
                return $this->stripConnectionFromLinks($html);
            }
        };

        // Act
        $stripped = $probe->strip(
            '<a href="adminer?pgsql=db&amp;username=app_user&amp;db=app_db&amp;table=users">t</a>'
            . '<form action="adminer?server=db&amp;username=root&amp;db=app_db">'
            . '<a href="https://www.adminer.org/?v=1">out</a>'
            . '<a href="adminer?file=static%2Fdefault.css">css</a>'
        );

        // Assert — the connection is gone
        $this->assertStringNotContainsString('username=', $stripped);
        $this->assertStringNotContainsString('app_user', $stripped);
        $this->assertStringNotContainsString('db=app_db', $stripped);
        $this->assertStringNotContainsString('pgsql=', $stripped);

        // …navigation is not
        $this->assertStringContainsString('table=users', $stripped);
        $this->assertStringContainsString('file=static', $stripped);

        // …and an outbound link is left exactly as Adminer wrote it
        $this->assertStringContainsString('https://www.adminer.org/?v=1', $stripped);
    }
}
