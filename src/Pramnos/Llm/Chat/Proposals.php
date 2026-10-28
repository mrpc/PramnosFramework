<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Bulk\BulkActions;
use Pramnos\Llm\CallLog;

/**
 * Changes the model proposes and a person approves: the model never writes on its own.
 *
 * Off unless the application turns it on — `'llm' => ['chat' => ['propose_actions' => true]]`.
 * Then the model is given `list_actions` (what this person may do, by kind of record — the
 * {@see BulkActions} the application declared) and `propose_action` (one of them, on some records,
 * with its fields and a reason). A proposal is kept as an artifact of kind `proposal`, shown under
 * the answer with what it would do, and does nothing until the person approves it; then it runs
 * through {@see BulkActions::run()} as them — their right to it asked again, the audit log
 * written, a long list queued. A rejected proposal stays, marked so.
 *
 * Not offered to a scheduled run: there is nobody there to approve.
 */
final class Proposals
{
    /** The artifact kind a proposal is kept as. */
    public const KIND = 'proposal';

    public function __construct(private readonly Scope $scope)
    {
    }

    /** Whether the application turned proposals on. */
    public static function enabled(): bool
    {
        $chat = \Pramnos\Llm\Llm::config('chat', []);

        return is_array($chat) && !empty($chat['propose_actions']);
    }

    /**
     * The model's tools, or none when proposals are off or nothing may be done.
     *
     * @return array<string, array<string, mixed>>
     */
    public function tools(Artifacts $artifacts): array
    {
        if (!self::enabled() || !Artifacts::available() || BulkActions::everything($this->scope->userId) === []) {
            return [];
        }

        return [
            'list_actions' => [
                'description' => 'What this person may change, by kind of record: each action\'s key, label and fields. Read it before proposing.',
                'parameters'  => ['type' => 'object', 'properties' => new \stdClass()],
                'run'         => fn (): string => (string) json_encode(BulkActions::everything($this->scope->userId), JSON_UNESCAPED_UNICODE),
            ],
            'propose_action' => [
                'description' => 'Propose a change for the person to approve: one of list_actions\' actions, on records by id, with its fields. '
                    . 'Nothing changes until they approve it under your answer. Use it when they ask for something to be done, not to read. '
                    . 'Say in your answer what you proposed and why.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'entity' => ['type' => 'string', 'description' => 'The kind of record, as list_actions names it.'],
                    'action' => ['type' => 'string', 'description' => 'The action\'s key.'],
                    'ids'    => ['type' => 'array', 'items' => ['type' => ['integer', 'string']], 'description' => 'The records, exactly as the tools gave their ids.'],
                    'params' => ['type' => 'object', 'description' => 'The action\'s fields, by name.'],
                    'reason' => ['type' => 'string', 'description' => 'One line: why, as the person will read it.'],
                ], 'required' => ['entity', 'action', 'ids', 'reason']],
                'run' => function (array $a) use ($artifacts): string {
                    $made = $this->propose($artifacts, $a);

                    return is_string($made) ? 'Error: ' . $made : 'Proposed as ' . $made['artifact_id'] . '; it waits for the person\'s approval.';
                },
            ],
        ];
    }

    /**
     * Keep a proposal, if it is one this person could approve. Answers it, or why not.
     *
     * @param array<string, mixed> $input entity, action, ids, params, reason
     * @return array<string, mixed>|string
     */
    public function propose(Artifacts $artifacts, array $input): array|string
    {
        $entity    = (string) ($input['entity'] ?? '');
        $key       = (string) ($input['action'] ?? '');
        $available = BulkActions::available($entity, $this->scope->userId);
        if (!isset($available[$key])) {
            return 'There is no such action this person may take. Read list_actions.';
        }
        $params = is_array($input['params'] ?? null) ? $input['params'] : [];
        foreach ($available[$key]['params'] as $name => $param) {
            if (!empty($param['required']) && trim((string) ($params[$name] ?? '')) === '') {
                return sprintf('%s is required.', (string) ($param['label'] ?? $name));
            }
        }
        $ids = array_values(array_unique(array_filter((array) ($input['ids'] ?? []), static fn ($id): bool => is_int($id) || (is_string($id) && $id !== '')), SORT_REGULAR));
        if ($ids === [] || count($ids) > BulkActions::MAX) {
            return 'Name between 1 and ' . BulkActions::MAX . ' records.';
        }

        return $artifacts->keep(self::KIND, $available[$key]['label'], $entity, [
            'action' => $key,
            'label'  => $available[$key]['label'],
            'ids'    => $ids,
            'params' => array_intersect_key($params, $available[$key]['params']),
            'reason' => mb_substr(trim((string) ($input['reason'] ?? '')), 0, 500),
            'status' => 'pending',
        ]);
    }

    /**
     * Approve one of this person's pending proposals: it runs, as them. Answers what happened, or
     * why not.
     *
     * @return array{done: int, queued: int, says: string}|string
     */
    public function approve(int $artifactId): array|string
    {
        $proposal = $this->pending($artifactId);
        if (is_string($proposal)) {
            return $proposal;
        }
        $content = $proposal['content'];
        $result  = BulkActions::run((string) $proposal['entity'], (string) $content['action'], (array) $content['ids'], (array) $content['params'], $this->scope->userId);
        if (is_string($result)) {
            return $result;
        }
        $this->settle($artifactId, $content, 'approved', $result);

        return $result;
    }

    /** Turn down one of this person's pending proposals. Answers null, or why not. */
    public function reject(int $artifactId): ?string
    {
        $proposal = $this->pending($artifactId);
        if (is_string($proposal)) {
            return $proposal;
        }
        $this->settle($artifactId, $proposal['content'], 'rejected', null);

        return null;
    }

    /**
     * The proposal, if it is this person's and still waits.
     *
     * @return array<string, mixed>|string
     */
    private function pending(int $artifactId): array|string
    {
        $found = (new Artifacts($this->scope))->find($artifactId);
        if ($found === null || $found['kind'] !== self::KIND) {
            return 'There is no such proposal.';
        }
        if (($found['content']['status'] ?? '') !== 'pending') {
            return 'That proposal was already ' . (string) ($found['content']['status'] ?? 'settled') . '.';
        }

        return $found;
    }

    /**
     * Mark a proposal approved or rejected, with when, by whom and what came of it.
     *
     * @param array<string, mixed>      $content
     * @param array<string, mixed>|null $result
     */
    private function settle(int $artifactId, array $content, string $status, ?array $result): void
    {
        $content['status']     = $status;
        $content['settled_at'] = CallLog::instant(time());
        if ($result !== null) {
            $content['result'] = $result;
        }
        $this->scope->apply(\Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#chat_artifacts'))
            ->where('artifact_id', $artifactId)
            ->update(['content' => (string) json_encode($content, JSON_UNESCAPED_UNICODE), 'updated_at' => CallLog::instant(time())]);
    }
}
