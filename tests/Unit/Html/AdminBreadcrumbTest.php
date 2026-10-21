<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Html\Breadcrumb;

/**
 * The framework's admin screens draw one trail — theirs, or the application shell's.
 *
 * An application with an administration of its own draws its breadcrumb in its toolbar, from
 * `addbreadcrumb()`. A framework screen that printed its own as well gave the page two trails,
 * the second one unstyled: "Home / Dashboard / Email history / #1452" on four plain lines
 * above the table. `'admin' => ['breadcrumb' => 'theme']` hands the steps to the shell.
 */
#[CoversClass(Breadcrumb::class)]
final class AdminBreadcrumbTest extends TestCase
{
    /** @var string|null The application that was current before the test made its own */
    private ?string $previous = null;

    /** @var Application|null The application the test made current */
    private ?Application $app = null;

    /**
     * Puts the previous application back.
     */
    protected function tearDown(): void
    {
        $this->app?->restoreCurrentInstance($this->previous);
        $this->app = null;
        parent::tearDown();
    }

    /**
     * An application with `$admin` as its `admin` configuration, made current.
     *
     * @param array<string, mixed> $admin
     */
    private function application(array $admin): Application
    {
        $this->app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        $this->app->applicationInfo = ['admin' => $admin];
        (new \ReflectionProperty(Application::class, 'breadcrumbs'))->setValue($this->app, new Breadcrumb());
        $this->previous = $this->app->makeCurrentInstance();

        return $this->app;
    }

    /**
     * The trail the application collected.
     *
     * @return array<string, array{label: string, url: string, title: string}>
     */
    private function collected(Application $app): array
    {
        return (new \ReflectionProperty(Application::class, 'breadcrumbs'))->getValue($app)->items;
    }

    /**
     * With no setting the screen draws its own trail, Home and Dashboard first, as it always has
     * — an application on a scaffolded theme has no other.
     */
    public function testWithoutTheSettingTheScreenDrawsItsOwnTrail(): void
    {
        // Arrange
        $app = $this->application([]);

        // Act
        $html = Breadcrumb::admin([['Email history', '/admin/Emails'], ['#1452', '']]);

        // Assert
        $this->assertStringContainsString('Home', $html);
        $this->assertStringContainsString('Dashboard', $html);
        $this->assertStringContainsString('Email history', $html);
        $this->assertStringContainsString('#1452', $html);
        $this->assertSame([], $this->collected($app), 'the shell was handed a trail it was not asked to draw');
    }

    /**
     * With `breadcrumb => theme` the screen prints nothing and the shell gets the steps: the
     * label as text, not the HTML-escaped form the partial builds, and `'#'` for the current
     * page, which is what `addbreadcrumb()` takes for "no link".
     */
    public function testWithTheSettingTheShellGetsTheStepsAndThePagePrintsNothing(): void
    {
        // Arrange
        $app = $this->application(['breadcrumb' => 'theme']);

        // Act
        $html = Breadcrumb::admin([['Email history', '/admin/Emails'], ['O&#039;Brien &amp; Co', '']]);

        // Assert
        $this->assertSame('', $html, 'a second trail was printed beside the shell\'s');
        $items = array_values($this->collected($app));
        $this->assertCount(2, $items, 'Home and Dashboard belong to the shell, not to the steps');
        $this->assertSame(['Email history', '/admin/Emails'], [$items[0]['label'], $items[0]['url']]);
        // Text, so the shell's own escaping does not print `&amp;` on the page.
        $this->assertSame(["O'Brien & Co", '#'], [$items[1]['label'], $items[1]['url']]);
    }

    /**
     * Every theme's admin partial goes through `Breadcrumb::admin()`; a copy that still built
     * its own `Breadcrumb` would ignore the setting on that theme's screens.
     */
    #[DataProvider('themes')]
    public function testEveryThemesPartialGoesThroughTheHelper(string $theme): void
    {
        // Arrange
        $file = dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme . '/views/partials/admin_breadcrumb.html.php';

        // Act
        $partial = (string) file_get_contents($file);

        // Assert
        $this->assertStringContainsString('Breadcrumb::admin(', $partial);
        $this->assertStringNotContainsString('new \Pramnos\Html\Breadcrumb', $partial);
    }

    /**
     * No admin screen builds a `Breadcrumb` of its own: one that did would print its trail
     * beside the shell's whatever the setting said, as the queue's task and statistics pages
     * did. The account area's partial is the site's, not the administration's.
     */
    #[DataProvider('themes')]
    public function testNoAdminViewBuildsItsOwnTrail(string $theme): void
    {
        // Arrange
        $root  = dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme . '/views';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $seen  = 0;
        $own   = [];

        // Act
        foreach ($files as $file) {
            $name = substr((string) $file, strlen($root) + 1);
            if (!str_ends_with($name, '.php') || $name === 'partials/account_breadcrumb.html.php') {
                continue;
            }
            $source = (string) file_get_contents((string) $file);
            $seen  += (int) str_contains($source, 'Breadcrumb::admin(');
            if (str_contains($source, 'new \\Pramnos\\Html\\Breadcrumb')) {
                $own[] = $name;
            }
        }

        // Assert
        $this->assertGreaterThanOrEqual(3, $seen, 'the sweep found none of the screens that use the helper');
        $this->assertSame([], $own, 'admin views that draw their own trail: use Breadcrumb::admin()');
    }

    /**
     * The email preview is sandboxed without `allow-same-origin` or `allow-scripts`, so the page
     * cannot read the frame's document: an `onload` that sized it from `contentDocument` threw a
     * TypeError on every message, and as an inline handler a CSP without `unsafe-inline` blocks
     * it anyway. The frame has a fixed height the reader can drag, and no handler. Popups are the
     * one thing allowed: the message's links open in a window of their own.
     */
    #[DataProvider('themes')]
    public function testTheEmailPreviewHasNoHandlerAndStaysSandboxed(string $theme): void
    {
        // Arrange
        $file = dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme . '/views/emails/show.html.php';

        // Act — the markup only: the PHP inside the tag carries a `>` of its own.
        $html = (string) preg_replace('/<\?(?:php|=).*?\?>/s', 'x', (string) file_get_contents($file));
        $this->assertSame(1, preg_match('/<iframe\b[^>]*>/s', $html, $m), 'no preview frame');
        $frame = $m[0];

        // Assert
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/i', $frame, 'an inline handler on the preview');
        $this->assertMatchesRegularExpression('/\ssandbox[\s>=]/', $frame, 'the preview is not sandboxed');
        // Either of these would hand the message the page's origin or a running script.
        $this->assertStringNotContainsString('allow-same-origin', $frame, 'the sandbox was loosened');
        $this->assertStringNotContainsString('allow-scripts', $frame, 'the sandbox was loosened');
        $this->assertStringContainsString('resize:vertical', $frame);
    }

    /**
     * The three scaffolded themes.
     *
     * @return array<string, array{string}>
     */
    public static function themes(): array
    {
        return ['tailwind' => ['tailwind'], 'bootstrap' => ['bootstrap'], 'plain-css' => ['plain-css']];
    }
}
