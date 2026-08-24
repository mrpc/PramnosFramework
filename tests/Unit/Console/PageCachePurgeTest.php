<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\ArrayAdapter;
use Pramnos\Cache\Page\PageCache;
use Pramnos\Console\Commands\PageCachePurge;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `pagecache:purge`, driven through the real console tester.
 *
 * The command is thin, and the part worth testing is the part that is easy to
 * get wrong from a terminal: a bare path has to become the absolute URL the
 * entries are actually keyed by, or the purge reports success and removes
 * nothing — the worst possible outcome for an invalidation command, because the
 * operator walks away believing the site is updated.
 */
#[CoversClass(PageCachePurge::class)]
class PageCachePurgeTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $server = [];
    /** @var array<string,mixed> */
    private array $get = [];

    private PageCache $cache;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->get    = $_GET;

        $_GET = [];
        $_SERVER['HTTP_HOST']      = 'example.test';
        $_SERVER['PHP_SELF']       = '/index.php';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        PageCache::resetRuntime();
        Request::resetInstance();

        // One shared engine over an in-memory store, handed to the command.
        $this->cache = new PageCache(['enabled' => true], new ArrayAdapter());
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET    = $this->get;

        PageCache::resetRuntime();
        Request::resetInstance();
    }

    /**
     * The command under test, wired to this test's engine and site URL.
     *
     * Both are overridden the way `CacheClear` does it — a protected seam
     * rather than a constructor argument — so production keeps building its own
     * from settings.
     */
    private function command(): PageCachePurge
    {
        $cache = $this->cache;

        return new class ($cache) extends PageCachePurge {
            public function __construct(private PageCache $injected)
            {
                parent::__construct();
            }

            protected function pageCache(): PageCache
            {
                return $this->injected;
            }

            protected function siteUrl(): string
            {
                return 'http://example.test';
            }
        };
    }

    /** Cache a page at a path, optionally tagged. */
    private function cachePage(string $uri, string $body, string ...$tags): void
    {
        $_SERVER['REQUEST_URI'] = $uri;
        Request::resetInstance();

        PageCache::resetRuntime();
        if ($tags !== []) {
            PageCache::tag(...$tags);
        }

        $this->assertTrue(
            $this->cache->store(new Request(), Response::make($body)),
            'the fixture page must actually be cached'
        );
    }

    private function isCached(string $uri): bool
    {
        $_SERVER['REQUEST_URI'] = $uri;
        Request::resetInstance();

        return $this->cache->lookup(new Request()) !== null;
    }

    /**
     * The command answers to its name, and describes itself.
     *
     * Asserted through the console application's own registry rather than by
     * instantiating the class: `new PageCachePurge()` proves the file parses and
     * nothing more. A command that exists but was never added to
     * `Console\Application` is invisible, and every other test here bypasses
     * that registration by constructing the command directly — so this is the
     * only thing standing between the class and being unreachable in production.
     */
    public function testTheCommandIsRegisteredAndDescribesItself(): void
    {
        // Arrange
        $app = new \Pramnos\Console\Application();

        // Assert
        $this->assertTrue($app->has('pagecache:purge'));
        $this->assertNotSame(
            '', $app->find('pagecache:purge')->getDescription()
        );
    }

    /**
     * A bare path is resolved against the site URL before purging.
     *
     * Entries are keyed by absolute URL because one installation can answer on
     * several hosts. An operator types `/stations/7`, not
     * `https://example.test/stations/7`, and a command that purged the literal
     * string would match no entry and still print success.
     *
     * The reversal that reddens this: return `$url` unchanged from
     * `absolute()`.
     */
    public function testABarePathIsPurged(): void
    {
        // Arrange
        $this->cachePage('/stations/7', 'seven');
        $this->assertTrue($this->isCached('/stations/7'));

        // Act
        $tester = new CommandTester($this->command());
        $status = $tester->execute(['url' => '/stations/7']);

        // Assert
        $this->assertSame(Command::SUCCESS, $status);
        $this->assertFalse($this->isCached('/stations/7'));
        $this->assertStringContainsString('Purged: /stations/7', $tester->getDisplay());
    }

    /** An absolute URL is used as given. */
    public function testAnAbsoluteUrlIsPurged(): void
    {
        // Arrange
        $this->cachePage('/stations/7', 'seven');

        // Act
        $tester = new CommandTester($this->command());
        $tester->execute(['url' => 'http://example.test/stations/7']);

        // Assert
        $this->assertFalse($this->isCached('/stations/7'));
    }

    /**
     * `--tag` purges every page carrying it and reports how many.
     *
     * The count is the operator's only feedback that the tag was the right one;
     * a silent success after a typo is indistinguishable from a working purge.
     */
    public function testTagsArePurgedAndCounted(): void
    {
        // Arrange
        $this->cachePage('/stations/7', 'seven', 'station:7');
        $this->cachePage('/stations/7/schedule', 'schedule', 'station:7');
        $this->cachePage('/stations/9', 'nine', 'station:9');

        // Act
        $tester = new CommandTester($this->command());
        $status = $tester->execute(['--tag' => ['station:7']]);

        // Assert
        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('Purged 2 page(s)', $tester->getDisplay());
        $this->assertFalse($this->isCached('/stations/7'));
        $this->assertTrue(
            $this->isCached('/stations/9'),
            'an unrelated page must survive'
        );
    }

    /** `--all` empties the page cache. */
    public function testAllPurgesEverything(): void
    {
        // Arrange
        $this->cachePage('/stations/7', 'seven');
        $this->cachePage('/artists/3', 'three');

        // Act
        $tester = new CommandTester($this->command());
        $status = $tester->execute(['--all' => true]);

        // Assert
        $this->assertSame(Command::SUCCESS, $status);
        $this->assertFalse($this->isCached('/stations/7'));
        $this->assertFalse($this->isCached('/artists/3'));
    }

    /**
     * A failing engine is reported as a failure, not a stack trace.
     *
     * `pagecache:purge` runs from deploy scripts and cron. A backend that is
     * down has to come back as a non-zero exit code and one readable line, or
     * the deploy either continues believing the cache was cleared or stops with
     * a PHP fatal in the log.
     */
    public function testABackendFailureIsReportedAsAFailure(): void
    {
        // Arrange — a store that throws on every read, as an unreachable Redis
        // does. Injected at the adapter rather than faked at the engine: the
        // engine is final, and this is the shape the real failure has anyway.
        $broken = new class extends ArrayAdapter {
            public function load($key, $timeout = 3600): mixed
            {
                throw new \RuntimeException('Connection refused');
            }
        };

        $command = new class ($broken) extends PageCachePurge {
            public function __construct(private ArrayAdapter $broken)
            {
                parent::__construct();
            }

            protected function pageCache(): PageCache
            {
                return new PageCache(['enabled' => true], $this->broken);
            }

            protected function siteUrl(): string
            {
                return 'http://example.test';
            }
        };

        // Act
        $tester = new CommandTester($command);
        $status = $tester->execute(['url' => '/stations/7']);

        // Assert
        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Purge failed', $tester->getDisplay());
        $this->assertStringContainsString('Connection refused', $tester->getDisplay());
    }

    /**
     * Built with no overrides it resolves its engine and its site URL from the
     * application, and neither answer is empty.
     *
     * The production path the other tests deliberately replace. It is asserted
     * rather than merely executed because a `siteUrl()` returning `''` would
     * turn every bare-path purge into a purge of `/stations/7` against a host
     * nothing is keyed under — success on stdout, nothing invalidated.
     */
    public function testItResolvesItsEngineAndSiteUrlFromTheApplication(): void
    {
        // Arrange
        $command = new PageCachePurge();

        // Act
        $engine = (new \ReflectionMethod($command, 'pageCache'))->invoke($command);
        $absolute = (new \ReflectionMethod($command, 'absolute'))
            ->invoke($command, '/stations/7');

        // Assert
        $this->assertInstanceOf(PageCache::class, $engine);
        $this->assertStringStartsWith('http', $absolute);
        $this->assertStringEndsWith('/stations/7', $absolute);
    }

    /**
     * Called with nothing at all, it refuses rather than guessing.
     *
     * The two plausible guesses are "purge everything" and "purge nothing", and
     * the first is a very expensive thing to do by accident.
     */
    public function testItRefusesToRunWithNoTarget(): void
    {
        // Arrange
        $this->cachePage('/stations/7', 'seven');

        // Act
        $tester = new CommandTester($this->command());
        $status = $tester->execute([]);

        // Assert
        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Give a URL', $tester->getDisplay());
        $this->assertTrue(
            $this->isCached('/stations/7'),
            'refusing must not have purged anything'
        );
    }
}
