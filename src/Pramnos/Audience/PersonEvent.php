<?php

declare(strict_types=1);

namespace Pramnos\Audience;

/**
 * An event class that is also something a person did: dispatched with `Event::dispatch()`, it is
 * recorded for that person with no other call — counted, first and last, and logged with its
 * properties — so filters and rules can ask about it.
 *
 * ```php
 * final class PaymentMade implements QueueableEvent, PersonEvent
 * {
 *     public const EVENT = 'payment.made';
 *
 *     public function __construct(public readonly Invoice $invoice) {}
 *
 *     public function personId(): ?int { return (int) $this->invoice->userid; }
 *     public function properties(): array { return ['amount' => $this->invoice->total, 'package' => $this->invoice->package]; }
 *     // …jsonSerialize() / fromQueue() as for any QueueableEvent
 * }
 *
 * Event::dispatch(new PaymentMade($invoice));   // the listeners, and a row in person_events
 * ```
 *
 * The recording runs in the queue ({@see RecordPersonEvent}), so the request that dispatched the
 * event does not wait for it.
 */
interface PersonEvent
{
    /** The person who did it — a users.userid — or null when nobody is known. */
    public function personId(): ?int;

    /**
     * What is worth keeping about it: plain values only, written to the log as JSON.
     *
     * @return array<string, scalar|null>
     */
    public function properties(): array;
}
