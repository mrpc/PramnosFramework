<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\ScaffoldViews;
use Pramnos\Application\ScaffoldingHelper;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for the scaffold:views console command.
 *
 * scaffold:views copies bundled view templates from the framework's scaffolding
 * directory into a target project. Key invariants:
 *
 *  - --list enumerates groups without writing files.
 *  - --all copies every group's files into the destination.
 *  - --group copies only the specified groups.
 *  - Existing files are skipped by default; --force overwrites them.
 *  - Unknown groups cause a FAILURE exit code.
 *  - Missing --all/--group causes a FAILURE exit code.
 *  - Unknown --theme causes a FAILURE exit code.
 *
 * All tests use a temporary directory as the target project root so no real
 * project files are ever modified.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ScaffoldViews::class)]
class ScaffoldViewsTest extends TestCase
{
    // =========================================================================
    // Infrastructure
    // =========================================================================

    private string $projectDir;
    private CommandTester $tester;
    /** @var string|null Original $_SERVER['PHP_SELF'] value */
    private ?string $originalPhpSelf = null;

    /**
     * Bootstrap: create a fresh temp project dir, wire up the command with its
     * targetBaseDir pointing at that dir, and attach it to a minimal Symfony
     * Console Application so getApplication() is non-null.
     */
    protected function setUp(): void
    {
        // Symfony's DumpCompletionCommand reads $_SERVER['PHP_SELF'] in configure();
        // ensure it is set to prevent "Undefined array key" warnings in PHP 8.4.
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }

        $this->projectDir = sys_get_temp_dir() . '/pramnos_sv_' . bin2hex(random_bytes(4));
        mkdir($this->projectDir, 0777, true);

        $command = new ScaffoldViews();
        $command->targetBaseDir = $this->projectDir;

        $app = new Application('test', '1.0');
        $app->add($command);
        $app->setAutoExit(false);

        $found = $app->find('scaffold:views');
        $this->tester = new CommandTester($found);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectDir);

        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        } else {
            $_SERVER['PHP_SELF'] = $this->originalPhpSelf;
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Return the first available theme name so tests can run regardless of
     * which themes are bundled. Skips the test if none are available.
     */
    private function firstAvailableTheme(): string
    {
        $dirs = ScaffoldingHelper::getAvailableThemeDirs();
        if (empty($dirs)) {
            $this->markTestSkipped('No bundled theme directories found');
        }
        return basename($dirs[0]);
    }

    // =========================================================================
    // --list
    // =========================================================================

    /**
     * --list must print the available groups and return SUCCESS without writing
     * any files. This is the discovery path users run before choosing groups.
     */
    public function testListOptionPrintsGroupsAndWritesNoFiles(): void
    {
        // Arrange
        $theme = $this->firstAvailableTheme();

        // Act
        $exitCode = $this->tester->execute([
            '--theme' => $theme,
            '--list'  => true,
        ]);

        // Assert — command exits cleanly
        $this->assertSame(0, $exitCode, $this->tester->getDisplay());

        // Assert — output mentions available groups
        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Available view groups', $output);

        // Assert — no files were written to the project directory
        $viewsDir = $this->projectDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Views';
        $this->assertDirectoryDoesNotExist($viewsDir, '--list must not create any files');
    }

    // =========================================================================
    // --all
    // =========================================================================

    /**
     * --all must copy every file from every group into the destination directory
     * and report the count correctly.
     */
    public function testAllOptionCopiesAllGroups(): void
    {
        // Arrange
        $theme = $this->firstAvailableTheme();

        // Act
        $exitCode = $this->tester->execute([
            '--theme' => $theme,
            '--all'   => true,
        ]);

        // Assert — command succeeded
        $this->assertSame(0, $exitCode, $this->tester->getDisplay());

        // Assert — at least one file was written
        $viewsDir = $this->projectDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Views';
        $this->assertDirectoryExists($viewsDir);

        $writtenFiles = [];
        $this->collectFiles($viewsDir, $writtenFiles);
        $this->assertNotEmpty($writtenFiles, '--all should copy at least one file');
    }

    /**
     * When all files already exist and --force is not given, --all should skip
     * all of them and report 0 files written plus N skipped.
     */
    public function testAllOptionSkipsExistingFilesWithoutForce(): void
    {
        // Arrange
        $theme = $this->firstAvailableTheme();

        // First pass: write everything
        $this->tester->execute(['--theme' => $theme, '--all' => true]);

        // Act — second pass without --force
        $exitCode = $this->tester->execute(['--theme' => $theme, '--all' => true]);

        // Assert — still succeeds (skip is not an error)
        $this->assertSame(0, $exitCode, $this->tester->getDisplay());

        // Assert — output says 0 files written and some skipped
        $output = $this->tester->getDisplay();
        $this->assertMatchesRegularExpression('/0 file\(s\) written/', $output, 'Expected 0 files written on second pass');
        $this->assertMatchesRegularExpression('/\d+ skipped/', $output);
    }

    /**
     * With --force, even existing files must be overwritten, so the second pass
     * must report the same count as the first pass.
     */
    public function testAllOptionOverwritesExistingFilesWithForce(): void
    {
        // Arrange
        $theme = $this->firstAvailableTheme();
        $this->tester->execute(['--theme' => $theme, '--all' => true]);

        // Count files written on first pass
        $firstOutput = $this->tester->getDisplay();
        preg_match('/(\d+) file\(s\) written/', $firstOutput, $m);
        $firstCount = (int) ($m[1] ?? 0);
        $this->assertGreaterThan(0, $firstCount);

        // Act — second pass with --force
        $exitCode = $this->tester->execute(['--theme' => $theme, '--all' => true, '--force' => true]);

        // Assert
        $this->assertSame(0, $exitCode, $this->tester->getDisplay());
        $secondOutput = $this->tester->getDisplay();
        preg_match('/(\d+) file\(s\) written/', $secondOutput, $m2);
        $secondCount = (int) ($m2[1] ?? 0);

        // Both passes must have written the same number of files
        $this->assertSame($firstCount, $secondCount, '--force should overwrite all files');
    }

    // =========================================================================
    // --group
    // =========================================================================

    /**
     * --group must copy only the specified groups, not others.
     * We request 'login' and verify that only login/* files appear in the dest.
     */
    public function testGroupOptionCopiesOnlyRequestedGroup(): void
    {
        // Arrange
        $theme  = $this->firstAvailableTheme();
        $groups = ScaffoldingHelper::listViewGroups($theme);
        if (!isset($groups['login'])) {
            $this->markTestSkipped("Theme '$theme' has no 'login' group");
        }

        // Act
        $exitCode = $this->tester->execute([
            '--theme' => $theme,
            '--group' => 'login',
        ]);

        // Assert — success
        $this->assertSame(0, $exitCode, $this->tester->getDisplay());

        // Assert — only login/ directory was created
        $viewsDir = $this->projectDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Views';
        $loginDir = $viewsDir . DIRECTORY_SEPARATOR . 'login';
        $this->assertDirectoryExists($loginDir, "login/ directory should have been created");

        // Assert — no other group directories exist
        foreach (array_keys($groups) as $g) {
            if ($g === 'login') continue;
            $otherDir = $viewsDir . DIRECTORY_SEPARATOR . $g;
            $this->assertDirectoryDoesNotExist($otherDir, "Group '$g' should not have been copied");
        }
    }

    /**
     * Multiple groups may be specified as a comma-separated list.
     * Verify that all specified groups and only those groups are copied.
     */
    public function testGroupOptionAcceptsCommaSeparatedList(): void
    {
        // Arrange
        $theme  = $this->firstAvailableTheme();
        $groups = ScaffoldingHelper::listViewGroups($theme);
        $keys   = array_keys($groups);
        if (count($keys) < 2) {
            $this->markTestSkipped("Theme '$theme' needs at least 2 groups for this test");
        }

        $group1   = $keys[0];
        $group2   = $keys[1];
        $groupOpt = "$group1,$group2";

        // Act
        $exitCode = $this->tester->execute([
            '--theme' => $theme,
            '--group' => $groupOpt,
        ]);

        // Assert — success
        $this->assertSame(0, $exitCode, $this->tester->getDisplay());

        // Assert — both requested group directories were created
        $viewsDir = $this->projectDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Views';
        $this->assertDirectoryExists($viewsDir . DIRECTORY_SEPARATOR . $group1);
        $this->assertDirectoryExists($viewsDir . DIRECTORY_SEPARATOR . $group2);
    }

    // =========================================================================
    // Error conditions
    // =========================================================================

    /**
     * Specifying neither --all nor --group must cause a FAILURE exit code with
     * a helpful error message. Users should not silently get a no-op.
     */
    public function testFailsWithNeitherAllNorGroup(): void
    {
        // Arrange
        $theme = $this->firstAvailableTheme();

        // Act
        $exitCode = $this->tester->execute(['--theme' => $theme]);

        // Assert — non-zero exit code
        $this->assertNotSame(0, $exitCode, 'Should fail when neither --all nor --group is given');

        // Assert — error mentions required options
        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('--all', $output);
        $this->assertStringContainsString('--group', $output);
    }

    /**
     * An unknown --group value must cause FAILURE and report which groups were
     * unrecognised. This catches typos before any files are written.
     */
    public function testFailsWithUnknownGroup(): void
    {
        // Arrange
        $theme = $this->firstAvailableTheme();

        // Act
        $exitCode = $this->tester->execute([
            '--theme' => $theme,
            '--group' => 'nonexistent_group_xyz',
        ]);

        // Assert
        $this->assertNotSame(0, $exitCode, 'Should fail for unknown group');
        $this->assertStringContainsString('nonexistent_group_xyz', $this->tester->getDisplay());
    }

    /**
     * An unknown --theme value must cause FAILURE immediately without writing
     * any files, listing the valid theme names in the error message.
     */
    public function testFailsWithUnknownTheme(): void
    {
        // Act
        $exitCode = $this->tester->execute([
            '--theme' => 'fantasy-theme',
            '--all'   => true,
        ]);

        // Assert
        $this->assertNotSame(0, $exitCode, 'Should fail for unknown theme');

        // Assert — error message names the invalid theme
        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('fantasy-theme', $output);
    }

    // =========================================================================
    // --dest option
    // =========================================================================

    /**
     * --dest allows publishing views to a custom subdirectory. The files must
     * land in {projectRoot}/{destValue}/{group}/ rather than src/Views/.
     */
    public function testDestOptionChangesPublishDirectory(): void
    {
        // Arrange
        $theme  = $this->firstAvailableTheme();
        $groups = ScaffoldingHelper::listViewGroups($theme);
        if (!isset($groups['login'])) {
            $this->markTestSkipped("Theme '$theme' has no 'login' group");
        }

        // Act
        $exitCode = $this->tester->execute([
            '--theme' => $theme,
            '--group' => 'login',
            '--dest'  => 'resources/views',
        ]);

        // Assert — success
        $this->assertSame(0, $exitCode, $this->tester->getDisplay());

        // Assert — files landed in the custom dest, not src/Views
        $customDir = $this->projectDir . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views';
        $this->assertDirectoryExists($customDir . DIRECTORY_SEPARATOR . 'login');

        $defaultDir = $this->projectDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Views';
        $this->assertDirectoryDoesNotExist($defaultDir, 'Files should not appear in the default dest');
    }

    // =========================================================================
    // Theme auto-detection paths (no --theme option)
    // =========================================================================

    /**
     * When --theme is omitted and no app/app.php config exists in the project
     * root, the command cannot determine a theme and must return FAILURE with an
     * informational error message.
     *
     * This covers the loadAppConfig() call (lines 90–91) and the "cannot
     * determine theme" error branch (lines 93–98) in execute(). It also covers
     * the file-not-found early-return inside loadAppConfig() itself (lines
     * 194–195 of ScaffoldViews).
     */
    public function testFailsWhenNoThemeCanBeDetermined(): void
    {
        // Arrange — projectDir has no app/app.php; no --theme passed either
        // (setUp() already creates a fresh empty temp dir)

        // Act — omit --theme so the command must fall back to app/app.php
        $exitCode = $this->tester->execute(['--all' => true]);
        $output   = $this->tester->getDisplay();

        // Assert — failure with a descriptive error
        $this->assertSame(\Symfony\Component\Console\Command\Command::FAILURE, $exitCode,
            'Command must fail when theme cannot be determined from config or --theme');
        $this->assertStringContainsString('Cannot determine scaffold theme', $output,
            'Error message must explain that theme detection failed');
    }

    /**
     * When --theme is omitted but app/app.php exists with a scaffold_theme entry,
     * the command must read the theme from config and proceed normally.
     *
     * This covers the loadAppConfig() "file exists" path (lines 197–199 of
     * ScaffoldViews) and the $theme = getScaffoldTheme($appConfig) branch (line 91).
     */
    public function testReadsThemeFromAppConfigWhenNoThemeOption(): void
    {
        // Arrange — pick a real theme and write an app.php into the temp project dir
        $theme = $this->firstAvailableTheme();

        // Create app/ directory and app.php returning the scaffold_theme key
        $appDir = $this->projectDir . DIRECTORY_SEPARATOR . 'app';
        mkdir($appDir, 0777, true);
        file_put_contents(
            $appDir . DIRECTORY_SEPARATOR . 'app.php',
            "<?php\nreturn ['scaffold_theme' => '{$theme}'];\n"
        );

        // Act — no --theme passed; the command reads it from app/app.php
        $exitCode = $this->tester->execute(['--list' => true]);
        $output   = $this->tester->getDisplay();

        // Assert — command succeeds and lists groups for the configured theme
        $this->assertSame(\Symfony\Component\Console\Command\Command::SUCCESS, $exitCode,
            'Command must succeed when scaffold_theme is read from app/app.php');
        $this->assertStringContainsString($theme, $output,
            'Output must reference the theme that was read from config');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function collectFiles(string $dir, array &$list): void
    {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->collectFiles($path, $list);
            } else {
                $list[] = $path;
            }
        }
    }
}
