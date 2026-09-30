<?php

declare(strict_types=1);

namespace Pramnos\Auth\Notifications;

use Pramnos\Notification\NotificationInterface;

/**
 * "Trying to sign in?" — the push a trusted phone receives when a sign-in waits on it.
 *
 * What the lock screen has room for: which browser, where, when. Tapping it opens the page
 * that answers ({@see \Pramnos\Auth\PushApprovals::APPROVE_PATH}): approving means picking the
 * number the waiting screen shows, and that takes a page. The one button the lock screen gets
 * is **No** — refusing needs no number, and a one-tap Yes is what prompt bombing counts on.
 *
 * `ack` is where the service worker reports that the push arrived, before it even shows it:
 * the waiting screen says "delivered" from that, and suggests another way when it never
 * comes.
 *
 * Push only. A mailed copy would arrive after the two minutes it can be answered in.
 */
class SignInApprovalNotification implements NotificationInterface
{
    public function __construct(
        private string $browser,
        private string $country,
        private int $when,
        private string $answerUrl,
        private string $ackUrl,
        private string $respondUrl = ''
    ) {
    }

    public function via(mixed $notifiable): array
    {
        return ['push'];
    }

    /** @return array<string, mixed> */
    public function toPush(mixed $notifiable): array
    {
        $where = $this->country !== '' ? ' · ' . $this->country : '';
        $data  = ['ack' => $this->ackUrl, 'open' => true];
        $push  = [
            'title' => t('Trying to sign in?'),
            'body'  => $this->browser . $where . ' · ' . date('H:i', $this->when),
            'url'   => $this->answerUrl,
            // One prompt at a time on the lock screen: a second ask replaces the first.
            'tag'   => 'signin-approval',
        ];

        if ($this->respondUrl !== '') {
            $push['actions'] = [['action' => 'deny', 'title' => t('No, it’s not me')]];
            $data['actions'] = ['deny' => ['post' => $this->respondUrl . '&decision=denied']];
        }

        $push['data'] = $data;

        return $push;
    }
}
