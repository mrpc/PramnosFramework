<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting;

/**
 * A {@see BroadcastableEvent} that should be published by a worker rather than
 * inline.
 *
 * Marker only: implementing it changes where {@see BroadcastingManager::event()}
 * sends the event, not what the event looks like.
 *
 * Worth it when the publish is slow or unreliable relative to the request — a
 * managed Pusher endpoint over HTTP, a fan-out across many channels — and not worth
 * it for a local Redis `PUBLISH`, which is faster than the queue push that would
 * defer it.
 *
 * ## What is queued is the payload, not the event object
 *
 * The framework serialises the **resolved** channel list, event name and payload,
 * and never the object. That is a deliberate difference from frameworks that
 * serialise the event and rebuild it in the worker, and it removes a whole class of
 * failure with it: an event holding a model cannot arrive at a worker after the row
 * was deleted, cannot reconstruct a stale copy of it, and cannot fail to unserialise
 * because a class moved.
 *
 * The cost is the mirror image: `broadcastWith()` runs **now**, in the request. An
 * event whose payload is meant to describe the state at delivery time cannot express
 * that, and should not be queued.
 */
interface QueuedBroadcastableEvent extends BroadcastableEvent
{
}
