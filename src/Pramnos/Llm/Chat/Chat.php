<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

/**
 * The application's conversations, as its configuration describes them.
 *
 * ```php
 * // app/app.php
 * 'llm' => ['chat' => [
 *     'assistant'            => App\Llm\Analyst::class,   // Assistant; BaseAssistant when unset
 *     'memory'               => ['max' => 40, 'chars' => 300],
 *     'routine_templates'    => ['weekly' => ['title' => '…', 'prompt' => '…', 'cadence' => 'weekly', 'at' => '09:00', 'day' => 1]],
 *     'routine_notification' => App\Notifications\RoutineReady::class,
 * ]],
 * ```
 */
final class Chat
{
    /** The configured assistant, built once per call. */
    public static function assistant(string $space = Scope::CHAT): Assistant
    {
        if ($space === Scope::ADMIN) {
            return \Pramnos\Llm\Analyst\Analyst::assistant();
        }

        $chat  = \Pramnos\Llm\Llm::config('chat', []);
        $class = is_array($chat) && is_string($chat['assistant'] ?? null) ? $chat['assistant'] : BaseAssistant::class;

        return is_a($class, Assistant::class, true) ? new $class() : new BaseAssistant();
    }

    /** Conversations with the configured assistant, as the request's person. */
    public static function conversations(): Conversations
    {
        return (new Conversations(self::assistant()))->as(Scope::current());
    }
}
