<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Llm\Connection;

/**
 * The application's half of a conversation.
 *
 * The framework keeps the conversations, the memory, the questions waiting in the queue and the
 * schedules, and runs the turn: history, attachments, data tags, the call and the rows. What a
 * conversation is *about* — the prompt, the tools, what an answer must have done, what it
 * produced — is the application's, here. Extend {@see BaseAssistant}, which answers every
 * method with a default, and declare it:
 *
 * ```php
 * 'llm' => ['chat' => ['assistant' => App\Llm\Analyst::class]],
 * ```
 */
interface Assistant
{
    /** The connection a conversation's answers go out on: its provider, model and budget. */
    public function connection(Scope $scope): Connection;

    /** The instructions every answer starts from. */
    public function systemPrompt(Turn $turn): string;

    /** What the model is told about the person and their world; the memory is added after it. */
    public function context(Turn $turn): string;

    /**
     * The tools this answer may use, as {@see \Pramnos\Llm\Tools} arrays. The framework adds
     * the memory's and the schedules' own.
     *
     * @return array<string, array<string, mixed>>
     */
    public function tools(Turn $turn): array;

    /**
     * Tools an answer must end with, each with its test: see `Client::askWithTools()`.
     *
     * @return array<string, callable>
     */
    public function due(Turn $turn): array;

    /** The check an answer is read against, or null: see `Client::askWithTools()`. */
    public function verify(Turn $turn): ?callable;

    /**
     * The tools that change something. A later answer is told which of them an earlier one
     * called, so it does not say it changed nothing.
     *
     * @return list<string>
     */
    public function writes(): array;

    /** The question as the model reads it — what the caller passed beside it, worked in. */
    public function question(Turn $turn, string $question): string;

    /**
     * After the answer is stored: what the application attaches to it. Returns the message,
     * decorated for the screen.
     *
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    public function answered(Turn $turn, array $message): array;

    /**
     * Stored messages, decorated for the screen.
     *
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    public function present(Scope $scope, array $messages): array;

    /**
     * Run a queued or scheduled answer, as whoever it is for — inside whatever the application
     * needs set up for its tools to see that person.
     */
    public function acting(Scope $scope, callable $run): mixed;

    /** Said when a conversation holds data of a tag that nowhere available may take. */
    public function heldRefusal(string $tag): string;

    /** Why this person may not have scheduled questions, or null. */
    public function routineRefusal(Scope $scope): ?string;

    /** Where a conversation is read, for a notification to link to. */
    public function conversationUrl(?int $conversationId): string;

    /** Tool rounds before the model must answer with what it has. */
    public function rounds(): int;

    /** How many turns of a conversation are sent back. */
    public function turns(): int;
}
