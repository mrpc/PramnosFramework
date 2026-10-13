<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat\Concerns;

use Pramnos\Llm\Chat\Asks;
use Pramnos\Llm\Chat\Attachments;
use Pramnos\Llm\Chat\Chat;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Llm;

/**
 * Taking a question from a person — what the JSON API and the conversation page both do, with
 * the same hooks for an application to make its own decisions.
 */
trait TakesQuestions
{
    /** After the consent check, before the question is asked: a limit, a charge. */
    protected function beforeAsk(Scope $scope, array $input): ?array
    {
        return null;
    }

    /** Keep an attached picture and answer its address, or null — and then pictures are not kept. */
    protected function storePicture(string $path, string $filename): ?string
    {
        return null;
    }

    /**
     * What travels with the question to the assistant.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function extra(array $input): array
    {
        return [];
    }

    protected function scope(): ?Scope
    {
        return Scope::current();
    }

    protected function conversations(): Conversations
    {
        return (new Conversations(Chat::assistant()))->as($this->scope());
    }

    protected function asks(): Asks
    {
        return new Asks($this->conversations());
    }

    /**
     * Check a question and ask it — queued, or in the request when the application has no
     * worker (`'llm' => ['chat' => ['answer_in_request' => true]]`).
     *
     * @param array<string, mixed>                     $input `content`, `conversation_id`, and the application's own
     * @param list<array{name: string, bytes: string}> $files
     * @return array{status: int, error?: string, data?: array<string, mixed>, why?: string, consent_required?: bool, provider?: string}
     */
    protected function takeQuestion(array $input, array $files): array
    {
        $scope = $this->scope();

        if ($scope === null) {
            return ['status' => 403, 'error' => 'This needs somebody signed in.'];
        }

        $connection = $this->conversations()->assistant()->connection($scope);

        if (!$connection->isConfigured()) {
            return ['status' => 503, 'error' => 'The AI is not connected yet. An administrator sets it up in Administration → AI.'];
        }

        $attachment = Attachments::build($files, fn (string $path, string $filename): ?string => $this->storePicture($path, $filename));

        if (is_string($attachment)) {
            return ['status' => 422, 'error' => $attachment];
        }

        $content        = (string) ($input['content'] ?? '');
        $conversationId = (int) ($input['conversation_id'] ?? 0);

        if (trim($content) === '' && $attachment === null) {
            return ['status' => 422, 'error' => 'Ask it something.'];
        }

        if ($conversationId > 0 && $this->conversations()->find($conversationId) === null) {
            return ['status' => 404, 'error' => 'There is no such conversation.'];
        }

        // Told now rather than from the worker, so the screen can ask for agreement on the spot.
        if (($says = Llm::consent()->refusal($scope->context(), $connection->provider())) !== null) {
            return ['status' => 403, 'error' => $says, 'consent_required' => true, 'provider' => $connection->provider()];
        }

        if (($stop = $this->beforeAsk($scope, $input)) !== null) {
            return $stop;
        }

        $extra = $this->extra($input);
        $chat  = Llm::config('chat', []);

        if (is_array($chat) && !empty($chat['answer_in_request'])) {
            $answer = $this->conversations()->ask($conversationId > 0 ? $conversationId : null, $content, $attachment, $extra);

            return $answer['ok']
                ? ['status' => 200, 'data' => ['conversation_id' => $answer['conversation_id'], 'message' => $answer['message']]]
                : ['status' => self::statusFor($answer['why']), 'error' => $answer['says'], 'why' => $answer['why']];
        }

        $askId = $this->asks()->start($scope, $conversationId > 0 ? $conversationId : null, $content, $attachment, $extra);

        return ['status' => 202, 'data' => ['ask_id' => $askId, 'conversation_id' => $conversationId > 0 ? $conversationId : null]];
    }

    /** The HTTP status of a conversation's refusal. */
    private static function statusFor(string $why): int
    {
        return match ($why) {
            'scope', 'consent' => 403,
            'missing'          => 404,
            'empty'            => 422,
            'limit', 'tags'    => 409,
            default            => 502,
        };
    }
}
