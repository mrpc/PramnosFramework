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
 * the framework, and unlike an alias it needs no registration, so it works
 * through `$notifiable->notify()`.
 *
 * A channel that throws is logged and the remaining channels are still tried;
 * {@see throwOnChannelFailure()} asks for the opposite. An unknown channel name
 * throws either way — that is a mistake in via(), not a failed delivery.
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
     * Whether a channel's failure should be raised instead of logged.
     *
     * Off by default: one channel failing must not decide whether the others are tried. See
     * {@see throwOnChannelFailure()} for the case that wants the opposite.
     */
    private bool $throwOnChannelFailure = false;

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
                    /*
                     * Resolved outside the try, and deliberately.
                     *
                     * An unknown channel name is a mistake in `via()` — a typo, a class that was
                     * renamed — and not a delivery that failed. Catching it would turn the one
                     * error in this subsystem that a test would catch into a line in a log file
                     * nobody reads, which is the opposite of useful. Delivery is best-effort;
                     * a wrong channel name is a bug and stays loud.
                     */
                    $channel = $this->resolveChannel($channelName);

                    try {
                        $channel->send($notifiable, $notification);
                    } catch (\Throwable $exception) {
                        $this->channelFailed($channelName, $notification, $exception);
                    }
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
     * What happens when a channel throws: the rest are still tried, and this is recorded.
     *
     * `ChannelInterface` asks channels not to throw, and the built-in ones keep to it — they
     * return early rather than raise when optional data is missing. Nothing enforces it, so the
     * channels that do throw are the custom ones the framework invites you to write, and those
     * are the ones talking to somebody else's gateway over a network.
     *
     * Left to propagate, one of those abandons every channel after it in `via()`. That makes the
     * *order* of a list decide whether the mail goes out when the SMS gateway times out —
     * load-bearing, invisible, and not a decision anybody wrote down.
     *
     * The same rule one level down: {@see \Pramnos\Notification\Channels\PushChannel} already
     * wraps its own delivery so that «one failed batch must not take down whatever queued it».
     * This is that rule where the loop is.
     *
     * @throws \Throwable When {@see throwOnChannelFailure()} was asked for.
     */
    protected function channelFailed(
        string $channelName,
        NotificationInterface $notification,
        \Throwable $exception
    ): void {
        if ($this->throwOnChannelFailure) {
            throw $exception;
        }

        /*
         * The channel and the notification, both named.
         *
         * Either alone is close to useless: «delivery failed» does not say which of four
         * channels, and the channel alone does not say which of an application's notifications
         * was lost. The pair is what makes the line answerable.
         */
        \Pramnos\Logs\Logger::log(
            'Notification channel \'' . $channelName . '\' failed for '
            . $notification::class . ': ' . $exception->getMessage(),
            /*
             * `notifier`, not `notifications`: the `log` *channel* already writes a file called
             * `notifications.log`, and it holds successful dispatches. Two different things
             * under one filename is the kind of collision somebody debugs for twenty minutes.
             */
            'notifier'
        );
    }

    /**
     * Raise a channel's failure instead of logging it and carrying on.
     *
     * For a caller that must know: a queue worker deciding whether to retry the job, a test
     * asserting that a gateway outage is visible, an administration screen that told an operator
     * «sent» and has to be able to take it back.
     *
     * Not the default, because the default has to serve the request path. Somebody changing their
     * password should not be shown a failure because the audit broadcast could not connect.
     *
     * An unknown channel name throws either way — see the note in `sendNow()`.
     *
     * @return $this
     */
    public function throwOnChannelFailure(bool $throw = true): static
    {
        $this->throwOnChannelFailure = $throw;

        return $this;
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

        /*
         * The FQCN first, and the alias second, because the order was the wrong way round.
         *
         * Somebody reading this message has almost always arrived through `$user->notify()`, and
         * `registerChannel()` cannot help them there: `NotifiableTrait::notify()` constructs its
         * own `Notifier`, so an alias registered anywhere else does not exist as far as that call
         * is concerned. The message named the one remedy that cannot work from where it is read.
         */
        throw new \InvalidArgumentException(
            "Unknown notification channel: '{$name}'. Return the fully-qualified class name of a "
            . "ChannelInterface implementation from via() — that needs no registration and works "
            . "through notify(). Notifier::registerChannel() also defines a short alias, but only "
            . "on the Notifier instance it is called on, which is not the one notify() builds."
        );
    }
}
