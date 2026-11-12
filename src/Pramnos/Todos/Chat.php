<?php

declare(strict_types=1);

namespace Pramnos\Todos;

use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Inbox;
use Pramnos\Conversations\Messages;
use Pramnos\Conversations\People;

/**
 * The to-dos in the conversations: "Add to-do" and "Remind me about this" on a message, a to-do
 * written in the chat, and the chat's list ({@see Todos} keeps them).
 *
 * ```php
 * Chat::fromMessage($messageId, $reader);                       // the whole message, private, the reader's
 * Chat::fromMessage($messageId, $reader, preset: '1h');         // "Message from Maria: …", reminded in an hour
 * Chat::write($reader, "Call the supplier\n- the invoice", preset: 'tomorrow');
 * Chat::list($reader, filter: 'messages');                      // with where each came from, and when
 * ```
 *
 * The presets are +20 minutes, +1 hour, +3 hours, tomorrow at 9:00 and next Monday at 9:00, in the
 * reader's zone (the organisation's, as for a to-do's day).
 */
final class Chat
{
    /** The reminder presets, in the order offered. */
    public const PRESETS = ['20m', '1h', '3h', 'tomorrow', 'monday'];

    /** The list's filters. */
    public const FILTERS = ['all', 'messages', 'automatic', 'overdue'];

    /** How long an automatic item stays on the chat's list once closed. */
    public const AUTOMATIC_CLOSED = 600;

    /**
     * When a preset is, from now in a zone.
     *
     * @throws \InvalidArgumentException For a preset there is not
     */
    public static function at(string $preset, ?int $now = null, ?\DateTimeZone $zone = null): int
    {
        $now   = $now ?? time();
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone($zone ?? \Pramnos\Metrics\Window::zoneOf(0));

        return match ($preset) {
            '20m'      => $now + 1200,
            '1h'       => $now + 3600,
            '3h'       => $now + 10800,
            'tomorrow' => $local->modify('+1 day')->setTime(9, 0)->getTimestamp(),
            'monday'   => $local->modify('next monday')->setTime(9, 0)->getTimestamp(),
            default    => throw new \InvalidArgumentException(t('A reminder is in 20 minutes, an hour, three hours, tomorrow or next Monday.')),
        };
    }

    /**
     * The presets as offered: key, label and time.
     *
     * @return list<array{key: string, label: string, at: int}>
     */
    public static function presets(?int $now = null): array
    {
        $labels = ['20m' => t('In 20 minutes'), '1h' => t('In 1 hour'), '3h' => t('In 3 hours'), 'tomorrow' => t('Tomorrow at 9:00'), 'monday' => t('Next Monday at 9:00')];

        return array_map(static fn (string $key): array => ['key' => $key, 'label' => $labels[$key], 'at' => self::at($key, $now)], self::PRESETS);
    }

    /**
     * A to-do from a message the reader can read: "Add to-do" — the whole message, private, the
     * reader's — or, with a reminder, "Remind me about this": "Message from X: …", reminded then.
     *
     * @return array<string, mixed> The item, as the list gives it
     * @throws \DomainException For a message not there or not the reader's to read
     */
    public static function fromMessage(int $messageId, object $reader, ?string $preset = null, ?int $remindAt = null): array
    {
        $message = Messages::get($messageId);
        if ($message === null || $message['deletedAt'] !== null || !Conversations::canRead($message['conversationId'], $reader)) {
            throw new \DomainException(t('That message no longer exists.'));
        }
        $remind = $preset !== null && $preset !== '' ? self::at($preset) : ($remindAt ?: null);
        $body   = trim($message['body']) !== '' ? $message['body'] : (string) ($message['card']['title'] ?? $message['card']['text'] ?? t('A message'));
        $writer = $message['authorId'] !== null ? (People::names([$message['authorId']])[$message['authorId']] ?? '') : '';
        $title  = $remind !== null && $writer !== '' ? sprintf(t('Message from %s: %s'), $writer, $body) : $body;
        $id     = Todos::add(['title' => mb_substr($title, 0, Todos::TITLE), 'link' => '', 'private' => true, 'remind_at' => $remind,
            'messageid' => $message['id'], 'conversationid' => $message['conversationId']], (int) $reader->userid);

        return self::present([Todos::get($id)], (int) $reader->userid)[0];
    }

    /**
     * A to-do written in the chat: several lines, Markdown, emoji and mentions, up to 4000
     * characters — the writer's, with a reminder chosen before it is added.
     *
     * @return array<string, mixed>
     */
    public static function write(object $reader, string $text, ?string $preset = null, ?int $remindAt = null): array
    {
        $remind = $preset !== null && $preset !== '' ? self::at($preset) : ($remindAt ?: null);
        // The text as written: its address stays in it, the link taken from it apart.
        $link   = preg_match('~https?://\S+~u', $text, $m) === 1 && mb_strlen($m[0]) <= Todos::LINK ? $m[0] : null;
        $id     = Todos::add(['title' => mb_substr(trim($text), 0, Todos::TITLE), 'link' => $link, 'remind_at' => $remind], (int) $reader->userid);

        return self::present([Todos::get($id)], (int) $reader->userid)[0];
    }

    /**
     * The chat's list: the reader's items and the unassigned they wrote; automatic items while
     * open and for {@see AUTOMATIC_CLOSED} seconds after they close. `filter`: all, messages,
     * automatic, overdue; `order`: `when` (soonest first, none last) or `newest`.
     *
     * @return list<array<string, mixed>>
     */
    public static function list(object $reader, string $filter = 'all', string $order = 'when', ?int $now = null): array
    {
        $now  = $now ?? time();
        $me   = (int) $reader->userid;
        $mine = array_filter(Todos::list(0, $me, $now), static function (array $i) use ($me, $now): bool {
            if ($i['kind'] !== 'own') {
                return $i['assigned_to'] === $me || $i['assigned_to'] === null
                    ? ($i['closed_at'] === null || $now - $i['closed_at'] < self::AUTOMATIC_CLOSED) : false;
            }

            return $i['assigned_to'] === $me || ($i['assigned_to'] === null && $i['created_by'] === $me);
        });
        $open    = static fn (array $i): bool => in_array($i['status'], [Todos::OPEN, Todos::IN_PROGRESS], true);
        $when    = static fn (array $i): ?int => $i['remind_at'] ?? $i['due_at'];
        $matches = match ($filter) {
            'messages'  => static fn (array $i): bool => $i['messageid'] !== null,
            'automatic' => static fn (array $i): bool => $i['kind'] !== 'own',
            'overdue'   => static fn (array $i): bool => $open($i) && $when($i) !== null && $when($i) < $now,
            default     => static fn (array $i): bool => true,
        };
        $items = array_values(array_filter($mine, $matches));
        usort($items, static fn (array $a, array $b): int => [$open($a) ? 0 : 1, ...($order === 'newest' ? [-$a['created_at'], -$a['id']] : [$when($a) ?? PHP_INT_MAX, $a['created_at']])]
            <=> [$open($b) ? 0 : 1, ...($order === 'newest' ? [-$b['created_at'], -$b['id']] : [$when($b) ?? PHP_INT_MAX, $b['created_at']])]);

        return self::present($items, $me, $now);
    }

    /**
     * Which of these messages the reader has an open to-do from: the "✓ to-do added" mark.
     *
     * @param list<int> $among
     * @return list<int>
     */
    public static function messageIds(int $reader, array $among): array
    {
        $among = array_values(array_filter(array_map('intval', $among)));
        // Asked on every conversation opened: where there is no to-do list, nothing is asked of
        // the database, rather than a query that fails and is logged each time.
        if ($among === [] || !Todos::offers('chat')) {
            return [];
        }
        try {
            $rows = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table(Todos::TABLE)->select(['messageid'])->whereIn('messageid', $among)
                ->where('created_by', $reader)->whereIn('status', [Todos::OPEN, Todos::IN_PROGRESS])->getAllOrFail();
        } catch (\Throwable) {
            return [];   // no to-do table here
        }

        return array_values(array_unique(array_map(static fn (array $r): int => (int) $r['messageid'], $rows)));
    }

    /**
     * "Move to saved": a message's to-do becomes the message saved for later, and goes.
     *
     * @throws \InvalidArgumentException For an item that is not the reader's, or not from a message
     */
    public static function toSaved(int $todoId, object $reader): void
    {
        $item = Todos::get($todoId);
        if ($item === null || $item['messageid'] === null || $item['created_by'] !== (int) $reader->userid) {
            throw new \InvalidArgumentException(t('There is no such to-do.'));
        }
        Inbox::save($item['messageid'], (int) $reader->userid);
        Todos::delete($todoId, (int) $reader->userid);
    }

    /**
     * Items as the chat shows them: where each came from — "Maria in #general", "Direct with
     * Maria" — and `when`, its reminder else its day, with `overdue` once that has passed.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function present(array $items, int $reader, ?int $now = null): array
    {
        $now      = $now ?? time();
        $messages = [];
        foreach (array_filter(array_column($items, 'messageid')) as $id) {
            $messages[(int) $id] = Messages::get((int) $id);
        }
        $people = People::names(array_values(array_filter(array_map(static fn (?array $m): ?int => $m['authorId'] ?? null, $messages))));
        $places = [];

        return array_map(static function (array $i) use ($messages, $people, &$places, $reader, $now): array {
            $from = null;
            $msg  = $i['messageid'] !== null ? ($messages[$i['messageid']] ?? null) : null;
            if ($msg !== null) {
                $c      = $places[$msg['conversationId']] ??= Conversations::get($msg['conversationId']);
                $writer = $people[$msg['authorId'] ?? 0] ?? t('System message');
                $from   = match ($c['kind'] ?? '') {
                    'dm'    => sprintf(t('Direct with %s'), $writer),
                    'channel', 'system' => sprintf(t('%s in #%s'), $writer, (string) ($c['slug'] ?? $c['name'] ?? '')),
                    default => sprintf(t('%s in %s'), $writer, (string) ($c['name'] ?? t('a group'))),
                };
            }
            $when = $i['remind_at'] ?? $i['due_at'];
            $open = in_array($i['status'], [Todos::OPEN, Todos::IN_PROGRESS], true);

            return $i + ['from' => $from, 'when' => $when, 'overdue' => $open && $when !== null && $when < $now,
                'editable' => $i['kind'] === 'own' && $i['messageid'] === null && $i['created_by'] === $reader];
        }, $items);
    }
}
