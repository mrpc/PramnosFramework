<?php

declare(strict_types=1);

namespace Pramnos\Notification\Channels;

use Pramnos\Notification\ChannelInterface;
use Pramnos\Notification\NotificationInterface;

/**
 * Notification channel that appends a JSON line to a log file.
 *
 * Useful during development to inspect notification dispatches without a
 * live transport. Each dispatch appends one JSON line:
 *
 *   {"time":"2026-05-26 12:00:00","notification":"App\\InvoicePaidNotification",
 *    "notifiable":"App\\User","data":{...}}
 *
 * If the notification implements `toLog(mixed $notifiable): mixed`, the return
 * value is used as the "data" field. Otherwise the channel uses
 * `toDatabase()` output when available, and falls back to an empty object.
 *
 * The log file path defaults to LOGS/notifications.log (if the LOGS constant
 * is defined) or sys_get_temp_dir()/notifications.log.
 *
 * @package     PramnosFramework
 * @subpackage  Notification\Channels
 */
class LogChannel implements ChannelInterface
{
    private string $logPath;

    public function __construct(string $logPath = '')
    {
        $this->logPath = $logPath !== ''
            ? $logPath
            : (defined('LOGS') ? rtrim(LOGS, '/') . '/notifications.log'
                               : sys_get_temp_dir() . '/notifications.log');
    }

    /**
     * Append a JSON line to the notification log file.
     */
    public function send(mixed $notifiable, NotificationInterface $notification): void
    {
        $data = $this->extractData($notifiable, $notification);

        $entry = json_encode([
            'time'         => date('Y-m-d H:i:s'),
            'notification' => get_class($notification),
            'notifiable'   => get_class($notifiable),
            'data'         => $data,
        ], JSON_UNESCAPED_UNICODE);

        file_put_contents($this->logPath, $entry . "\n", FILE_APPEND | LOCK_EX);
    }

    public function getLogPath(): string
    {
        return $this->logPath;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function extractData(mixed $notifiable, NotificationInterface $notification): mixed
    {
        if (method_exists($notification, 'toLog')) {
            return $notification->toLog($notifiable);
        }
        if (method_exists($notification, 'toDatabase')) {
            return $notification->toDatabase($notifiable);
        }
        return new \stdClass();
    }
}
