<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst;

/**
 * A question to the analyst from the console, as an administrator — `llm:ask` and the `ask` tool
 * of `mcp:call`: to see how it answers after a tool or the help was fixed, without a browser.
 *
 * The analyst of `/admin/Ai`, with the administrator's tools and abilities, through the same
 * path a question from the screen takes in the worker (`Assistant::acting()`, then
 * `Conversations::ask()`). The conversation is kept as any other and opens on the screen; it is
 * marked as asked from the console, so `help:gaps` leaves it out and nothing in it is learned
 * about the person ({@see \Pramnos\Llm\Chat\Conversations::fromConsole()}).
 */
final class ConsoleAsk
{
    /**
     * Ask, as the administrator `$userId`, in a new conversation or to continue one.
     *
     * @param string|null $minRole A role the administrator must hold, or null for any account
     * @return array{ok: bool, says: string, conversation_id: int|null, answer: string, tools: list<array{name: string, arguments: mixed}>, cost: float|null, url: string|null}
     */
    public static function ask(int $userId, string $question, ?int $conversationId = null, ?string $minRole = null): array
    {
        $refused = static fn (string $says): array => ['ok' => false, 'says' => $says, 'conversation_id' => $conversationId,
            'answer' => '', 'tools' => [], 'cost' => null, 'url' => null];
        $user = $userId > 1 ? new \Pramnos\User\User($userId) : null;
        if ($user === null || (int) ($user->userid ?? 0) !== $userId) {
            return $refused('There is no account ' . $userId . '.');
        }
        if ($minRole !== null && !\Pramnos\User\Roles::atLeast($user, $minRole)) {
            return $refused('Account ' . $userId . ' is not ' . $minRole . ': the analyst is asked here only as one.');
        }
        if (trim($question) === '') {
            return $refused('Ask it something.');
        }
        $scope = Analyst::scope($userId);
        $chat  = Analyst::conversations($userId)->fromConsole();
        if (!$chat->connection()->isConfigured()) {
            return $refused('The AI is not connected yet. An administrator sets it up in Administration → AI.');
        }

        try {
            $answer = Analyst::assistant()->acting($scope, static fn (): array => $chat->ask(
                $conversationId,
                $question,
                null,
                ['area' => \Pramnos\Http\AdminArea::url()]
            ));
        } catch (\Throwable $exception) {
            return $refused('The AI stopped: ' . $exception->getMessage());
        }
        if (!$answer['ok']) {
            return $refused((string) $answer['says']);
        }
        $message = (array) ($answer['message'] ?? []);
        $id      = (int) $answer['conversation_id'];

        return [
            'ok'              => true,
            'says'            => '',
            'conversation_id' => $id,
            'answer'          => (string) ($message['content'] ?? ''),
            'tools'           => array_values(array_map(static fn ($call): array => [
                'name'      => (string) ($call['name'] ?? ''),
                'arguments' => $call['arguments'] ?? [],
            ], (array) ($message['tools'] ?? []))),
            'cost'            => isset($message['cost']) ? (float) $message['cost'] : null,
            'url'             => Analyst::assistant()->conversationUrl($id),
        ];
    }
}
