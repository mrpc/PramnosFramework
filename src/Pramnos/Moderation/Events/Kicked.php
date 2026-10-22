<?php

declare(strict_types=1);

namespace Pramnos\Moderation\Events;

use Pramnos\Moderation\Subject;

/**
 * Somebody was put out of a scope now: what they have open there should close. They may come back.
 */
final class Kicked
{
    public const EVENT = 'moderation.kicked';

    /**
     * @param Subject $subject Who
     * @param string  $scope   Where; empty for everywhere
     * @param string  $reason  Why
     */
    public function __construct(public readonly Subject $subject, public readonly string $scope, public readonly string $reason)
    {
    }
}
