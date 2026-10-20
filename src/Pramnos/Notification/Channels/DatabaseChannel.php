<?php

declare(strict_types=1);

namespace Pramnos\Notification\Channels;

use Pramnos\Database\Database;
use Pramnos\Notification\ChannelInterface;
use Pramnos\Notification\NotifiableInterface;
use Pramnos\Notification\NotificationInterface;

/**
 * Notification channel that persists notifications to the database.
 *
 * Writes one row to `#PREFIX#notifications` per (notifiable, notification)
 * pair. The notification must implement `toDatabase(mixed $notifiable): array`
 * returning whatever JSON-serialisable data the application needs:
 *
 *   [
 *     'message' => 'Invoice #42 has been paid.',
 *     'data'    => ['invoice_id' => 42, 'amount' => 150.00],
 *   ]
 *
 * Rows are meant to be read by the application to display an in-app
 * notification feed. Mark them read by setting `read_at` to a timestamp.
 *
 * The notifiable's primary-key ID is resolved via:
 *   1. $notifiable->routeNotificationFor('database')
 *   2. $notifiable->userid  (Pramnos User convention)
 *   3. $notifiable->id
 *
 * Silently skips if the notification has no toDatabase() method or the
 * notifiable has no resolvable ID.
 *
 * After the row is written, and where broadcasting is set up, the notifiable's channel —
 * `private-users.{id}`, or `broadcasting.notifications_channel` — hears `notification.created`
 * with the row's id, so a bell on a page with a live connection refreshes at once instead of at
 * its next poll. Best effort: a broadcast that fails is logged and the row stays.
 */
class DatabaseChannel implements ChannelInterface
{
    /**
     * @param Database|null $db  Inject a Database instance (for testing).
     */
    public function __construct(private ?Database $db = null)
    {
    }

    /**
     * Persist the notification to the `#PREFIX#notifications` table.
     */
    public function send(mixed $notifiable, NotificationInterface $notification): void
    {
        if (!method_exists($notification, 'toDatabase')) {
            return;
        }

        $notifiableId = $this->resolveNotifiableId($notifiable);
        if ($notifiableId === null) {
            return;
        }

        $data = $notification->toDatabase($notifiable);

        $db   = $this->db ?? Database::getInstance();
        $uuid = $this->generateUuid();

        $db->queryBuilder()->table('#PREFIX#notifications')->insert([
            'id'              => $uuid,
            'type'            => get_class($notification),
            'notifiable_type' => get_class($notifiable),
            'notifiable_id'   => (int) $notifiableId,
            'data'            => json_encode($data, JSON_UNESCAPED_UNICODE),
            'read_at'         => null,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        $this->announce((int) $notifiableId, $uuid);
    }

    /**
     * Tell the notifiable's channel a notification arrived — only the id: a page re-reads its
     * feed with its own session, so nothing private travels over the socket.
     */
    private function announce(int $notifiableId, string $uuid): void
    {
        try {
            $manager = $this->broadcaster();
            if ($manager === null) {
                return;
            }
            $config = \Pramnos\Application\Application::currentInstance()?->applicationInfo['broadcasting'] ?? [];
            $manager->broadcast(
                \Pramnos\Broadcasting\RealtimeConfig::userChannel($notifiableId, is_array($config) ? $config : []),
                'notification.created',
                ['id' => $uuid]
            );
        } catch (\Throwable $e) {
            // The notification is written; a page finds it at its next poll.
            \Pramnos\Logs\Logger::log('Notification ' . $uuid . ' was not announced: ' . $e->getMessage(), 'broadcasting');
        }
    }

    /**
     * The broadcasting manager, where the application has one: the `broadcasting` feature's, or
     * one set as the default. Null otherwise — never one built just to announce.
     */
    protected function broadcaster(): ?\Pramnos\Broadcasting\BroadcastingManager
    {
        $container = \Pramnos\Application\Application::currentInstance()?->getContainer();
        if ($container !== null && $container->has('broadcasting')) {
            $manager = $container->get('broadcasting');
            if ($manager instanceof \Pramnos\Broadcasting\BroadcastingManager) {
                return $manager;
            }
        }

        return \Pramnos\Broadcasting\BroadcastingManager::currentInstance();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function resolveNotifiableId(mixed $notifiable): mixed
    {
        if ($notifiable instanceof NotifiableInterface) {
            return $notifiable->routeNotificationFor('database');
        }

        return $notifiable->userid ?? $notifiable->id ?? null;
    }

    /**
     * Generate a version-4 UUID (random).
     */
    protected function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
