<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

/**
 * The background queue: what waits now, by state and kind, what failed in a window and why,
 * and how long finished work took.
 */
final class QueueTool extends AdminTool
{
    public function name(): string
    {
        return 'queue';
    }

    public function description(): string
    {
        return 'The background queue: tasks by state now, what is waiting by kind, failures in a '
            . 'window by kind with their commonest error, and how long finished tasks took.';
    }

    protected function answer(array $input, Window $window): array
    {
        if (!$this->has('queueitems')) {
            return self::absent('background queue');
        }

        $items    = fn () => $this->table('queueitems');
        $failures = [];
        $result   = $window->apply($items()->where('status', 'failed'), 'updatedat', false)->select(['type', 'error'])->get();
        while (($row = $result->fetch()) !== null) {
            $type = (string) $row['type'];
            $failures[$type]['count'] = ($failures[$type]['count'] ?? 0) + 1;
            $failures[$type]['error'] ??= (string) strtok((string) $row['error'], "\n");
        }
        uasort($failures, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        $done = $window->apply($items()->where('status', 'completed'), 'completedat', false)->whereNotNull('execution_time');

        return [
            'window'       => $window->describe(),
            'by_status'    => $this->countBy($items(), 'status'),
            'waiting'      => $this->countBy($items()->where('status', 'pending'), 'type'),
            'failed'       => array_slice($failures, 0, self::TOP, true),
            'completed'    => $window->apply($items()->where('status', 'completed'), 'completedat', false)->count(),
            'seconds'      => ['average' => round((float) $done->avg('execution_time'), 3), 'longest' => round((float) $window->apply($this->table('queueitems')->where('status', 'completed'), 'completedat', false)->max('execution_time'), 3)],
        ];
    }
}
