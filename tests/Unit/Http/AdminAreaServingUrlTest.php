<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\AdminArea;

/**
 * Developer pages link within the application that serves them.
 *
 * The DevPanel, Adminer and the debug-toolbar grant are reachable at the site root and inside
 * an administration area. Their links were built from `sURL`, so a panel served at
 * `/admin/devpanel` pointed its tabs at `/devpanel/…` and Adminer at `/adminer?file=…` for its
 * stylesheet and scripts — a page with no CSS, whose statistics never loaded.
 * {@see AdminArea::servingUrl()} is the base they use now.
 */
class AdminAreaServingUrlTest extends TestCase
{
    protected function setUp(): void
    {
        AdminArea::reset();
        $_GET = [];
    }

    protected function tearDown(): void
    {
        AdminArea::reset();
        $_GET = [];
    }

    /** Enter the `admin` area as a request for `admin/<path>` would. */
    private function insideTheArea(string $path): void
    {
        $_GET['r'] = 'admin/' . $path;
        AdminArea::detect('admin');
    }

    /**
     * Inside an area, the base is the area's URL.
     */
    public function testInsideAnAreaTheBaseIsTheArea(): void
    {
        // Arrange
        $this->insideTheArea('devpanel');

        // Act
        $base = AdminArea::servingUrl();

        // Assert
        $this->assertSame(rtrim(sURL, '/') . '/admin', $base);
    }

    /**
     * With an area configured but the request outside it, the base is the site — `URL` would
     * say the area, because it is the area's URL on every request.
     */
    public function testOutsideAConfiguredAreaTheBaseIsTheSite(): void
    {
        // Arrange — the area is configured, this request is not in it
        $_GET['r'] = 'devpanel';
        AdminArea::detect('admin');

        // Act
        $base = AdminArea::servingUrl();

        // Assert
        $this->assertSame(rtrim(sURL, '/'), $base);
    }

    /**
     * With no area configured, the base is `URL` — the URL a secondary application defines as
     * its own — without its trailing slash.
     */
    public function testWithoutAnAreaTheBaseIsUrl(): void
    {
        // Act
        $base = AdminArea::servingUrl();

        // Assert
        $this->assertSame(rtrim(URL, '/'), $base);
    }

    /**
     * The panel's tabs, served inside the area, stay inside it.
     */
    public function testThePanelTabsStayInsideTheArea(): void
    {
        // Arrange
        $this->insideTheArea('devpanel');

        // Act
        $html = \Pramnos\DevPanel\DevPanelController::tabStrip('db');

        // Assert — every tab under /admin/, none at the site root
        $this->assertStringContainsString(rtrim(sURL, '/') . '/admin/devpanel', $html);
        $this->assertStringNotContainsString('"' . rtrim(sURL, '/') . '/devpanel', $html);
    }

    /**
     * Adminer, served inside the area, asks for its stylesheet and scripts inside it.
     */
    public function testAdminerAsksForItsAssetsInsideTheArea(): void
    {
        // Arrange
        $this->insideTheArea('adminer');
        $adminer = (new \ReflectionClass(\Pramnos\Application\Controllers\Adminer::class))
            ->newInstanceWithoutConstructor();

        // Act
        $url  = (new \ReflectionMethod($adminer, 'routeUrl'))->invoke($adminer);
        $path = (new \ReflectionMethod($adminer, 'routePath'))->invoke($adminer);

        // Assert
        $this->assertSame(rtrim(sURL, '/') . '/admin/adminer', $url);
        $this->assertSame('/admin/adminer', $path, 'the path a request URI is matched against');
    }
}
