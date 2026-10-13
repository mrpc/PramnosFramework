<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Queue\AbstractTask;
use Pramnos\Queue\QueueItem;

/**
 * A queued question answered in the worker — {@see Asks}. Registered for `chat_ask` by default.
 *
 * The assistant is the one `'llm' => ['chat' => ['assistant' => …]]` names. A refusal is the
 * asker's to read, on the ask's row; for the queue the task ran, so it ends as a warning.
 */
class AskTask extends AbstractTask
{
    public string $name = Asks::TASK;

    public function execute(QueueItem $queueItem): mixed
    {
        $job = (array) json_decode((string) $queueItem->payload, true);
        // An analyst's question is answered by the analyst, not by the application's Ask AI.
        $result = (new Asks(new Conversations(Chat::assistant((string) ($job['space'] ?? Scope::CHAT)))))->run($job);

        return $result['ok'] ? true : ['warning' => (string) ($result['says'] ?? '')];
    }

    public function getDescription(QueueItem $queueItem): string
    {
        return 'Answer a queued question ' . (int) (((array) json_decode((string) $queueItem->payload, true))['ask_id'] ?? 0);
    }
}
