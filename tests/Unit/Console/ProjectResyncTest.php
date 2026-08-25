<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\ProjectResync;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for the project:resync command.
 *
 * The command re-copies framework-owned scaffolded files (the pf-*.js UI hooks
 * and the docs tooling) from the installed framework's scaffolding into an
 * existing project. Tests point both targetBaseDir and scaffoldingDir at
 * temporary directories so the sync runs fully offline against controlled
 * fixtures — a "v1" project and a "v2" scaffolding — and every branch (refresh,
 * skip-missing, --all, --dry-run, unchanged, scope flags, error paths) is
 * verified by inspecting the resulting files, not just the output text.
 */
#[CoversClass(ProjectResync::class)]
class ProjectResyncTest extends TestCase
{
    private string $projectDir;
    private string $scaffoldDir;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->projectDir  = sys_get_temp_dir() . '/pramnos_resync_proj_' . $suffix;
        $this->scaffoldDir = sys_get_temp_dir() . '/pramnos_resync_scaf_' . $suffix;

        // Scaffolding source (the "v2" framework files).
        mkdir($this->scaffoldDir . '/assets/js', 0777, true);
        mkdir($this->scaffoldDir . '/scripts', 0777, true);
        mkdir($this->scaffoldDir . '/templates', 0777, true);
        file_put_contents($this->scaffoldDir . '/assets/js/pf-utils.js', 'UTILS_V2');
        file_put_contents($this->scaffoldDir . '/assets/js/pf-auth.js', 'AUTH_V2');
        file_put_contents($this->scaffoldDir . '/scripts/apidoc-to-openapi.js', 'GEN_V2');
        // The real generator ships as .cjs (a SPA project's package.json sets
        // "type": "module"); both extensions must be picked up by the sync.
        file_put_contents($this->scaffoldDir . '/scripts/apidoc-to-openapi.cjs', 'CJS_V2');
        file_put_contents($this->scaffoldDir . '/templates/doc.sh.stub', "#!/usr/bin/env bash\nDOC_V2\n");

        // Project root — a valid project (has app/app.php) with a stale subset.
        mkdir($this->projectDir . '/app', 0777, true);
        file_put_contents($this->projectDir . '/app/app.php', "<?php\nreturn ['name' => 'App'];\n");
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->projectDir);
        $this->rmdir($this->scaffoldDir);
    }

    /** Seed a project file (creating parent dirs) with given content. */
    private function seed(string $rel, string $content): void
    {
        $abs = $this->projectDir . '/' . $rel;
        if (!is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0777, true);
        }
        file_put_contents($abs, $content);
    }

    private function read(string $rel): string
    {
        return (string) file_get_contents($this->projectDir . '/' . $rel);
    }

    private function tester(array $overrides = []): CommandTester
    {
        $cmd = new ProjectResync();
        $cmd->targetBaseDir = $overrides['base']       ?? $this->projectDir;
        $cmd->scaffoldingDir = $overrides['scaffold']  ?? $this->scaffoldDir;
        $app = new Application();
        $app->add($cmd);
        return new CommandTester($cmd);
    }

    // ── The PWA group: icons and the manifest ───────────────────────────────

    /**
     * A scaffolding directory with a sibling `brand/favicons`, as the framework has.
     *
     * Nested one level deeper than the shared fixture, because `collectPwaFiles()`
     * finds the artwork at `dirname($scaffoldingDir) . '/brand/favicons'` — and with the
     * flat layout that resolves to the system temp directory itself, which no test may
     * write into.
     *
     * @return string The scaffolding path to hand the command
     */
    private function seedBrand(): string
    {
        $root     = $this->scaffoldDir . '/nested';
        $scaffold = $root . '/scaffolding';
        $brand    = $root . '/brand/favicons';
        mkdir($scaffold, 0777, true);
        mkdir($brand, 0777, true);

        file_put_contents($brand . '/favicon-32x32.png', 'FRAMEWORK-PNG');
        file_put_contents($brand . '/favicon.ico', 'FRAMEWORK-ICO');
        file_put_contents($brand . '/browserconfig.xml', '<x src="/ms-icon.png"/>');
        file_put_contents($brand . '/manifest.json', json_encode([
            'name'  => 'App',
            'icons' => [['src' => '/favicon-32x32.png', 'sizes' => '32x32']],
        ]));

        // webRootOf() looks for the front controller, since nothing records the
        // document root in configuration.
        $this->seed('www/index.php', '<?php');

        return $scaffold;
    }

    /**
     * Custom icons are kept, not overwritten.
     *
     * The one thing this group must never do. The framework ships placeholder artwork
     * and a project is expected to replace it; a resync run for an unrelated reason
     * that put the framework's logo back would be undoing work with no undo of its own,
     * and nothing in the output would look wrong.
     *
     * Asserted on the file contents rather than the output line, because a command that
     * printed "kept" and wrote anyway would pass the weaker test.
     */
    public function testCustomIconsAreKept(): void
    {
        // Arrange — the project has its own artwork under the framework's filename.
        $scaffold = $this->seedBrand();
        $this->seed('www/assets/favicons/favicon-32x32.png', 'MY-LOGO');

        // Act
        $tester = $this->tester(['scaffold' => $scaffold]);
        $tester->execute(['--pwa' => true]);

        // Assert — untouched, and said so.
        $this->assertSame('MY-LOGO', $this->read('www/assets/favicons/favicon-32x32.png'));
        $this->assertStringContainsString('kept', $tester->getDisplay());
    }

    /**
     * A missing icon is created, so the group is still useful.
     *
     * The counterpart of the test above: "never overwrite" must not collapse into
     * "never write". A project that has no icon at all wants the placeholder.
     */
    public function testAMissingIconIsCreated(): void
    {
        // Arrange
        $scaffold = $this->seedBrand();

        // Act — --all, since the file is absent and absent files are opt-in.
        $this->tester(['scaffold' => $scaffold])->execute(['--pwa' => true, '--all' => true]);

        // Assert
        $this->assertSame('FRAMEWORK-PNG', $this->read('www/assets/favicons/favicon-32x32.png'));
    }

    /**
     * The manifest gains the keys it lacks and keeps every key it has.
     *
     * Merged rather than copied, and that is the difference between this and the
     * images: an existing manifest is precisely the case that needs fixing. A project
     * scaffolded before `start_url` and `display` existed was detected by a browser and
     * then rejected as not installable — while also carrying a `short_name` somebody
     * shortened and an `icons` array somebody curated, neither of which a regenerated
     * file would have kept.
     */
    public function testTheManifestIsMergedRatherThanReplaced(): void
    {
        // Arrange — a manifest as an older scaffold left it, plus local edits.
        $scaffold = $this->seedBrand();
        $this->seed('www/manifest.json', json_encode([
            'name'       => 'My Application',
            'short_name' => 'MyApp',
            'icons'      => [['src' => 'assets/favicons/mine.png', 'sizes' => '48x48']],
        ]));

        // Act
        $this->tester(['scaffold' => $scaffold])->execute(['--pwa' => true]);

        // Assert
        $manifest = json_decode($this->read('www/manifest.json'), true);

        // …the missing standard keys arrived…
        $this->assertSame('./', $manifest['start_url']);
        $this->assertSame('standalone', $manifest['display']);

        // …and nothing local was lost.
        $this->assertSame('MyApp', $manifest['short_name'], 'a shortened name must survive');
        $this->assertSame(
            'assets/favicons/mine.png',
            $manifest['icons'][0]['src'],
            'a curated icon list must survive'
        );
    }

    /**
     * An unparseable manifest is left completely alone.
     *
     * It is a hand-editable file, so a syntax error is most likely somebody midway
     * through a change. Replacing it with a generated one would destroy both the edit
     * and the reason for it — and a resync is not the moment to find that out.
     */
    public function testAnUnparseableManifestIsNotTouched(): void
    {
        // Arrange
        $scaffold = $this->seedBrand();
        $this->seed('www/manifest.json', '{ "name": "half-edited",');

        // Act
        $this->tester(['scaffold' => $scaffold])->execute(['--pwa' => true]);

        // Assert
        $this->assertSame('{ "name": "half-edited",', $this->read('www/manifest.json'));
    }

    /**
     * The document root is detected, not assumed.
     *
     * `init` takes `--web-root` and writes nothing down, so the only evidence is which
     * directory holds the front controller. Assuming `www` would write a project's
     * icons into a directory nothing serves — and they would look present while being
     * unreachable, which is the shape of bug this whole exercise started with.
     */
    public function testTheDocumentRootIsDetected(): void
    {
        // Arrange — served from public/, and www/ does not exist.
        $root     = $this->scaffoldDir . '/nested';
        $scaffold = $root . '/scaffolding';
        $brand    = $root . '/brand/favicons';
        mkdir($scaffold, 0777, true);
        mkdir($brand, 0777, true);
        file_put_contents($brand . '/favicon.ico', 'FRAMEWORK-ICO');
        $this->seed('public/index.php', '<?php');

        // Act
        $this->tester(['scaffold' => $scaffold])->execute(['--pwa' => true, '--all' => true]);

        // Assert
        $this->assertFileExists($this->projectDir . '/public/favicon.ico');
        $this->assertFileDoesNotExist($this->projectDir . '/www/favicon.ico');
    }

    /**
     * A file that cannot be written is reported as failed, not as updated.
     *
     * The whole purpose of `project:resync` is that a framework-owned file downstream
     * **is** the framework's current one. Until 2026-08-16 the write was
     * `file_put_contents($abs, $content);` with the return value discarded, so a
     * write that failed still printed `updated`, still counted as updated in the
     * summary, and still exited `0`.
     *
     * The only trace was a PHP warning on stderr, inside a wall of output that a CI
     * job or a habitual `2>/dev/null` throws away. So a caller checking the exit
     * code — the correct way to run this from a deploy script — was told the resync
     * had succeeded while the file on disk was still the old one. A resync that
     * reports success without writing is the exact failure the command exists to
     * prevent.
     *
     * Reported by a consuming project, which reproduced it by running the command as
     * a user that could not write the target and confirming with `diff` that the file
     * was byte-identical afterwards.
     *
     * **The failure is produced with a directory in the target's place, not with
     * `chmod`.** The first version of this test made the file read-only, which the
     * test container — running as root, where the mode is advisory — ignored: the
     * test skipped, and a skipped test is green. `file_put_contents()` on a path that
     * is a directory fails for root too, so this runs everywhere.
     *
     * @return void
     */
    public function testAnUnwritableFileIsReportedAsFailed(): void
    {
        // Arrange — the destination path exists as a directory, so no write can land
        $rel = 'www/assets/js/pf-utils.js';
        mkdir($this->projectDir . '/' . $rel, 0777, true);

        // Act — --all, because the target is not a regular file and would otherwise
        // be reported as absent and skipped
        $tester  = $this->tester();
        $exit    = $tester->execute(['--all' => true]);
        $display = $tester->getDisplay();

        // Assert — the exit code is what a deploy script reads
        $this->assertNotSame(
            Command::SUCCESS,
            $exit,
            'A resync that could not write must not report success.'
        );

        // Assert — and the human output says so, naming the file
        $this->assertStringContainsString('failed', $display);
        $this->assertStringContainsString($rel, $display);
        $this->assertStringContainsString('FAILED', $display);

        // Assert — it is not counted as an update, which is what the summary claimed
        $this->assertDoesNotMatchRegularExpression('/[1-9]\d* updated/', $display);
    }

    /**
     * A directory that cannot be created is reported the same way.
     *
     * The other unchecked call on that path: `mkdir()` above the write. A parent that
     * exists as a regular file cannot be turned into a directory, for root either.
     *
     * @return void
     */
    public function testAnUncreatableDirectoryIsReportedAsFailed(): void
    {
        // Arrange — `www/assets/js` exists as a file, so its child cannot be created
        mkdir($this->projectDir . '/www/assets', 0777, true);
        file_put_contents($this->projectDir . '/www/assets/js', 'not a directory');

        // Act
        $tester  = $this->tester();
        $exit    = $tester->execute(['--all' => true]);
        $display = $tester->getDisplay();

        // Assert
        $this->assertNotSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('failed', $display);
        $this->assertStringContainsString('permissions', $display);
    }

    /**
     * A successful run still exits zero and says nothing about failures.
     *
     * The guard against over-correcting: a `0 failed` on every healthy run is noise
     * that teaches the reader to skip the line the one time it matters.
     *
     * @return void
     */
    public function testASuccessfulRunDoesNotMentionFailures(): void
    {
        // Arrange
        $this->seed('www/assets/js/pf-utils.js', 'UTILS_V1');

        // Act
        $tester  = $this->tester();
        $exit    = $tester->execute([]);
        $display = $tester->getDisplay();

        // Assert
        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringNotContainsString('FAILED', $display);
        $this->assertStringContainsString('Done.', $display);
    }

    /** Not a project root (no app/app.php) → clean failure. */
    public function testMissingAppConfigFails(): void
    {
        // Arrange: remove the marker that identifies a project root.
        unlink($this->projectDir . '/app/app.php');

        // Act
        $tester = $this->tester();
        $exit = $tester->execute([]);

        // Assert
        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('Could not find app/app.php', $tester->getDisplay());
    }

    /** A missing/invalid scaffolding dir → clean failure. */
    public function testMissingScaffoldingDirFails(): void
    {
        // Act: point scaffoldingDir at a non-existent path.
        $tester = $this->tester(['scaffold' => $this->scaffoldDir . '_nope']);
        $exit = $tester->execute([]);

        // Assert
        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('scaffolding directory', $tester->getDisplay());
    }

    /**
     * Default run refreshes files that already exist and skips missing ones —
     * it must never introduce tooling the project did not opt into.
     */
    public function testDefaultRefreshesExistingAndSkipsMissing(): void
    {
        // Arrange: only pf-utils.js and apidoc-to-openapi.js exist, both stale.
        $this->seed('www/assets/js/pf-utils.js', 'UTILS_V1');
        $this->seed('scripts/apidoc-to-openapi.js', 'GEN_V1');

        // Act
        $tester = $this->tester();
        $exit = $tester->execute([]);

        // Assert: exit OK, present files updated to v2...
        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertSame('UTILS_V2', $this->read('www/assets/js/pf-utils.js'));
        $this->assertSame('GEN_V2', $this->read('scripts/apidoc-to-openapi.js'));
        // ...and absent framework files are NOT created without --all.
        $this->assertFileDoesNotExist($this->projectDir . '/www/assets/js/pf-auth.js');
        $this->assertFileDoesNotExist($this->projectDir . '/scripts/doc.sh');
        $this->assertStringContainsString('2 updated', $tester->getDisplay());
        $this->assertStringContainsString('skipped', $tester->getDisplay());
    }

    /**
     * --all copies framework files that are not present yet (creating dirs),
     * including doc.sh which is rendered from the stub and marked executable.
     */
    public function testAllCopiesMissingFiles(): void
    {
        // Arrange: empty project (none of the framework files present).

        // Act
        $tester = $this->tester();
        $exit = $tester->execute(['--all' => true]);

        // Assert: every framework-owned file is created.
        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertSame('UTILS_V2', $this->read('www/assets/js/pf-utils.js'));
        $this->assertSame('AUTH_V2', $this->read('www/assets/js/pf-auth.js'));
        $this->assertSame('GEN_V2', $this->read('scripts/apidoc-to-openapi.js'));
        // .cjs too — that is the extension the real generator ships with.
        $this->assertSame('CJS_V2', $this->read('scripts/apidoc-to-openapi.cjs'));
        $this->assertStringContainsString('DOC_V2', $this->read('scripts/doc.sh'));
        $this->assertStringContainsString('created', $tester->getDisplay());

        // doc.sh must be executable (chmod 0755 applied to shell scripts).
        $this->assertTrue(
            (fileperms($this->projectDir . '/scripts/doc.sh') & 0100) !== 0,
            'doc.sh should be owner-executable'
        );
    }

    /** --dry-run reports intended actions but writes nothing to disk. */
    public function testDryRunWritesNothing(): void
    {
        // Arrange: a stale file that would otherwise be updated.
        $this->seed('www/assets/js/pf-utils.js', 'UTILS_V1');

        // Act
        $tester = $this->tester();
        $exit = $tester->execute(['--dry-run' => true, '--all' => true]);

        // Assert: output previews changes, but the file is untouched on disk.
        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertStringContainsString('Dry run', $tester->getDisplay());
        $this->assertStringContainsString('would', $tester->getDisplay());
        $this->assertSame('UTILS_V1', $this->read('www/assets/js/pf-utils.js'),
            'dry-run must not modify the existing file');
        $this->assertFileDoesNotExist($this->projectDir . '/www/assets/js/pf-auth.js',
            'dry-run must not create missing files');
    }

    /** A file already matching the source is reported unchanged, not rewritten. */
    public function testUnchangedFileIsReported(): void
    {
        // Arrange: project already has the current (v2) content.
        $this->seed('www/assets/js/pf-utils.js', 'UTILS_V2');

        // Act
        $tester = $this->tester();
        $tester->execute(['--js' => true]);

        // Assert
        $this->assertStringContainsString('unchanged', $tester->getDisplay());
        $this->assertStringContainsString('0 updated', $tester->getDisplay());
    }

    /** --js scopes the sync to the pf-*.js hooks only; scripts stay untouched. */
    public function testJsScopeOnly(): void
    {
        // Arrange
        $this->seed('www/assets/js/pf-utils.js', 'UTILS_V1');
        $this->seed('scripts/apidoc-to-openapi.js', 'GEN_V1');

        // Act
        $tester = $this->tester();
        $tester->execute(['--js' => true]);

        // Assert: JS updated, script left as-is.
        $this->assertSame('UTILS_V2', $this->read('www/assets/js/pf-utils.js'));
        $this->assertSame('GEN_V1', $this->read('scripts/apidoc-to-openapi.js'),
            '--js must not touch the docs tooling scripts');
    }

    /** --scripts scopes the sync to the docs tooling only; JS stays untouched. */
    public function testScriptsScopeOnly(): void
    {
        // Arrange
        $this->seed('www/assets/js/pf-utils.js', 'UTILS_V1');
        $this->seed('scripts/apidoc-to-openapi.js', 'GEN_V1');

        // Act
        $tester = $this->tester();
        $tester->execute(['--scripts' => true]);

        // Assert: script updated, JS left as-is.
        $this->assertSame('GEN_V2', $this->read('scripts/apidoc-to-openapi.js'));
        $this->assertSame('UTILS_V1', $this->read('www/assets/js/pf-utils.js'),
            '--scripts must not touch the pf-*.js hooks');
    }

    /**
     * The docs-tooling group merges the API-docs npm scripts into an existing
     * package.json while preserving whatever the project already declared.
     */
    public function testPackageJsonScriptsMergedIntoExisting(): void
    {
        // Arrange: a manifest with a custom script the merge must not clobber.
        $this->seed('package.json', json_encode([
            'name'    => 'oldapp',
            'scripts' => ['test' => 'phpunit'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        // Act
        $tester = $this->tester();
        $tester->execute(['--scripts' => true]);

        // Assert: existing script kept, API-docs scripts + rapidoc devDep added.
        $pkg = json_decode($this->read('package.json'), true);
        $this->assertSame('phpunit', $pkg['scripts']['test'], 'existing scripts must be preserved');
        // docs:build no longer runs the legacy apiDoc HTML step — OpenAPI/RapiDoc only.
        $this->assertSame('npm run openapi:generate', $pkg['scripts']['docs:build']);
        // Renamed to .cjs: a SPA project's package.json declares "type": "module",
        // which would otherwise turn this CommonJS generator into an ES module.
        $this->assertSame('node scripts/apidoc-to-openapi.cjs', $pkg['scripts']['openapi:generate']);
        // The two-version "Old apiDoc" pipeline is gone: no apidoc:generate, no apidoc devDep.
        $this->assertArrayNotHasKey('apidoc:generate', $pkg['scripts']);
        $this->assertArrayNotHasKey('apidoc', $pkg['devDependencies']);
        $this->assertArrayHasKey('rapidoc', $pkg['devDependencies']);
    }

    /** With --all, a project lacking package.json gets one seeded + merged. */
    public function testPackageJsonCreatedWithAll(): void
    {
        // Arrange: no package.json present.

        // Act
        $tester = $this->tester();
        $tester->execute(['--scripts' => true, '--all' => true]);

        // Assert
        $this->assertFileExists($this->projectDir . '/package.json');
        $pkg = json_decode($this->read('package.json'), true);
        $this->assertArrayHasKey('docs:build', $pkg['scripts']);
        $this->assertArrayHasKey('rapidoc', $pkg['devDependencies']);
    }

    /** The --js scope must not touch package.json (a docs-tooling concern). */
    public function testJsScopeLeavesPackageJsonUntouched(): void
    {
        // Arrange
        $this->seed('www/assets/js/pf-utils.js', 'UTILS_V1');
        $original = "{\n    \"name\": \"x\"\n}\n";
        $this->seed('package.json', $original);

        // Act
        $tester = $this->tester();
        $tester->execute(['--js' => true]);

        // Assert: JS synced, package.json left exactly as it was.
        $this->assertSame('UTILS_V2', $this->read('www/assets/js/pf-utils.js'));
        $this->assertSame($original, $this->read('package.json'),
            '--js must not merge package.json scripts');
    }

    /**
     * With auth-related features, the framework-documented endpoints are refreshed
     * into openapi-overrides.json while user-added paths are preserved.
     */
    public function testApiOverridesRefreshedAndUserPathsPreserved(): void
    {
        // Arrange: a project with features + an existing overrides file holding a
        // user-defined path and a stale (empty) framework surface.
        file_put_contents(
            $this->projectDir . '/app/app.php',
            "<?php\nreturn ['name' => 'App', 'features' => ['auth', 'authserver']];\n"
        );
        $this->seed('src/Api/openapi-overrides.json', json_encode([
            'paths' => ['/widgets' => ['get' => ['summary' => 'List widgets']]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        // Act
        $tester = $this->tester();
        $tester->execute(['--scripts' => true]);

        // Assert
        $ov = json_decode($this->read('src/Api/openapi-overrides.json'), true);
        $this->assertArrayHasKey('/me', $ov['paths'], 'framework endpoints injected');
        $this->assertArrayHasKey('/capabilities/sync', $ov['paths']);
        $this->assertArrayHasKey('/widgets', $ov['paths'], 'user-added paths are preserved');
        $this->assertArrayHasKey('OAuth2', $ov['components']['securitySchemes']);
    }

    /** Without auth-related features, openapi-overrides.json is left untouched. */
    public function testApiOverridesUntouchedWithoutFeatures(): void
    {
        // Arrange: setUp's app/app.php has no 'features' key.
        $this->seed('src/Api/openapi-overrides.json', "{\n    \"paths\": {}\n}\n");
        $before = $this->read('src/Api/openapi-overrides.json');

        // Act
        $tester = $this->tester();
        $tester->execute(['--scripts' => true]);

        // Assert
        $this->assertSame($before, $this->read('src/Api/openapi-overrides.json'),
            'no features → nothing framework-owned to document, file untouched');
    }

    /** --all creates openapi-overrides.json when absent (auth-only → no oauth scheme). */
    public function testApiOverridesCreatedWithAll(): void
    {
        // Arrange
        file_put_contents(
            $this->projectDir . '/app/app.php',
            "<?php\nreturn ['name' => 'App', 'features' => ['auth']];\n"
        );

        // Act
        $tester = $this->tester();
        $tester->execute(['--scripts' => true, '--all' => true]);

        // Assert
        $this->assertFileExists($this->projectDir . '/src/Api/openapi-overrides.json');
        $ov = json_decode($this->read('src/Api/openapi-overrides.json'), true);
        $this->assertArrayHasKey('/me', $ov['paths']);
        $this->assertArrayNotHasKey('/oauth/token', $ov['paths'], 'auth-only → no oauth paths');
        $this->assertArrayNotHasKey('securitySchemes', $ov['components'], 'auth-only → no oauth2 scheme');
    }

    /**
     * When the scaffolding groups yield no source files, the command reports
     * "nothing to sync" and still succeeds.
     */
    public function testNothingToSync(): void
    {
        // Arrange: strip every source file out of the scaffolding.
        unlink($this->scaffoldDir . '/assets/js/pf-utils.js');
        unlink($this->scaffoldDir . '/assets/js/pf-auth.js');
        unlink($this->scaffoldDir . '/scripts/apidoc-to-openapi.js');
        unlink($this->scaffoldDir . '/scripts/apidoc-to-openapi.cjs');
        unlink($this->scaffoldDir . '/templates/doc.sh.stub');

        // Act
        $tester = $this->tester();
        $exit = $tester->execute([]);

        // Assert
        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('Nothing to sync', $tester->getDisplay());
    }

    /**
     * Turn the fixture project into a SPA one and give the scaffolding a debug
     * panel stub.
     *
     * @param string $stack A value of app.php's `spa_stack`: 'svelte' (build,
     *                      sources in frontend/) or '' (build-less, sources in
     *                      www/assets/js/).
     */
    private function seedSpaProject(string $stack): void
    {
        file_put_contents(
            $this->projectDir . '/app/app.php',
            "<?php\nreturn ['name' => 'Acme', 'app_style' => 'spa', 'spa_stack' => '$stack'];\n"
        );
        // No stub to seed: the panel comes from the framework's single toolbar
        // source (DebugBarAsset), not from a copy under scaffolding/.
    }

    /**
     * --debug-panel refreshes a stale panel in place.
     *
     * The panel is the framework's own renderer living in the project; when the
     * framework improves it, an existing project must have a way to receive that
     * — otherwise the only route to a better panel is writing a second one.
     */
    public function testSpaScopeRefreshesDebugPanel(): void
    {
        // Arrange: a Vite-based SPA project holding last year's panel.
        $this->seedSpaProject('svelte');
        $this->seed('frontend/lib/debug.js', '// OLD PANEL');

        // Act
        $tester = $this->tester();
        $exit = $tester->execute(['--debug-panel' => true]);

        // Assert: refreshed, with the project's own name substituted into it.
        $this->assertSame(Command::SUCCESS, $exit);
        $panel = $this->read('frontend/lib/debug.js');
        $this->assertStringContainsString('Debug panel for Acme', $panel);
        $this->assertStringContainsString('FRAMEWORK-OWNED', $panel, 'the header says not to edit it');
        $this->assertStringContainsString('export function record(', $panel, 'the client can feed it');
        // No stub markers in shipped source: they would be a bug the reader has
        // to diagnose before trusting the file.
        $this->assertStringNotContainsString('{{ ', $panel);
    }

    /**
     * A project scaffolded before the panel existed gains it with --all.
     *
     * This is the case the flag exists for: without it the file can only be
     * copied by hand, and the practical outcome is a hand-rolled panel instead.
     */
    public function testSpaAllCreatesMissingDebugPanel(): void
    {
        // Arrange: SPA project, no panel anywhere.
        $this->seedSpaProject('svelte');

        // Act
        $tester = $this->tester();
        $tester->execute(['--debug-panel' => true, '--all' => true]);

        // Assert
        $this->assertFileExists($this->projectDir . '/frontend/lib/debug.js');
        $this->assertStringContainsString('created', $tester->getDisplay());
    }

    /** Without --all a missing panel is reported as skipped, not created. */
    public function testSpaSkipsMissingPanelWithoutAll(): void
    {
        // Arrange
        $this->seedSpaProject('svelte');

        // Act
        $tester = $this->tester();
        $tester->execute(['--debug-panel' => true]);

        // Assert
        $this->assertFileDoesNotExist($this->projectDir . '/frontend/lib/debug.js');
        $this->assertStringContainsString('use --all to add', $tester->getDisplay());
    }

    /**
     * The build-less stack keeps its sources inside the web root, so the panel
     * belongs under www/assets/js/ — resolved from app.php, not assumed.
     *
     * Writing it to frontend/ there would produce a file no page ever loads,
     * which looks exactly like a panel that does not work.
     */
    public function testSpaPathFollowsBuildlessStack(): void
    {
        // Arrange: no spa_stack → no build step.
        $this->seedSpaProject('');

        // Act
        $tester = $this->tester();
        $tester->execute(['--debug-panel' => true, '--all' => true]);

        // Assert
        $this->assertFileExists($this->projectDir . '/www/assets/js/lib/debug.js');
        $this->assertFileDoesNotExist($this->projectDir . '/frontend/lib/debug.js');
    }

    /** An MVC project has no front-end sources, so the SPA group yields nothing. */
    public function testSpaScopeIsANoOpForMvcProject(): void
    {
        // Arrange: the default fixture app.php has no app_style at all.

        // Act
        $tester = $this->tester();
        $exit = $tester->execute(['--debug-panel' => true, '--all' => true]);

        // Assert: nothing written, nothing claimed.
        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('Nothing to sync', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->projectDir . '/frontend/lib/debug.js');
    }

    /** --debug-panel is a scope: the pf-*.js hooks and docs tooling stay untouched. */
    public function testSpaScopeLeavesOtherGroupsAlone(): void
    {
        // Arrange
        $this->seedSpaProject('svelte');
        $this->seed('www/assets/js/pf-utils.js', 'UTILS_V1');
        $this->seed('scripts/apidoc-to-openapi.js', 'GEN_V1');
        $this->seed('frontend/lib/debug.js', '// OLD PANEL');

        // Act
        $tester = $this->tester();
        $tester->execute(['--debug-panel' => true]);

        // Assert
        $this->assertSame('UTILS_V1', $this->read('www/assets/js/pf-utils.js'), '--debug-panel must not touch the UI hooks');
        $this->assertSame('GEN_V1', $this->read('scripts/apidoc-to-openapi.js'), '--debug-panel must not touch the docs tooling');
        $this->assertStringNotContainsString('OLD PANEL', $this->read('frontend/lib/debug.js'));
    }

    /** With no scope flag the SPA group is synced along with the others. */
    public function testDefaultRunIncludesSpaGroup(): void
    {
        // Arrange
        $this->seedSpaProject('svelte');
        $this->seed('frontend/lib/debug.js', '// OLD PANEL');
        $this->seed('www/assets/js/pf-utils.js', 'UTILS_V1');

        // Act
        $tester = $this->tester();
        $tester->execute([]);

        // Assert: both groups moved.
        $panel = $this->read('frontend/lib/debug.js');
        $this->assertStringContainsString('Debug panel for Acme', $panel);
        $this->assertStringContainsString('FRAMEWORK-OWNED', $panel, 'the header says not to edit it');
        $this->assertStringContainsString('export function record(', $panel, 'the client can feed it');
        $this->assertSame('UTILS_V2', $this->read('www/assets/js/pf-utils.js'));
    }

    /**
     * A panel nothing calls is as silent as a missing one, so an api.js that
     * never records is reported — with the two lines to add.
     *
     * Not repaired automatically: lib/api.js is the project's file and people
     * edit it; rewriting it from the stub would discard those edits.
     */
    public function testUnwiredPanelIsReported(): void
    {
        // Arrange: panel present, client that never imports it.
        $this->seedSpaProject('svelte');
        $this->seed('frontend/lib/debug.js', '// OLD PANEL');
        $this->seed('frontend/lib/api.js', "export async function get() {}\n");

        // Act
        $tester = $this->tester();
        $tester->execute(['--debug-panel' => true]);

        // Assert
        $this->assertStringContainsString('does not feed the debug panel', $tester->getDisplay());
        $this->assertStringContainsString("from './debug.js'", $tester->getDisplay());
        // The advice is advice: the file itself is left exactly as it was.
        $this->assertSame("export async function get() {}\n", $this->read('frontend/lib/api.js'));
    }

    /** A client that already records stays silent — no false alarm. */
    public function testWiredPanelIsNotReported(): void
    {
        // Arrange
        $this->seedSpaProject('svelte');
        $this->seed('frontend/lib/debug.js', '// OLD PANEL');
        $this->seed('frontend/lib/api.js', "import { record } from './debug.js';\n");

        // Act
        $tester = $this->tester();
        $tester->execute(['--debug-panel' => true]);

        // Assert
        $this->assertStringNotContainsString('does not feed the debug panel', $tester->getDisplay());
    }

    /**
     * The wiring check needs both files: with no api.js at all (or no panel
     * yet) there is nothing to diagnose, and warning would be noise.
     */
    public function testWiringCheckSilentWhenApiClientAbsent(): void
    {
        // Arrange: panel only.
        $this->seedSpaProject('svelte');
        $this->seed('frontend/lib/debug.js', '// OLD PANEL');

        // Act
        $tester = $this->tester();
        $tester->execute(['--debug-panel' => true]);

        // Assert
        $this->assertStringNotContainsString('does not feed the debug panel', $tester->getDisplay());
    }

    /** --dry-run covers the SPA group too: reported, not written. */
    /**
     * "Nothing to sync" says why, and an MVC project is told it is MVC.
     *
     * The sentence used to be identical for a project with no SPA and for one whose
     * sources are somewhere this command does not look — and the second reading is
     * the one that sends somebody hunting in the wrong place. It cost a reviewer
     * exactly that, and the fix they made was a repo-wide rename they would otherwise
     * not have made.
     */
    public function testAnEmptyResyncExplainsItself(): void
    {
        // Arrange — a project with nothing of ours in it, and no SPA declared
        $tester = $this->tester();

        // Act — only the SPA group, which an MVC project has nothing in
        $tester->execute(['--debug-panel' => true]);

        // Assert
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Nothing to sync', $display);
        $this->assertStringContainsString('app_style', $display);
        $this->assertStringContainsString('no SPA front end', $display);
    }

    /**
     * A SPA project is told where it looked, and how to point it elsewhere.
     *
     * Without this the only route was renaming the directory to the one the
     * framework assumes.
     */
    public function testAnEmptyResyncNamesTheDirectoryItLookedIn(): void
    {
        // Arrange — a SPA project whose sources are not where the stack implies
        file_put_contents(
            $this->projectDir . '/app/app.php',
            "<?php\nreturn ['name' => 'App', 'app_style' => 'spa', 'spa_stack' => 'svelte'];\n"
        );

        // Act
        $tester = $this->tester();
        $tester->execute(['--debug-panel' => true]);

        // Assert — the path it tried, and the setting that changes it
        $display = $tester->getDisplay();
        $this->assertStringContainsString('frontend/', $display);
        $this->assertStringContainsString('spa_source_dir', $display);
        $this->assertStringContainsString('rather than renaming', $display);
    }

    /**
     * `spa_source_dir` is honoured, so a project keeps its own layout.
     */
    public function testAConfiguredSourceDirectoryIsUsed(): void
    {
        // Arrange — sources under admin-ui/, declared in app.php
        file_put_contents(
            $this->projectDir . '/app/app.php',
            "<?php\nreturn ['name' => 'App', 'app_style' => 'spa', 'spa_stack' => 'svelte',"
            . " 'spa_source_dir' => 'admin-ui/'];\n"
        );
        $this->seed('admin-ui/lib/debug.js', 'OLD PANEL');
        $this->seed('admin-ui/lib/api.js', "import { record } from './debug.js';");

        // Act
        $tester = $this->tester();
        $exit = $tester->execute(['--debug-panel' => true]);

        // Assert — the panel was refreshed where the project actually keeps it
        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertStringContainsString('admin-ui/lib/debug.js', $tester->getDisplay());
        $this->assertNotSame('OLD PANEL', $this->read('admin-ui/lib/debug.js'));
    }

    public function testSpaDryRunWritesNothing(): void
    {
        // Arrange
        $this->seedSpaProject('svelte');
        $this->seed('frontend/lib/debug.js', '// OLD PANEL');

        // Act
        $tester = $this->tester();
        $tester->execute(['--debug-panel' => true, '--dry-run' => true]);

        // Assert
        $this->assertSame('// OLD PANEL', $this->read('frontend/lib/debug.js'));
        $this->assertStringContainsString('would update', $tester->getDisplay());
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
