<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst;

use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Scope;

/**
 * One administrator's analyst, for the screen at `/admin/Ai/analyst`: their conversations,
 * asking, what it remembers, and agreement to the provider.
 *
 * The same checks as Ask AI (`Chat\Concerns\TakesQuestions`) in the `admin` space; the screen
 * that holds it decides who is an administrator.
 */
class Desk
{
    use \Pramnos\Llm\Chat\Concerns\TakesQuestions;

    public function __construct(private readonly int $adminId)
    {
    }

    protected function scope(): ?Scope
    {
        return $this->adminId > 0 ? Analyst::scope($this->adminId) : null;
    }

    protected function conversations(): Conversations
    {
        return Analyst::conversations($this->adminId);
    }

    /**
     * Everything the page draws.
     *
     * @return array{conversations: list<array<string, mixed>>, conversation: array<string, mixed>|null, ask: array<string, mixed>|null, memories: list<array<string, mixed>>, limits: array<string, mixed>, consent: array<string, mixed>}
     */
    public function page(int $open, int $askId): array
    {
        $scope = $this->scope() ?? Analyst::scope(0);
        $chat  = $this->conversations();
        $ask   = $askId > 0 ? $this->asks()->find($scope, $askId) : null;

        // Answered since the page was drawn: open the conversation it went into.
        if ($ask !== null && $ask['status'] === 'answered' && $open === 0) {
            $open = (int) $ask['conversation_id'];
        }

        return [
            'conversations' => $chat->list(),
            'conversation'  => $open > 0 ? $chat->find($open) : null,
            'ask'           => $ask,
            'memories'      => $chat->memory()?->all() ?? [],
            'limits'        => $chat->limits(),
            'consent'       => $this->consentState($scope),
        ];
    }

    /**
     * Ask — `content`, `conversation_id` — with the files uploaded beside it.
     *
     * @param array<string, mixed>                     $input
     * @param list<array{name: string, bytes: string}> $files
     * @return array<string, mixed> As the conversation API answers
     */
    public function ask(array $input, array $files): array
    {
        return $this->takeQuestion($input, $files);
    }

    /** A queued question's state, or null. @return array<string, mixed>|null */
    public function asked(int $askId): ?array
    {
        $scope = $this->scope();

        return $scope === null ? null : $this->asks()->find($scope, $askId);
    }

    public function forget(int $conversationId): bool
    {
        return $this->conversations()->delete($conversationId);
    }

    public function forgetMemory(int $memoryId): bool
    {
        return (bool) $this->conversations()->memory()?->forget($memoryId);
    }

    public function consent(bool $give): void
    {
        $scope = $this->scope();

        if ($scope !== null) {
            $this->recordConsent($scope, $give);
        }
    }

    /**
     * The analyst as JSON, in the conversation API's shape: `chat`, `chat/{id}`, `asks/{id}`,
     * `memory`, `memory/{id}`, `consent`.
     *
     * @param list<string>         $path   What follows `api/`
     * @param array<string, mixed> $input  The request's body
     * @return array{status: int, data?: mixed, error?: string}
     */
    public function api(string $method, array $path, array $input): array
    {
        $scope = $this->scope();

        if ($scope === null) {
            return ['status' => 403, 'error' => 'This needs somebody signed in.'];
        }

        $resource = $path[0] ?? '';
        $id       = (int) ($path[1] ?? 0);
        $chat     = $this->conversations();
        $missing  = static fn (string $what): array => ['status' => 404, 'error' => 'There is no such ' . $what . '.'];

        return match (true) {
            $resource === 'chat' && $method === 'GET' && $id === 0     => ['status' => 200, 'data' => ['conversations' => $chat->list(), 'limits' => $chat->limits()]],
            $resource === 'chat' && $method === 'GET'                  => ($found = $chat->find($id)) === null ? $missing('conversation') : ['status' => 200, 'data' => $found],
            $resource === 'chat' && $method === 'POST'                 => $this->takeQuestion($input, \Pramnos\Llm\Chat\Attachments::fromJson($input)),
            $resource === 'chat' && $method === 'DELETE'               => $this->forget($id) ? ['status' => 200, 'data' => ['deleted' => true]] : $missing('conversation'),
            $resource === 'asks' && $method === 'GET'                  => ($ask = $this->asked($id)) === null ? $missing('question') : ['status' => 200, 'data' => $ask],
            $resource === 'memory' && $method === 'GET'                => ['status' => 200, 'data' => ['memories' => $chat->memory()?->all() ?? []]],
            $resource === 'memory' && $method === 'DELETE'             => $this->forgetMemory($id) ? ['status' => 200, 'data' => ['deleted' => true]] : $missing('memory'),
            $resource === 'consent' && $method === 'GET'               => ['status' => 200, 'data' => $this->consentState($scope)],
            $resource === 'consent' && in_array($method, ['POST', 'DELETE'], true) => $this->changed($scope, $method === 'POST'),
            default                                                    => ['status' => 404, 'error' => 'There is no such address.'],
        };
    }

    /** @return array{status: int, data: array<string, mixed>} */
    private function changed(Scope $scope, bool $give): array
    {
        $this->recordConsent($scope, $give);

        return ['status' => 200, 'data' => $this->consentState($scope)];
    }
}
