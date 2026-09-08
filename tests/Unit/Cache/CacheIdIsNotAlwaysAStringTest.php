<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Cache;
use Pramnos\Logs\Logger;

/**
 * `PHP Warning: Array to string conversion` — 38,081 times, from one installation.
 *
 * `load()`, `save()`, `delete()` and `increment()` all take an untyped `$id`, so an array
 * travelled all the way to the string concatenation that builds the cache name, and PHP
 * wrote the literal `Array` into it.
 *
 * **The warning was the smaller half of it.** Every array-keyed call produced the same
 * name — `<category>_Array.<ext>` — so they all shared one entry, and a `load()` could
 * return whatever another caller had saved under a different array. Thirty-eight thousand
 * reads and writes of a single key.
 *
 * A `string` type declaration is not available as a fix: `$id` is public API on four
 * overridable methods, so adding one is a fatal at class load for any application that
 * overrides them, and it would turn a wrong cache entry into a broken page for whoever is
 * passing the array. So the id is normalised at the one place all four go through, and the
 * caller is named in the log — because the reported warning named this framework's own file
 * and nothing about who asked, and every caller in the framework passes a string.
 */
#[CoversClass(Cache::class)]
class CacheIdIsNotAlwaysAStringTest extends TestCase
{
    private mixed $previousMode = null;
    private $stream = null;

    protected function setUp(): void
    {
        // Read the log from a stream: a file under LOG_PATH accumulates between runs, and
        // a test that greps an accumulating file can pass on an earlier run's line.
        $property = new \ReflectionProperty(Logger::class, 'outputMode');
        $this->previousMode = $property->getValue();

        $this->stream = fopen('php://memory', 'w+');
        Logger::setStreamTarget($this->stream);
        Logger::setOutputMode(Logger::OUTPUT_STREAM);

        // The reporter is once-per-call-site per process, and every test here reaches it
        // through the same line of the probe — so without this the first test to run
        // silences every assertion about the log below it.
        $reported = new \ReflectionProperty(Cache::class, 'reportedIdCallers');
        $reported->setValue(null, array());
    }

    protected function tearDown(): void
    {
        Logger::setStreamTarget(null);
        $property = new \ReflectionProperty(Logger::class, 'outputMode');
        $property->setValue(null, $this->previousMode);

        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    private function logged(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }

    /**
     * A cache with the two name-building methods exposed and no store behind it.
     *
     * The subject is the name, so nothing needs to connect: `_generateCacheName()` is
     * reached before any adapter is asked for.
     */
    private function probe(string $category = ''): object
    {
        return new class ($category) extends Cache {
            public function __construct(string $category)
            {
                $this->category = $category;
                $this->extension = 'cache';
            }

            /** @param mixed $id */
            public function nameFor($id): string
            {
                return $this->_generateCacheName($id);
            }
        };
    }

    /**
     * Two different arrays no longer produce the same cache name.
     *
     * This is the defect. Before, both were `<category>_Array.cache`, so one entry served
     * every array-keyed call — which is not a warning, it is the wrong value coming back.
     */
    public function testTwoDifferentArraysNoLongerCollide(): void
    {
        // Arrange
        $probe = $this->probe('views');

        // Act
        $first  = $probe->nameFor(['user' => 1]);
        $second = $probe->nameFor(['user' => 2]);

        // Assert
        $this->assertNotSame($first, $second, 'distinct ids must produce distinct entries');
        $this->assertStringNotContainsString('Array', $first);
        $this->assertStringNotContainsString('Array', $second);
    }

    /**
     * And the same array produces the same name every time.
     *
     * Without this the fix is worse than the bug: a key that differs per call never hits,
     * so the cache costs a write on every request and returns nothing.
     */
    public function testTheSameArrayIsAlwaysTheSameName(): void
    {
        // Arrange
        $probe = $this->probe('views');
        $id = ['a' => 1, 'b' => [2, 3]];

        // Act & Assert
        $this->assertSame($probe->nameFor($id), $probe->nameFor($id));
    }

    /**
     * A string id is untouched, which is every caller in the framework.
     *
     * The guard against the fix having a cost: normalising must be invisible to the callers
     * that were already correct, or every existing entry in every installation is orphaned
     * by an upgrade.
     */
    public function testAStringIdIsUnchanged(): void
    {
        // Arrange
        $probe = $this->probe('views');

        // Act
        $name = $probe->nameFor('abc123');

        // Assert
        $this->assertSame('views_abc123.cache', $name);
    }

    /**
     * The scalars PHP was already converting silently keep converting the same way.
     *
     * An int id raised no warning and produced a usable name, so it has to go on doing
     * exactly that — an installation whose entries are keyed by a row id must still find
     * them after an upgrade.
     *
     * @param mixed  $id       What the caller passed
     * @param string $expected The name it has always produced
     */
    #[DataProvider('scalarIds')]
    public function testScalarIdsKeepTheNameTheyAlwaysHad(mixed $id, string $expected): void
    {
        // Act & Assert
        $this->assertSame($expected, $this->probe('views')->nameFor($id));
    }

    /**
     * @return array<string,array{mixed,string}>
     */
    public static function scalarIds(): array
    {
        return [
            'int'    => [42, 'views_42.cache'],
            'float'  => [1.5, 'views_1.5.cache'],
            'true'   => [true, 'views_1.cache'],
            // `(string) false` is '' and `(string) null` is '', which is what the
            // concatenation produced before. Preserved rather than improved: an entry
            // written under the old behaviour has to stay findable.
            'false'  => [false, 'views_.cache'],
            'null'   => [null, 'views_.cache'],
        ];
    }

    /**
     * The log names the caller, not this class.
     *
     * The reported 38,081 warnings all named `Cache.php:1114`, which says where the
     * conversion happened and nothing about who asked for it — and every caller in the
     * framework passes a string, so the line was unanswerable from inside. This is the
     * whole reason the finding took a separate look.
     */
    public function testTheLogNamesTheCallerAndNotTheCache(): void
    {
        // Act
        $this->probe('views')->nameFor(['a' => 1]);

        // Assert
        $line = $this->logged();
        $this->assertStringContainsString('Cache id is a array, not a string', $line);
        $this->assertStringContainsString(
            basename(__FILE__),
            $line,
            'the log must name the file that passed the array, not Cache.php'
        );
        $this->assertStringContainsString('category "views"', $line);
    }

    /**
     * Once per call site, not once per call.
     *
     * 38,081 identical lines is the shape of the report; the answer must not be 38,081
     * lines that merely say more. A repeated warning is one nobody reads twice.
     */
    public function testItIsReportedOncePerCallSite(): void
    {
        // Arrange
        $probe = $this->probe('views');

        // Act — the same call site, three different arrays
        $probe->nameFor(['a' => 1]);
        $probe->nameFor(['a' => 2]);
        $probe->nameFor(['a' => 3]);

        // Assert
        $this->assertSame(1, substr_count($this->logged(), 'Cache id is a array'));
    }

    /**
     * A string id logs nothing.
     *
     * The control: a warning that also fires for correct calls is noise, and noise is how
     * the next one goes unnoticed.
     */
    public function testACorrectCallLogsNothing(): void
    {
        // Act
        $this->probe('views')->nameFor('proper-key');

        // Assert
        $this->assertStringNotContainsString('Cache id is', $this->logged());
    }

    /**
     * An object id works the same way as an array.
     *
     * Nothing stops a caller passing one, and it converted worse than an array did — an
     * object with no `__toString` is a fatal rather than a warning, so this path was a
     * broken page rather than a shared cache entry.
     */
    public function testAnObjectIdIsHashedToo(): void
    {
        // Arrange
        $probe = $this->probe('views');
        $first = new \stdClass();
        $first->id = 1;
        $second = new \stdClass();
        $second->id = 2;

        // Act
        $nameOne = $probe->nameFor($first);
        $nameTwo = $probe->nameFor($second);

        // Assert
        $this->assertNotSame($nameOne, $nameTwo);
        $this->assertStringContainsString('Cache id is a stdClass', $this->logged());
    }

    /**
     * A value that will not serialise still produces a name rather than an exception.
     *
     * A closure inside the array is the realistic case, and `serialize()` throws on it. The
     * name it falls back to collides exactly as the old behaviour did — but the log line
     * has already named the caller, and a cache that shares an entry is better than a
     * request that dies inside the cache layer.
     */
    public function testAnUnserialisableIdDoesNotThrow(): void
    {
        // Arrange
        $probe = $this->probe('views');

        // Act
        $name = $probe->nameFor(['fn' => static fn () => 1]);

        // Assert
        $this->assertSame('views_array.cache', $name);
        $this->assertStringContainsString('Cache id is a array', $this->logged());
    }
}
