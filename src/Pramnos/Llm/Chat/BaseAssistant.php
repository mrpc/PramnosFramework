<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Llm\Connection;

/**
 * An assistant with nothing of its own: no tools beyond the memory, a short prompt, and the
 * framework's defaults for everything else. Extend it and override what the application has.
 */
class BaseAssistant implements Assistant
{
    public function connection(Scope $scope): Connection
    {
        return new Connection(null, isset(Connection::features()['chat']) ? 'chat' : null);
    }

    public function systemPrompt(Turn $turn): string
    {
        return 'You are a helpful assistant. Today is ' . gmdate('l j F Y') . ' (UTC). '
            . 'Answer in the language the question was asked in. Never claim to have done anything no tool result says was done.';
    }

    public function context(Turn $turn): string
    {
        return '';
    }

    public function tools(Turn $turn): array
    {
        return [];
    }

    public function due(Turn $turn): array
    {
        return [];
    }

    public function verify(Turn $turn): ?callable
    {
        return null;
    }

    public function writes(): array
    {
        return ['remember', 'forget', 'schedule_task', 'cancel_scheduled_task'];
    }

    public function question(Turn $turn, string $question): string
    {
        return $question;
    }

    public function answered(Turn $turn, array $message): array
    {
        return $message;
    }

    public function present(Scope $scope, array $messages): array
    {
        return $messages;
    }

    public function acting(Scope $scope, callable $run): mixed
    {
        return $run();
    }

    public function heldRefusal(string $tag): string
    {
        $rule = \Pramnos\Llm\Llm::rules()[$tag] ?? null;

        return $rule !== null
            ? $rule->refusalResult()
            : 'This conversation holds data that no available AI provider may be sent. Start a new conversation.';
    }

    public function routineRefusal(Scope $scope): ?string
    {
        return null;
    }

    public function conversationUrl(?int $conversationId): string
    {
        return \Pramnos\Http\SiteUrl::to('chat' . ($conversationId ? '?c=' . $conversationId : ''));
    }

    public function rounds(): int
    {
        return 16;
    }

    public function turns(): int
    {
        return 30;
    }
}
