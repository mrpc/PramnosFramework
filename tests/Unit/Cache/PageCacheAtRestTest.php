<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\ArrayAdapter;
use Pramnos\Cache\Page\PageCache;
use Pramnos\Console\Commands\PageCachePurge;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The page cache's store (FW-171): pages kept gzipped only, for a store short of memory; which
 * Redis server it reaches; and the purge command reading a configuration kept in a file.
 */
#[CoversClass(PageCache::class)]
#[CoversClass(PageCachePurge::class)]
final class PageCacheAtRestTest extends TestCase
{
    private array $server = [];

    private mixed $cacheSettings = null;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST']      = 'example.test';
        $_SERVER['PHP_SELF']       = '/index.php';
        unset($_SERVER['HTTP_IF_NONE_MATCH'], $_SERVER['HTTP_ACCEPT_ENCODING']);
        PageCache::resetRuntime();
        Request::resetInstance();
        \Pramnos\Debug\DebugBar::reset();
        $this->cacheSettings = \Pramnos\Application\Settings::getSetting('cache');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        PageCache::resetRuntime();
        Request::resetInstance();
        \Pramnos\Application\Settings::setSetting('cache', $this->cacheSettings, false);
    }

    /** A GET request for a path. */
    private function request(string $uri): Request
    {
        $_SERVER['REQUEST_URI'] = $uri;
        Request::resetInstance();

        return new Request();
    }

    /**
     * With `compressAtRest`, an entry keeps only the gzipped body — a client that takes gzip is
     * served it as it is, one that does not has it unzipped on the way out — and the ETag is the
     * unzipped body's, as without it. Without the option both bodies are kept.
     */
    public function testAPageKeptGzippedOnly(): void
    {
        // Arrange
        $body    = '<html>' . str_repeat('stations ', 500) . '</html>';
        $adapter = new ArrayAdapter();
        $cache   = new PageCache(['enabled' => true, 'compressAtRest' => true], $adapter);
        $cache->store($this->request('/stations'), Response::make($body, 200));
        $plain   = new PageCache(['enabled' => true], $plainStore = new ArrayAdapter());
        $plain->store($this->request('/stations'), Response::make($body, 200));

        // Act
        $_SERVER['HTTP_ACCEPT_ENCODING'] = 'gzip, br';
        $zipped = $cache->lookup($this->request('/stations'));
        unset($_SERVER['HTTP_ACCEPT_ENCODING']);
        $unzipped = $cache->lookup($this->request('/stations'));
        $kept     = self::entry($adapter);

        // Assert
        $this->assertSame('', $kept['body'], 'only the gzipped body is kept');
        $this->assertTrue($kept['compressed']);
        $this->assertSame($body, gzdecode((string) $zipped->getBody()));
        $this->assertSame('gzip', $zipped->getHeaderLine('Content-Encoding'));
        $this->assertSame($body, (string) $unzipped->getBody(), 'unzipped for a client without gzip');
        $this->assertSame('"' . sha1($body) . '"', $unzipped->getHeaderLine('ETag'));
        $this->assertSame($body, self::entry($plainStore)['body'], 'without the option, both are kept');
    }

    /**
     * The Redis server is the page cache's own `redis` where it names one, then the `cache`
     * settings' hostname, then the shared connection Cache uses — not localhost because nothing
     * was set, which on a Docker network misses on every request.
     */
    public function testWhichRedisItReaches(): void
    {
        // Arrange
        $server = new \ReflectionMethod(PageCache::class, 'redisServer');
        \Pramnos\Application\Settings::setSetting('cache', null, false);
        $shared = \Pramnos\Redis\ConnectionManager::getInstance();

        // Act
        $own      = $server->invoke(new PageCache(['redis' => ['host' => 'pages', 'port' => 6380, 'database' => 2, 'password' => 'x']]), []);
        $settings = $server->invoke(new PageCache(), ['hostname' => 'cachehost', 'port' => 6381]);
        $fallback = $server->invoke(new PageCache(), []);

        // Assert
        $this->assertSame(['pages', 6380, 2, 'x'], $own);
        $this->assertSame(['cachehost', 6381, 0, null], $settings);
        $this->assertSame([$shared->host(), $shared->port(), $shared->database(), $shared->password()], $fallback);
    }

    /**
     * `pagecache:purge --config` reads the page cache's configuration from a file of the
     * application's, so it purges the store the pages are in; a file that is not there fails.
     */
    public function testThePurgeReadsAConfigurationFile(): void
    {
        // Arrange
        $file = sys_get_temp_dir() . '/pf-pagecache-' . bin2hex(random_bytes(3)) . '.php';
        file_put_contents($file, "<?php return ['store' => 'array', 'prefix' => 'pages:'];");
        $console = new ConsoleApplication();
        $console->add(new PageCachePurge());
        $tester = new CommandTester($console->find('pagecache:purge'));

        // Act
        $ok      = $tester->execute(['--all' => true, '--config' => $file]);
        $missing = $tester->execute(['--all' => true, '--config' => $file . '.nope']);
        $display = $tester->getDisplay();
        unlink($file);

        // Assert
        $this->assertSame(0, $ok);
        $this->assertSame(1, $missing);
        $this->assertStringContainsString('No configuration at', $display);
    }

    /** The one entry an adapter holds. */
    private static function entry(ArrayAdapter $adapter): array
    {
        $store = (new \ReflectionProperty(ArrayAdapter::class, 'store'))->getValue($adapter);
        foreach ($store as $key => $item) {
            if (is_array($item['data']) && isset($item['data']['etag'])) {
                return $item['data'];
            }
        }

        return [];
    }
}
