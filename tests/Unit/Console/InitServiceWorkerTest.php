<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The scaffolded service worker caches assets, is opt-in, and never touches HTML.
 *
 * The design was taken from a production service worker in a consuming application,
 * by reading what had gone wrong with it. Its own comments record two incidents — an
 * unversioned cache name that made a stale page permanent, and an error page stored as
 * if it were a real one — and it also carried a hand-maintained list of eleven URL
 * substrings never to cache, because it cached HTML. That list is the shape of the
 * problem: every private page added to the application is a chance to forget a line,
 * and the consequence is somebody's personal page in a stranger's browser, where
 * nothing on the server can purge it.
 *
 * So the assertions here are mostly about what the worker will **not** do. A test that
 * only proved it caches things would pass just as well on the version that caused the
 * incidents.
 */
#[CoversClass(Init::class)]
class InitServiceWorkerTest extends TestCase
{
    private string $tmpDir = '';
    private Init $command;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pramnos-sw-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0777, true);
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));

        $this->command = new Init();
        $this->command->targetBaseDir = $this->tmpDir;
        $this->command->skipDockerRun = true;
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->tmpDir);
    }

    /** @param array<string,mixed> $extra */
    private function scaffold(array $extra = []): void
    {
        $app = new Application();
        $app->add($this->command);
        (new CommandTester($this->command))->execute(array_merge([
            '--app-name'    => 'Worker App',
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace'   => 'WorkerApp',
            '--features'    => '',
            '--ui-system'   => 'tailwind',
            '--docker'      => 'n',
            '--libraries'   => '',
            '--db-type'     => 'postgresql',
            '--db-host'     => 'localhost',
            '--db-name'     => 'worker_db',
            '--db-user'     => 'worker',
            '--db-pass'     => 'pass',
            '--db-prefix'   => '',
        ], $extra), ['interactive' => false]);
    }

    private function worker(): string
    {
        return (string) file_get_contents($this->tmpDir . '/www/sw.js');
    }

    /**
     * Nothing is written unless it was asked for.
     *
     * A service worker is the most persistent thing an application can install on a
     * visitor's machine: it keeps itself alive across reloads, so a mistake in one is
     * not corrected by the next deployment the way a mistake in a page is. Default-on
     * would be handing that to people who did not ask.
     */
    public function testItIsNotScaffoldedByDefault(): void
    {
        // Act
        $this->scaffold();

        // Assert
        $this->assertFileDoesNotExist($this->tmpDir . '/www/sw.js');
        $this->assertStringNotContainsString(
            'serviceWorker',
            (string) file_get_contents($this->tmpDir . '/app/themes/default/footer.php'),
            'no registration should be emitted for a project that declined one'
        );
    }

    /**
     * With `--service-worker=y` the file and the registration both appear.
     */
    public function testItIsScaffoldedWhenAsked(): void
    {
        // Act
        $this->scaffold(['--service-worker' => 'y']);

        // Assert
        $this->assertFileExists($this->tmpDir . '/www/sw.js');
        $this->assertStringContainsString(
            "navigator.serviceWorker.register('<?php echo sURL; ?>sw.js')",
            (string) file_get_contents($this->tmpDir . '/app/themes/default/footer.php')
        );
    }

    /**
     * It sits at the web root, because that is what decides its scope.
     *
     * A service worker's default scope is the directory it is served from, so one under
     * `assets/` could only ever see requests for `assets/…`. Its own path is the whole
     * of what it is permitted to intercept, which makes the location a correctness
     * question rather than a tidiness one.
     */
    public function testItIsServedFromTheWebRootSoItsScopeIsTheSite(): void
    {
        // Act
        $this->scaffold(['--service-worker' => 'y', '--web-root' => 'public']);

        // Assert
        $this->assertFileExists($this->tmpDir . '/public/sw.js');
        $this->assertFileDoesNotExist($this->tmpDir . '/public/assets/sw.js');
    }

    /**
     * The registration URL comes from `sURL`, not from a literal `/sw.js`.
     *
     * An application served from a subdirectory registers at `/sub/sw.js`, and its
     * scope follows that path. A hard-coded `/sw.js` would either 404 or — worse, if
     * something answers it — register a worker scoped above the application.
     */
    public function testTheRegistrationUrlIsDerivedRatherThanHardCoded(): void
    {
        // Act
        $this->scaffold(['--service-worker' => 'y']);

        // Assert
        $footer = (string) file_get_contents($this->tmpDir . '/app/themes/default/footer.php');
        $this->assertStringContainsString('<?php echo sURL; ?>sw.js', $footer);
        $this->assertStringNotContainsString("register('/sw.js')", $footer);
    }

    /**
     * HTML is never intercepted.
     *
     * The single most important property. The worker filters by file extension and
     * bails out on anything else, so there is no list of private URLs to maintain and
     * no way for one visitor's page to be stored for another. Asserted on the guard
     * itself rather than on the absence of a deny-list, because "there is no list"
     * would also be true of a worker that cached everything.
     */
    public function testItOnlyHandlesAssetExtensions(): void
    {
        // Act
        $this->scaffold(['--service-worker' => 'y']);
        $worker = $this->worker();

        // Assert — the extension gate, and the bail-out that depends on it.
        $this->assertMatchesRegularExpression('/const ASSET = .+css.+js.+/', $worker);
        $this->assertStringContainsString('!ASSET.test(url.pathname)', $worker);

        // And no trace of the deny-list approach it replaces. Asserted on code, not on
        // the file's prose — its docblock names both, because explaining why they are
        // absent is the point of it.
        $this->assertStringNotContainsString("includes('text/html')", $worker);
        $this->assertStringNotContainsString('offlineUrl', $worker);
    }

    /**
     * Non-GET requests and other origins are left alone.
     *
     * A POST is never idempotent and a cross-origin response is opaque — neither can
     * be usefully cached, and intercepting either only adds a way to be wrong.
     */
    public function testItIgnoresNonGetAndCrossOrigin(): void
    {
        // Act
        $this->scaffold(['--service-worker' => 'y']);
        $worker = $this->worker();

        // Assert
        $this->assertStringContainsString("request.method !== 'GET'", $worker);
        $this->assertStringContainsString('url.origin !== self.location.origin', $worker);
    }

    /**
     * Only a successful response is stored.
     *
     * The second of the two recorded incidents: the worker being replaced stashed
     * whatever came back, so the "Maintenance Mode" page served while the database was
     * down became the cached copy of a real page and survived hard reloads.
     */
    public function testOnlySuccessfulResponsesAreStored(): void
    {
        // Act
        $this->scaffold(['--service-worker' => 'y']);

        // Assert
        $this->assertStringContainsString('response.ok', $this->worker());
    }

    /**
     * There is no hand-bumped cache version, and the cache is bounded instead.
     *
     * The first recorded incident: two of three caches had unversioned names, so the
     * sweep that deletes "caches not in the current list" could never reach them —
     * bumping the version purged one and left the others stale for good.
     *
     * Nothing here needs a version: immutable entries stay valid by definition and
     * everything else revalidates itself, so a bump would be repairing no state. What
     * bounds the cache is a cap enforced on write — unlike the `setInterval` sweep it
     * replaces, which could not run at all, because a browser terminates an idle
     * service worker long before a six-hour timer fires.
     */
    public function testThereIsNoHandBumpedVersionAndNoIdleTimer(): void
    {
        // Act
        $this->scaffold(['--service-worker' => 'y']);
        $worker = $this->worker();

        // Assert
        $this->assertStringContainsString('MAX_ENTRIES', $worker);
        $this->assertStringContainsString('keys.length > MAX_ENTRIES', $worker);
        // `setInterval(` with the paren: the docblock mentions the function by name to
        // explain why there is no call to it.
        $this->assertStringNotContainsString('setInterval(', $worker,
            'a timer inside a service worker does not fire — the worker is terminated when idle');
        // The code form, not the prose: the docblock quotes `v1.31::` in order to
        // explain why nothing here has one.
        $this->assertDoesNotMatchRegularExpression('/^const version\s*=/m', $worker,
            'a hand-bumped version prefix is what made a stale page permanent');
        $this->assertDoesNotMatchRegularExpression('/^\s*const \w*[Cc]acheName\s*=/m', $worker);
    }

    /**
     * Immutable URLs are cache-first; everything else revalidates.
     *
     * Cache-first on a URL whose contents can change is stale *forever*. The versioned
     * vendor directory and the content-hashed build output cannot change meaning, so
     * they are the only two things served without a check.
     */
    public function testImmutableUrlsAreCacheFirstAndTheRestRevalidate(): void
    {
        // Act
        $this->scaffold(['--service-worker' => 'y']);
        $worker = $this->worker();

        // Assert — the two immutable patterns…
        $this->assertStringContainsString('assets\/vendor\/', $worker);
        $this->assertStringContainsString('assets\/spa\/', $worker);

        // …served without revalidation, and everything else with it.
        $this->assertStringContainsString('if (cached && immutable)', $worker);
        $this->assertStringContainsString('event.waitUntil(network', $worker);
    }

    /**
     * The cache name is per-project.
     *
     * Two applications on one origin — a staging path, a subdirectory install — would
     * otherwise share one cache and serve each other's assets.
     */
    public function testTheCacheNameIsPerProject(): void
    {
        // Act
        $this->scaffold(['--service-worker' => 'y']);

        // Assert
        $this->assertStringContainsString("const CACHE = 'worker-app-assets'", $this->worker());
    }

    /**
     * A page can purge the cache or remove the worker.
     *
     * The recovery path, and it needs to exist even though the blast radius is small:
     * a service worker keeps itself alive, so "deploy a fix" is not a recovery
     * mechanism for the worker itself.
     */
    public function testThePageCanPurgeOrUnregister(): void
    {
        // Act
        $this->scaffold(['--service-worker' => 'y']);
        $worker = $this->worker();

        // Assert
        $this->assertStringContainsString("command === 'purge'", $worker);
        $this->assertStringContainsString("command === 'unregister'", $worker);
        $this->assertStringContainsString('self.registration.unregister()', $worker);
    }

    /**
     * A SPA project registers it from the shell, not from the theme.
     *
     * The shell emits its own HTML and never reaches the theme footer, so without this
     * a SPA project would get the worker file and nothing that installs it — the
     * quietest possible failure, since a service worker that is never registered leaves
     * no trace at all.
     */
    public function testASpaProjectRegistersItFromTheShell(): void
    {
        // Act
        $this->scaffold([
            '--service-worker' => 'y',
            '--app-style'      => 'spa',
            '--spa-stack'      => 'vanilla',
        ]);

        // Assert
        $shell = (string) file_get_contents($this->tmpDir . '/www/spa.php');
        $this->assertStringContainsString('navigator.serviceWorker.register', $shell);
        $this->assertStringNotContainsString('{{ serviceWorkerRegistration }}', $shell);
    }

    /**
     * A SPA project that declined one has no placeholder left behind.
     *
     * The token is replaced with an empty string, so the negative case is worth
     * asserting on its own: an unreplaced `{{ … }}` in the shell would be visible text
     * on the page.
     */
    public function testASpaShellHasNoLeftoverTokenWhenDeclined(): void
    {
        // Act
        $this->scaffold(['--app-style' => 'spa', '--spa-stack' => 'vanilla']);

        // Assert
        $shell = (string) file_get_contents($this->tmpDir . '/www/spa.php');
        $this->assertStringNotContainsString('serviceWorker', $shell);
        $this->assertStringNotContainsString('{{', $shell);
    }

    /**
     * A refused registration is reported, not discarded.
     *
     * The first version of this swallowed the rejection, on the reasoning that a
     * browser which declines to register is simply a browser without the cache. It cost
     * a real debugging session: the framework's own policy said `worker-src 'none'`,
     * every registration was refused by CSP, and the only thing that would have said so
     * had been thrown away. A refused registration is a misconfiguration somebody can
     * fix.
     */
    public function testARefusedRegistrationIsReported(): void
    {
        // Act
        $this->scaffold(['--service-worker' => 'y']);

        // Assert
        $footer = (string) file_get_contents($this->tmpDir . '/app/themes/default/footer.php');
        $this->assertStringContainsString('console.warn', $footer,
            'a rejected register() must leave something behind to read');
        $this->assertStringNotContainsString('.catch(function(){', $footer,
            'an empty catch is what hid this the first time');
    }

    /**
     * No unresolved template placeholders survive.
     *
     * A stub token left in a JavaScript file is a syntax error at the worst possible
     * place: the browser refuses to install the worker and says so only in a console
     * nobody is watching.
     */
    public function testNoPlaceholdersRemain(): void
    {
        // Act
        $this->scaffold(['--service-worker' => 'y']);

        // Assert
        $this->assertStringNotContainsString('{{', $this->worker());
    }
}
