<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controller;
use Pramnos\Application\View;

/**
 * A view's template of either kind: `.html.php` or `.tpl.php` (the template engine).
 *
 * A `display()` asked for HTML used to look only for `.html.php`, so a view written only as a
 * template came out blank with nothing said. Now each place a template may be — the theme's
 * override, the application's view directory, the scaffold — is tried for the kind asked first
 * and the other next: a `.tpl.php` alone is drawn, and an application's own `.html.php` still
 * wins over a scaffold's `.tpl.php` whichever kind the controller asked for.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(View::class)]
class ViewKindFallbackTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/pf-view-kind-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/Home', 0777, true);
        mkdir($this->tmp . '/metricreports', 0777, true);
        mkdir($this->tmp . '/cache', 0777, true);
        View::setTemplateCacheDir($this->tmp . '/cache');
    }

    protected function tearDown(): void
    {
        View::setTemplateCacheDir('');
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->tmp);
    }

    /** A view in a directory of the test's own. */
    private function view(string $name, string $type = 'html'): View
    {
        return new View(new Controller(), $this->tmp . '/' . $name, $name, $type);
    }

    /** A `.tpl.php` alone is drawn by a display() that asked for HTML, through the template engine. */
    public function testATemplateAloneIsDrawn(): void
    {
        // Arrange
        file_put_contents($this->tmp . '/Home/page.tpl.php', '<p>{{ $this->who }}</p>@if (true)<b>yes</b>@endif');
        $view      = $this->view('Home');
        $view->who = '<Ann>';

        // Act
        $html = (string) $view->display('page');

        // Assert
        $this->assertSame('<p>&lt;Ann&gt;</p><b>yes</b>', trim($html), 'compiled, escaped, drawn');
    }

    /** Both kinds side by side: the one asked for wins. */
    public function testTheKindAskedForWins(): void
    {
        // Arrange
        file_put_contents($this->tmp . '/Home/page.html.php', 'html');
        file_put_contents($this->tmp . '/Home/page.tpl.php', 'tpl');

        // Act & Assert
        $this->assertSame('html', trim((string) $this->view('Home')->display('page')));
        $this->assertSame('tpl', trim((string) $this->view('Home', 'tpl')->display('page')));
    }

    /**
     * An application's own `.html.php` of a framework view wins over the scaffold's `.tpl.php`,
     * even when the controller asks for `tpl` — an override is not lost when the scaffold's view
     * changes kind.
     */
    public function testAnApplicationsOwnViewWinsOverTheScaffolds(): void
    {
        // Arrange
        file_put_contents($this->tmp . '/metricreports/metricreports.html.php', 'the application\'s own');

        // Act
        $html = (string) $this->view('metricreports', 'tpl')->display();

        // Assert
        $this->assertSame('the application\'s own', trim($html));
    }
}
