<?php

declare(strict_types=1);

namespace Pramnos\Notification;

use Pramnos\Notification\Channels\BroadcastChannel;
use Pramnos\Notification\Channels\DatabaseChannel;
use Pramnos\Notification\Channels\LogChannel;
use Pramnos\Notification\Channels\MailChannel;

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
 * @package     PramnosFramework
 * @subpackage  Notification
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
        $channels = $notification->via($notifiable);

        foreach ($channels as $channelName) {
            $channel = $this->resolveChannel($channelName);
            $channel->send($notifiable, $notification);
        }
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
