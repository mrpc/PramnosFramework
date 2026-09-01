<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;

/**
 * The four things `exec()` does with what `app.php` declared, none of which had ever run.
 *
 * `exec()` is the request. It had eight hits across the suite and thirty-two statements that had
 * never executed once, and the interesting part is *which* thirty-two: not error handling, but the
 * declarations an application makes about itself in `app.php` and then never sees applied.
 *
 *  - **`scripts` and `css`** are documented keys. Every asset an application registers globally goes
 *    through these two loops, so an application that used the feature got no scripts and no
 *    stylesheets — and the symptom is a page that renders with no styling, three layers from the
 *    cause.
 *  - **`forcessl`** is a setting whose entire purpose is to happen on every request.
 *  - **`defaultController`** is the answer to «what does `/` show», and the fallback when there is no
 *    answer is a 404 rather than a blank page.
 *
 * The methods with side effects outside this one — the version check, the migration runner, the CSP
 * header, the controller — are mocked away, because the thing under test is the wiring between
 * `applicationInfo` and the document, and nothing else in `exec()` participates in it.
 */
#[CoversClass(Application::class)]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class ApplicationExecWiringTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('ROOT')) {
            define('ROOT', realpath(__DIR__ . '/../../../../'));
        }
        if (!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
        }
        if (!defined('APP_PATH')) {
            define('APP_PATH', ROOT . DS . 'app');
        }
        if (!defined('sURL')) {
            define('sURL', 'http://exec.test/');
        }
    }

    /**
     * An application with the methods that reach outside a request stubbed out.
     *
     * @param array<string, mixed> $info
     * @param list<string>         $alsoMock
     */
    private function application(array $info, array $alsoMock = []): Application
    {
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods(array_merge([
                'sendCspHeader',
                'checkversion',
                'runAutoMigrations',
                'getController',
                'addbreadcrumb',
            ], $alsoMock))
            ->getMock();

        $app->method('checkversion')->willReturn(true);
        $app->method('sendCspHeader')->willReturn(null);
        $app->method('addbreadcrumb')->willReturn($app);

        $controller = $this->getMockBuilder(\Pramnos\Application\Controller::class)
            ->disableOriginalConstructor()
            ->getMock();
        $controller->method('exec')->willReturn('');
        $app->method('getController')->willReturn($controller);

        $app->applicationInfo = $info;

        return $app;
    }

    /** The document's registries are protected; this is what the page later renders from. */
    private function registered(string $property): array
    {
        $doc = \Pramnos\Framework\Factory::getDocument();
        // No setAccessible(): deprecated in PHP 8.5, where it has had no effect since 8.1.
        return (array) (new \ReflectionProperty($doc, $property))->getValue($doc);
    }

    /**
     * A script declared in `app.php` is registered on the document, with `sURL` prefixed.
     *
     * The prefix is the part worth asserting rather than assuming. `app.php` gives a path relative to
     * the web root — `assets/js/app.js`, not a URL — because the application does not know its own
     * host at the time the file is written. A loop that registered the raw value would emit a
     * relative `<script src>`, which resolves against the *current page* and so silently breaks on
     * every URL with more than one path segment.
     */
    public function testAScriptDeclaredInAppPhpReachesTheDocument(): void
    {
        // Arrange
        $app = $this->application([
            'scripts' => [
                [
                    'script'  => 'exec-wiring',
                    'src'     => 'assets/js/exec-wiring.js',
                    'deps'    => ['jquery'],
                    'version' => '2.1',
                    'footer'  => true,
                ],
            ],
        ]);

        // Act
        $app->exec('home');

        // Assert
        $scripts = $this->registered('_js');
        $this->assertArrayHasKey('exec-wiring', $scripts, 'the declared script was never registered');
        $this->assertSame(sURL . 'assets/js/exec-wiring.js', $scripts['exec-wiring']['src']);
        $this->assertSame(['jquery'], $scripts['exec-wiring']['deps']);
        $this->assertSame('2.1', $scripts['exec-wiring']['ver']);
        $this->assertTrue($scripts['exec-wiring']['footer'], 'the footer flag was dropped');
    }

    /**
     * A stylesheet declared in `app.php` is registered too, with its media query intact.
     *
     * The same loop, one key over, and worth its own test because the two do not share a code path:
     * `registerStyle()` takes `media` where `registerScript()` takes `footer`, and a copy-paste
     * between them loses whichever one it did not expect.
     */
    public function testAStylesheetDeclaredInAppPhpReachesTheDocument(): void
    {
        // Arrange
        $app = $this->application([
            'css' => [
                [
                    'name'    => 'exec-print',
                    'src'     => 'assets/css/print.css',
                    'deps'    => [],
                    'version' => '1.0',
                    'media'   => 'print',
                ],
            ],
        ]);

        // Act
        $app->exec('home');

        // Assert
        $styles = $this->registered('_css');
        $this->assertArrayHasKey('exec-print', $styles, 'the declared stylesheet was never registered');
        $this->assertSame(sURL . 'assets/css/print.css', $styles['exec-print']['src']);
        $this->assertSame('print', $styles['exec-print']['media'], 'the media query was dropped');
    }

    /**
     * With `forcessl` on and a plain-HTTP base, the request is redirected permanently.
     *
     * 301 and not 302, which is the whole value of doing it here: a permanent redirect is cached by
     * the browser, so the second visit never makes the insecure request at all. A temporary one asks
     * for the password over HTTP again tomorrow.
     */
    public function testForceSslRedirectsPermanently(): void
    {
        // Arrange — in memory only; this setting is read, not written, on a request
        Settings::setSetting('forcessl', '1', false);
        // `sURL` is a constant the suite's bootstrap defines as an https address, so the branch is
        // reachable only through the seam — which is why the seam exists.
        $app = $this->application([], ['redirect', 'siteUrl']);
        $app->method('siteUrl')->willReturn('http://exec.test/');

        $captured = [];
        $app->expects($this->once())
            ->method('redirect')
            ->willReturnCallback(function ($url, $moved = false, $code = 302) use (&$captured) {
                $captured = ['url' => $url, 'code' => $code];
            });

        // Act
        $app->exec('home');

        // Assert
        $this->assertSame('https://exec.test/', $captured['url']);
        $this->assertSame(301, $captured['code'], 'a temporary redirect asks for HTTP again tomorrow');
    }

    /**
     * `forcessl` off leaves the request alone.
     *
     * The other half, and the one that would make the feature a bug rather than a missing feature: a
     * redirect that fired regardless of the setting would loop forever behind a TLS-terminating
     * proxy, where the application sees HTTP for a request the visitor made over HTTPS.
     */
    public function testWithoutForceSslNothingIsRedirected(): void
    {
        // Arrange
        Settings::setSetting('forcessl', '0', false);
        $app = $this->application([], ['redirect', 'siteUrl']);
        $app->method('siteUrl')->willReturn('http://exec.test/');
        $app->expects($this->never())->method('redirect');

        // Act
        $app->exec('home');

        // Assert
        $this->assertSame('home', $app->controller);
    }

    /**
     * An empty request falls back to the default controller.
     *
     * This is what answers `/`. `exec('')` with nothing already resolved is the front page, and the
     * fallback is the only thing that decides what it shows.
     */
    public function testAnEmptyRequestFallsBackToTheDefaultController(): void
    {
        // Arrange
        $app = $this->application([]);
        $app->defaultController = 'dashboard';

        // Act
        $app->exec('');

        // Assert
        $this->assertSame('dashboard', $app->controller);
    }

    /**
     * With no default controller either, it is a 404 — not a blank page.
     *
     * An application that never set one has no answer for `/`, and the two ways to have no answer are
     * not equivalent: an empty 200 tells a crawler the page exists and is empty, and tells a person
     * nothing at all.
     */
    public function testAnEmptyRequestWithNoDefaultIsNotFound(): void
    {
        // Arrange
        $app = $this->application([], ['notFound']);
        $app->defaultController = '';
        $app->expects($this->once())->method('notFound');

        // Act
        $app->exec('');

        // Assert — the expectation above is the assertion; this states the resolved state
        $this->assertSame('', $app->controller);
    }
}
