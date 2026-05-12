<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controller;
use Pramnos\Application\ScaffoldingHelper;

/**
 * Unit tests for the scaffolding fallback in Controller::getView().
 *
 * The fallback is implemented in the private method _getScaffoldingFallbackDirs()
 * which is exercised via Reflection. The invariants tested are:
 *
 *  - When scaffold_theme is set in applicationInfo, only that theme's dir is returned.
 *  - When scaffold_theme is absent, all available theme dirs are returned (multi-theme
 *    fallback for projects pre-dating the scaffold_theme key).
 *  - When scaffold_theme names a non-existent theme, an empty array is returned so
 *    getView() proceeds to throw its "cannot find view" exception rather than a
 *    confusing PHP error.
 *
 * _getScaffoldingFallbackDirs() is tested in isolation because wiring up a full
 * Pramnos Application object (with document factory, ROOT defines, etc.) would
 * make this an integration test rather than a unit test. The integration path —
 * where getView() actually resolves to a bundled view file — is covered by the
 * fact that ScaffoldingHelperTest verifies the directory structure exists.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(Controller::class)]
class ControllerScaffoldingFallbackTest extends TestCase
{
    // =========================================================================
    // Infrastructure
    // =========================================================================

    /**
     * Create a minimal Controller whose $application->applicationInfo can be
     * controlled via the supplied array. Uses an anonymous subclass that skips
     * the real constructor (which calls Application::getInstance() and requires
     * the full framework bootstrap).
     *
     * @param array $applicationInfo Config array — simulates app/app.php content
     */
    private function makeController(array $applicationInfo): Controller
    {
        // Build a minimal application stub — only applicationInfo is accessed
        // by _getScaffoldingFallbackDirs().
        $appStub = new \stdClass();
        $appStub->applicationInfo = $applicationInfo;
        $appStub->appName         = '';

        // Anonymous subclass to bypass the real constructor.
        $controller = new class($appStub) extends Controller {
            public function __construct(object $appStub)
            {
                // Skip parent constructor — we only test the private helper method.
                $this->application = $appStub;
            }
        };

        return $controller;
    }

    /**
     * Invoke the private _getScaffoldingFallbackDirs() method via Reflection.
     *
     * @return string[]
     */
    private function callGetFallbackDirs(Controller $controller): array
    {
        $ref    = new \ReflectionMethod(Controller::class, '_getScaffoldingFallbackDirs');
        $ref->setAccessible(true);
        return $ref->invoke($controller);
    }

    // =========================================================================
    // scaffold_theme is set
    // =========================================================================

    /**
     * When applicationInfo contains scaffold_theme pointing to an existing
     * theme, _getScaffoldingFallbackDirs() must return exactly one directory
     * whose basename matches the configured theme.
     *
     * This is the normal case for every project created after scaffold_theme
     * was introduced.
     */
    public function testReturnsSingleThemeDirWhenScaffoldThemeIsSet(): void
    {
        // Arrange — use the first real theme that actually exists on disk
        $availableDirs = ScaffoldingHelper::getAvailableThemeDirs();
        if (empty($availableDirs)) {
            $this->markTestSkipped('No bundled theme directories found');
        }
        $theme      = basename($availableDirs[0]);
        $controller = $this->makeController(['scaffold_theme' => $theme]);

        // Act
        $dirs = $this->callGetFallbackDirs($controller);

        // Assert — exactly one directory, named after the theme
        $this->assertCount(1, $dirs, "Expected exactly one fallback dir when scaffold_theme is set");
        $this->assertSame($theme, basename($dirs[0]), "Returned dir should match the configured theme");
        $this->assertDirectoryExists($dirs[0], "Returned dir must exist on disk");
    }

    /**
     * When scaffold_theme names a theme that does not exist on disk,
     * _getScaffoldingFallbackDirs() must return an empty array so that
     * getView() falls through cleanly to its "cannot find view" exception.
     *
     * A missing theme must never trigger a PHP error or infinite loop.
     */
    public function testReturnsEmptyArrayWhenScaffoldThemeDoesNotExist(): void
    {
        // Arrange
        $controller = $this->makeController(['scaffold_theme' => 'nonexistent-theme-xyz']);

        // Act
        $dirs = $this->callGetFallbackDirs($controller);

        // Assert
        $this->assertIsArray($dirs);
        $this->assertEmpty($dirs, 'Non-existent theme should yield an empty fallback dir list');
    }

    // =========================================================================
    // scaffold_theme is absent (legacy / unconfigured projects)
    // =========================================================================

    /**
     * When applicationInfo has no scaffold_theme key, _getScaffoldingFallbackDirs()
     * must return all available theme directories so that legacy projects
     * (initialised before this key existed) still benefit from the fallback.
     */
    public function testReturnsAllThemeDirsWhenScaffoldThemeIsAbsent(): void
    {
        // Arrange — no scaffold_theme key
        $controller = $this->makeController(['app_name' => 'Legacy App']);

        $expectedDirs = ScaffoldingHelper::getAvailableThemeDirs();
        if (empty($expectedDirs)) {
            $this->markTestSkipped('No bundled theme directories found');
        }

        // Act
        $dirs = $this->callGetFallbackDirs($controller);

        // Assert — same list as getAvailableThemeDirs()
        $this->assertSame(
            $expectedDirs,
            $dirs,
            'When scaffold_theme is absent, all available theme dirs should be returned'
        );
    }

    /**
     * An empty scaffold_theme string is equivalent to absent — return all
     * available theme dirs so the multi-theme fallback applies.
     */
    public function testReturnsAllThemeDirsWhenScaffoldThemeIsEmptyString(): void
    {
        // Arrange
        $controller = $this->makeController(['scaffold_theme' => '']);

        $expectedDirs = ScaffoldingHelper::getAvailableThemeDirs();
        if (empty($expectedDirs)) {
            $this->markTestSkipped('No bundled theme directories found');
        }

        // Act
        $dirs = $this->callGetFallbackDirs($controller);

        // Assert
        $this->assertSame(
            $expectedDirs,
            $dirs,
            'Empty scaffold_theme should behave the same as absent (return all themes)'
        );
    }

    /**
     * When applicationInfo itself is absent (null/falsy), the fallback must
     * still work without error and return all available theme dirs.
     *
     * This covers controllers where $application->applicationInfo is null.
     */
    public function testHandlesNullApplicationInfo(): void
    {
        // Arrange — applicationInfo is null
        $appStub = new \stdClass();
        $appStub->applicationInfo = null;
        $appStub->appName         = '';

        $controller = new class($appStub) extends Controller {
            public function __construct(object $appStub)
            {
                $this->application = $appStub;
            }
        };

        $expectedDirs = ScaffoldingHelper::getAvailableThemeDirs();

        // Act
        $ref    = new \ReflectionMethod(Controller::class, '_getScaffoldingFallbackDirs');
        $ref->setAccessible(true);
        $dirs = $ref->invoke($controller);

        // Assert — no exception, returns expected dirs
        $this->assertSame($expectedDirs, $dirs);
    }
}
