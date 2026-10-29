<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Queue\AbstractTask;
use Pramnos\Queue\QueueItem;

/**
 * One tool call of a round run at once — {@see ParallelTools}. Registered for `llm_tool` by
 * default. A call the answer itself, or another worker, took first is nothing to do.
 */
class ToolTask extends AbstractTask
{
    public string $name = ParallelTools::TASK;

    public function execute(QueueItem $queueItem): mixed
    {
        ParallelTools::runQueued((array) json_decode((string) $queueItem->payload, true));

        return true;
    }

    public function getDescription(QueueItem $queueItem): string
    {
        return 'Run a tool call ' . (int) (((array) json_decode((string) $queueItem->payload, true))['run_id'] ?? 0);
    }
}
