<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use PHPUnit\Framework\TestCase;

/**
 * A published view must be a view something renders.
 *
 * `project:publish-views` copies whole directories, so every template in
 * `scaffolding/themes/<theme>/views/` lands in a project whether or not any controller
 * ever renders it. One did not: `health/check.html.php`, in all three themes.
 *
 * `Health::check()` returns `Response::json(...)` — it is the monitoring endpoint,
 * and it never touches a view. So the template sat next to `health/health.html.php`,
 * named after an action, looking exactly like the thing to edit if you wanted to
 * change what `/health/check` returns. Editing it would have changed nothing, with
 * no error to say so.
 *
 * This test does not demand that every template be reachable — several are
 * partials included by their siblings, and `_`-prefixed ones say so by name. It
 * checks the specific trap: a template named after an action of a controller that
 * answers with a `Response` instead of a view.
 */
class PublishedViewsAreReachableTest extends TestCase
{
    /**
     * @return list<string> Theme view roots that exist
     */
    private function themeViewRoots(): array
    {
        $roots = [];
        foreach (glob(dirname(__DIR__, 3) . '/scaffolding/themes/*/views', GLOB_ONLYDIR) ?: [] as $dir) {
            $roots[] = $dir;
        }

        return $roots;
    }

    /**
     * Every theme is checked, so a fix in one does not hide a miss in another.
     */
    public function testEveryThemeIsChecked(): void
    {
        // Assert
        $this->assertGreaterThanOrEqual(3, count($this->themeViewRoots()),
            'the bundled themes must be discoverable');
    }

    /**
     * No theme ships a health/check template.
     *
     * `Health::check()` answers with JSON. A template of that name is a file that
     * cannot affect the endpoint it appears to belong to.
     */
    public function testNoThemeShipsATemplateForTheJsonHealthCheck(): void
    {
        // Act
        $found = [];
        foreach ($this->themeViewRoots() as $root) {
            if (file_exists($root . '/health/check.html.php')) {
                $found[] = $root . '/health/check.html.php';
            }
        }

        // Assert
        $this->assertSame([], $found,
            'these templates are named after a JSON action and render nothing: '
            . implode(', ', $found));
    }

    /**
     * And the health view that *is* rendered is still there.
     *
     * Deleting the dead one must not take the live one with it — the dashboard at
     * `/health` renders `health/health.html.php`.
     */
    public function testTheRenderedHealthViewIsStillPublished(): void
    {
        // Act
        $missing = [];
        foreach ($this->themeViewRoots() as $root) {
            if (!file_exists($root . '/health/health.html.php')) {
                $missing[] = $root;
            }
        }

        // Assert
        $this->assertSame([], $missing,
            'the health dashboard view must still be published: ' . implode(', ', $missing));
    }
}
