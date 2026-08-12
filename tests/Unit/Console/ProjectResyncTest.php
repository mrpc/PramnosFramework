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
        file_put_contents(
            $this->scaffoldDir . '/templates/spa-debug-panel.js.stub',
            "// Debug panel for the {{ appName }} SPA.\nexport function record() {}\n"
        );
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
        $this->assertStringContainsString('Debug panel for the Acme SPA', $this->read('frontend/lib/debug.js'));
        // The token must not survive: a stub marker in shipped source is a bug
        // the reader would have to diagnose before trusting the file.
        $this->assertStringNotContainsString('{{', $this->read('frontend/lib/debug.js'));
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
        file_put_contents(
            $this->scaffoldDir . '/templates/spa-debug-panel.js.stub',
            "// {{ appName }}\n"
        );

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
        $this->assertStringContainsString('Debug panel for the Acme SPA', $this->read('frontend/lib/debug.js'));
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
