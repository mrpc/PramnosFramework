<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

/**
 * A notification of the conversations that knows the space it comes from. One of the companion's
 * (`chat`, `chat:<persona>`) goes by push to the devices of its own app
 * (`'llm' => ['chat' => ['app_url' => 'https://…/']]`), the push opening the app; with
 * `'notify' => ['channel' => 'app']`, there alone — never the site's bell or devices
 * ({@see RoutineReady::channels()}). Anywhere else, the site's.
 *
 * {@see RoutineReady}, {@see TaskReminder} and {@see SharedWithYou} use it, and so may an
 * application's own replacement of them: the senders call {@see fromSpace()} where it is there,
 * through `RoutineReady::tag()`.
 */
trait TellsItsApp
{
    /** The conversations' space it comes from. */
    private string $space = '';

    /** What kind of telling it is — morning, reminder, calendar, answer, share — for the application's `notifies()`. */
    private string $tellsKind = '';

    /** Say what kind of telling it is; answered for chaining. */
    public function ofKind(string $kind): static
    {
        $this->tellsKind = $kind;

        return $this;
    }

    /** Say which space it comes from; answered for chaining. */
    public function fromSpace(string $space): static
    {
        $this->space = $space;

        return $this;
    }

    /** The app whose devices its push goes to ({@see \Pramnos\Notification\Channels\PushChannel}); '' for the site's. */
    public function pushApp(mixed $notifiable): string
    {
        return self::appOf($this->space);
    }

    /**
     * The app a space's notifications go to: `chat` for the companion's, every persona's, where the
     * application has an app for it — `chat@<partition>` for a partition's, whose devices alone
     * are told; the holder's `chat` reaches only the devices of no partition.
     */
    public static function appOf(string $space): string
    {
        if (!People::companion($space) || self::appUrl() === '') {
            return '';
        }
        $partition = (new Scope(0, null, $space))->partition;

        return Scope::CHAT . ($partition === null ? '' : '@' . $partition);
    }

    /** The assistant's own app, where the application has one: `'llm' => ['chat' => ['app_url' => …]]`. */
    public static function appUrl(): string
    {
        $chat = \Pramnos\Llm\Llm::config('chat', []);

        return is_array($chat) ? trim((string) ($chat['app_url'] ?? '')) : '';
    }

    /** Where its push opens: the app, for the app's devices; otherwise `$link`. */
    protected function pushLink(mixed $notifiable, string $link): string
    {
        return $this->pushApp($notifiable) !== '' ? self::appUrl() : $link;
    }

    /** Tell a notification its space and its kind, where it can be told — called on a class using this, `RoutineReady::tag()`. */
    public static function tag(object $notification, string $space, string $kind = ''): object
    {
        if (method_exists($notification, 'fromSpace')) {
            $notification->fromSpace($space);
        }
        if ($kind !== '' && method_exists($notification, 'ofKind')) {
            $notification->ofKind($kind);
        }

        return $notification;
    }
}
