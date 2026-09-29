<?php

declare(strict_types=1);

namespace Pramnos\Auth\Notifications;

use Pramnos\Notification\NotificationInterface;

/**
 * The mail that confirms a self-registered address. Sent now, not queued: somebody has just
 * registered and is waiting for it.
 */
class VerifyEmailNotification implements NotificationInterface
{
    private string $siteName;

    public function __construct(
        private string $link,
        private int $ttl,
        private string $username = '',
        string $siteName = ''
    ) {
        $this->siteName = $siteName !== '' ? $siteName : (defined('URL') ? (string) URL : 'this site');
    }

    public function via(mixed $notifiable): array
    {
        return array('mail');
    }

    public function toMail(mixed $notifiable): array
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $body = '<p>' . t('Confirm that this address is yours to finish creating your account on <strong>%s</strong>:',
                $e($this->siteName)) . '</p>'
            . '<p><a href="' . $e($this->link) . '">' . $e($this->link) . '</a></p>'
            . '<p>' . t('The link expires in %s hours. Until you open it, the account cannot sign in.', $this->hours()) . '</p>'
            . '<p>' . t('If you did not register, you can ignore this message.') . '</p>';

        return array(
            'subject' => t('Confirm your email address for %s', $this->siteName),
            'body'    => $body,
        );
    }

    public function storedMailTemplate(): array
    {
        return array(
            'category' => 'auth.verify_email',
            'vars'     => array(
                'link'     => $this->link,
                'hours'    => $this->hours(),
                'username' => $this->username,
                'sitename' => $this->siteName,
            ),
        );
    }

    public function link(): string
    {
        return $this->link;
    }

    private function hours(): int
    {
        return (int) max(1, round($this->ttl / 3600));
    }
}
