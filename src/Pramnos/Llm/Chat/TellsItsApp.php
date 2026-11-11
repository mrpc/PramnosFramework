<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

/**
 * A notification of the conversations that knows the space it comes from, and goes, by push, to
 * the devices of the assistant's own app where it comes from the `chat` space and the
 * application has an app (`'llm' => ['chat' => ['app_url' => 'https://…/']]`) — its devices
 * alone, the push opening the app. Anywhere else, the site's, as before.
 *
 * {@see RoutineReady}, {@see TaskReminder} and {@see SharedWithYou} use it, and so may an
 * application's own replacement of them: the senders call {@see fromSpace()} where it is there,
 * through `RoutineReady::tag()`.
 */
trait TellsItsApp
{
    /** The conversations' space it comes from. */
    private string $space = '';

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

    /** The app a space's notifications go to: `chat`, where the application has an app for it. */
    public static function appOf(string $space): string
    {
        return $space === Scope::CHAT && self::appUrl() !== '' ? Scope::CHAT : '';
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

    /** Tell a notification its space, where it can be told — called on a class using this, `RoutineReady::tag()`. */
    public static function tag(object $notification, string $space): object
    {
        if (method_exists($notification, 'fromSpace')) {
            $notification->fromSpace($space);
        }

        return $notification;
    }
}
