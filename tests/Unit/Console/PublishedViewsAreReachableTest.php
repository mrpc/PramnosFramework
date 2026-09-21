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
    /**
     * The scaffold writes no view into a directory the resolver never looks in.
     *
     * WHAT: every `src/Views/<dir>/` that `init` creates or writes into is a `<dir>` some
     *       controller asks for with `getView('<dir>')`.
     *
     * WHY:  the scaffold wrote `src/Views/account/dashboard.html.php` and
     *       `profile.html.php`, and neither was rendered once.
     *       `Account::display()` calls `getView('dashboard')` and `profile()` calls
     *       `getView('profile')`, which resolve to `src/Views/dashboard/` and
     *       `src/Views/profile/` — a directory called `account` is one nothing looks in.
     *
     *       Worse than dead code, because they look like the account screens: somebody
     *       wanting to change the account page finds them, edits them, reloads, and
     *       nothing happens. They were raw Tailwind where every other view is daisyUI, so
     *       even that was not a visible hint.
     *
     *       Found in a consuming project by asking why 56 statements sat at 0% coverage
     *       that no request could reach — which is not a question a test asks, so the
     *       invariant is asserted here instead.
     *
     * @return void
     */
    public function testTheScaffoldWritesNoViewIntoADirectoryNothingRenders(): void
    {
        // Arrange
        $root      = dirname(__DIR__, 3);
        $generator = (string) file_get_contents($root . '/src/Pramnos/Console/Commands/Init.php');

        // Act — the view directories the generator creates or writes into, ignoring the
        // prose: a comment naming the mistake must not be read as committing it.
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $generator);
        $code = (string) preg_replace('#^\s*(//|\*).*$#m', '', $code);

        preg_match_all("#'src/Views/([a-zA-Z_][a-zA-Z0-9_]*)#", $code, $matches);
        $directories = array_values(array_unique($matches[1]));

        $this->assertNotEmpty(
            $directories,
            'no view directories found in the generator, so this checks nothing'
        );

        // Every `getView('x')` the framework makes, plus the ones the scaffold emits.
        $asked = [];
        $sources = [$code];
        foreach ($this->frameworkSources() as $file) {
            $sources[] = (string) file_get_contents($file);
        }
        foreach ($sources as $source) {
            preg_match_all(
                // No optional-backslash alternative in this pattern, deliberately.
                // Writing one costs four backslashes in a PHP string and two attempts
                // got three: `\\?` in either quoting style is the regex `\?`, a
                // *literal* question mark, so the sweep matched nothing. Nothing calls
                // `\getView(` anyway — and the emptiness assertion below is what said
                // so both times, which is why it is there.
                '#getView\(\s*\'([a-zA-Z_][a-zA-Z0-9_]*)\'#',
                $source,
                $found
            );
            foreach ($found[1] as $name) {
                $asked[strtolower($name)] = true;
            }
        }

        $this->assertNotEmpty($asked, 'no getView() calls found, so this checks nothing');

        $orphans = [];
        foreach ($directories as $directory) {
            if (!isset($asked[strtolower($directory)])) {
                $orphans[] = 'src/Views/' . $directory;
            }
        }

        // Assert
        $this->assertSame(
            [],
            $orphans,
            "Nothing calls getView() for these, so a template in them is never rendered —\n"
            . "and it looks exactly like the one to edit:\n" . implode("\n", $orphans)
        );
    }

    /**
     * Every framework PHP file, for the `getView()` sweep above.
     *
     * @return list<string>
     */
    private function frameworkSources(): array
    {
        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                dirname(__DIR__, 3) . '/src',
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

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
        // A sweep over nothing passes. The collection is asserted non-empty first, because a
        // moved directory or a renamed helper turns this guard into a no-op that still reports
        // success — which is how a guard stops guarding without anybody noticing.
        $this->assertNotEmpty($this->themeViewRoots(), 'the sweep found nothing to check');
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
        // A sweep over nothing passes. The collection is asserted non-empty first, because a
        // moved directory or a renamed helper turns this guard into a no-op that still reports
        // success — which is how a guard stops guarding without anybody noticing.
        $this->assertNotEmpty($this->themeViewRoots(), 'the sweep found nothing to check');
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
