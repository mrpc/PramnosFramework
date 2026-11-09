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
 * the bell.
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
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        $allowed = Notify::config('notify_via');
        $allowed = is_array($allowed) ? $allowed : ['database', 'push'];
        $push    = in_array('push', $allowed, true) && \Pramnos\Notification\Channels\PushChannel::sends()
            && \Pramnos\Push\Subscriptions::exist((int) ($notifiable->userid ?? 0));

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
        // never). Not shown while a page of the site is in front: it tells of the message itself.
        $silent = match ((string) (Conversations::settings()['push_silent'] ?? 'ifOpen')) {
            'always' => true,
            'never'  => false,
            default  => 'ifOpen',
        };

        return ['title' => mb_substr($told['title'], 0, 90), 'body' => $told['text'], 'url' => $told['url'], 'tag' => 'conversation-' . $told['conversationId'],
            'silent' => $silent, 'unlessFocused' => true,
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
