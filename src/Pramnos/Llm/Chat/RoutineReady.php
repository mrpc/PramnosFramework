<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Notification\NotificationInterface;

/**
 * A scheduled question was answered — or could not be.
 *
 * In the person's notifications, and as a push where they have a browser subscribed
 * ({@see channels()}, `'llm' => ['chat' => ['notify_via' => …]]`). An application replaces it with `'llm' => ['chat' => ['routine_notification' => …]]`, a class
 * constructed the same way: title, whether it worked, why not, and the link to the answer.
 */
class RoutineReady implements NotificationInterface
{
    public function __construct(
        private readonly string $title,
        private readonly bool $ok,
        private readonly string $why,
        private readonly string $link
    ) {
    }

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return self::channels($notifiable);
    }

    /**
     * The bell, and a push where the person has a browser subscribed — of those, the ones
     * `'llm' => ['chat' => ['notify_via' => ['database']]]` names: that one is the bell only.
     *
     * @return list<string>
     */
    public static function channels(mixed $notifiable): array
    {
        $chat    = \Pramnos\Llm\Llm::config('chat', []);
        $allowed = is_array($chat['notify_via'] ?? null) ? $chat['notify_via'] : ['database', 'push'];
        $push    = in_array('push', $allowed, true) && \Pramnos\Push\Subscriptions::exist((int) ($notifiable->userid ?? 0));

        return array_values(array_filter(['database', $push ? 'push' : ''], static fn (string $via): bool => $via !== '' && in_array($via, $allowed, true)));
    }

    /** @return array{message: string, url: string} */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'message' => $this->ok ? self::t('%s is ready.', $this->title) : self::t('%s could not be made: %s', $this->title, $this->why),
            'url'     => $this->link,
        ];
    }

    /** @return array{title: string, body: string, url: string, tag: string} */
    public function toPush(mixed $notifiable): array
    {
        return [
            'title' => mb_substr($this->title, 0, 90),
            'body'  => $this->ok ? self::t('Your scheduled report is ready.') : mb_substr(self::t('It could not be made: %s', $this->why), 0, 180),
            'url'   => $this->link,
            // One per routine: this morning's replaces yesterday's.
            'tag'   => 'routine-' . substr(md5($this->title), 0, 8),
        ];
    }

    /** Words in the person's language, where the application translates them. */
    private static function t(string $words, string ...$args): string
    {
        return function_exists('t') ? t($words, ...$args) : vsprintf($words, $args);
    }
}
