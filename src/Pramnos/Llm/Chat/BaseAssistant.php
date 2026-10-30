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

    /** The same for every question: today's date comes with each question ({@see ContextAssembler}). */
    public function systemPrompt(Turn $turn): string
    {
        return 'You are a helpful assistant. '
            . 'Answer in the language the question was asked in, and write anything you say while you call tools in that language too. Never claim to have done anything no tool result says was done.';
    }

    public function context(Turn $turn): string
    {
        return '';
    }

    /**
     * What is true only for this question — the page the person is on, say — sent just before
     * it rather than in the system prompt, which stays the same for the provider's cache. The
     * time is added for every assistant ({@see ContextAssembler::dynamicTail()}).
     */
    public function volatileContext(Turn $turn): string
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
        return ['remember', 'forget', 'schedule_task', 'update_scheduled_task', 'cancel_scheduled_task', 'subscribe_report', 'unsubscribe_report'];
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

    public function routineRefusal(Scope $scope, bool $adding = false): ?string
    {
        return null;
    }

    public function conversationUrl(?int $conversationId): string
    {
        return \Pramnos\Http\SiteUrl::to('chat' . ($conversationId ? '?c=' . $conversationId : ''));
    }

    /** The server's zone, when it is a real one; UTC otherwise. */
    public function timezone(Scope $scope): string
    {
        $zone = date_default_timezone_get();

        return in_array($zone, \DateTimeZone::listIdentifiers(), true) ? $zone : 'UTC';
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
