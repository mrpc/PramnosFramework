<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controller;
use Pramnos\Application\View;

/**
 * A layout shared between views is found, and one that is not says so.
 *
 * `View::resolveTemplatePath()` searched, in order: an absolute path, `$this->path`
 * (which is `ROOT/src/Views/<Name>`), `ROOT/views`, then a theme override. It never
 * searched **`ROOT/src/Views`** — the directory holding the view directories, and the
 * obvious place to put something shared by several of them.
 *
 * So `$this->layout('layouts/main')` from `src/Views/Home/home.html.php` looked in
 * `src/Views/Home/layouts/main.html.php` and in `ROOT/views/layouts/main.html.php`,
 * found neither, and rendered the child **alone** — a page returned with `200`, no
 * `<head>`, no layout, and nothing in any log. It presents as a stylesheet that did
 * not load.
 *
 * Two changes, and the second matters more than the first: the directory is searched,
 * and a layout that still cannot be found is logged. A framework cannot know every
 * place somebody will put a file; it can refuse to fail silently about it.
 */
class ViewLayoutResolutionTest extends TestCase
{
    /** @var string Temporary view tree */
    private string $tmp;

    /**
     * Builds `<tmp>/Home` as a view directory, so `dirname()` of it is the shared root.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/pramnos_views_' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/Home', 0775, true);
    }

    /**
     * Removes the tree without spawning a process.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->removeTree($this->tmp);
    }

    /**
     * Recursively deletes a directory.
     *
     * @param  string $dir Directory
     * @return void
     */
    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * A view rooted at `<tmp>/Home`.
     *
     * @return View
     */
    private function view(): View
    {
        return new View(new Controller(), $this->tmp . '/Home', 'Home');
    }

    /**
     * Resolves a template name through the view.
     *
     * @param  string $name Template name
     * @return string|null Resolved path, or null
     */
    private function resolve(string $name): ?string
    {
        return (new \ReflectionMethod(View::class, 'resolveTemplatePath'))
            ->invoke($this->view(), $name);
    }

    /**
     * A layout beside the view directories is found.
     *
     * `src/Views/layouts/main.html.php` is where a developer puts a layout shared by
     * `src/Views/Home`, `src/Views/Directory` and the rest. It was the one place
     * never looked in.
     *
     * @return void
     */
    public function testALayoutBesideTheViewDirectoriesIsFound(): void
    {
        // Arrange
        mkdir($this->tmp . '/layouts', 0775, true);
        $expected = $this->tmp . '/layouts/main.html.php';
        file_put_contents($expected, '<html></html>');

        // Act
        $resolved = $this->resolve('layouts/main');

        // Assert
        $this->assertSame($expected, $resolved);
    }

    /**
     * The view's own directory still wins over the shared one.
     *
     * The new base was appended rather than inserted, so a project that already had
     * both resolves exactly as it did before. A per-view override must not start
     * losing to a shared file that has been sitting there all along.
     *
     * @return void
     */
    public function testTheViewsOwnDirectoryStillWins(): void
    {
        // Arrange — the same name in both places
        mkdir($this->tmp . '/layouts', 0775, true);
        mkdir($this->tmp . '/Home/layouts', 0775, true);
        file_put_contents($this->tmp . '/layouts/main.html.php', 'shared');
        $own = $this->tmp . '/Home/layouts/main.html.php';
        file_put_contents($own, 'own');

        // Act
        $resolved = $this->resolve('layouts/main');

        // Assert
        $this->assertSame($own, $resolved, 'A per-view override must keep priority.');
    }

    /**
     * A template that exists nowhere still resolves to null.
     *
     * The guard against the search becoming so broad it starts finding things it
     * should not — `dirname()` of a view path is one level up, and one level only.
     *
     * @return void
     */
    public function testAnUnknownTemplateStillResolvesToNull(): void
    {
        // Act & Assert
        $this->assertNull($this->resolve('layouts/nothing-here'));
    }

    /**
     * The rendered template's path is disclosed only while debugging.
     *
     * Every HTML view appended `<!-- View Rendered at: … View Path: /src/Views/… -->`
     * unconditionally. On a page nobody sees that is a convenience; on a public,
     * server-rendered page it tells anybody reading the source where the
     * application's files live, and gets indexed with the rest of the markup.
     *
     * **Both directions are asserted through an overridden `inDebugMode()` rather
     * than through the environment**, and that is not laziness. The first version of
     * this test set nothing and assumed the suite was not in debug mode. It passed
     * alone and failed in the full run, because another test calls
     * `putenv('APP_DEBUG=true')` and `DEVELOPMENT` is a constant that cannot be
     * unset once defined. A test whose subject is a conditional must control the
     * condition, not inherit it from whatever ran first.
     *
     * @return void
     */
    public function testTheTemplatePathIsShownOnlyWhileDebugging(): void
    {
        // Arrange
        file_put_contents($this->tmp . '/Home/plain.html.php', 'hello');

        $notDebugging = new class (new Controller(), $this->tmp . '/Home', 'Home') extends View {
            /**
             * @return bool
             */
            protected function inDebugMode(): bool
            {
                return false;
            }
        };
        $debugging = new class (new Controller(), $this->tmp . '/Home', 'Home') extends View {
            /**
             * @return bool
             */
            protected function inDebugMode(): bool
            {
                return true;
            }
        };

        // Act
        $quiet = (string) $notDebugging->display('plain');
        $loud  = (string) $debugging->display('plain');

        // Assert — the page renders either way; only one of them says where from
        $this->assertStringContainsString('hello', $quiet);
        $this->assertStringNotContainsString('View Path:', $quiet);
        $this->assertStringNotContainsString('View Rendered at:', $quiet);

        $this->assertStringContainsString('hello', $loud);
        $this->assertStringContainsString('View Path:', $loud);
    }

    /**
     * The gate asks the application rather than reading `DEVELOPMENT` itself.
     *
     * `isDebugMode()` also honours `APP_DEBUG`, and a second copy of that decision
     * inside the view would answer differently on the machines where the environment
     * variable is the one in use.
     *
     * @return void
     */
    public function testTheGateFollowsTheApplication(): void
    {
        // Arrange — remember what the environment had, whatever that is
        $original = getenv('APP_DEBUG');
        $view     = $this->view();
        $gate     = new \ReflectionMethod(View::class, 'inDebugMode');

        try {
            // Act & Assert — on
            putenv('APP_DEBUG=1');
            $this->assertTrue($gate->invoke($view));

            // Act & Assert — off
            putenv('APP_DEBUG=0');
            $this->assertSame(
                \Pramnos\Application\Application::getInstance()->isDebugMode(),
                $gate->invoke($view),
                'The view must agree with the application, not decide separately.'
            );
        } finally {
            // Restore rather than unset: this suite has already been bitten once by
            // a test leaving APP_DEBUG behind for everything that ran after it.
            if ($original === false) {
                putenv('APP_DEBUG');
            } else {
                putenv('APP_DEBUG=' . $original);
            }
        }
    }

    /**
     * A layout that cannot be found is written to the log.
     *
     * This is the half that matters. The framework cannot know every place a file
     * might be put; it can refuse to be silent about not finding one. Before this,
     * the only evidence was a page missing its entire `<head>`, served with `200`.
     *
     * @return void
     */
    public function testAMissingLayoutIsLogged(): void
    {
        // Arrange — a template that declares a layout which does not exist
        file_put_contents(
            $this->tmp . '/Home/page.html.php',
            '<?php $this->layout(\'layouts/absent\'); ?>body only'
        );
        // Ask the Logger where it writes rather than deriving the path a second
        // time — its own docblock says so, and the first version of this test
        // derived it, looked at an empty string and reported the feature broken.
        $logFile = \Pramnos\Logs\Logger::logDirectory() . '/pramnosframework.log';
        $before  = is_file($logFile) ? (string) file_get_contents($logFile) : '';

        // Act
        $output = (string) $this->view()->display('page');

        // Assert — the child still rendered, and the failure is recorded
        $this->assertStringContainsString('body only', $output);
        $after = is_file($logFile) ? (string) file_get_contents($logFile) : '';
        $this->assertStringContainsString(
            'Layout not found: layouts/absent',
            str_replace($before, '', $after),
            'A layout that could not be resolved must leave a trace somewhere.'
        );
    }
}
