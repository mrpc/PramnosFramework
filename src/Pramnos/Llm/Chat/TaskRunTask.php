<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Queue\AbstractTask;
use Pramnos\Queue\QueueItem;

/**
 * One scheduled AI task's run, in the worker — what `llm:routines` queues when
 * `'llm' => ['chat' => ['task_queue' => true]]`. A report that reads for a minute then holds up
 * no other, and the minute's tick only claims what is due.
 */
class TaskRunTask extends AbstractTask
{
    public string $name = Routines::TASK;

    public function execute(QueueItem $queueItem): mixed
    {
        $job     = (array) json_decode((string) $queueItem->payload, true);
        $routines = new Routines(new Conversations(Chat::assistant((string) ($job['space'] ?? Scope::CHAT))));
        $task    = $routines->byId((int) ($job['routine_id'] ?? 0));
        if ($task === null) {
            return ['warning' => 'The scheduled task is gone.'];
        }
        $result = $routines->run($task, time());

        return $result['ok'] ? true : ['warning' => (string) $result['says']];
    }

    public function getDescription(QueueItem $queueItem): string
    {
        return 'Run scheduled AI task ' . (int) (((array) json_decode((string) $queueItem->payload, true))['routine_id'] ?? 0);
    }
}
