<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting;

use Pramnos\Event\ChangeFeed;
use Pramnos\Event\ListenerInterface;
use Pramnos\Event\ModelChange;

/**
 * Publishes model changes on channels, so a browser learns about them without polling.
 *
 * Registered automatically when the `broadcasting` feature is enabled. A model that has
 * turned on `$emitChanges` then reaches subscribers with no further wiring:
 *
 * ```js
 * PramnosEcho.private('wcm-device')
 *     .listen('model.changed', debounce(refetchList, 150));
 * ```
 *
 * ## Identifiers only, unless the model says otherwise
 *
 * With no `$broadcastFields` on the model — the default — the payload is the entity, the
 * key and the operation. Nothing else. The subscriber refetches through the API, where
 * permissions already apply, so no column can reach somebody the API would not have shown
 * it to, no allow-list has to be maintained as columns are added, and a missed or
 * rolled-back event costs one refetch that returns the current data rather than leaving a
 * stale copy behind.
 *
 * A model that declares an allow-list gets values on the wire and takes responsibility for
 * the choice. Read the channel warning on {@see \Pramnos\Application\Model::changeChannels()}
 * first: with values on, a subscriber on the wrong channel is a breach rather than a hint.
 *
 * ## Why this never sees the model
 *
 * The channels and the allow-list are resolved when the change is emitted and carried on
 * the {@see ModelChange}. Holding a model reference until a listener runs is the failure
 * {@see QueuedBroadcastableEvent} documents — a stale copy of a row that may no longer
 * exist — and a listener that can be queued must not be able to fall into it.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class ChangeBroadcaster implements ListenerInterface
{
    /**
     * Register on the change feed. Safe to call repeatedly.
     */
    public static function listen(): void
    {
        if (\Pramnos\Event\Event::hasListeners(ChangeFeed::EVENT)) {
            foreach (\Pramnos\Event\Event::getListeners(ChangeFeed::EVENT) as $listener) {
                if ($listener instanceof self || $listener === self::class) {
                    return;
                }
            }
        }

        \Pramnos\Event\Event::listen(ChangeFeed::EVENT, self::class);
    }

    /**
     * Publish one change on the channels it named.
     *
     * Failure is swallowed by design: broadcasting is a side effect of a save that has
     * already committed, and an unreachable relay must not turn a successful write into
     * an exception the user sees. There is nothing the caller could do about it either.
     */
    public function handle(mixed ...$args): mixed
    {
        $change = $args[0] ?? null;
        if (!$change instanceof ModelChange || $change->channels === []) {
            return null;
        }

        // currentInstance(), never getInstance(): asking whether broadcasting is
        // configured must not be the thing that configures it. The same reasoning
        // Broadcastable carries — building a manager here would be a side effect inside a
        // check whose answer is "no manager".
        $manager = BroadcastingManager::currentInstance();
        if ($manager === null) {
            return null;
        }

        $payload = $this->payloadFor($change);

        foreach ($change->channels as $channel) {
            try {
                $manager->broadcast($channel, ChangeFeed::EVENT, $payload);
            } catch (\Throwable $ex) {
                \Pramnos\Logs\Logger::log(
                    'Change broadcast failed on "' . $channel . '" for '
                    . $change->entity . ' ' . (string) $change->key . ': '
                    . $ex->getMessage(),
                    'broadcasting'
                );
            }
        }

        return null;
    }

    /**
     * What leaves the process.
     *
     * @return array<string, mixed>
     */
    protected function payloadFor(ModelChange $change): array
    {
        $payload = [
            'entity' => $change->entity,
            'key'    => $change->key,
            'op'     => $change->op,
        ];

        if ($change->broadcastFields === null) {
            // Not even the names of the fields that changed. A half-rule — "identifiers,
            // plus which columns moved" — is a thing to argue about later, and the names
            // are enough to map a schema the API never exposed.
            return $payload;
        }

        $payload['data']    = $change->only($change->broadcastFields);
        $payload['changes'] = $change->changesOnly($change->broadcastFields);

        return $payload;
    }
}
