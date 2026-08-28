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
     * The root usertype is 100, and that is asserted rather than assumed.
     *
     * A floor that drifted down to the administration area's — 80 on a typical deployment —
     * would be invisible until it mattered: everybody who can open `/admin` would be able to
     * read and write every row in the database, on production, and nothing about the screen
     * would look different.
     */
    public function testTheFloorIsRoot(): void
    {
        // Act & Assert
        $this->assertSame(100, Adminer::ROOT_USERTYPE);
    }
}
