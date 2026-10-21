<?php

declare(strict_types=1);

namespace Pramnos\Audience\Rules;

use Pramnos\Queue\AbstractTask;
use Pramnos\Queue\QueueItem;

/**
 * The worker's side of a rule: an event to act on, a step of a run, or everybody to take in.
 * Answered by the framework without registration ({@see \Pramnos\Queue\Worker::FRAMEWORK_HANDLERS}).
 */
class RuleTask extends AbstractTask
{
    public string $name = Engine::TASK;

    /** Do what the task says; the message says what happened. */
    public function execute(QueueItem $queueItem): mixed
    {
        return ['message' => Engine::handle((array) json_decode((string) $queueItem->payload, true))];
    }

    /** "step of run 812", "payment.made for 41". */
    public function getDescription(QueueItem $queueItem): string
    {
        $job = (array) json_decode((string) $queueItem->payload, true);

        return match ($job['kind'] ?? '') {
            'step'      => 'Rule step: run ' . ($job['run'] ?? '?') . (empty($job['timeout']) ? '' : ' (timeout)'),
            'event'     => 'Rule event: ' . ($job['event'] ?? '?') . ' for ' . ($job['person'] ?? '?'),
            'everybody' => 'Rule ' . ($job['rule'] ?? '?') . ': everybody who matches',
            default     => 'Rule task',
        };
    }
}
