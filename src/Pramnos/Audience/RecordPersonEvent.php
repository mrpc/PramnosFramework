<?php

declare(strict_types=1);

namespace Pramnos\Audience;

/**
 * Record a person event in the worker, then recompute the person's segments — folded, so a burst
 * of events is one recomputation.
 *
 * Queued by {@see PersonEvents::recordLater()}, which is what `Event::dispatch()` of a
 * {@see PersonEvent} calls.
 */
final class RecordPersonEvent extends \Pramnos\Event\QueuedListener
{
    /**
     * Write the occurrence, and ask for the person's segments to be worked out again.
     *
     * @param array<string, scalar|null> $properties
     */
    public function handle(int $person, string $event, array $properties = [], int $at = 0): mixed
    {
        PersonEvents::record($person, $event, $properties, $at > 0 ? $at : null);
        try {
            \Pramnos\Event\QueuedEvents::enqueue(new RefreshPersonSegments(), 'segments.refresh', [$person], \Pramnos\Event\QueuedEvents::meta('segments.refresh'));
        } catch (\Throwable $e) {
            // The hourly recomputation finds the change; the occurrence is what had to be kept.
            \Pramnos\Logs\Logger::log('Segments not refreshed after ' . $event . ' for ' . $person . ': ' . $e->getMessage(), 'audience');
        }

        return true;
    }
}
