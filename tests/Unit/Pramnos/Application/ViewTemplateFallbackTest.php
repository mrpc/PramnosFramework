<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\ScaffoldingHelper;
use Pramnos\Application\View;

/**
 * The scaffolding fallback for a **template**, not just for a view directory.
 *
 * `Controller::getView()` has always fallen back to the bundled theme when it
 * cannot find the view directory. The template lookup inside `View::getTpl()` had
 * no fallback at all — so the unit of inheritance was the whole directory.
 *
 * What that cost: an application with `src/Views/services/logs.html.php` and no
 * `services.html.php` matched at the directory, failed at the template, and the
 * services list came back as a page shell. Status 200, no panel, one line in a
 * log nobody reads. The same for any project that customised one screen out of a
 * group and expected the rest to keep working.
 *
 * Which is the shape a project actually wants inverted: keep the screens you
 * rewrote, inherit the others — and get their fixes with the next framework
 * update rather than copying them again.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(View::class)]
class ViewTemplateFallbackTest extends TestCase
{
    /**
     * Ask a View for the bundled template of a given name.
     *
     * The method is private because it is not a seam — it is one step of the
     * lookup — so Reflection is the honest way to test it in isolation.
     */
    private function resolve(string $viewName, string $tpl, string $type): ?string
    {
        // Constructed without running the constructor: it builds a Request and
        // reads the flash bag, and the lookup under test needs neither — only
        // `$name`.
        $view = (new \ReflectionClass(View::class))->newInstanceWithoutConstructor();
        $nameProperty = new \ReflectionProperty(View::class, 'name');
        $nameProperty->setValue($view, $viewName);

        $method = new \ReflectionMethod(View::class, 'scaffoldedTemplate');

        return $method->invoke($view, $tpl, $type);
    }

    /**
     * A bundled template is found for a view the application does not have.
     *
     * `login` is the one every application gets for free — the auth flow has to
     * work before anybody has scaffolded a single view.
     */
    public function testABundledTemplateIsFound(): void
    {
        // Act
        $path = $this->resolve('login', 'login', 'html');

        // Assert
        $this->assertNotNull($path, 'the bundled login template must be reachable');
        $this->assertFileExists((string) $path);
        $this->assertStringContainsString('scaffolding', (string) $path);
    }

    /**
     * A template that no bundled theme has resolves to null.
     *
     * Not to an exception and not to a wrong file: the caller's existing "cannot
     * find view template" path has to stay in charge of that case, because it is
     * the one that logs where the lookup came from.
     */
    public function testATemplateNobodyShipsResolvesToNull(): void
    {
        // Act
        $path = $this->resolve('no_such_view_anywhere', 'no_such_template', 'html');

        // Assert
        $this->assertNull($path);
    }

    /**
     * The lookup is per template, not per view directory.
     *
     * This is the whole change: two templates of the same view group resolve
     * independently, so an application can own one and inherit the other.
     */
    public function testTwoTemplatesOfOneViewResolveIndependently(): void
    {
        // Act
        $found = $this->resolve('login', 'login_2fa', 'html');
        $absent = $this->resolve('login', 'not_a_login_template', 'html');

        // Assert
        $this->assertNotNull($found);
        $this->assertNull($absent);
    }

    /**
     * Every bundled theme is searched when no `scaffold_theme` is configured.
     *
     * Projects initialised before that key was tracked have no theme recorded,
     * and they are exactly the projects most likely to be relying on the
     * fallback. Asserted through the helper the lookup uses, because there is no
     * application object in a unit test to carry the config.
     */
    public function testEveryBundledThemeIsAvailableAsAFallback(): void
    {
        // Act
        $dirs = ScaffoldingHelper::getAvailableThemeDirs();

        // Assert
        $this->assertNotSame([], $dirs);
        foreach ($dirs as $dir) {
            $this->assertFileExists($dir . '/views/login/login.html.php',
                basename($dir) . ' must ship the login template it is a fallback for');
        }
    }
}
