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
        // With the persona the person chose (Personas), where the application has personas.
        $scope = Scope::current();

        if ($scope === null) {
            return null;
        }
        $scope = \Pramnos\Llm\Chat\Personas::scopeFor($scope);
        // The application's word for this request — a partition of the account, and anything else.
        $hook = ((array) \Pramnos\Llm\Llm::config('chat', []))['scope'] ?? null;
        if (is_callable($hook)) {
            $given = $hook($scope);
            if ($given instanceof Scope && $given->userId === $scope->userId) {
                $scope = $given;
            }
        }

        return \Pramnos\Llm\Chat\Personas::ofPartition($scope);
    }

    protected function conversations(): Conversations
    {
        $scope = $this->scope();

        return (new Conversations(Chat::assistant($scope?->stored() ?? Scope::CHAT)))->as($scope);
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

        $connection = $this->conversations()->connection();

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

        $found = $conversationId > 0 ? $this->conversations()->find($conversationId) : null;
        if ($conversationId > 0 && $found === null) {
            return ['status' => 404, 'error' => 'There is no such conversation.'];
        }

        // Ended by the assistant (Chat\Ending): told now, not queued for the worker to refuse.
        if (!empty($found['ended'])) {
            return ['status' => 409, 'error' => \Pramnos\Llm\Chat\Ending::SAYS, 'why' => 'ended'];
        }

        // Told now rather than from the worker, so the screen can ask for agreement on the spot.
        if (($says = Llm::consent()->refusal($scope->context(), $connection->provider())) !== null) {
            return ['status' => 403, 'error' => $says, 'consent_required' => true, 'provider' => $connection->provider()];
        }

        if (($stop = $this->beforeAsk($scope, $input)) !== null) {
            return $stop;
        }

        // Asked aloud (`voice`): the answer is read aloud, so it opens with what can be said
        // ({@see \Pramnos\Llm\Chat\ContextAssembler::dynamicTail()}).
        $extra = $this->extra($input) + (filter_var($input['voice'] ?? false, FILTER_VALIDATE_BOOLEAN) ? ['voice' => true] : [])
            // Who is speaking on the account now, where it is shared (FW-442).
            + (($speaker = self::speaker($input['speaker'] ?? null)) === null ? [] : ['speaker' => $speaker]);
        $chat  = Llm::config('chat', []);

        if (is_array($chat) && !empty($chat['answer_in_request'])) {
            $answer = $this->conversations()->ask($conversationId > 0 ? $conversationId : null, $content, $attachment, $extra);

            return $answer['ok']
                ? ['status' => 200, 'data' => ['conversation_id' => $answer['conversation_id'], 'message' => $answer['message']]]
                : ['status' => self::statusFor($answer['why']), 'error' => $answer['says'], 'why' => $answer['why']];
        }

        $askId = $this->asks()->start($scope, $conversationId > 0 ? $conversationId : null, $content, $attachment, $extra);

        // The mood the question calls for, now, for the avatar to react while the answer is written
        // (Mood::senseWith()); there, null when nothing is sensed.
        return ['status' => 202, 'data' => ['ask_id' => $askId, 'conversation_id' => $conversationId > 0 ? $conversationId : null]
            + (\Pramnos\Llm\Chat\Mood::senses($scope) ? ['sensed' => \Pramnos\Llm\Chat\Mood::sense($scope, $content)] : [])];
    }

    /**
     * The provider a question here goes to, whether it asks for agreement, and whether this
     * person gave it — for a page to say so.
     *
     * @return array{provider: string, label: string, place: string, required: bool, given: bool}
     */
    protected function consentState(Scope $scope): array
    {
        $provider = $this->conversations()->connection()->provider();
        $config   = \Pramnos\Llm\Providers::config($provider);

        return [
            'provider' => $provider,
            'label'    => (string) $config['label'],
            'place'    => (string) ($config['place'] ?? ''),
            'required' => \Pramnos\Llm\Policy\StoredConsent::required($provider) && Llm::consent() instanceof \Pramnos\Llm\Policy\StoredConsent,
            'given'    => Llm::consent()->refusal($scope->context(), $provider) === null,
        ];
    }

    /** Agree to the provider a question here goes to, or withdraw. */
    protected function recordConsent(Scope $scope, bool $give): void
    {
        $provider = $this->conversations()->connection()->provider();
        $store    = new \Pramnos\Llm\Policy\StoredConsent();
        $give
            ? $store->give($scope->organizationId, $scope->userId, $provider)
            : $store->withdraw($scope->organizationId, $scope->userId, $provider);
    }

    /** The HTTP status of a conversation's refusal. */
    /**
     * Who is speaking, as a question says it: `{"kind": "guest", "name": "Eleni"}` — a kind of
     * letters, a name of at most 60 characters — or null.
     *
     * @return array{kind: string, name: string}|null
     */
    private static function speaker(mixed $given): ?array
    {
        if (!is_array($given)) {
            return null;
        }
        $kind = strtolower(trim((string) ($given['kind'] ?? '')));

        return preg_match('/^[a-z][a-z_-]{0,30}$/', $kind) === 1
            ? ['kind' => $kind, 'name' => mb_substr(trim(strip_tags((string) ($given['name'] ?? ''))), 0, 60)]
                // Their age, where given — what a skill's `age` is matched against (Audience).
                + (is_numeric($given['age'] ?? null) && (int) $given['age'] >= 1 && (int) $given['age'] <= 120 ? ['age' => (int) $given['age']] : [])
            : null;
    }

    private static function statusFor(string $why): int
    {
        return match ($why) {
            'scope', 'consent' => 403,
            'missing'          => 404,
            'empty'            => 422,
            'limit', 'tags', 'ended' => 409,
            default            => 502,
        };
    }
}
