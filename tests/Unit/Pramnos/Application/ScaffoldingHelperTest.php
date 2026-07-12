<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\ScaffoldingHelper;

/**
 * Unit tests for ScaffoldingHelper.
 *
 * ScaffoldingHelper is the single source of truth for locating the framework's
 * bundled scaffolding directory and for reading the scaffold_theme configuration
 * key that governs which view theme is active. These tests verify:
 *
 *  - The scaffolding directory is resolvable from within the installed framework.
 *  - Theme directory paths are composed correctly.
 *  - scaffold_theme extraction is correct and null-safe.
 *  - getAvailableThemeDirs() returns only directories that actually exist.
 *  - listViewGroups() returns the expected structure (group → [relPath, …]).
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ScaffoldingHelper::class)]
class ScaffoldingHelperTest extends TestCase
{
    // =========================================================================
    // resolveScaffoldingDir
    // =========================================================================

    /**
     * resolveScaffoldingDir() must return a path whose `templates/` subdirectory
     * exists — that is the sentinel used by the walker to recognise the
     * scaffolding root. Without this guarantee the entire scaffold system breaks.
     */
    public function testResolveScaffoldingDirContainsTemplatesSubdir(): void
    {
        // Act
        $dir = ScaffoldingHelper::resolveScaffoldingDir();

        // Assert — the directory and the sentinel sub-directory must both exist
        $this->assertIsString($dir);
        $this->assertDirectoryExists($dir, 'resolveScaffoldingDir() did not return an existing directory');
        $this->assertDirectoryExists(
            $dir . DIRECTORY_SEPARATOR . 'templates',
            'scaffolding/ directory does not contain the expected templates/ subdirectory'
        );
    }

    /**
     * The returned path must not end with a directory separator because callers
     * append their own separator when building sub-paths.
     */
    public function testResolveScaffoldingDirHasNoTrailingSlash(): void
    {
        // Act
        $dir = ScaffoldingHelper::resolveScaffoldingDir();

        // Assert
        $this->assertStringEndsNotWith('/', $dir);
        $this->assertStringEndsNotWith(DIRECTORY_SEPARATOR, $dir);
    }

    // =========================================================================
    // getThemeDir
    // =========================================================================

    /**
     * getThemeDir() must return a path of the form {scaffoldingDir}/themes/{name}.
     * We verify the composition without asserting the directory exists (themes
     * are optional for the helper itself — callers must check).
     */
    public function testGetThemeDirComposesCorrectPath(): void
    {
        // Arrange
        $scaffoldingDir = ScaffoldingHelper::resolveScaffoldingDir();

        // Act
        $themeDir = ScaffoldingHelper::getThemeDir('bootstrap');

        // Assert — path ends with the expected segments
        $expected = $scaffoldingDir
            . DIRECTORY_SEPARATOR . 'themes'
            . DIRECTORY_SEPARATOR . 'bootstrap';
        $this->assertSame($expected, $themeDir);
    }

    /**
     * getThemeDir() should work for every canonical theme identifier.
     * If the bundled themes directory exists, all three entries must resolve.
     */
    public function testGetThemeDirWorksForAllCanonicalThemes(): void
    {
        // Act + Assert
        foreach (ScaffoldingHelper::THEMES as $theme) {
            $dir = ScaffoldingHelper::getThemeDir($theme);
            $this->assertIsString($dir, "getThemeDir() did not return a string for theme '$theme'");
            $this->assertStringContainsString($theme, $dir, "Theme directory path does not contain the theme name");
        }
    }

    // =========================================================================
    // getScaffoldTheme
    // =========================================================================

    /**
     * When scaffold_theme is present and non-empty, getScaffoldTheme() must
     * return it unchanged. This is the happy path used by every project that
     * was initialised with a UI theme.
     */
    public function testGetScaffoldThemeReturnsPresentValue(): void
    {
        // Arrange
        $config = ['scaffold_theme' => 'bootstrap', 'app_name' => 'TestApp'];

        // Act
        $result = ScaffoldingHelper::getScaffoldTheme($config);

        // Assert
        $this->assertSame('bootstrap', $result);
    }

    /**
     * When scaffold_theme is absent, getScaffoldTheme() must return null.
     * This represents a legacy project (initialised before the key existed)
     * — callers use this null to trigger the multi-theme fallback.
     */
    public function testGetScaffoldThemeReturnsNullWhenKeyAbsent(): void
    {
        // Arrange
        $config = ['app_name' => 'OldProject'];

        // Act
        $result = ScaffoldingHelper::getScaffoldTheme($config);

        // Assert
        $this->assertNull($result);
    }

    /**
     * An empty string value must be treated as absent (return null) because
     * an empty scaffold_theme is functionally equivalent to not being set.
     */
    public function testGetScaffoldThemeReturnsNullForEmptyString(): void
    {
        // Arrange
        $config = ['scaffold_theme' => ''];

        // Act
        $result = ScaffoldingHelper::getScaffoldTheme($config);

        // Assert
        $this->assertNull($result, 'Empty scaffold_theme should be treated as absent');
    }

    /**
     * A non-string value (e.g. null explicitly stored) must also yield null.
     */
    public function testGetScaffoldThemeReturnsNullForNullValue(): void
    {
        // Arrange
        $config = ['scaffold_theme' => null];

        // Act
        $result = ScaffoldingHelper::getScaffoldTheme($config);

        // Assert
        $this->assertNull($result);
    }

    /**
     * getScaffoldTheme() must handle an empty config array without errors.
     */
    public function testGetScaffoldThemeHandlesEmptyArray(): void
    {
        // Act
        $result = ScaffoldingHelper::getScaffoldTheme([]);

        // Assert
        $this->assertNull($result);
    }

    // =========================================================================
    // getAvailableThemeDirs
    // =========================================================================

    /**
     * getAvailableThemeDirs() must return only paths that exist on disk.
     * Each returned entry must be a string and must point to an existing
     * directory — the caller relies on this guarantee before scanning for views.
     */
    public function testGetAvailableThemeDirsReturnsOnlyExistingDirectories(): void
    {
        // Act
        $dirs = ScaffoldingHelper::getAvailableThemeDirs();

        // Assert
        $this->assertIsArray($dirs);
        foreach ($dirs as $dir) {
            $this->assertIsString($dir);
            $this->assertDirectoryExists($dir, "getAvailableThemeDirs() returned a path that does not exist: $dir");
        }
    }

    /**
     * The bundled framework ships with at least one theme directory, so the
     * result must not be empty. An empty result would mean the scaffold fallback
     * in Controller::getView() silently does nothing.
     */
    public function testGetAvailableThemeDirsIsNotEmpty(): void
    {
        // Act
        $dirs = ScaffoldingHelper::getAvailableThemeDirs();

        // Assert
        $this->assertNotEmpty($dirs, 'No theme directories found — at least one bundled theme must exist');
    }

    /**
     * getAvailableThemeDirs() must honour the canonical THEMES order.
     * Concretely, the tail of every returned path must appear in THEMES in the
     * same relative order (no reordering based on filesystem scandir() output).
     */
    public function testGetAvailableThemeDirsRespectCanonicalOrder(): void
    {
        // Act
        $dirs = ScaffoldingHelper::getAvailableThemeDirs();

        // Build list of theme names extracted from returned paths
        $names = array_map('basename', $dirs);

        // Assert — every name must be in THEMES
        foreach ($names as $name) {
            $this->assertContains($name, ScaffoldingHelper::THEMES, "Unexpected theme directory: $name");
        }

        // Assert — order matches the canonical THEMES subset
        $canonicalSubset = array_values(array_intersect(ScaffoldingHelper::THEMES, $names));
        $this->assertSame($canonicalSubset, $names, 'Returned theme dirs are not in canonical order');
    }

    // =========================================================================
    // listViewGroups
    // =========================================================================

    /**
     * listViewGroups() must return an associative array whose keys are group
     * names and whose values are non-empty arrays of relative paths. The login
     * group is mandatory because it contains the core authentication views.
     */
    public function testListViewGroupsReturnsExpectedStructure(): void
    {
        // Arrange — use the first available theme so the test works regardless
        // of which themes are bundled
        $availableDirs = ScaffoldingHelper::getAvailableThemeDirs();
        if (empty($availableDirs)) {
            $this->markTestSkipped('No bundled theme directories found');
        }
        $themeName = basename($availableDirs[0]);

        // Act
        $groups = ScaffoldingHelper::listViewGroups($themeName);

        // Assert — result is a non-empty associative array
        $this->assertIsArray($groups);
        $this->assertNotEmpty($groups, "listViewGroups('$themeName') returned an empty array");

        // Assert — every value is a non-empty array of strings
        foreach ($groups as $groupName => $files) {
            $this->assertIsString($groupName);
            $this->assertIsArray($files);
            $this->assertNotEmpty($files, "Group '$groupName' has no files");
            foreach ($files as $relPath) {
                $this->assertIsString($relPath);
                // Each relative path must start with the group name directory
                $this->assertStringStartsWith($groupName . DIRECTORY_SEPARATOR, $relPath,
                    "Relative path '$relPath' does not start with group name '$groupName/'");
            }
        }
    }

    /**
     * listViewGroups() must return groups in alphabetical order so that the
     * --list output of scaffold:views is deterministic and readable.
     */
    public function testListViewGroupsReturnsGroupsInAlphabeticalOrder(): void
    {
        // Arrange
        $availableDirs = ScaffoldingHelper::getAvailableThemeDirs();
        if (empty($availableDirs)) {
            $this->markTestSkipped('No bundled theme directories found');
        }
        $themeName = basename($availableDirs[0]);

        // Act
        $groups = ScaffoldingHelper::listViewGroups($themeName);
        $keys   = array_keys($groups);

        // Assert — keys are sorted alphabetically
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys, 'listViewGroups() did not return groups in alphabetical order');
    }

    /**
     * listViewGroups() must return an empty array for an unknown (non-existent)
     * theme without throwing an exception. Callers rely on this for validation.
     */
    public function testListViewGroupsReturnsEmptyArrayForNonExistentTheme(): void
    {
        // Act
        $groups = ScaffoldingHelper::listViewGroups('nonexistent-theme-xyz');

        // Assert
        $this->assertIsArray($groups);
        $this->assertEmpty($groups, 'Expected empty array for non-existent theme');
    }

    /**
     * listViewGroups() must include the 'login' group because it contains the
     * authentication views that every project needs.
     */
    public function testListViewGroupsContainsLoginGroup(): void
    {
        // Arrange
        $availableDirs = ScaffoldingHelper::getAvailableThemeDirs();
        if (empty($availableDirs)) {
            $this->markTestSkipped('No bundled theme directories found');
        }
        $themeName = basename($availableDirs[0]);

        // Act
        $groups = ScaffoldingHelper::listViewGroups($themeName);

        // Assert
        $this->assertArrayHasKey('login', $groups, "The 'login' view group must be present in every theme");
    }

    /**
     * listViewGroups() must skip:
     *  - files at the views-root level (not directories)         → `if (!is_dir($groupDir)) continue`
     *  - non-file entries inside a group directory (e.g. subdir) → `if (is_file(...))` false branch
     *  - empty group directories (no files inside)               → `if (!empty($files))` false branch
     *
     * Tested using a synthetic temp directory structure so the test is
     * deterministic regardless of what themes are bundled.
     */
    public function testListViewGroupsSkipsEdgeCases(): void
    {
        // Arrange — build a temp scaffolding/themes/synthetic-edge/views/ tree
        $tmpBase  = sys_get_temp_dir() . '/pramnos_scaffold_edge_' . bin2hex(random_bytes(4));
        $viewsDir = $tmpBase . '/themes/synthetic-edge/views';
        mkdir($viewsDir, 0777, true);

        // Case 1: a plain file at the views-root level (must be skipped)
        file_put_contents($viewsDir . '/not_a_group.txt', 'ignored');

        // Case 2: an empty group directory (has no files → must be excluded from result)
        mkdir($viewsDir . '/empty_group', 0777, true);

        // Case 3: a group directory that has a subdirectory instead of files
        mkdir($viewsDir . '/group_with_subdir/a_subdir', 0777, true);

        // Case 4: a normal group with a real file (must appear in result)
        mkdir($viewsDir . '/login', 0777, true);
        file_put_contents($viewsDir . '/login/login.html.php', '<?php // stub');

        // Wire up ScaffoldingHelper to use this temp tree by overriding resolveScaffoldingDir
        // via the protected static late-binding. We subclass ScaffoldingHelper in-line so
        // getThemeDir() calls our resolveScaffoldingDir().
        $syntheticHelper = new class ($tmpBase) extends ScaffoldingHelper {
            private static string $root;
            public function __construct(string $root) { static::$root = $root; }
            public static function resolveScaffoldingDir(): string { return static::$root; }
        };

        // Act
        $groups = $syntheticHelper::listViewGroups('synthetic-edge');

        // Cleanup
        foreach (glob($viewsDir . '/group_with_subdir/a_subdir') ?: [] as $d) @rmdir($d);
        foreach (glob($viewsDir . '/*/*.html.php') ?: [] as $f) @unlink($f);
        foreach (glob($viewsDir . '/*.txt') ?: [] as $f) @unlink($f);
        foreach (glob($viewsDir . '/*') ?: [] as $d) @rmdir($d);
        @rmdir($viewsDir);
        @rmdir(dirname($viewsDir));
        @rmdir(dirname($viewsDir, 2));
        @rmdir($tmpBase);

        // Assert — only the 'login' group (with a real file) must appear
        $this->assertArrayHasKey('login', $groups,
            "'login' group with a real file must appear in result");
        $this->assertArrayNotHasKey('empty_group', $groups,
            "Empty group directory must be excluded from result");
        $this->assertArrayNotHasKey('group_with_subdir', $groups,
            "Group with only subdirectories must be excluded (is_file() branch)");
        $this->assertArrayNotHasKey('not_a_group.txt', $groups,
            "Plain files at views-root level must be skipped (is_dir() branch)");
    }
}
