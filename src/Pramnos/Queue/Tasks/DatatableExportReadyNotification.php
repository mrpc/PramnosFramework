<?php

declare(strict_types=1);

namespace Pramnos\Queue\Tasks;

use Pramnos\Notification\Channels\PushChannel;
use Pramnos\Notification\NotificationInterface;
use Pramnos\Push\Subscriptions;

/**
 * "Your export is ready" — by mail always, and by push too where the requester
 * has a live subscription, same as {@see \Pramnos\Todos\TodoNotification}.
 *
 * Delivered through the framework's own {@see \Pramnos\Notification\Notifier},
 * not a raw `Email::queue()` call, so a requester with browser push enabled —
 * the same permission the admin already asks for elsewhere — gets it there
 * too, with no further wiring from {@see DatatableExportTask}.
 */
final class DatatableExportReadyNotification implements NotificationInterface
{
    /**
     * @param bool $skipPush Drop the push channel even where a subscription
     *   exists — the requester polled this export's status recently enough
     *   ({@see \Pramnos\Queue\QueueManager::recentlyPolled()}) that a push would
     *   only repeat the download link already on their screen. Mail still
     *   always goes out; that is a notification, a push would be a second one.
     */
    public function __construct(
        private readonly string $message,
        private readonly string $url,
        private readonly bool $skipPush = false
    ) {
    }

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        if ($this->skipPush) {
            return ['mail'];
        }

        $push = PushChannel::sends() && Subscriptions::exist((int) ($notifiable->userid ?? 0));

        return $push ? ['mail', 'push'] : ['mail'];
    }

    /** @return array{subject: string, body: string} */
    public function toMail(mixed $notifiable): array
    {
        return [
            'subject' => t('Your export is ready'),
            'body'    => '<p>' . $this->message . '.</p><p><a href="' . htmlspecialchars($this->url, ENT_QUOTES)
                . '">' . t('Download') . '</a></p>',
        ];
    }

    /** @return array{title: string, body: string, url: string, tag: string} */
    public function toPush(mixed $notifiable): array
    {
        return ['title' => t('Export ready'), 'body' => $this->message, 'url' => $this->url, 'tag' => 'datatable-export'];
    }

    /** Composed in this request, handed to the outbox — the task does not block a worker on SMTP. */
    public function queueable(): bool
    {
        return true;
    }
}
