<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Queue\ReservedJob;

/**
 * The wire shape a reserved job is handed to a worker in.
 *
 * `toArray()` had no covered line, and its docblock states a compatibility contract rather than a
 * convenience: the keys are `id`, `type`, `payload`, `attempts` and `run_at` **because that is the
 * shape the classic JobQueue used**, so worker loops written against it keep working byte-for-byte.
 *
 * Which makes this one of the few places where the *names* are the behaviour. A rename that reads
 * as tidying — `runAt` for `run_at`, `tries` for `attempts` — silently stops an existing worker
 * from finding the field it dispatches on, and a worker that cannot read `type` does nothing at
 * all while reporting no error.
 */
#[CoversClass(ReservedJob::class)]
class ReservedJobWireShapeTest extends TestCase
{
    /**
     * The keys are exactly the five the classic queue used, in that order.
     *
     * `assertSame` on `array_keys` rather than five `assertArrayHasKey` calls: an extra key is
     * also a change to the shape, and a test that only checks the five it knows about would not
     * notice one being added.
     */
    public function testTheKeysAreExactlyTheClassicFive(): void
    {
        // Arrange
        $job = new ReservedJob('id-1', 'SendMail', ['to' => 'a@b.c'], 2, 1756800000);

        // Act
        $wire = $job->toArray();

        // Assert
        $this->assertSame(
            ['id', 'type', 'payload', 'attempts', 'run_at'],
            array_keys($wire),
            'the wire shape changed, and existing worker loops read these names'
        );
    }

    /**
     * Every value is the job's own, unconverted.
     *
     * `payload` in particular stays an array. Encoding it here would make a worker decode twice —
     * and the one that does not would receive a JSON string where it expects a map, which is a
     * failure inside somebody's job handler rather than in the queue.
     */
    public function testEveryValueIsTheJobsOwn(): void
    {
        // Arrange
        $payload = ['to' => 'a@b.c', 'subject' => 'Καλημέρα', 'attempts' => ['nested' => true]];
        $job     = new ReservedJob('id-2', 'SendMail', $payload, 3, 1756800123);

        // Act
        $wire = $job->toArray();

        // Assert
        $this->assertSame('id-2', $wire['id']);
        $this->assertSame('SendMail', $wire['type']);
        $this->assertSame($payload, $wire['payload'], 'the payload was converted on the way out');
        $this->assertSame(3, $wire['attempts']);
        $this->assertSame(1756800123, $wire['run_at']);
    }

    /**
     * `run_at` carries the reserved time, not the current one.
     *
     * A worker decides whether a job is due by comparing this; filling it in at serialisation
     * would make every job due the moment it is read, which turns a delayed job into an immediate
     * one and a retry backoff into a hot loop.
     */
    public function testRunAtIsTheReservedTimeNotTheCurrentOne(): void
    {
        // Arrange — a run time well in the future
        $future = time() + 86400;
        $job    = new ReservedJob('id-3', 'Retry', [], 1, $future);

        // Act
        $wire = $job->toArray();

        // Assert
        $this->assertSame($future, $wire['run_at']);
        $this->assertGreaterThan(time(), $wire['run_at'], 'a delayed job came back as due now');
    }

    /**
     * An empty payload stays an empty array, not `null` and not `'[]'`.
     *
     * A job that carries no arguments is ordinary, and `foreach (null)` in a worker is not.
     */
    public function testAnEmptyPayloadStaysAnEmptyArray(): void
    {
        // Arrange
        $job = new ReservedJob('id-4', 'Sweep', [], 0, 1756800000);

        // Act
        $wire = $job->toArray();

        // Assert
        $this->assertSame([], $wire['payload']);
        $this->assertSame(0, $wire['attempts']);
    }
}
