<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * The three development-only query logs, and the memory they used to keep.
 *
 * All three grew without limit and only under `DEVELOPMENT` — so the machine that hits it is a
 * developer's, running a whole test suite in one process. Reported from one: a 3,123-test suite
 * died at the 2,394th with exit 255 and no message, which is what PHP's memory limit looks like
 * from the outside.
 *
 * The worst was keyed by the whole SQL string. A suite issuing fifty thousand distinct
 * statements held fifty thousand of them as array keys, to answer a question a 32-character
 * hash answers just as well.
 *
 * Asserted through reflection because these are private by design — they are a development
 * aid, not an API — and what has to hold is the bound, which nothing else can observe.
 */
#[CoversClass(Database::class)]
class QueryLogBoundsTest extends TestCase
{
    /**
     * The in-memory log stops growing, and keeps the recent end.
     *
     * A debug toolbar renders the last few dozen statements. Holding two hundred thousand so
     * that it can is how a long-running process runs out of memory for a panel nobody has open.
     */
    public function testTheInMemoryLogIsBounded(): void
    {
        // Arrange
        $db  = $this->database();
        $max = $this->constant('IN_MEMORY_QUERY_LOG_MAX');

        // Act — twice the limit, numbered so the survivors can be identified
        for ($i = 0; $i < $max * 2; $i++) {
            $this->call($db, 'rememberQuery', [
                ['sql' => 'SELECT ' . $i, 'time' => 0.0, 'at' => 0.0, 'from_cache' => false],
            ]);
        }

        $log = $db->getQueryLog();

        // Assert
        $this->assertLessThanOrEqual($max, count($log));
        $this->assertSame(
            'SELECT ' . ($max * 2 - 1),
            $log[count($log) - 1]['sql'],
            'the newest entry is kept — the recent end is the useful one'
        );
    }

    /**
     * And it does not get slower the longer the process runs.
     *
     * Trimming one entry per append past the limit is O(n) on every query, which makes the log
     * slower the longer it is used — the opposite of what a bound is for. It drops half at a
     * time, so the size oscillates between half the limit and the limit.
     */
    public function testTrimmingDoesNotHappenOnEveryAppend(): void
    {
        // Arrange
        $db  = $this->database();
        $max = $this->constant('IN_MEMORY_QUERY_LOG_MAX');

        // Act
        for ($i = 0; $i <= $max; $i++) {
            $this->call($db, 'rememberQuery', [['sql' => 'SELECT ' . $i]]);
        }

        // Assert
        $this->assertLessThanOrEqual(
            (int) ($max / 2) + 1,
            count($db->getQueryLog()),
            'the trim drops half, so the next one is far away'
        );
    }

    /**
     * The duplicate map is bounded, and keyed by a hash rather than by the statement.
     *
     * The map only ever answers "have I seen this before", and a 32-character key answers it as
     * well as a four-kilobyte one. On a suite issuing tens of thousands of distinct statements
     * the difference is the whole of the memory this feature was costing.
     */
    public function testTheDuplicateMapIsBoundedAndKeyedByAHash(): void
    {
        // Arrange
        $db  = $this->database();
        $max = $this->constant('DUPLICATE_QUERY_KEYS');

        // Act
        for ($i = 0; $i < $max * 2; $i++) {
            $this->call($db, 'rememberFingerprint', [md5('SELECT ' . $i)]);
        }

        $map = $this->read($db, '_duplicateQueries');

        // Assert
        $this->assertLessThanOrEqual($max, count($map));

        foreach (array_keys($map) as $key) {
            $this->assertSame(32, strlen((string) $key), 'a fingerprint, not a statement');
        }
    }

    /**
     * The most recent statements are the ones kept.
     *
     * A duplicate seen once at the start of a very long process and again at the end is two
     * requests, not one request asking twice — and the second is what this exists to find.
     */
    public function testTheDuplicateMapKeepsTheRecentEnd(): void
    {
        // Arrange
        $db  = $this->database();
        $max = $this->constant('DUPLICATE_QUERY_KEYS');

        // Act
        for ($i = 0; $i < $max * 2; $i++) {
            $this->call($db, 'rememberFingerprint', [md5('SELECT ' . $i)]);
        }

        $map = $this->read($db, '_duplicateQueries');

        // Assert
        $this->assertArrayHasKey(md5('SELECT ' . ($max * 2 - 1)), $map);
        $this->assertArrayNotHasKey(md5('SELECT 0'), $map);
    }

    /**
     * The written log is flushed as it grows, not held until the destructor.
     *
     * It used to be written in the destructor and nowhere else, so a process killed by the
     * memory limit this log was helping to reach wrote nothing at all — the log was empty on
     * exactly the run somebody needed it for.
     */
    public function testTheWrittenLogIsFlushedRatherThanHeld(): void
    {
        // Arrange
        $db   = $this->database();
        $file = tempnam(sys_get_temp_dir(), 'qlog');
        $this->write($db, '_queryLogHandler', fopen($file, 'a+'));

        try {
            // Act — past the flush threshold
            $this->write($db, '_querieslog', str_repeat('x', $this->constant('QUERY_LOG_FLUSH_BYTES') + 1));
            $this->call($db, 'flushQueryLog', []);

            // Assert
            $this->assertSame('', $this->read($db, '_querieslog'), 'the buffer was emptied');
            $this->assertGreaterThan(0, filesize($file), 'and its contents reached the file');
        } finally {
            $handle = $this->read($db, '_queryLogHandler');

            if (is_resource($handle)) {
                fclose($handle);
            }

            @unlink($file);
        }
    }

    /**
     * A buffer under the threshold is left alone.
     *
     * The point of buffering at all: one write per quarter of a megabyte rather than one per
     * query, which on a request issuing four hundred statements is four hundred syscalls.
     */
    public function testASmallBufferIsNotFlushed(): void
    {
        // Arrange
        $db = $this->database();
        $this->write($db, '_querieslog', 'SELECT 1');

        // Act
        $this->call($db, 'flushQueryLog', []);

        // Assert
        $this->assertSame('SELECT 1', $this->read($db, '_querieslog'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function database(): Database
    {
        $db = (new \ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        $db->enableQueryLog();

        return $db;
    }

    private function constant(string $name): int
    {
        return (int) (new \ReflectionClass(Database::class))->getConstant($name);
    }

    /** @param list<mixed> $args */
    private function call(Database $db, string $method, array $args): mixed
    {
        return (new \ReflectionMethod(Database::class, $method))->invokeArgs($db, $args);
    }

    private function read(Database $db, string $property): mixed
    {
        return (new \ReflectionProperty(Database::class, $property))->getValue($db);
    }

    private function write(Database $db, string $property, mixed $value): void
    {
        (new \ReflectionProperty(Database::class, $property))->setValue($db, $value);
    }
}
