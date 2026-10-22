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
            . 'window by kind with their commonest error, how long finished tasks took, and the event '
            . 'stream\'s consumer groups: entries waiting, to be tried again, dead.';
    }

    protected function answer(array $input, Window $window): array
    {
        if (!$this->has('queueitems')) {
            return self::absent('background queue');
        }

        $items    = fn () => $this->table('#PREFIX#queueitems');
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
            'seconds'      => ['average' => round((float) $done->avg('execution_time'), 3), 'longest' => round((float) $window->apply($this->table('#PREFIX#queueitems')->where('status', 'completed'), 'completedat', false)->max('execution_time'), 3)],
            'streams'      => self::streams(),
        ];
    }

    /**
     * The event stream's consumer groups — how many entries wait for each, how many to be tried
     * again, how many are dead — or none where there is no stream.
     *
     * @return list<array<string, mixed>>
     */
    private static function streams(): array
    {
        try {
            return (new \Pramnos\Event\Transport\StreamTransport())->available()
                ? array_map(static fn (array $s): array => array_intersect_key($s, array_flip(['group', 'lag', 'lagCapped', 'pending', 'dead', 'oldestPending'])),
                    \Pramnos\Event\Transport\StreamConsumer::status())
                : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
