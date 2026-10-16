<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Push;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Push\ServiceWorker;

/**
 * Whether the browser half of push is actually there.
 *
 * Push is delivered **to a service worker**. Everything on the server can be right — a key pair,
 * subscriptions, the library, a `201` from the push service — and a worker with no `push`
 * listener discards every notification, silently, on every device. There is no error anywhere:
 * the send succeeds and nobody mentions receiving anything.
 *
 * Found exactly that way on a real installation whose `sw.js` predated the feature by four days.
 */
#[CoversClass(ServiceWorker::class)]
class ServiceWorkerTest extends TestCase
{
    /**
     * The worker's address to register carries its modification time, so a deploy needs no
     * version bumped by hand, and the parameters the worker reads from its own address after it.
     */
    public function testTheWorkerAddressIsVersioned(): void
    {
        // Arrange
        $web = \Pramnos\Application\FrameworkScripts::webRoot((string) ROOT);
        $name = 'pf-sw-' . bin2hex(random_bytes(3)) . '.js';
        file_put_contents($web . '/' . $name, '//');
        touch($web . '/' . $name, 1700000002);

        try {
            // Act
            $plain  = ServiceWorker::url($name);
            $params = ServiceWorker::url('/' . $name, ['skip' => 'admin/,api/', 'offline' => 'errors/offline.html']);
            $none   = ServiceWorker::url('no-such-worker.js', ['skip' => '']);
        } finally {
            @unlink($web . '/' . $name);
        }

        // Assert
        $this->assertStringEndsWith($name . '?v=1700000002', $plain);
        $this->assertStringEndsWith($name . '?v=1700000002&skip=admin%2F%2Capi%2F&offline=errors%2Foffline.html', $params);
        $this->assertStringEndsWith('no-such-worker.js?skip=', $none, 'no file: no stamp, the parameters still');
    }

    /**
     * The worker app.php names — `push.service_worker`, under the web root or absolute — is the
     * one checked, before the usual names; and `serviceWorker.js` is among the usual names, so an
     * installation whose worker is called that is not told it has none.
     */
    public function testTheConfiguredWorkerIsLookedAtFirst(): void
    {
        // Arrange
        $app  = \Pramnos\Application\Application::getInstance();
        $info = $app->applicationInfo;
        $file = sys_get_temp_dir() . '/sw-' . bin2hex(random_bytes(4)) . '.js';
        file_put_contents($file, "self.addEventListener('push', () => {});");
        $candidates = new \ReflectionMethod(ServiceWorker::class, 'candidates');

        try {
            // Act
            $app->applicationInfo['push'] = ['service_worker' => $file];
            $found = ServiceWorker::path();
            $app->applicationInfo['push'] = ['service_worker' => './js/worker.js'];
            $relative = $candidates->invoke(null);
            $app->applicationInfo['push'] = ['service_worker' => '  '];
            $blank = ServiceWorker::configured();
        } finally {
            $app->applicationInfo = $info;
            @unlink($file);
        }

        // Assert
        $this->assertSame($file, $found);
        $this->assertSame(ROOT . DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR . 'js/worker.js', $relative[0], 'under the web root, without its ./');
        $this->assertContains(ROOT . DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR . 'serviceWorker.js', $relative);
        $this->assertNull($blank, 'an empty setting is no setting');
    }

    /**
     * A worker with none of the handlers is reported as missing all three.
     *
     * The state of every project scaffolded before web push existed.
     */
    public function testAWorkerWithoutTheHandlersIsReportedAsSuch(): void
    {
        // Arrange — a cache-only worker, which is what `init` used to write
        $missing = $this->missingFor(<<<'JS'
            const CACHE = 'v1';
            self.addEventListener('install', (e) => e.waitUntil(caches.open(CACHE)));
            self.addEventListener('fetch', (e) => e.respondWith(fetch(e.request)));
            JS);

        // Assert
        $this->assertSame(
            ['push', 'notificationclick', 'pushsubscriptionchange'],
            array_keys($missing)
        );
    }

    /**
     * The scaffolded worker has all three.
     *
     * Asserted against the real stub, so the check and the thing it checks cannot drift: if
     * somebody removes a handler from the template, this says so.
     */
    public function testTheScaffoldedWorkerHasAllThree(): void
    {
        // Arrange
        $stub = dirname(__DIR__, 3) . '/scaffolding/templates/service-worker.js.stub';

        // Act
        $missing = $this->missingFor((string) file_get_contents($stub));

        // Assert
        $this->assertSame([], $missing, 'the template a project starts from must be complete');
    }

    /**
     * The scaffolded worker reports its own errors where the pages report theirs.
     *
     * A page cannot see what fails inside the worker, so without these listeners an error in the
     * push or fetch handlers is invisible. Asserted on the stub, as the handlers above are.
     */
    public function testTheScaffoldedWorkerReportsItsOwnErrors(): void
    {
        // Arrange
        $stub = (string) file_get_contents(dirname(__DIR__, 3) . '/scaffolding/templates/service-worker.js.stub');

        // Assert
        $this->assertStringContainsString("self.addEventListener('error'", $stub);
        $this->assertStringContainsString("self.addEventListener('unhandledrejection'", $stub);
        $this->assertStringContainsString("fetch(sitePath('ClientErrors')", $stub, 'the address the pages report to');
        $this->assertStringContainsString("kind: 'worker'", $stub);
    }

    /**
     * `pushManager` is not a `push` listener.
     *
     * The word appears in a subscribing page, in a cache name, in a comment. What decides
     * whether a notification is received is whether something is listening for it — so the
     * match is on the registration, not on the word.
     */
    public function testTheWordPushIsNotAPushListener(): void
    {
        // Arrange
        $missing = $this->missingFor(<<<'JS'
            // push notifications are handled elsewhere
            const sub = self.registration.pushManager.getSubscription();
            self.addEventListener('fetch', () => {});
            JS);

        // Assert
        $this->assertArrayHasKey('push', $missing);
    }

    /**
     * Whitespace and double quotes are the same registration.
     */
    public function testTheMatchSurvivesFormatting(): void
    {
        // Arrange
        $missing = $this->missingFor(<<<'JS'
            self.addEventListener( "push" , (e) => {});
            self.addEventListener("notificationclick", (e) => {});
            self.addEventListener(
                'pushsubscriptionchange',
                (e) => {}
            );
            JS);

        // Assert
        $this->assertSame([], $missing);
    }

    /**
     * A partly-updated worker names exactly what it still needs.
     *
     * `pushsubscriptionchange` is the one people leave out, and it is the one that fails later:
     * the browser rotates a subscription's keys, every push to the old endpoint returns 410, the
     * row is deleted, and the device stops receiving with nobody the wiser.
     */
    public function testItNamesOnlyWhatIsStillMissing(): void
    {
        // Arrange
        $missing = $this->missingFor(<<<'JS'
            self.addEventListener('push', (e) => {});
            self.addEventListener('notificationclick', (e) => {});
            JS);

        // Assert
        $this->assertSame(['pushsubscriptionchange'], array_keys($missing));
        $this->assertStringContainsString('silently stops receiving', $missing['pushsubscriptionchange']);
    }

    /**
     * The scaffolded worker does not let `showNotification()` reject uncaught.
     *
     * It rejects for real: permission revoked after this browser subscribed, or a push
     * simulated from the developer tools. Handed to `waitUntil()` without a catch, that becomes
     * an unhandled rejection in the console of somebody else's browser — which is where it was
     * first seen.
     */
    public function testTheScaffoldedWorkerCatchesAFailedNotification(): void
    {
        // Arrange
        $stub = (string) file_get_contents(
            dirname(__DIR__, 3) . '/scaffolding/templates/service-worker.js.stub'
        );

        // Assert
        $this->assertMatchesRegularExpression(
            '~showNotification\(.*?\}\)\.catch\(~s',
            $stub,
            'showNotification() rejects, and an uncaught rejection is a console error nobody sees'
        );
        $this->assertStringContainsString(
            'NotAllowedError',
            $stub,
            'and permission revoked after subscribing has to stop the server sending for ever'
        );
    }

    /**
     * The browser half exists and asks from a click.
     *
     * The worker can receive a notification; nothing in it can ask to show one.
     * `requestPermission()` and `subscribe()` live in a page, and an installation with the
     * worker, the keys, the table and the endpoints has no subscriptions for ever without them
     * — with nothing anywhere saying why. Found exactly that way.
     */
    public function testTheBrowserHalfIsShippedAndAsksFromAClick(): void
    {
        // Arrange
        $script = (string) file_get_contents(
            dirname(__DIR__, 3) . '/scaffolding/templates/push-notifications.js.stub'
        );

        // Assert
        $this->assertStringContainsString('Notification.requestPermission', $script);
        $this->assertStringContainsString("addEventListener('click'", $script);
        $this->assertStringContainsString("sitePath('push/subscribe')", $script, 'from where the site is, not the host\'s root');
        $this->assertStringNotContainsString("'/push/", $script);
        $this->assertStringNotContainsString(
            "addEventListener('load', subscribe",
            $script,
            'a prompt on page load is denied by most people and suppressed by Chrome for the rest'
        );
    }

    /**
     * Every handler comes with what it is for.
     *
     * A list of three identifiers tells somebody to go and look them up. The reason is what
     * makes the finding actionable in the place it is read.
     */
    public function testEveryHandlerSaysWhatItIsFor(): void
    {
        foreach (ServiceWorker::HANDLERS as $handler => $why) {
            // Assert
            $this->assertNotSame('', $why, $handler);
            $this->assertGreaterThan(30, strlen($why), $handler);
        }
    }

    /**
     * No worker at all is reported as no worker, not as a broken one.
     *
     * An application that put its worker somewhere the framework cannot find is not an
     * application with a broken worker, and guessing at paths would report one.
     */
    public function testNoWorkerIsReportedAsAllThreeMissing(): void
    {
        // Act — a path that cannot exist
        $missing = $this->missingIn(sys_get_temp_dir() . '/no-such-root-' . bin2hex(random_bytes(4)));

        // Assert
        $this->assertSame(ServiceWorker::HANDLERS, $missing);
    }

    /**
     * `handlesPush()` is the one question most callers have.
     */
    public function testHandlesPushIsTheShortAnswer(): void
    {
        // Assert — this checkout's own worker, whatever it is, agrees with `missing()`
        $this->assertSame(
            !array_key_exists('push', ServiceWorker::missing()),
            ServiceWorker::handlesPush()
        );
    }

    /**
     * Run the check over a worker with this source.
     *
     * @return array<string, string>
     */
    private function missingFor(string $source): array
    {
        $root = sys_get_temp_dir() . '/sw-' . bin2hex(random_bytes(5));
        mkdir($root . '/www', 0700, true);
        file_put_contents($root . '/www/sw.js', $source);

        try {
            return $this->missingIn($root);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /**
     * The check, rooted somewhere other than this installation.
     *
     * `ROOT` is a constant and cannot be redefined, so the candidate paths are the seam.
     *
     * @return array<string, string>
     */
    private function missingIn(string $root): array
    {
        RootedServiceWorker::$root = $root;

        return RootedServiceWorker::missing();
    }
}

/** The check, looking somewhere a test can write. */
class RootedServiceWorker extends ServiceWorker
{
    public static string $root = '';

    /** @return list<string> */
    protected static function candidates(): array
    {
        return [
            self::$root . '/www/sw.js',
            self::$root . '/public/sw.js',
            self::$root . '/sw.js',
        ];
    }
    /**
     * A worker that cannot be read counts as having nothing.
     *
     * Unreadable is not "fine" — a file that is there but cannot be opened tells us nothing about
     * what it listens for, and the safe answer for a check whose whole job is to warn is to warn.
     */
    public function testAWorkerThatCannotBeReadCountsAsMissingEverything(): void
    {
        // Arrange — reported as present, gone by the time it is opened
        $worker = new class extends ServiceWorker {
            public static function path(): ?string
            {
                return '/nonexistent/www/sw.js';
            }
        };

        // Act
        $missing = $worker::missing();

        // Assert
        $this->assertSame(ServiceWorker::HANDLERS, $missing);
        $this->assertFalse($worker::handlesPush());
    }

}
