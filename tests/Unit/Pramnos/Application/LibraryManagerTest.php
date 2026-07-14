<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\LibraryManager;

/**
 * Unit tests for {@see LibraryManager} — the shared engine that resolves and
 * installs front-end vendor libraries from the bundled asset catalog into a
 * project's www/ tree, and wires their registration into the app bootstrap.
 *
 * These tests drive the manager against a *controlled* temporary scaffolding
 * directory (its own assets.json + a bundled source file) rather than the real
 * shipped catalog. This lets every branch be exercised deterministically:
 *  - a CDN library (downloaded — mocked by PRAMNOS_TESTING),
 *  - a bundled library (copied from scaffolding/resources),
 *  - a library whose CSS dependency itself carries CSS (the cssDeps branch),
 *  - the "unknown / present / installed" install outcomes,
 *  - bootstrap registration: insert, idempotent skip, and the manual fallback
 *    when the anchor line is absent.
 *
 * Downloads never hit the network: PRAMNOS_TESTING (set in tests/bootstrap.php)
 * makes downloadFile() write a deterministic stub instead.
 */
#[CoversClass(LibraryManager::class)]
class LibraryManagerTest extends TestCase
{
    /** Temp scaffolding dir holding our crafted assets.json + bundled source. */
    private string $scaffoldingDir;

    /** Temp project www/ dir libraries are installed into. */
    private string $wwwDir;

    private LibraryManager $manager;

    protected function setUp(): void
    {
        // Arrange: a throwaway scaffolding dir with a hand-built catalog that
        // covers every code path (CDN, bundled, css-carrying dependency, etc.).
        $this->scaffoldingDir = sys_get_temp_dir() . '/pramnos_libmgr_scaf_' . bin2hex(random_bytes(4));
        $this->wwwDir         = sys_get_temp_dir() . '/pramnos_libmgr_www_' . bin2hex(random_bytes(4));
        mkdir($this->scaffoldingDir . '/resources/vendor/adapters', 0777, true);
        mkdir($this->wwwDir, 0777, true);

        $catalog = [
            'libraries' => [
                // A CSS-only base library used as a dependency that *has* css —
                // this is what makes the cssDeps branch of registrationLines fire.
                'basecss' => [
                    'version'    => '1.0.0',
                    'css'        => ['https://cdn.example.com/basecss@1.0.0/base.min.css'],
                    'js'         => [],
                    'local_path' => 'assets/vendor/basecss/1.0.0',
                ],
                // A CDN library with both js and css, requiring basecss (css dep)
                // and jquery (a dep with no css). Exercises requires + cssDeps.
                'widget' => [
                    'version'    => '2.1.0',
                    'requires'   => ['basecss', 'jquery'],
                    'css'        => ['https://cdn.example.com/widget@2.1.0/widget.min.css'],
                    'js'         => ['https://cdn.example.com/widget@2.1.0/widget.min.js'],
                    'local_path' => 'assets/vendor/widget/2.1.0',
                ],
                // Plain js-only dependency, no css → keeps it out of cssDeps.
                'jquery' => [
                    'version'    => '3.7.1',
                    'css'        => [],
                    'js'         => ['https://cdn.example.com/jquery@3.7.1/jquery.min.js'],
                    'local_path' => 'assets/vendor/jquery/3.7.1',
                ],
                // Mandatory flag — feeds mandatoryKeys()/mandatoryFromCatalog().
                'chartjs' => [
                    'version'    => '4.4.3',
                    'mandatory'  => true,
                    'css'        => [],
                    'js'         => ['https://cdn.example.com/chartjs@4.4.3/chart.umd.min.js'],
                    'local_path' => 'assets/vendor/chartjs/4.4.3',
                ],
                // Framework-bundled library: copied from scaffolding/resources,
                // not downloaded. source_path + bundled:true drive the copy path.
                'adapters' => [
                    'version'     => '1.2.0',
                    'bundled'     => true,
                    'source_path' => 'resources/vendor/adapters',
                    'css'         => [],
                    'js'          => ['adapter.js'],
                    'local_path'  => 'assets/vendor/adapters/1.2.0',
                ],
            ],
        ];
        file_put_contents(
            $this->scaffoldingDir . '/assets.json',
            (string) json_encode($catalog, JSON_PRETTY_PRINT)
        );
        // The bundled library's actual source file, copied on install.
        file_put_contents(
            $this->scaffoldingDir . '/resources/vendor/adapters/adapter.js',
            "/* bundled adapter */\n"
        );

        $this->manager = new LibraryManager($this->scaffoldingDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->scaffoldingDir);
        $this->removeDir($this->wwwDir);
    }

    // =========================================================================
    // Catalog accessors
    // =========================================================================

    /**
     * catalog() loads assets.json; libraryDef() returns a known entry and null
     * for an unknown key; availableKeys() lists every catalog key.
     */
    public function testCatalogAccessors(): void
    {
        // Act
        $catalog = $this->manager->catalog();

        // Assert — the crafted catalog round-trips through json_decode.
        $this->assertArrayHasKey('widget', $catalog['libraries']);
        $this->assertSame('2.1.0', $this->manager->libraryDef('widget')['version']);
        $this->assertNull($this->manager->libraryDef('doesnotexist'), 'unknown key → null');
        $this->assertContains('chartjs', $this->manager->availableKeys());
        $this->assertContains('adapters', $this->manager->availableKeys());
    }

    /**
     * A missing assets.json degrades to an empty catalog instead of throwing —
     * the manager must be usable even when scaffolding is absent.
     */
    public function testCatalogFallsBackToEmptyWhenFileMissing(): void
    {
        // Arrange — point a manager at an empty dir with no assets.json.
        $emptyDir = sys_get_temp_dir() . '/pramnos_libmgr_empty_' . bin2hex(random_bytes(4));
        mkdir($emptyDir, 0777, true);
        $manager = new LibraryManager($emptyDir);

        // Act + Assert
        $this->assertSame(['libraries' => []], $manager->catalog());
        $this->assertSame([], $manager->availableKeys());

        $this->removeDir($emptyDir);
    }

    /**
     * mandatoryKeys() (instance) and mandatoryFromCatalog() (static) agree and
     * return only libraries flagged mandatory:true.
     */
    public function testMandatoryKeys(): void
    {
        // Act + Assert
        $this->assertSame(['chartjs'], $this->manager->mandatoryKeys());
        $this->assertSame(
            ['chartjs'],
            LibraryManager::mandatoryFromCatalog($this->manager->catalog())
        );
        // An empty/absent catalog yields no mandatory keys.
        $this->assertSame([], LibraryManager::mandatoryFromCatalog([]));
    }

    // =========================================================================
    // expectedFiles / isInstalled
    // =========================================================================

    /**
     * expectedFiles() derives relative paths from URLs (CDN) and bare filenames
     * (bundled), combining css then js under the library's local_path.
     */
    public function testExpectedFilesForCdnAndBundled(): void
    {
        // Act
        $cdn     = $this->manager->expectedFiles($this->manager->libraryDef('widget'));
        $bundled = $this->manager->expectedFiles($this->manager->libraryDef('adapters'));

        // Assert — CDN filenames come from the URL path basename.
        $this->assertSame([
            'assets/vendor/widget/2.1.0/widget.min.css',
            'assets/vendor/widget/2.1.0/widget.min.js',
        ], $cdn);
        // Bundled filenames are used verbatim (already bare names).
        $this->assertSame(['assets/vendor/adapters/1.2.0/adapter.js'], $bundled);
    }

    /**
     * isInstalled() is false when files are missing and true once every
     * expected asset exists on disk; unknown keys are never installed.
     */
    public function testIsInstalledFalseThenTrue(): void
    {
        // Arrange — nothing installed yet.
        $this->assertFalse($this->manager->isInstalled('chartjs', $this->wwwDir));
        $this->assertFalse($this->manager->isInstalled('doesnotexist', $this->wwwDir), 'unknown → false');

        // Act — install the mandatory CDN library.
        $this->manager->install('chartjs', $this->wwwDir);

        // Assert — now every expected file exists.
        $this->assertTrue($this->manager->isInstalled('chartjs', $this->wwwDir));
    }

    // =========================================================================
    // install()
    // =========================================================================

    /**
     * Installing an unknown key returns status 'unknown' with no files and
     * writes nothing to disk.
     */
    public function testInstallUnknownKey(): void
    {
        // Act
        $result = $this->manager->install('doesnotexist', $this->wwwDir);

        // Assert
        $this->assertSame('unknown', $result['status']);
        $this->assertSame([], $result['files']);
    }

    /**
     * A fresh CDN install returns 'installed' and lays down the (mocked) asset;
     * a second install without --force short-circuits to 'present'.
     */
    public function testInstallFreshThenPresent(): void
    {
        // Act — first install downloads (mocked) the file.
        $first = $this->manager->install('jquery', $this->wwwDir);

        // Assert — fresh install.
        $this->assertSame('installed', $first['status']);
        $this->assertFileExists($this->wwwDir . '/assets/vendor/jquery/3.7.1/jquery.min.js');

        // Act — second install finds it present.
        $second = $this->manager->install('jquery', $this->wwwDir);

        // Assert — no re-work without force.
        $this->assertSame('present', $second['status']);
    }

    /**
     * The bundled branch copies files from scaffolding/resources rather than
     * downloading them, yielding status 'installed'.
     */
    public function testInstallBundledCopiesFromScaffolding(): void
    {
        // Act
        $result = $this->manager->install('adapters', $this->wwwDir);

        // Assert — copied verbatim from the scaffolding source_path.
        $this->assertSame('installed', $result['status']);
        $dest = $this->wwwDir . '/assets/vendor/adapters/1.2.0/adapter.js';
        $this->assertFileExists($dest);
        $this->assertStringContainsString('bundled adapter', (string) file_get_contents($dest));
    }

    /**
     * A bundled library whose source file is missing reports status 'failed'
     * (the copy could not happen) — covers the $ok=false branch.
     */
    public function testInstallBundledMissingSourceFails(): void
    {
        // Arrange — remove the bundled source so copy() cannot succeed.
        unlink($this->scaffoldingDir . '/resources/vendor/adapters/adapter.js');

        // Act
        $result = $this->manager->install('adapters', $this->wwwDir);

        // Assert
        $this->assertSame('failed', $result['status']);
    }

    /**
     * --force re-installs even when the asset is already present, returning
     * 'installed' rather than short-circuiting to 'present'.
     */
    public function testInstallForceReinstalls(): void
    {
        // Arrange — put it in place first.
        $this->manager->install('jquery', $this->wwwDir);

        // Act — force re-download.
        $result = $this->manager->install('jquery', $this->wwwDir, true);

        // Assert
        $this->assertSame('installed', $result['status']);
    }

    // =========================================================================
    // registrationLines()
    // =========================================================================

    /**
     * registrationLines() for a library with css AND requires emits both a
     * registerScript (js, with the full deps list) and a registerStyle (css,
     * with only the *css-carrying* deps). Unknown keys yield an empty list.
     */
    public function testRegistrationLinesWithCssAndRequires(): void
    {
        // Act
        $lines = $this->manager->registrationLines('widget');

        // Assert — one script + one style line.
        $this->assertCount(2, $lines);
        $js  = $lines[0];
        $css = $lines[1];

        // The JS line lists ALL declared requires as dependencies.
        $this->assertStringContainsString("registerScript('widget'", $js);
        $this->assertStringContainsString("['basecss', 'jquery']", $js);
        $this->assertStringContainsString("assets/vendor/widget/2.1.0/widget.min.js", $js);
        $this->assertStringContainsString("'2.1.0'", $js);

        // The CSS line lists ONLY deps that themselves carry css (basecss, not jquery).
        $this->assertStringContainsString("registerStyle('widget'", $css);
        $this->assertStringContainsString("['basecss']", $css);
        $this->assertStringNotContainsString('jquery', $css, 'jquery has no css → not a css dep');

        // Unknown key → no lines.
        $this->assertSame([], $this->manager->registrationLines('doesnotexist'));
    }

    /**
     * A dependency-free js-only library emits a single registerScript line with
     * an empty deps array — the no-requires / no-css path.
     */
    public function testRegistrationLinesNoDeps(): void
    {
        // Act
        $lines = $this->manager->registrationLines('jquery');

        // Assert
        $this->assertCount(1, $lines);
        $this->assertStringContainsString("registerScript('jquery'", $lines[0]);
        $this->assertStringContainsString('[]', $lines[0], 'no requires → empty deps array');
    }

    // =========================================================================
    // detectRegisteredHandles()
    // =========================================================================

    /**
     * detectRegisteredHandles() extracts the unique handles from
     * registerScript()/registerStyle() calls; a missing file yields [].
     */
    public function testDetectRegisteredHandles(): void
    {
        // Arrange
        $appFile = $this->wwwDir . '/Application.php';
        file_put_contents($appFile, <<<'PHP'
        <?php
        $doc->registerScript('leaflet', sURL . 'x', [], '1', true);
        $doc->registerStyle('leaflet', sURL . 'y', [], '1');
        $doc->registerScript('chartjs', sURL . 'z', [], '1', true);
        PHP);

        // Act
        $handles = $this->manager->detectRegisteredHandles($appFile);

        // Assert — deduped across script+style for leaflet.
        $this->assertSame(['leaflet', 'chartjs'], $handles);

        // A non-existent file returns an empty list, not an error.
        $this->assertSame([], $this->manager->detectRegisteredHandles($this->wwwDir . '/nope.php'));
    }

    // =========================================================================
    // registerInBootstrap()
    // =========================================================================

    /**
     * registerInBootstrap() inserts registration lines after the document
     * anchor, and a second call is a no-op (already registered → nothing added).
     */
    public function testRegisterInBootstrapInsertThenIdempotent(): void
    {
        // Arrange — an Application.php containing the required anchor line.
        $appFile = $this->wwwDir . '/Application.php';
        file_put_contents($appFile, <<<'PHP'
        <?php
        class Application
        {
            private function registerVendorLibraries(): void
            {
                $doc = \Pramnos\Framework\Factory::getDocument();
            }
        }
        PHP);

        // Act — first registration.
        $first = $this->manager->registerInBootstrap($appFile, ['jquery']);

        // Assert — jquery written, nothing left for manual insertion.
        $this->assertSame(['jquery'], $first['registered']);
        $this->assertSame([], $first['manual']);
        $this->assertStringContainsString("registerScript('jquery'", (string) file_get_contents($appFile));

        // Act — second registration of the same handle.
        $second = $this->manager->registerInBootstrap($appFile, ['jquery']);

        // Assert — idempotent: nothing registered again, not duplicated in file.
        $this->assertSame([], $second['registered']);
        $this->assertSame(
            1,
            substr_count((string) file_get_contents($appFile), "registerScript('jquery'")
        );
    }

    /**
     * When the anchor line is absent, registerInBootstrap() writes nothing and
     * hands the lines back in 'manual' for the caller to surface.
     */
    public function testRegisterInBootstrapManualWhenAnchorMissing(): void
    {
        // Arrange — a file WITHOUT the getDocument() anchor.
        $appFile = $this->wwwDir . '/Application.php';
        file_put_contents($appFile, "<?php\nclass Application {}\n");
        $before = (string) file_get_contents($appFile);

        // Act
        $result = $this->manager->registerInBootstrap($appFile, ['jquery']);

        // Assert — nothing registered, lines returned for manual insertion.
        $this->assertSame([], $result['registered']);
        $this->assertNotEmpty($result['manual']);
        $this->assertStringContainsString("registerScript('jquery'", $result['manual'][0]);
        $this->assertSame($before, (string) file_get_contents($appFile), 'file must be untouched');
    }

    /**
     * Registering only already-known handles (or unknown keys) produces no
     * lines at all → both arrays empty and the file is never opened.
     */
    public function testRegisterInBootstrapNoLines(): void
    {
        // Arrange — file already registers jquery.
        $appFile = $this->wwwDir . '/Application.php';
        file_put_contents($appFile, "<?php\n\$doc->registerScript('jquery', 'x', [], '1', true);\n");

        // Act — ask to register jquery (already there) and an unknown key.
        $result = $this->manager->registerInBootstrap($appFile, ['jquery', 'doesnotexist']);

        // Assert — nothing to do.
        $this->assertSame([], $result['registered']);
        $this->assertSame([], $result['manual']);
    }

    /**
     * A CDN install where the write fails must report status 'failed' with no
     * installed files — covering the else-branch $ok=false path. The failure is
     * forced by placing a *directory* where the asset file should be written, so
     * downloadFile()'s file_put_contents() cannot succeed (deterministic, and
     * unaffected by the running uid).
     */
    public function testInstallCdnDownloadFailure(): void
    {
        // Arrange — create a directory exactly where jquery.min.js would be
        // written, so writing the file is impossible.
        $destDir = $this->wwwDir . '/assets/vendor/jquery/3.7.1';
        mkdir($destDir . '/jquery.min.js', 0777, true);

        // Act — force bypasses the isInstalled short-circuit; the write fails.
        // Swallow the expected "Is a directory" warning from file_put_contents.
        set_error_handler(static fn (): bool => true);
        try {
            $result = $this->manager->install('jquery', $this->wwwDir, true);
        } finally {
            restore_error_handler();
        }

        // Assert — failed status, no files recorded.
        $this->assertSame('failed', $result['status']);
        $this->assertSame([], $result['files']);
    }

    /**
     * registrationLines() for a framework-bundled library must resolve the asset
     * filename from the bare entry (not a URL), exercising the bundled branch of
     * assetFilename(). A dependency-free bundled js library yields one
     * registerScript line with an empty deps array.
     */
    public function testRegistrationLinesForBundledLibrary(): void
    {
        // Act
        $lines = $this->manager->registrationLines('adapters');

        // Assert — a single script line built from the bare bundled filename.
        $this->assertCount(1, $lines);
        $this->assertStringContainsString("registerScript('adapters'", $lines[0]);
        $this->assertStringContainsString('assets/vendor/adapters/1.2.0/adapter.js', $lines[0]);
        $this->assertStringContainsString("'1.2.0'", $lines[0]);
        $this->assertStringContainsString('[]', $lines[0], 'no requires → empty deps array');
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
