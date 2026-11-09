<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

use Pramnos\Notification\NotificationInterface;

/**
 * Somebody mentioned in a canvas ({@see Canvases}): in the bell, and as a push where they have
 * one, linked to the canvas in its conversation. Told once per person, when first mentioned.
 */
final class CanvasMention implements NotificationInterface
{
    public function __construct(public readonly int $canvasId, public readonly int $conversationId, public readonly string $message)
    {
    }

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        $push = \Pramnos\Notification\Channels\PushChannel::sends() && \Pramnos\Push\Subscriptions::exist((int) ($notifiable->userid ?? 0));

        return $push ? ['database', 'push'] : ['database'];
    }

    /** @return array{message: string, url: string, group: string, about: list<string>} */
    public function toDatabase(mixed $notifiable): array
    {
        return ['message' => $this->message, 'url' => $this->url(), 'group' => 'conversations', 'about' => ['canvas:' . $this->canvasId]];
    }

    /** @return array{title: string, body: string, url: string, tag: string} */
    public function toPush(mixed $notifiable): array
    {
        return ['title' => t('Canvas'), 'body' => $this->message, 'url' => $this->url(), 'tag' => 'canvas-' . $this->canvasId];
    }

    /** The canvas in its conversation. */
    private function url(): string
    {
        return adminUrl('Conversations') . '#/c/' . $this->conversationId . '/canvas/' . $this->canvasId;
    }
}
