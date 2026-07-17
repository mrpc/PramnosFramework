<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controller;
use Pramnos\Application\View;

/**
 * Unit tests for view-group binding in Controller::_getView().
 *
 * WHAT: that a resolved View is bound to the view-group directory itself, so
 *       $view->display('someTemplate') resolves {group}/someTemplate.<type>.php —
 *       even when the group ships only secondary templates and no default
 *       "{group}" / "view" template.
 * WHY:  a group with only secondary templates (e.g. passkey/manage,
 *       account/profile) used to bind to the PARENT directory, so every
 *       display('sub') resolved a non-existent parent/sub file and rendered a
 *       blank page. That was a silent, page-wide breakage; this pins the fix.
 */
class ControllerGetViewBindingTest extends TestCase
{
    private string $base = '';

    /** Build a throwaway view-tree root under the system temp dir. */
    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/pf_getview_' . uniqid('', true);
        mkdir($this->base . '/views/probe', 0777, true);
        // A group with ONLY a secondary template (no probe.html.php / view.html.php).
        file_put_contents($this->base . '/views/probe/inner.html.php', 'PROBE-INNER');
    }

    /** Recursively remove the throwaway tree. */
    protected function tearDown(): void
    {
        if ($this->base !== '' && is_dir($this->base)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->base);
        }
    }

    /**
     * A controller stub whose real constructor is bypassed (it needs the full
     * framework bootstrap). Only $application is read by _getView().
     */
    private function makeController(): Controller
    {
        $appStub = new \stdClass();
        $appStub->applicationInfo = ['namespace' => 'NoSuchAppNs'];
        $appStub->appName         = '';

        return new class($appStub) extends Controller {
            public function __construct(object $appStub)
            {
                $this->application = $appStub;
            }
        };
    }

    /** Invoke the private _getView() and read the resolved View's bound path. */
    private function resolvedPath(Controller $c, string $group): string
    {
        $view = (new \ReflectionMethod(Controller::class, '_getView'))
            ->invoke($c, $this->base, $group, 'html');

        $this->assertInstanceOf(View::class, $view);

        // Protected properties are readable via reflection without setAccessible()
        // (a no-op since PHP 8.1, deprecated in 8.5).
        $prop = new \ReflectionProperty(View::class, 'path');
        return (string) $prop->getValue($view);
    }

    /**
     * A group that has only a secondary template binds the View to the GROUP
     * directory (not its parent), so display('inner') can resolve inner.html.php.
     */
    public function testBindsToGroupDirectoryForSecondaryOnlyGroup(): void
    {
        $path = $this->resolvedPath($this->makeController(), 'probe');

        $this->assertSame(
            $this->base . '/views/probe',
            $path,
            'View must bind to the group directory so display(sub) resolves group/sub.html.php'
        );
        $this->assertFileExists($path . '/inner.html.php', 'the secondary template lives in the bound dir');
    }

    /**
     * The same binding holds when the group DOES ship a default template — proving
     * the fix is a no-op for groups that already worked (e.g. login/login.html.php).
     */
    public function testBindsToGroupDirectoryWhenDefaultTemplatePresent(): void
    {
        file_put_contents($this->base . '/views/probe/probe.html.php', 'PROBE-DEFAULT');

        $path = $this->resolvedPath($this->makeController(), 'probe');

        $this->assertSame($this->base . '/views/probe', $path);
    }
}
