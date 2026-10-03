<?php

declare(strict_types=1);

namespace Pramnos\Push\Notifications;

use Pramnos\Notification\NotificationInterface;

/**
 * The fixed notification a test sends: "if you can read this, notifications reach this device".
 *
 * It carries a receipt address, which the service worker posts to the moment the push arrives,
 * and a tag unique to the test, so the push log row can be matched to its receipt and two
 * tests do not replace each other on the lock screen.
 */
class TestPushNotification implements NotificationInterface
{
    /** The prefix of every test's tag; the rest is the test's token. */
    public const TAG_PREFIX = 'pramnos-test:';

    public function __construct(private string $token, private string $ackUrl)
    {
    }

    /** Push only: a test of the push channel. */
    public function via(mixed $notifiable): array
    {
        return ['push'];
    }

    /** @return array<string, mixed> */
    public function toPush(mixed $notifiable): array
    {
        $site = (string) (\Pramnos\Application\Settings::getSetting('sitename') ?: '');

        return [
            'title' => t('Test notification'),
            'body'  => $site !== ''
                ? sprintf(t('If you can read this, notifications from %s reach this device.'), $site)
                : t('If you can read this, notifications reach this device.'),
            'tag'   => self::TAG_PREFIX . $this->token,
            'data'  => ['ack' => $this->ackUrl],
        ];
    }
}
