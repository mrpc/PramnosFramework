<?php

declare(strict_types=1);

namespace Pramnos\Audience;

/**
 * Recompute one person's segments in the queue, after an event about them.
 *
 * Registered by {@see Segments::refreshOn()}. Events about the same person within a minute fold
 * into one task, so a profile saved five times in a form's lifetime is one recomputation.
 */
final class RefreshPersonSegments extends \Pramnos\Event\QueuedListener
{
    /** Recompute the person the event names; an event that names nobody does nothing. */
    public function handle(mixed ...$args): mixed
    {
        $person = self::person($args[0] ?? null);

        return $person === null ? null : Segments::recomputePerson($person);
    }

    /** One waiting task per person. */
    public function debounceKey(mixed ...$args): ?string
    {
        $person = self::person($args[0] ?? null);

        return $person === null ? null : 'segments:person:' . $person;
    }

    /**
     * The person an event's first argument names: an id, or an object with `personId()`.
     */
    public static function person(mixed $subject): ?int
    {
        $id = match (true) {
            is_int($subject), is_string($subject) && ctype_digit($subject) => (int) $subject,
            is_object($subject) && method_exists($subject, 'personId')    => (int) $subject->personId(),
            default                                                        => 0,
        };

        return $id > 0 ? $id : null;
    }
}
