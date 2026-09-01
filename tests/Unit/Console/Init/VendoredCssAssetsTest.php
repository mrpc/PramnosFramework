<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Init;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;

/**
 * Pulling a CDN stylesheet's own references local — 51 statements, never executed.
 *
 * `init` can install a front-end library by downloading it, and a stylesheet is not one file: Font
 * Awesome's CSS names five webfont files, a theme's CSS names its images. Download only the CSS and
 * the project gets a stylesheet whose every `url()` still points at the CDN — which works in
 * development, and is exactly the outcome somebody chose `init` to avoid. Worse, it works *silently*:
 * the page looks right until the CDN is unreachable, blocked by a content policy, or the machine is
 * offline, and then the icons are empty boxes with no error anywhere.
 *
 * So the two methods here do the unglamorous part: find every reference, resolve it against the
 * stylesheet's own URL, fetch it beside the CSS, and rewrite the CSS to point at the copy.
 *
 * The resolution is where this goes wrong, and one shape matters more than the rest.
 * `../webfonts/fa-solid-900.woff2` is what a CDN stylesheet under `/npm/pkg/css/` actually contains,
 * and the parent segments have to be *resolved* rather than pasted — pasted, it asks the server for
 * `/npm/pkg/css/../webfonts/...`, which some servers answer and some do not, so it half-works
 * depending on the CDN.
 *
 * The rewriting has its own trap, asserted below: `?#iefix` and `#fontawesome` are how font stacks
 * disambiguate formats, and neither belongs in a filename — but the *original* reference, fragment
 * included, is what has to be replaced in the CSS text, or the rewrite silently matches nothing and
 * the stylesheet still points at the CDN while the files sit downloaded and unused.
 *
 * No network: `downloadFile()` already answers from a `PRAMNOS_TESTING` seam, writing a placeholder
 * instead of fetching, which is what makes the whole path testable without one.
 */
#[CoversClass(Init::class)]
class VendoredCssAssetsTest extends TestCase
{
    private Init $command;

    private string $dir = '';

    protected function setUp(): void
    {
        $this->command = new Init();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'pramnos-vendor-' . bin2hex(random_bytes(6));
        mkdir($this->dir . DIRECTORY_SEPARATOR . 'css', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($full) ? $this->removeTree($full) : @unlink($full);
        }

        @rmdir($path);
    }

    private function resolve(string $reference, string $sourceUrl): ?string
    {
        return (new \ReflectionMethod(Init::class, 'resolveAssetUrl'))
            ->invoke($this->command, $reference, $sourceUrl);
    }

    /** Write a stylesheet and vendor its references; returns [count, rewritten CSS]. */
    private function vendor(string $css, string $sourceUrl): array
    {
        $cssPath = $this->dir . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'app.css';
        file_put_contents($cssPath, $css);

        $count = (new \ReflectionMethod(Init::class, 'vendorCssReferences'))
            ->invoke($this->command, $cssPath, $sourceUrl, $this->dir, null);

        return [(int) $count, (string) file_get_contents($cssPath)];
    }

    // ── Resolving a reference ─────────────────────────────────────────────────

    /**
     * An already-absolute reference is left alone.
     *
     * A stylesheet may name a font on another host entirely, and rewriting that against the
     * stylesheet's own origin would ask the wrong server for it.
     */
    public function testAnAbsoluteReferenceIsUnchanged(): void
    {
        // Act & Assert
        $this->assertSame(
            'https://other.example/f.woff2',
            $this->resolve('https://other.example/f.woff2', 'https://cdn.example/npm/pkg/css/a.css')
        );
        $this->assertSame(
            'http://other.example/f.woff2',
            $this->resolve('http://other.example/f.woff2', 'https://cdn.example/npm/pkg/css/a.css')
        );
    }

    /**
     * A protocol-relative reference becomes https, not http.
     *
     * `//host/path` inherits the *page's* scheme in a browser, which is not available here — and
     * guessing http would downgrade an asset on an https page, where a browser blocks it outright
     * and the icon simply never appears.
     */
    public function testAProtocolRelativeReferenceBecomesHttps(): void
    {
        // Act & Assert
        $this->assertSame(
            'https://cdn.example/f.woff2',
            $this->resolve('//cdn.example/f.woff2', 'https://cdn.example/npm/pkg/css/a.css')
        );
    }

    /** A root-relative reference is placed on the stylesheet's own origin. */
    public function testARootRelativeReferenceUsesTheOrigin(): void
    {
        // Act & Assert
        $this->assertSame(
            'https://cdn.example/webfonts/f.woff2',
            $this->resolve('/webfonts/f.woff2', 'https://cdn.example/npm/pkg/css/a.css')
        );
    }

    /**
     * A parent-relative reference is resolved, not pasted.
     *
     * The shape that matters: `../webfonts/fa-solid-900.woff2` in a stylesheet under
     * `/npm/pkg/css/` is `/npm/pkg/webfonts/...`. Pasted, it asks for
     * `/npm/pkg/css/../webfonts/...` — which some servers normalise and some reject, so the bug
     * half-works depending on which CDN the project chose.
     */
    public function testAParentRelativeReferenceIsResolvedRatherThanPasted(): void
    {
        // Act
        $resolved = $this->resolve(
            '../webfonts/fa-solid-900.woff2',
            'https://cdn.example/npm/pkg/css/all.css'
        );

        // Assert
        $this->assertSame('https://cdn.example/npm/pkg/webfonts/fa-solid-900.woff2', $resolved);
        $this->assertStringNotContainsString('..', (string) $resolved, 'the parent segment was pasted');
    }

    /** A sibling reference resolves against the stylesheet's own directory. */
    public function testASiblingReferenceResolvesAgainstTheStylesheetsDirectory(): void
    {
        // Act & Assert
        $this->assertSame(
            'https://cdn.example/npm/pkg/css/img/bg.png',
            $this->resolve('img/bg.png', 'https://cdn.example/npm/pkg/css/all.css')
        );
        $this->assertSame(
            'https://cdn.example/npm/pkg/css/bg.png',
            $this->resolve('./bg.png', 'https://cdn.example/npm/pkg/css/all.css')
        );
    }

    /**
     * A non-standard port survives.
     *
     * A local mirror or a proxy is how an installation without internet access installs these at
     * all, and dropping the port asks port 443 of a host that is not listening there.
     */
    public function testANonStandardPortIsKept(): void
    {
        // Act & Assert
        $this->assertSame(
            'http://mirror.internal:8080/pkg/webfonts/f.woff2',
            $this->resolve('../webfonts/f.woff2', 'http://mirror.internal:8080/pkg/css/all.css')
        );
    }

    /**
     * A source URL that is not a URL yields null rather than a guess.
     *
     * The caller skips a reference it cannot place, which leaves the CSS pointing at the CDN for
     * that one file — correct, and far better than rewriting it to a path that resolves to nothing
     * and turns a working remote asset into a 404.
     */
    public function testAnUnusableSourceUrlYieldsNull(): void
    {
        // Act & Assert
        $this->assertNull($this->resolve('../webfonts/f.woff2', 'not a url at all'));
        $this->assertNull($this->resolve('webfonts/f.woff2', '/only/a/path.css'));
    }

    // ── Vendoring the stylesheet ──────────────────────────────────────────────

    /**
     * Each reference is downloaded beside the CSS and the CSS is rewritten to point at it.
     *
     * The whole purpose. The assertion is on both halves: the file exists *and* the stylesheet no
     * longer names the CDN — because downloading without rewriting leaves files nobody reads, and
     * rewriting without downloading leaves a stylesheet pointing at nothing.
     */
    public function testEachReferenceIsDownloadedAndTheCssRewritten(): void
    {
        // Arrange
        $css = '@font-face{src:url("../webfonts/fa-solid-900.woff2") format("woff2");}'
            . "\n.bg{background:url(img/bg.png);}";

        // Act
        [$count, $rewritten] = $this->vendor($css, 'https://cdn.example/npm/pkg/css/all.css');

        // Assert
        $this->assertSame(2, $count, 'not every reference was vendored');
        $this->assertFileExists($this->dir . '/files/fa-solid-900.woff2');
        $this->assertFileExists($this->dir . '/files/bg.png');

        $this->assertStringContainsString('files/fa-solid-900.woff2', $rewritten);
        $this->assertStringContainsString('files/bg.png', $rewritten);
        $this->assertStringNotContainsString('../webfonts/', $rewritten, 'the CSS still points away');
    }

    /**
     * A reference carrying `?#iefix` is downloaded under a clean name and still rewritten.
     *
     * The trap. The fragment disambiguates a font format and has no place in a filename, so the
     * download strips it — but the text replaced in the CSS has to be the *original* reference,
     * fragment included. Strip it from both and the rewrite matches nothing: the file is downloaded,
     * the stylesheet still points at the CDN, and nothing reports a problem.
     */
    public function testAReferenceWithAFragmentIsCleanedForTheFileAndMatchedInTheCss(): void
    {
        // Arrange
        $css = '@font-face{src:url("../webfonts/fa-brands-400.eot?#iefix") format("embedded-opentype");}';

        // Act
        [$count, $rewritten] = $this->vendor($css, 'https://cdn.example/npm/pkg/css/all.css');

        // Assert
        $this->assertSame(1, $count);
        $this->assertFileExists(
            $this->dir . '/files/fa-brands-400.eot',
            'the fragment reached the filename'
        );
        $this->assertStringContainsString(
            'files/fa-brands-400.eot',
            $rewritten,
            'the fragment was stripped before matching, so the rewrite hit nothing'
        );
        $this->assertStringNotContainsString('../webfonts/', $rewritten);
    }

    /**
     * The same reference twice is downloaded once and counted once.
     *
     * A font stack names the same file in several `@font-face` blocks, and a count that grew per
     * occurrence would report installing eleven files where five exist — while re-fetching each
     * one, which on a slow link is the difference between an install that finishes and one somebody
     * interrupts.
     */
    public function testARepeatedReferenceIsFetchedOnce(): void
    {
        // Arrange
        $css = '.a{background:url(img/bg.png);}'
            . "\n.b{background:url(img/bg.png);}"
            . "\n.c{background:url('img/bg.png');}";

        // Act
        [$count, $rewritten] = $this->vendor($css, 'https://cdn.example/npm/pkg/css/all.css');

        // Assert
        $this->assertSame(1, $count, 'the same file was counted more than once');
        $this->assertSame(
            3,
            substr_count($rewritten, 'files/bg.png'),
            'only some occurrences were rewritten, so the stylesheet is half local'
        );
    }

    /**
     * Inline data and fragment-only references are left alone.
     *
     * A `data:` URI is already local, and `url(#gradient)` addresses an element in the document.
     * Treating either as a file to fetch turns a working stylesheet into one naming a download that
     * cannot exist.
     */
    public function testDataUrisAndFragmentsAreSkipped(): void
    {
        // Arrange
        $css = '.a{background:url(data:image/png;base64,AAAA);}'
            . "\n.b{fill:url(#gradient);}";

        // Act
        [$count, $rewritten] = $this->vendor($css, 'https://cdn.example/npm/pkg/css/all.css');

        // Assert
        $this->assertSame(0, $count);
        $this->assertStringContainsString('data:image/png', $rewritten, 'a data URI was rewritten');
        $this->assertStringContainsString('url(#gradient)', $rewritten);
    }

    /**
     * A stylesheet with no references is left exactly as it was.
     *
     * Most stylesheets are this. Rewriting the file anyway would touch its timestamp on every
     * install, which is the sort of thing that makes a build cache miss for no reason.
     */
    public function testAStylesheetWithNoReferencesIsUntouched(): void
    {
        // Arrange
        $css = ".a{color:red}\n.b{color:blue}\n";

        // Act
        [$count, $rewritten] = $this->vendor($css, 'https://cdn.example/npm/pkg/css/all.css');

        // Assert
        $this->assertSame(0, $count);
        $this->assertSame($css, $rewritten);
    }

    /**
     * An unreadable or empty stylesheet is zero, not a failure.
     *
     * A library whose CSS failed to download is already reported by the caller; raising here would
     * turn one missing optional asset into an aborted `init`, with the project half-scaffolded.
     */
    public function testAnUnreadableStylesheetIsZero(): void
    {
        // Act
        $missing = (new \ReflectionMethod(Init::class, 'vendorCssReferences'))->invoke(
            $this->command,
            $this->dir . '/css/not-there.css',
            'https://cdn.example/npm/pkg/css/all.css',
            $this->dir,
            null
        );

        // Assert
        $this->assertSame(0, $missing);

        // And an empty one, which is what a truncated download leaves behind.
        [$empty] = $this->vendor('', 'https://cdn.example/npm/pkg/css/all.css');
        $this->assertSame(0, $empty);
    }

    /**
     * A reference that cannot be placed is skipped, and the rest are still vendored.
     *
     * One unresolvable reference must not cost the other four. The stylesheet then points at the CDN
     * for that one file, which is the honest outcome — it is what it pointed at before.
     */
    public function testAnUnplaceableReferenceDoesNotStopTheRest(): void
    {
        // Arrange — a source URL with no host, so the relative reference cannot be resolved
        $css = '.a{background:url(img/bg.png);}'
            . "\n.b{background:url(https://other.example/logo.svg);}";

        // Act
        [$count, $rewritten] = $this->vendor($css, '/no/host/here.css');

        // Assert
        $this->assertSame(1, $count, 'the absolute reference should still have been vendored');
        $this->assertStringContainsString('files/logo.svg', $rewritten);
        $this->assertStringContainsString('img/bg.png', $rewritten, 'the unplaceable one was rewritten');
    }
}
