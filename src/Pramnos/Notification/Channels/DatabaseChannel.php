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
 * @package     PramnosFramework
 * @subpackage  Notification\Channels
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
        $type = $db->prepareInput(get_class($notification));
        $ntType   = $db->prepareInput(get_class($notifiable));
        $payload  = $db->prepareInput(json_encode($data, JSON_UNESCAPED_UNICODE));
        $now      = date('Y-m-d H:i:s');

        $db->query(
            "INSERT INTO #PREFIX#notifications "
            . "(id, type, notifiable_type, notifiable_id, data, read_at, created_at) "
            . "VALUES ('{$uuid}', '{$type}', '{$ntType}', " . (int) $notifiableId . ", '{$payload}', NULL, '{$now}')"
        );
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
