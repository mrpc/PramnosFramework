<?php

declare(strict_types=1);

namespace Pramnos\Notification;

use Pramnos\Notification\Channels\BroadcastChannel;
use Pramnos\Notification\Channels\DatabaseChannel;
use Pramnos\Notification\Channels\LogChannel;
use Pramnos\Notification\Channels\MailChannel;
use Pramnos\Notification\Channels\PushChannel;

/**
 * Dispatches notifications to their declared channels.
 *
 * The Notifier resolves each channel name returned by
 * NotificationInterface::via() to a ChannelInterface instance and calls
 * send(). Built-in short aliases:
 *
 *   'mail'      → MailChannel
 *   'database'  → DatabaseChannel
 *   'broadcast' → BroadcastChannel
 *   'push'      → PushChannel
 *   'log'       → LogChannel
 *
 * Any fully-qualified class name that implements ChannelInterface is also
 * accepted as a channel name — this allows custom channels without modifying
 * the framework.
 *
 * Usage:
 *
 *   // Via notifiable entity (preferred)
 *   $user->notify(new InvoicePaidNotification($invoice));
 *
 *   // Bulk dispatch
 *   (new Notifier())->send([$user1, $user2], new InvoicePaidNotification($invoice));
 *
 */
class Notifier
{
    /**
     * Map of short channel aliases to channel class names.
     * @var array<string, class-string<ChannelInterface>>
     */
    private array $channelMap = [
        'mail'      => MailChannel::class,
        'database'  => DatabaseChannel::class,
        'broadcast' => BroadcastChannel::class,
        'push'      => PushChannel::class,
        'log'       => LogChannel::class,
    ];

    /**
     * Dispatch a notification to every entity in the array.
     *
     * @param mixed[]               $notifiables
     * @param NotificationInterface $notification
     */
    public function send(array $notifiables, NotificationInterface $notification): void
    {
        foreach ($notifiables as $notifiable) {
            $this->sendNow($notifiable, $notification);
        }
    }

    /**
     * Dispatch a notification to a single notifiable entity.
     *
     * Iterates over the channels returned by $notification->via() and calls
     * send() on each resolved ChannelInterface.
     *
     * @param mixed                 $notifiable
     * @param NotificationInterface $notification
     */
    public function sendNow(mixed $notifiable, NotificationInterface $notification): void
    {
        // In the recipient's language, not the sender's.
        //
        // A notification is the one piece of text in an application that is not for whoever
        // made the request: the language of the request belongs to the person who triggered
        // it — an operator resetting somebody's password from an English administration
        // area, a queue worker with no language at all — and the person who reads it is the
        // notifiable. So a Greek account was told about its own new sign-in in English.
        //
        // Here rather than in each notification, because every one of them has the same
        // answer and each would have got it right separately or not at all. `using()`
        // restores the previous catalogue afterwards, which matters more than it sounds:
        // `Language::load()` merges, so switching by loading twice leaves the second
        // language's strings in place for everything that follows.
        \Pramnos\Translator\Language::using(
            $this->languageOf($notifiable),
            function () use ($notifiable, $notification): void {
                $channels = $notification->via($notifiable);

                foreach ($channels as $channelName) {
                    $channel = $this->resolveChannel($channelName);
                    $channel->send($notifiable, $notification);
                }
            }
        );
    }

    /**
     * The notifiable's own language, or an empty string when it has none.
     *
     * Empty is the common case and it means "do not change anything": a `PlainAddress` is
     * an address and nothing else, and an account that never chose a language should be
     * told in whatever the installation's language is rather than in a guess.
     */
    protected function languageOf(mixed $notifiable): string
    {
        if (is_object($notifiable) && isset($notifiable->language)) {
            return trim((string) $notifiable->language);
        }

        if (is_array($notifiable) && isset($notifiable['language'])) {
            return trim((string) $notifiable['language']);
        }

        return '';
    }

    /**
     * Register a custom channel under a short alias.
     *
     * @param string                          $alias
     * @param class-string<ChannelInterface>  $className
     */
    public function registerChannel(string $alias, string $className): static
    {
        $this->channelMap[$alias] = $className;
        return $this;
    }

    /**
     * Resolve a channel name to a ChannelInterface instance.
     *
     * @throws \InvalidArgumentException When the name is unknown.
     */
    private function resolveChannel(string $name): ChannelInterface
    {
        if (isset($this->channelMap[$name])) {
            return new $this->channelMap[$name]();
        }

        // Accept fully-qualified class names as channel names
        if (class_exists($name) && is_subclass_of($name, ChannelInterface::class)) {
            return new $name();
        }

        throw new \InvalidArgumentException(
            "Unknown notification channel: '{$name}'. "
            . "Register it with Notifier::registerChannel() or pass the FQCN of a ChannelInterface class."
        );
    }
}
