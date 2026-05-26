<?php

declare(strict_types=1);

namespace Pramnos\Notification\Channels;

use Pramnos\Broadcasting\BroadcastingManager;
use Pramnos\Notification\ChannelInterface;
use Pramnos\Notification\NotificationInterface;

/**
 * Notification channel that broadcasts via the BroadcastingManager.
 *
 * The notification must implement `toBroadcast(mixed $notifiable): array`
 * returning a payload recognised by the configured broadcast driver:
 *
 *   [
 *     'channel' => 'users.42',            // optional, defaults to 'notifications'
 *     'event'   => 'notification.created', // optional, defaults to 'notification.created'
 *     'payload' => ['message' => '...'],   // the actual data sent to subscribers
 *   ]
 *
 * Silently skips if the notification has no toBroadcast() method.
 *
 * @package     PramnosFramework
 * @subpackage  Notification\Channels
 */
class BroadcastChannel implements ChannelInterface
{
    /**
     * @param BroadcastingManager|null $manager  Inject a manager instance (for testing).
     */
    public function __construct(private ?BroadcastingManager $manager = null)
    {
    }

    /**
     * Broadcast the notification payload.
     */
    public function send(mixed $notifiable, NotificationInterface $notification): void
    {
        if (!method_exists($notification, 'toBroadcast')) {
            return;
        }

        $data    = $notification->toBroadcast($notifiable);
        $channel = (string) ($data['channel'] ?? 'notifications');
        $event   = (string) ($data['event']   ?? 'notification.created');
        $payload = (array)  ($data['payload'] ?? $data);

        $this->getManager()->broadcast($channel, $event, $payload);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function getManager(): BroadcastingManager
    {
        return $this->manager ?? new BroadcastingManager();
    }
}
