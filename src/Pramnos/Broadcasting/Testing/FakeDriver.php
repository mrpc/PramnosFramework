<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Testing;

use Pramnos\Broadcasting\BroadcastingManager;
use Pramnos\Broadcasting\Drivers\ExcludesSocketInterface;

/**
 * A broadcasting driver that records instead of publishing, with assertions.
 *
 * `NullDriver` and `LogDriver` existed, and neither is a test double: one discards
 * silently, the other writes a file a test then has to parse. So a test asserting
 * "this action broadcasts" either published to a real Redis, or asserted nothing
 * and passed regardless — which is the version that keeps passing after the
 * broadcast is deleted.
 *
 * ```php
 * $fake = FakeDriver::swap();                      // becomes the process default
 *
 * $order->markPaid();
 *
 * $fake->assertBroadcast('private-order.42', 'order.paid');
 * $fake->assertBroadcastCount(1);
 *
 * FakeDriver::restore();
 * ```
 *
 * ## The assertions fail rather than error
 *
 * They delegate to PHPUnit's `Assert` when it is loaded, so a mismatch reports as a
 * test failure with a readable diff. Without PHPUnit they throw. The distinction
 * matters in practice: an exception is reported as an *error*, which reads as "the
 * test is broken" rather than "the code is wrong", and sends whoever sees it to
 * the wrong file.
 *
 * The failure message lists what *was* broadcast. An assertion that only says
 * "expected order.paid on private-order.42" leaves the reader unable to tell a
 * missing broadcast from one on a channel whose name is built slightly differently
 * — which is the usual cause.
 */
final class FakeDriver implements ExcludesSocketInterface
{
    /** @var list<array{channel:string, event:string, payload:array<string,mixed>, except:?string}> */
    private array $recorded = [];

    /** The manager displaced by {@see swap()}, so {@see restore()} can put it back. */
    private static ?BroadcastingManager $previous = null;

    private static bool $swapped = false;

    /**
     * Install a fake as the process-default broadcasting manager and return it.
     *
     * Remembers whatever was there so {@see restore()} is exact — a test that left
     * a fake installed would silently swallow every later test's broadcasts, and
     * the failure would appear in an unrelated file.
     */
    public static function swap(): self
    {
        if (!self::$swapped) {
            // currentInstance(), not instance(): the latter is a factory and would
            // build a Redis-backed manager as a side effect of asking what to
            // restore later.
            self::$previous = BroadcastingManager::currentInstance();
            self::$swapped  = true;
        }

        $fake = new self();

        $manager = new BroadcastingManager();
        $manager->addDriver($fake)->setDefault('fake');
        BroadcastingManager::setInstance($manager);

        return $fake;
    }

    /**
     * Put the previous default manager back.
     *
     * Safe to call when nothing was swapped, so it can live in an unconditional
     * tearDown.
     */
    public static function restore(): void
    {
        if (!self::$swapped) {
            return;
        }

        BroadcastingManager::setInstance(self::$previous);
        self::$previous = null;
        self::$swapped  = false;
    }

    // -------------------------------------------------------------------------
    // DriverInterface
    // -------------------------------------------------------------------------

    public function broadcast(string $channel, string $event, array $payload): void
    {
        $this->recorded[] = [
            'channel' => $channel,
            'event'   => $event,
            'payload' => $payload,
            'except'  => null,
        ];
    }

    public function broadcastExcept(
        string $channel,
        string $event,
        array $payload,
        ?string $exceptSocketId
    ): void {
        $this->recorded[] = [
            'channel' => $channel,
            'event'   => $event,
            'payload' => $payload,
            'except'  => $exceptSocketId,
        ];
    }

    public function name(): string
    {
        return 'fake';
    }

    // -------------------------------------------------------------------------
    // Inspection
    // -------------------------------------------------------------------------

    /**
     * Everything recorded, in order.
     *
     * @return list<array{channel:string, event:string, payload:array<string,mixed>, except:?string}>
     */
    public function recorded(): array
    {
        return $this->recorded;
    }

    /** Forget everything recorded so far. */
    public function flush(): void
    {
        $this->recorded = [];
    }

    /**
     * The recorded broadcasts matching a channel, an event, and an optional
     * predicate over the payload.
     *
     * @param callable(array<string,mixed>):bool|null $payloadMatches
     * @return list<array<string,mixed>>
     */
    public function matching(?string $channel = null, ?string $event = null, ?callable $payloadMatches = null): array
    {
        return array_values(array_filter(
            $this->recorded,
            static function (array $entry) use ($channel, $event, $payloadMatches): bool {
                if ($channel !== null && $entry['channel'] !== $channel) {
                    return false;
                }
                if ($event !== null && $entry['event'] !== $event) {
                    return false;
                }

                return $payloadMatches === null || $payloadMatches($entry['payload']) === true;
            }
        ));
    }

    /**
     * @param callable(array<string,mixed>):bool|null $payloadMatches
     */
    public function hasBroadcast(?string $channel = null, ?string $event = null, ?callable $payloadMatches = null): bool
    {
        return $this->matching($channel, $event, $payloadMatches) !== [];
    }

    // -------------------------------------------------------------------------
    // Assertions
    // -------------------------------------------------------------------------

    /**
     * @param callable(array<string,mixed>):bool|null $payloadMatches
     */
    public function assertBroadcast(
        ?string $channel = null,
        ?string $event = null,
        ?callable $payloadMatches = null
    ): void {
        $this->assert(
            $this->hasBroadcast($channel, $event, $payloadMatches),
            'Expected a broadcast' . $this->describe($channel, $event) . ', but none matched.'
        );
    }

    public function assertNotBroadcast(?string $channel = null, ?string $event = null): void
    {
        $this->assert(
            !$this->hasBroadcast($channel, $event),
            'Expected no broadcast' . $this->describe($channel, $event) . ', but one was sent.'
        );
    }

    public function assertBroadcastCount(int $expected, ?string $channel = null, ?string $event = null): void
    {
        $actual = count($this->matching($channel, $event));

        $this->assert(
            $actual === $expected,
            'Expected ' . $expected . ' broadcast(s)' . $this->describe($channel, $event)
            . ', got ' . $actual . '.'
        );
    }

    public function assertNothingBroadcast(): void
    {
        $this->assert($this->recorded === [], 'Expected nothing to be broadcast.');
    }

    /**
     * Assert a broadcast excluded a specific connection — that `toOthers()` was
     * used and reached the driver.
     *
     * Worth asserting on its own: the exclusion is easy to lose (a driver that does
     * not support it, a socket id that never made it out of the request) and its
     * only symptom in production is one user seeing a duplicate.
     */
    public function assertBroadcastExcept(string $socketId, ?string $channel = null, ?string $event = null): void
    {
        $matches = array_filter(
            $this->matching($channel, $event),
            static fn (array $entry): bool => $entry['except'] === $socketId
        );

        $this->assert(
            $matches !== [],
            'Expected a broadcast' . $this->describe($channel, $event)
            . ' excluding socket ' . $socketId . ', but none did.'
        );
    }

    // -------------------------------------------------------------------------
    // Plumbing
    // -------------------------------------------------------------------------

    private function describe(?string $channel, ?string $event): string
    {
        $parts = [];
        if ($channel !== null) {
            $parts[] = 'on "' . $channel . '"';
        }
        if ($event !== null) {
            $parts[] = 'named "' . $event . '"';
        }

        return $parts === [] ? '' : ' ' . implode(' ', $parts);
    }

    /**
     * Report through PHPUnit when it is loaded, so a mismatch is a failure with a
     * diff rather than an error that reads as a broken test.
     */
    private function assert(bool $passed, string $message): void
    {
        $message .= "\nRecorded: " . $this->summary();

        if (class_exists(\PHPUnit\Framework\Assert::class)) {
            \PHPUnit\Framework\Assert::assertTrue($passed, $message);

            return;
        }

        if (!$passed) {
            throw new \RuntimeException($message);
        }
    }

    private function summary(): string
    {
        if ($this->recorded === []) {
            return 'nothing.';
        }

        $lines = [];
        foreach ($this->recorded as $entry) {
            $lines[] = '  - ' . $entry['channel'] . ' / ' . $entry['event']
                . ($entry['except'] !== null ? ' (except ' . $entry['except'] . ')' : '');
        }

        return "\n" . implode("\n", $lines);
    }
}
