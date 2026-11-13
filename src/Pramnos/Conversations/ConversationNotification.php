<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

use Pramnos\Notification\NotificationInterface;

/**
 * A message somebody should see ({@see Notify}): in their bell, and as a push where they have a
 * browser subscribed — titled with who wrote it, or the channel, and its first words; opening
 * the conversation at the message. A conversation's pushes replace one another rather than stack.
 *
 * `'conversations' => ['notification' => …]` names a class of the application's own, constructed
 * with the same arguments — the message, why, and where it opens; `'conversations' => ['notify_via' => ['database']]` keeps them to
 * the bell. `'push' => ['hold_unopened' => 'mobile'|'all']` holds a push off a device that
 * already has an earlier one of these sitting unopened ({@see heldFor()}, FW-495) — a phone
 * buried under unopened notifications is how Chrome on Android ends up revoking the permission.
 */
class ConversationNotification implements NotificationInterface
{
    /** @var array{title: string, text: string, url: string, conversationId: int}|null The message, as it is told */
    private ?array $told = null;

    /**
     * @param int    $messageId The message
     * @param string      $why       direct | mention | thread | message | nudge ({@see Notify::recipients()})
     * @param string|null $page      Where it opens, as the request that wrote it said ({@see Notify::page()})
     */
    public function __construct(public readonly int $messageId, public readonly string $why, public readonly ?string $page = null)
    {
    }

    /**
     * The bell, and a push where the person has a browser subscribed and push is switched on — of
     * those, the ones `conversations.notify_via` names.
     *
     * No push while the client the person is in is in front ({@see Clients::inFront()}): it tells
     * of the message itself, from the realtime connection. A push its worker then had to keep from
     * showing would be one the browser counts against the subscription — Chrome on Android cancels
     * a subscription after a few. Nor while their desktop application runs and they are at it
     * ({@see Clients::appRunning()}): it notifies them, and a push would open the browser instead.
     *
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        $allowed = Notify::config('notify_via');
        $allowed = is_array($allowed) ? $allowed : ['database', 'push'];
        $person  = (int) ($notifiable->userid ?? 0);
        $push    = in_array('push', $allowed, true) && \Pramnos\Notification\Channels\PushChannel::sends()
            && \Pramnos\Push\Subscriptions::exist($person) && !Clients::inFront($person) && !Clients::appRunning($person);

        return array_values(array_filter(['database', $push ? 'push' : ''], static fn (string $via): bool => $via !== '' && in_array($via, $allowed, true)));
    }

    /**
     * The client to push to — the one the person was last active in — or null for every one
     * ({@see Clients::target()}); a bell is told regardless.
     */
    public function pushClient(mixed $notifiable): ?string
    {
        return Clients::target((int) ($notifiable->userid ?? 0));
    }

    /**
     * FW-495: whether an earlier conversation push on this one browser is still sitting there
     * unopened and undismissed, so this message must not pile another on top of it.
     *
     * `'push' => ['hold_unopened' => 'mobile'|'all'|false]`, off by default. Unlocked by opening
     * or dismissing the held one, by the person doing anything on any client since it was sent
     * ({@see Clients::activeSince()}), or by having caught up on *its own* conversation — read
     * as "nothing unread there any more", not the exact message it was about: a held push from a
     * different conversation is not cleared by reading this one.
     *
     * Reminders and anything else outside this rule simply do not implement this method — it is
     * read with `method_exists()`, never assumed.
     *
     * @param array<string, mixed> $subscription One row of {@see \Pramnos\Push\Subscriptions::forUser()}
     */
    public function heldFor(array $subscription, int $userId): bool
    {
        $mode = self::holdMode();
        if ($mode === false || ($mode === 'mobile' && !self::isMobile((string) ($subscription['user_agent'] ?? '')))) {
            return false;
        }
        $hash = (string) ($subscription['endpoint_hash'] ?? hash('sha256', (string) ($subscription['endpoint'] ?? '')));
        $held = \Pramnos\Push\Log::unresolved($hash, static::class);
        if ($held === null || Clients::activeSince($userId, $held['sent'])) {
            return false;
        }

        return !self::caughtUpOn($held['tag'], $userId);
    }

    /** Whether the person has nothing unread left in the conversation a held push's tag names. */
    private static function caughtUpOn(string $tag, int $userId): bool
    {
        if (preg_match('/^conversation-(\d+)$/', $tag, $m) !== 1) {
            // Not a conversation's own tag (an application's own notification class, say):
            // nothing here knows how to tell "caught up", so only opening/dismissing/activity clears it.
            return false;
        }
        $conversationId = (int) $m[1];
        $lastMessageId  = Conversations::get($conversationId)['lastMessageId'] ?? null;
        $member         = Conversations::member($conversationId, $userId);

        return $lastMessageId === null || $member === null || !Messages::isAfter((int) $lastMessageId, $member['lastReadId']);
    }

    /** `'push' => ['hold_unopened' => …]`: `'mobile'`, `'all'`, or `false` (off, the default) for anything else. */
    private static function holdMode(): string|false
    {
        $app   = \Pramnos\Application\Application::currentInstance();
        $value = is_object($app) ? ($app->applicationInfo['push']['hold_unopened'] ?? false) : false;

        return in_array($value, ['mobile', 'all'], true) ? $value : false;
    }

    /**
     * A phone or tablet, by its subscription's user agent.
     *
     * ponytail: a user-agent sniff, not a client hint — good enough to tell a phone from a
     * desktop for this; add `navigator.userAgentData.mobile` sent at subscribe time if it ever
     * needs to be exact.
     */
    private static function isMobile(string $userAgent): bool
    {
        return preg_match('/Mobi|Android|iPhone|iPad|iPod/i', $userAgent) === 1;
    }

    /** @return array{message: string, url: string, group: string, about: list<string>} */
    public function toDatabase(mixed $notifiable): array
    {
        $told = $this->told();

        return ['message' => $told['title'] . ': ' . $told['text'], 'url' => $told['url'], 'group' => 'conversations',
            // Settled when its conversation is read; a nudge remembered, so it is sent once.
            'about' => array_merge(['conversation:' . $told['conversationId']], $this->why === 'nudge' ? ['nudge:' . $this->messageId] : [])];
    }

    /** @return array{title: string, body: string, url: string, tag: string, data: array<string, int>} */
    public function toPush(mixed $notifiable): array
    {
        $told = $this->told();

        // Quiet while a page of the site is open, which plays the conversations' own sound; with none
        // open — a phone, mostly — the system's sound (`conversations.push_silent`: ifOpen, always,
        // never). Never `unlessFocused`: a client in front gets no push at all (via()), and any other
        // page in front is not the one told of the message, so it must not silence it.
        $silent = match ((string) (Conversations::settings()['push_silent'] ?? 'ifOpen')) {
            'always' => true,
            'never'  => false,
            default  => 'ifOpen',
        };

        return ['title' => mb_substr($told['title'], 0, 90), 'body' => $told['text'], 'url' => $told['url'], 'tag' => 'conversation-' . $told['conversationId'],
            'silent' => $silent,
            'data' => ['conversationId' => $told['conversationId'], 'messageId' => $this->messageId]];
    }

    /**
     * Who wrote it — or, outside a direct conversation, where — and its first words, and the
     * link to it.
     *
     * @return array{title: string, text: string, url: string, conversationId: int}
     */
    private function told(): array
    {
        if ($this->told !== null) {
            return $this->told;
        }
        $message      = Messages::get($this->messageId) ?? throw new \InvalidArgumentException(t('That message no longer exists.'));
        $conversation = Conversations::get($message['conversationId']) ?? [];
        preg_match_all('/<@(\d+)>/', $message['body'], $m);
        $names  = People::names(array_values(array_unique(array_merge(array_map('intval', $m[1]), array_filter([$message['authorId']])))));
        $author = $message['authorId'] === null ? (string) ($message['card']['from'] ?? '') : ($names[$message['authorId']] ?? '');
        $text   = Messages::plain($message['body'], $names, 180);
        $text   = $text !== '' ? $text : t('Sent a file');
        $direct = ($conversation['kind'] ?? '') === 'dm';
        $where  = $conversation['displayName'] ?? ($conversation['name'] ?? '');
        $page   = $this->page ?? Notify::page();

        return $this->told = [
            'title' => $direct || $where === '' ? $author : (($conversation['kind'] ?? '') === 'group' ? $where : '#' . $where),
            'text'  => $direct || $where === '' || $author === '' ? $text : $author . ': ' . $text,
            'url'   => $page . '#/c/' . $message['conversationId'] . '/m/' . $this->messageId,
            'conversationId' => $message['conversationId'],
        ];
    }
}
