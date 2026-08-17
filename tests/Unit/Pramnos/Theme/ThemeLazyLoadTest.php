<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Theme;

use PHPUnit\Framework\TestCase;
use Pramnos\Theme\Theme;

/**
 * A theme object reads its file when somebody asks for a piece of it.
 *
 * `Document::loadtheme()` builds the theme with `$load = false`, so `Theme::loadtheme()`
 * never ran and `$contents` stayed empty. `Html::render()` happens to call `loadTheme()`
 * itself, which is exactly why this was hard to find from outside: the framework's own path
 * works, and an application that assigns `themeObject` and renders through any other route
 * got an object that reported no error and produced the framework's bare default —
 * `gethead()` and `getfoot()` splitting an empty string.
 *
 * The reporter asked for a line in the guide saying the body is loaded lazily. It is
 * cheaper to make that true, so these tests assert the accessors load on demand — and, just
 * as importantly, that they do not read over a body somebody supplied themselves.
 */
class ThemeLazyLoadTest extends TestCase
{
    /**
     * A throwaway theme directory, **inside** the repository.
     *
     * `Theme::getTheme()` resolves a non-empty `$path` as `ROOT . DS . $path`, so a path in
     * the system temp directory silently resolves to nothing and every accessor returns the
     * empty result this class exists to prove is gone. Cost four failing assertions before
     * it was read rather than assumed.
     *
     * @var string
     */
    private string $relative = 'tests/fixtures/tmp-themes';

    /** @var string The absolute form of the above */
    private string $root = '';

    /** @var string The theme's name */
    private const NAME = 'lazyfixture';

    /**
     * Writes a minimal theme whose template is identifiable in the output.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->root = ROOT . '/' . $this->relative;
        if (!is_dir($this->root . '/' . self::NAME)) {
            mkdir($this->root . '/' . self::NAME, 0777, true);
        }

        file_put_contents(
            $this->root . '/' . self::NAME . '/theme.html.php',
            '<html><head>HEAD-MARK</head><body>BEFORE-MARK[MODULE]AFTER-MARK</body></html>'
        );
    }

    /**
     * Removes it.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        @unlink($this->root . '/' . self::NAME . '/theme.html.php');
        @rmdir($this->root . '/' . self::NAME);
        @rmdir($this->root);

        // `getTheme()` caches instances by name in a static, so a later test asking for
        // this name would otherwise be handed the object built against a directory that no
        // longer exists.
        $instances = new \ReflectionProperty(Theme::class, 'instances');
        $instances->setValue(null, null);
    }

    /**
     * A theme built the way `Document::loadtheme()` builds it — unloaded.
     *
     * @return Theme
     */
    private function unloadedTheme(): Theme
    {
        // The same call, with the same `$load = false`, so this is the state under test and
        // not an approximation of it.
        return Theme::getTheme(self::NAME, $this->relative, false);
    }

    /**
     * Nothing is read at construction; the object starts genuinely empty.
     *
     * Establishes the precondition, so the assertions below are attributable to the lazy
     * load rather than to the constructor having done the work all along.
     *
     * @return void
     */
    public function testAThemeBuiltWithoutLoadingStartsEmpty(): void
    {
        // Arrange
        $theme = $this->unloadedTheme();

        // Assert
        $contents = new \ReflectionProperty($theme, 'contents');
        $this->assertSame('', (string) $contents->getValue($theme));
    }

    /**
     * `gethead()` reads the theme file rather than splitting an empty string.
     *
     * The symptom as reported: a configured theme rendering the bare default.
     *
     * @return void
     */
    public function testGetHeadLoadsTheThemeOnDemand(): void
    {
        // Act
        $head = $this->unloadedTheme()->gethead();

        // Assert
        $this->assertStringContainsString('BEFORE-MARK', $head);
        $this->assertStringNotContainsString('AFTER-MARK', $head, 'That is the foot.');
    }

    /**
     * `getfoot()` does too.
     *
     * @return void
     */
    public function testGetFootLoadsTheThemeOnDemand(): void
    {
        // Act
        $foot = $this->unloadedTheme()->getfoot();

        // Assert
        $this->assertStringContainsString('AFTER-MARK', $foot);
        $this->assertStringNotContainsString('BEFORE-MARK', $foot, 'That is the head.');
    }

    /**
     * `getheader()` — everything inside `<head>` — does too.
     *
     * A separate assertion because it reads `contents` rather than `body`, so it is the one
     * accessor a fix aimed only at the `[MODULE]` split would have missed.
     *
     * @return void
     */
    public function testGetHeaderLoadsTheThemeOnDemand(): void
    {
        // Act & Assert
        $this->assertStringContainsString('HEAD-MARK', $this->unloadedTheme()->getheader());
    }

    /**
     * A body assigned by the caller is never read over.
     *
     * The condition is *nothing read yet* — both `contents` and `body` — rather than an
     * empty `contents`. A caller that assigns `body` itself has supplied the very thing the
     * load would produce, and replacing it with a file's contents would discard a
     * deliberate value.
     *
     * Not hypothetical: with the narrower condition, an existing test in this suite that
     * sets `body` by reflection failed immediately.
     *
     * @return void
     */
    public function testAnAssignedBodyIsNotOverwritten(): void
    {
        // Arrange
        $theme = $this->unloadedTheme();
        $body  = new \ReflectionProperty($theme, 'body');
        $body->setValue($theme, 'MINE-BEFORE[MODULE]MINE-AFTER');

        // Act
        $head = $theme->gethead();
        $foot = $theme->getfoot();

        // Assert
        $this->assertStringContainsString('MINE-BEFORE', $head);
        $this->assertStringContainsString('MINE-AFTER', $foot);
        $this->assertStringNotContainsString('BEFORE-MARK', $head, 'The file must not win.');
    }

    /**
     * An explicit `loadtheme()` still re-reads, because the template it picks can change.
     *
     * The load is lazy, not memoised. `loadtheme()` chooses its file from the content type,
     * so an application that sets a content type and reloads is asking for a different
     * template on purpose — and a short-circuit would silently ignore that.
     *
     * @return void
     */
    public function testAnExplicitLoadStillReReads(): void
    {
        // Arrange — read once, then replace what the file says
        $theme = $this->unloadedTheme();
        $this->assertStringContainsString('BEFORE-MARK', $theme->gethead());

        file_put_contents(
            $this->root . '/' . self::NAME . '/theme.html.php',
            '<html><head>H2</head><body>SECOND-MARK[MODULE]END2</body></html>'
        );

        // Act
        $theme->loadtheme();

        // Assert
        $this->assertStringContainsString('SECOND-MARK', $theme->gethead());
    }

    /**
     * A theme whose file does not exist stays empty instead of failing.
     *
     * The lazy load must not turn a missing template into an error: a theme directory that
     * has not been created yet is an ordinary state during scaffolding, and the framework's
     * behaviour there has always been to render nothing rather than to stop.
     *
     * @return void
     */
    public function testAMissingTemplateLeavesTheThemeEmptyRatherThanThrowing(): void
    {
        // Arrange
        $theme = Theme::getTheme('no-such-theme-here', $this->relative, false);

        // Act
        $head = $theme->gethead();

        // Assert — the module marker comment only, with no template around it
        $this->assertStringNotContainsString('BEFORE-MARK', $head);
        $this->assertStringContainsString('Begin Module Content', $head);
    }
}
