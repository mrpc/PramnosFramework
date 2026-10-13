<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

/**
 * Web push: sent and failed per period, by kind of notification, and the commonest failures.
 * The status is the push service's answer: a 2xx was delivered to it.
 */
final class PushTool extends AdminTool
{
    private const TABLE = 'pramnos.pushlog';

    public function name(): string
    {
        return 'push';
    }

    public function description(): string
    {
        return 'Web push notifications in a window: sent and failed, per period, by kind of '
            . 'notification, and the commonest failure reasons.';
    }

    protected function answer(array $input, Window $window): array
    {
        if (!$this->has(self::TABLE)) {
            return self::absent('push notifications');
        }

        $rows      = fn () => $window->apply($this->table(self::TABLE), 'sent', false);
        $delivered = $rows()->where('status', '>=', 200)->where('status', '<', 300)->count();
        $total     = $rows()->count();
        $errors    = [];
        $result    = $rows()->select(['error'])->whereNotNull('error')->where('error', '!=', '')->get();
        while (($row = $result->fetch()) !== null) {
            $line          = strtok((string) $row['error'], "\n");
            $errors[$line] = ($errors[$line] ?? 0) + 1;
        }
        arsort($errors);

        return [
            'window'       => $window->describe(),
            'sent'         => $total,
            'delivered'    => $delivered,
            'failed'       => $total - $delivered,
            'by_kind'      => $this->countBy($rows(), 'notification'),
            'by_status'    => $this->countBy($rows(), 'status'),
            'per_period'   => $this->series($this->table(self::TABLE), 'sent', $window, false),
            'failures'     => array_slice($errors, 0, 10, true),
        ];
    }
}
