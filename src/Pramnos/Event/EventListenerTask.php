<?php

declare(strict_types=1);

namespace Pramnos\Event;

use Pramnos\Queue\AbstractTask;
use Pramnos\Queue\QueueItem;

/**
 * Runs a {@see QueuedListener} in the worker: the task `Event::fire()` wrote for it.
 *
 * Registered by the framework for {@see TYPE}, so every worker runs it without the application
 * naming it. What it does is {@see QueuedEvents::run()}.
 */
class EventListenerTask extends AbstractTask
{
    /** The task type `fire()` writes. */
    public const TYPE = 'event.listener';

    public string $name = self::TYPE;

    /** Run the listener with the event's arguments; the message says what happened. */
    public function execute(QueueItem $queueItem): mixed
    {
        $job = (array) json_decode((string) $queueItem->payload, true);

        return ['message' => QueuedEvents::run($job, (int) $queueItem->taskid)];
    }

    /** "user.saved → App\Listeners\SyncMoosend". */
    public function getDescription(QueueItem $queueItem): string
    {
        $job = (array) json_decode((string) $queueItem->payload, true);

        return (string) ($job['event'] ?? '?') . ' → ' . (string) ($job['listener'] ?? '?');
    }
}
