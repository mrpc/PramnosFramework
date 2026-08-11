<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\ArrayAdapter;
use Pramnos\Cache\Adapter\FileAdapter;
use Pramnos\Cache\Adapter\MemcachedAdapter;
use Pramnos\Cache\Adapter\RedisAdapter;

/**
 * Which adapters actually count atomically.
 *
 * WHAT: does `supportsAtomicCounter()` tell the truth for each real adapter?
 * WHY:  the first implementation of this asked `method_exists($adapter,
 *       'increment')`, and every adapter inherits a working `increment()` from
 *       AbstractAdapter — a load followed by a save. So the File and Array
 *       adapters reported themselves as atomic, and the rate limiter took the
 *       "exact under concurrency" path on a backend that loses increments.
 *
 *       That is the same failure this whole audit is about: a control that
 *       reports it is working while it is not. It was caught only because the
 *       inherited method was noticed by hand — the middleware's own tests used
 *       doubles that overrode the answer, so they could never have found it.
 *       These tests use the real adapters for that reason.
 */
class AtomicCounterCapabilityTest extends TestCase
{
    /**
     * The adapters backed by a real server say yes.
     *
     * Both implement the capability with a single server-side operation:
     * Redis `INCRBY`, and Memcached `increment` with creation through the
     * equally atomic `add`.
     *
     * No connection is made — the question is about the adapter class, not
     * about whether a server happens to be running in this environment.
     */
    public function testServerBackedAdaptersCountAtomically(): void
    {
        // Assert
        $this->assertTrue((new RedisAdapter())->supportsAtomicCounter());
        $this->assertTrue((new MemcachedAdapter())->supportsAtomicCounter());
    }

    /**
     * The in-process adapters say no, despite having an `increment()` method.
     *
     * This is the assertion that would have caught the bug. Both inherit
     * `increment()` from AbstractAdapter and it works — it simply is not
     * atomic, and a caller doing security work needs to be told the difference
     * rather than left to infer it from the method's existence.
     */
    public function testInProcessAdaptersDoNotClaimAtomicity(): void
    {
        // Arrange
        $array = new ArrayAdapter();
        $file  = new FileAdapter();

        // Assert — the method is there…
        $this->assertTrue(method_exists($array, 'increment'), 'the inherited method exists');
        $this->assertTrue(method_exists($file, 'increment'), 'the inherited method exists');

        // …and it is emphatically not atomic
        $this->assertFalse(
            $array->supportsAtomicCounter(),
            'a method inherited from AbstractAdapter is not an atomic counter'
        );
        $this->assertFalse($file->supportsAtomicCounter());
    }

    /**
     * The inherited counter still counts — it is lossy, not broken.
     *
     * Saying "not atomic" must not be read as "does not work". Sequential use
     * is exact; only overlapping use loses increments, which is why the rate
     * limiter keeps it as a fallback rather than refusing to limit at all.
     */
    public function testTheNonAtomicCounterStillCountsSequentially(): void
    {
        // Arrange
        $adapter = new ArrayAdapter();

        // Act
        $adapter->increment('probe', 1, 60);
        $adapter->increment('probe', 1, 60);
        $third = $adapter->increment('probe', 1, 60);

        // Assert
        $this->assertSame(3, (int) $third);
    }
}
