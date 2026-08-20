<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Cache\Cache;
use Pramnos\Logs\Logger;

/**
 * `Cache::getInstance()` serves the store the application configured.
 *
 * The factory declared `$method='memcached'`, which is not a default but an
 * answer. The constructor reads `Settings::getSetting('cache')` first and only
 * then applies the argument — `if ($method != '') { $this->method = $method; }` —
 * so the argument nobody passed overwrote the configuration everybody set. On an
 * installation running Redis, every caller without an opinion (the service
 * provider, `Factory::getCache()`, the view cache, the DevPanel cache screen)
 * asked for memcached, failed to connect to it, and was walked down to the file
 * adapter. Nothing errored: the process simply had a private on-disk cache that
 * shared nothing with the store the rest of the application was using, and the
 * one screen that exists to show what the cache holds described that empty file
 * store.
 *
 * The `if ($this->method == '')` guard below it was the branch meant to catch
 * "nothing was configured" — it could never be reached.
 *
 * These tests also cover the second half of the fix: a downgrade is reported at
 * warning level, and `$cache->method` names the store `getStats()` is counting.
 */
class CacheConfiguredMethodTest extends TestCase
{
    /**
     * Reset the process-wide fallback log guard so each test sees a clean slate.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetFallbackLog();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Settings::clearSettings();
        Logger::setStreamTarget(null);
        Logger::setOutputMode(Logger::OUTPUT_FILE);
        $this->resetFallbackLog();
        parent::tearDown();
    }

    /**
     * Empty the "already reported" set inside Cache.
     *
     * @return void
     */
    private function resetFallbackLog(): void
    {
        $property = new \ReflectionProperty(Cache::class, 'loggedFallbacks');
        $property->setValue(null, []);
    }

    /**
     * A caller with no opinion gets the configured store.
     *
     * The regression test for the whole report: with `array` configured and no
     * method argument, the instance must be an array cache. Before the fix this
     * asked for memcached and came back as `file`.
     *
     * @return void
     */
    public function testGetInstanceUsesTheConfiguredMethod(): void
    {
        // Arrange — configure the store, exactly as an application's settings do
        Settings::setSetting('cache', ['method' => 'array']);

        // Act — the call every opinion-less caller makes
        $cache = Cache::getInstance('configured_method_test');

        // Assert
        $this->assertSame(
            'array',
            $cache->method,
            'getInstance() must not overwrite the configured method with its own default.'
        );
        // The adapter is the store data actually goes to; the property alone
        // could agree while the bytes went somewhere else.
        $this->assertInstanceOf(
            \Pramnos\Cache\Adapter\ArrayAdapter::class,
            $cache->getAdapter()
        );
    }

    /**
     * `new Cache()` and `Cache::getInstance()` land on the same store.
     *
     * The asymmetry is what made the bug demonstrable in a single request:
     * constructing directly honoured the settings, going through the factory did
     * not, and the two disagreed about where the application's cache lived.
     *
     * @return void
     */
    public function testDirectConstructionAndTheFactoryAgree(): void
    {
        // Arrange
        Settings::setSetting('cache', ['method' => 'array']);

        // Act
        $direct  = new Cache('agreement_direct');
        $factory = Cache::getInstance('agreement_factory');

        // Assert
        $this->assertSame($direct->method, $factory->method);
    }

    /**
     * A caller that does name a store still gets it.
     *
     * The fix is source-compatible: passing a method keeps winning over the
     * configuration, which is why the argument exists.
     *
     * @return void
     */
    public function testAnExplicitMethodStillOverridesTheConfiguration(): void
    {
        // Arrange — configuration says array...
        Settings::setSetting('cache', ['method' => 'array']);

        // Act — ...but this caller asks for a file cache on purpose
        $cache = Cache::getInstance('explicit_method_test', null, 'file');

        // Assert
        $this->assertSame('file', $cache->method);
        $this->assertInstanceOf(
            \Pramnos\Cache\Adapter\FileAdapter::class,
            $cache->getAdapter()
        );
    }

    /**
     * The empty string means "whatever is configured", and always did.
     *
     * `getInstance($category, $extension, '')` was the documented workaround
     * while the default was wrong; it must keep behaving identically to passing
     * nothing at all, or code written against the workaround changes meaning.
     *
     * @return void
     */
    public function testAnEmptyMethodMeansTheConfiguredOne(): void
    {
        // Arrange
        Settings::setSetting('cache', ['method' => 'array']);

        // Act
        $explicitEmpty = Cache::getInstance('empty_method_test', null, '');
        $omitted       = Cache::getInstance('omitted_method_test');

        // Assert
        $this->assertSame('array', $explicitEmpty->method);
        $this->assertSame($omitted->method, $explicitEmpty->method);
    }

    /**
     * With nothing configured at all, the store is one that is actually installed.
     *
     * The empty default must not turn into "no cache" — but it must not name a
     * backend nobody installed either. `'memcached'` was the literal here, which
     * is the same mistake `getInstance()` made with its argument one level up: an
     * installation running Redis and carrying no `cache` setting asked for
     * memcached, could not reach it, and cached to disk with Redis working beside
     * it. The default is now the first backend whose extension is present, Redis
     * first — the one this framework's guide recommends.
     *
     * @return void
     */
    public function testNoConfigurationResolvesToAnInstalledBackend(): void
    {
        // Arrange — no cache setting whatsoever
        Settings::clearSettings();

        // Act
        $cache = Cache::getInstance('unconfigured_method_test');

        // Assert — it resolved to *something* usable rather than nothing
        $this->assertNotNull($cache->getAdapter());
        $this->assertNotSame('', $cache->method);

        // ...and specifically to a backend this PHP can actually talk to, rather
        // than to a name that only leads to the file adapter through two failures
        $expected = match (true) {
            class_exists('\Redis')     => 'redis',
            class_exists('\Memcached') => 'memcached',
            class_exists('\Memcache')  => 'memcache',
            default                    => 'file',
        };
        $this->assertSame($expected, $cache->requestedMethod);
    }

    /**
     * The resolved default is the first backend that is installed.
     *
     * Asserted against `defaultMethod()` directly, because which one that is depends
     * on the extensions of the machine running the tests — the invariant is the
     * order, not the answer.
     *
     * @return void
     */
    public function testTheDefaultPrefersRedisThenMemcachedThenFile(): void
    {
        // Arrange
        $resolve = new \ReflectionMethod(Cache::class, 'defaultMethod');
        $cache   = new Cache(null, null, 'array');

        // Act
        $default = $resolve->invoke($cache);

        // Assert
        $this->assertContains($default, ['redis', 'memcached', 'memcache', 'file']);

        if (class_exists('\Redis')) {
            $this->assertSame('redis', $default, 'Redis is the recommended backend');
        } elseif (!class_exists('\Memcached') && !class_exists('\Memcache')) {
            $this->assertSame(
                'file',
                $default,
                'with no memory cache installed, say so rather than failing into it'
            );
        }
    }

    /**
     * A store that could not be used is reported at warning level.
     *
     * A cache that silently changes store is a bug with no symptom of its own: a
     * value written to Redis and read back from disk is indistinguishable from
     * an expiry, and the application keeps answering — from a per-process cache
     * it believes is shared. The log line is the only place that is visible.
     *
     * Redis is pointed at a closed port so the fallback is a connection failure
     * rather than a missing extension; both are downgrades and both must log.
     *
     * @return void
     */
    public function testAnAbandonedAdapterIsLogged(): void
    {
        // Arrange — capture the log stream instead of writing a file
        $stream = fopen('php://memory', 'r+');
        Logger::setOutputMode(Logger::OUTPUT_STREAM);
        Logger::setStreamTarget($stream);

        // Act — nothing is listening on 127.0.0.1:1
        $cache = new Cache(null, null, 'redis', [
            'hostname' => '127.0.0.1',
            'port'     => 1,
        ]);

        rewind($stream);
        $log = (string) stream_get_contents($stream);
        fclose($stream);

        // Assert — the downgrade is named, at warning level
        $this->assertStringContainsString('warning', strtolower($log));
        $this->assertStringContainsString('redis', $log);
        // And the object agrees it is no longer a Redis cache, so a caller
        // reading `->method` is not told the store it asked for.
        $this->assertNotSame('redis', $cache->method);
    }

    /**
     * An unknown method name is a downgrade too, and says so.
     *
     * The `default:` arm of `initializeAdapter()` quietly builds a file cache for
     * any name it does not recognise — a typo in a settings file used to produce
     * a working application with the wrong cache and no way to tell.
     *
     * @return void
     */
    public function testAnUnknownMethodIsReportedAndResolvesToFile(): void
    {
        // Arrange
        $stream = fopen('php://memory', 'r+');
        Logger::setOutputMode(Logger::OUTPUT_STREAM);
        Logger::setStreamTarget($stream);

        // Act
        $cache = new Cache(null, null, 'redys');

        rewind($stream);
        $log = (string) stream_get_contents($stream);
        fclose($stream);

        // Assert
        $this->assertStringContainsString('redys', $log);
        $this->assertStringContainsString('unknown cache method', $log);
        $this->assertSame('file', $cache->method);
    }

    /**
     * The same downgrade is reported once per process, not once per instance.
     *
     * An application builds several `Cache` objects per request (the service
     * provider, the view cache, the SQL cache), and an identical line per object
     * turns a useful warning into noise that gets filtered out — which is how it
     * stops being read at all.
     *
     * @return void
     */
    public function testTheSameFallbackIsNotLoggedTwice(): void
    {
        // Arrange
        $stream = fopen('php://memory', 'r+');
        Logger::setOutputMode(Logger::OUTPUT_STREAM);
        Logger::setStreamTarget($stream);

        // Act — two instances making exactly the same doomed request
        new Cache(null, null, 'redys');
        new Cache(null, null, 'redys');

        rewind($stream);
        $log = (string) stream_get_contents($stream);
        fclose($stream);

        // Assert — one line, not two. Counted on the message prefix rather than
        // the reason text, which also appears in the line's context payload.
        $this->assertSame(
            1,
            substr_count($log, 'falling back from'),
            'A per-instance warning for a per-process condition is noise.'
        );
    }

    /**
     * `->method` and `getStats()['method']` name the same store.
     *
     * The DevPanel cache screen prints both. They came from different places —
     * one from the requested method, one from the adapter that was actually
     * built — so after any fallback the screen labelled a store with the name of
     * the one it had failed to reach.
     *
     * @return void
     */
    public function testStatsReportTheSameMethodAsTheProperty(): void
    {
        // Arrange + Act
        $cache = new Cache('stats_agreement', null, 'array');
        $stats = $cache->getStats();

        // Assert
        $this->assertArrayHasKey('method', $stats);
        $this->assertSame($cache->method, $stats['method']);
        $this->assertSame('array', $stats['method']);
    }

    /**
     * The same agreement holds on a store that reached a fallback.
     *
     * @return void
     */
    public function testStatsAgreeAfterAFallback(): void
    {
        // Arrange + Act — an unrecognised name resolves to the file adapter
        $cache = new Cache('stats_after_fallback', null, 'not_a_cache_store');

        // Assert — both name the store the data is really in
        $this->assertSame('file', $cache->method);
        $this->assertSame('file', $cache->getStats()['method']);
    }

    /**
     * An adapter that does not name itself is named by the cache.
     *
     * `AbstractAdapter::getStats()` returns `'method' => 'unknown'`, and an
     * adapter may omit the key altogether. Neither is useful on a diagnostic
     * screen when the object knows perfectly well which store it built, so
     * `getStats()` fills the name in rather than passing the placeholder on.
     *
     * @return void
     */
    public function testStatsNameAnAdapterThatDoesNotNameItself(): void
    {
        // Arrange — an adapter reporting no method, and one reporting the
        // AbstractAdapter placeholder
        $cache = new Cache('stats_anonymous_adapter', null, 'array');

        $silent = $this->createStub(\Pramnos\Cache\AdapterInterface::class);
        $silent->method('getStats')->willReturn(['items' => 7]);

        $placeholder = $this->createStub(\Pramnos\Cache\AdapterInterface::class);
        $placeholder->method('getStats')->willReturn(['method' => 'unknown']);

        $adapter = new \ReflectionProperty(Cache::class, 'adapter');

        // Act + Assert — the key is added...
        $adapter->setValue($cache, $silent);
        $stats = $cache->getStats();
        $this->assertSame('array', $stats['method']);
        // ...without disturbing what the adapter did report
        $this->assertSame(7, $stats['items']);

        // ...and the placeholder is replaced rather than shown
        $adapter->setValue($cache, $placeholder);
        $this->assertSame('array', $cache->getStats()['method']);
    }

    /**
     * A Redis cache with no host of its own uses the framework's Redis.
     *
     * `REDIS_HOST` and friends are the documented way to configure Redis, and
     * `\Pramnos\Redis\ConnectionManager` is what reads them. This class read only
     * its own `cache` settings and otherwise assumed localhost — so in a container
     * stack, where Redis is a service name, an installation with a working Redis
     * and no `cache` section could not reach it and cached to disk instead. Which
     * is exactly how it was reported.
     *
     * @return void
     */
    public function testARedisCacheWithoutAHostUsesTheFrameworksRedis(): void
    {
        // Arrange — the shared configuration says Redis lives at "redis:6380"
        $previous = null;
        try {
            $previous = \Pramnos\Redis\ConnectionManager::getInstance();
        } catch (\Throwable) {
            // none yet
        }

        \Pramnos\Redis\ConnectionManager::setInstance(
            new \Pramnos\Redis\ConnectionManager([
                'host' => 'redis-from-env',
                'port' => 6380,
                'database' => 3,
            ])
        );

        try {
            // Act — a cache that asks for redis and says nothing about where
            $cache = new Cache(null, null, 'redis');

            // Assert — it looked where the rest of the framework looks
            $this->assertSame('redis-from-env', $cache->hostname);
            $this->assertSame(6380, $cache->port);
            $this->assertSame(3, $cache->database);
        } finally {
            \Pramnos\Redis\ConnectionManager::setInstance($previous);
        }
    }

    /**
     * A cache that names its own host keeps it.
     *
     * An application naming a cache host means it — including when it means a
     * different Redis from the one the rest of the framework talks to. Adopting
     * over an explicit value would be the same class of bug as the default that
     * started all this.
     *
     * @return void
     */
    public function testAnExplicitCacheHostIsNotOverwritten(): void
    {
        // Arrange
        $previous = null;
        try {
            $previous = \Pramnos\Redis\ConnectionManager::getInstance();
        } catch (\Throwable) {
            // none yet
        }

        \Pramnos\Redis\ConnectionManager::setInstance(
            new \Pramnos\Redis\ConnectionManager(['host' => 'redis-from-env', 'port' => 6380])
        );

        try {
            // Act — the cache settings name a host of their own
            $cache = new Cache(null, null, 'redis', [
                'hostname' => 'cache-redis',
                'port'     => 6399,
            ]);

            // Assert
            $this->assertSame('cache-redis', $cache->hostname);
            $this->assertSame(6399, $cache->port);
        } finally {
            \Pramnos\Redis\ConnectionManager::setInstance($previous);
        }
    }

    /**
     * The store that was asked for survives the fallback as its own property.
     *
     * `->method` following the chain is what makes it honest, but the original
     * request is still worth having: the legacy `_connect()` treats it as a
     * class name, and "asked for redis, running on file" is the sentence an
     * operator needs. Losing one to gain the other would only move the problem.
     *
     * @return void
     */
    public function testTheRequestedMethodIsKeptAlongsideTheResolvedOne(): void
    {
        // Act
        $cache = new Cache(null, null, 'redys');

        // Assert
        $this->assertSame('redys', $cache->requestedMethod);
        $this->assertSame('file', $cache->method);
    }

    /**
     * With no fallback, the two names are the same.
     *
     * @return void
     */
    public function testTheRequestedMethodMatchesWhenNothingFallsBack(): void
    {
        // Arrange + Act
        $cache = new Cache(null, null, 'array');

        // Assert
        $this->assertSame('array', $cache->requestedMethod);
        $this->assertSame('array', $cache->method);
    }

    /**
     * The legacy `_connect()` still reads `->method` when nothing else is set.
     *
     * `_connect()` treats the method as a class name to instantiate, so it now
     * prefers `->requestedMethod`. A subclass that never went through this
     * constructor — or that assigns `->method` itself and calls `_connect()` —
     * has an empty `requestedMethod`, and must keep the behaviour it had before
     * the property existed rather than silently failing `class_exists('')`.
     *
     * @return void
     */
    public function testLegacyConnectFallsBackToTheMethodProperty(): void
    {
        // Arrange — a class named like a cache "method", as _connect() expects
        if (!class_exists('LegacyConnectDummy')) {
            eval('
                class LegacyConnectDummy {
                    public function connect($host, $port) { return true; }
                }
            ');
        }

        $cache = new Cache(null, null, 'array');
        // Simulate the pre-property state: only ->method names the class
        $cache->requestedMethod = '';
        $cache->method          = 'LegacyConnectDummy';

        // Clear any connection state a previous test left behind
        $connected   = new \ReflectionProperty(Cache::class, '_connected');
        $connections = new \ReflectionProperty(Cache::class, '_connections');
        $connectedValue   = $connected->getValue();
        $connectionsValue = $connections->getValue();
        unset(
            $connectedValue['legacyconnectdummy'],
            $connectionsValue['legacyconnectdummy']
        );
        $connected->setValue(null, $connectedValue);
        $connections->setValue(null, $connectionsValue);

        // Act
        $connect = new \ReflectionMethod(Cache::class, '_connect');

        // Assert — it found the class through ->method alone
        $this->assertTrue($connect->invoke($cache));
    }
}
