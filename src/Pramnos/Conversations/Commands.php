<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

/**
 * The application's `/commands` in the composer: `/jira NAN-6212`, `/remind 15:00 κλήση`.
 *
 * ```php
 * Commands::register('jira', t('Make a Jira issue from this conversation'), function (string $args, int $conversation, object $person, ?int $thread) {
 *     $issue = Jira::create($args, Conversations::get($conversation));
 *     SystemPost::post('#jira', '', [['title' => $issue->key . ' · ' . $issue->summary, 'title_link' => $issue->url]], ['username' => 'Jira']);
 *     return t('Made %s.', $issue->key);
 * }, usage: '<τίτλος>');
 * ```
 *
 * A command answers the person who wrote it, and only them: a line, or `['text' => …, 'card' => …]`
 * — what everybody should see, it posts itself. Its name is letters, digits, `-` and `_`. Writing a
 * command takes being able to write in the conversation.
 */
final class Commands
{
    /** @var array<string, array{description: string, usage: string, run: callable}> */
    private static array $commands = [];

    /**
     * A command.
     *
     * @param callable(string, int, object, ?int): (string|array{text?: string, card?: array<string, mixed>}|null) $run
     *        What follows the name, the conversation, the person, the thread — the answer for them
     */
    public static function register(string $name, string $description, callable $run, string $usage = ''): void
    {
        $name = strtolower(ltrim($name, '/'));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $name)) {
            throw new \InvalidArgumentException('A command is named by letters, digits, - and _.');
        }
        self::$commands[$name] = ['description' => $description, 'usage' => $usage, 'run' => $run];
    }

    /**
     * The commands, for the composer's suggestions.
     *
     * @return list<array{name: string, description: string, usage: string}>
     */
    public static function all(): array
    {
        $all = [];
        foreach (self::$commands as $name => $command) {
            $all[] = ['name' => $name, 'description' => $command['description'], 'usage' => $command['usage']];
        }

        return $all;
    }

    /**
     * Run what the person wrote as a command.
     *
     * @return array{text: string, card: ?array<string, mixed>} The answer for them alone
     */
    public static function run(string $text, int $conversationId, object $person, ?int $thread = null): array
    {
        if (!preg_match('/^\/([a-z0-9][a-z0-9_-]*)(?:\s+(.*))?$/is', trim($text), $m) || !isset(self::$commands[strtolower($m[1])])) {
            throw new \InvalidArgumentException(t('There is no such command.'));
        }
        if (!Conversations::canWrite($conversationId, $person)) {
            throw new \InvalidArgumentException(t('Only its members write in a conversation.'));
        }
        try {
            $answer = (self::$commands[strtolower($m[1])]['run'])(trim((string) ($m[2] ?? '')), $conversationId, $person, $thread);
        } catch (\InvalidArgumentException $e) {
            return ['text' => $e->getMessage(), 'card' => null];
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Conversations: /' . $m[1] . ' failed: ' . $e->getMessage(), 'conversations');

            return ['text' => t('The command failed. It has been logged.'), 'card' => null];
        }
        if (is_array($answer)) {
            return ['text' => (string) ($answer['text'] ?? ''), 'card' => isset($answer['card']) && is_array($answer['card']) ? References::card($answer['card']) : null];
        }

        return ['text' => (string) ($answer ?? ''), 'card' => null];
    }

    /** Forget the commands: tests. */
    public static function reset(): void
    {
        self::$commands = [];
    }
}
