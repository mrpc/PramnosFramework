<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

use Pramnos\Changelog\ChangelogWriter;

/**
 * A conversation's canvases: documents of rich text beside its messages, as Slack's — a title and
 * a body with headings, lists, a checklist, links, pictures from the conversation's files and
 * mentions of people.
 *
 * ```php
 * $id = Canvases::create($conversation, 'Launch plan', $by);       // and a tab for it
 * Canvases::lock($id, $by);                                        // "Maria is editing"; again every 20 s
 * Canvases::save($id, 'Launch plan', '<h1>Plan</h1><ul data-type="taskList">…</ul>', $by);
 * Canvases::unlock($id, $by);
 * Canvases::versions($id);                                         // who saved which, when
 * Canvases::restore($id, 3, $by);                                  // version 3 is the newest again
 * ```
 *
 * - **One editor at a time.** Whoever edits holds the lock and keeps it with a heartbeat
 *   ({@see lock()} every {@see HEARTBEAT} seconds). Everyone else reads, told who is editing. A lock
 *   whose heartbeat stopped for {@see LOCK} seconds is free. Writing at the same time (CRDT) is not
 *   offered.
 * - **Versions.** Each save is a version — its title, body, who and when — kept as a
 *   `canvas.saved` event in the changelog; {@see restore()} saves an old one as the newest.
 * - **Mentions** (`<span data-type="mention" data-id="5">`) notify the person once: a save tells
 *   only who was not mentioned before.
 * - **Who:** those who read the conversation read its canvases; its members edit them; its
 *   owner, admins and moderators, and the canvas's author, delete one.
 * - **Live:** `canvas.changed` (saved, locked, unlocked, deleted) on the conversation's channel.
 */
final class Canvases
{
    /** Seconds after the last heartbeat the lock is free. */
    public const LOCK = 45;

    /** Seconds between an editor's heartbeats. */
    public const HEARTBEAT = 20;

    /** The longest body, in bytes. */
    public const BODY = 500000;

    /** What a canvas's body keeps beyond a message's markup: a checklist, mentions, code, rules, a heading 1, pictures with captions. */
    private const MARKUP = [
        'h1' => [], 'ul' => ['data-type'], 'li' => ['data-type', 'data-checked'], 'span' => ['data-type', 'data-id', 'data-label'],
        'code' => [], 'pre' => [], 's' => [], 'hr' => [], 'p' => [], 'figure' => [], 'figcaption' => [], 'img' => ['src', 'alt'],
    ];

    /**
     * Make a canvas in a conversation, and a tab for it.
     *
     * @throws \DomainException For somebody who does not write in it
     */
    public static function create(int $conversation, string $title, int $by): int
    {
        self::writer($conversation, $by);
        $title = self::title($title);
        $now   = time();
        $db    = Conversations::db();
        $db->queryBuilder()->table('chat.conversation_canvases')->insert(['conversationid' => $conversation, 'title' => $title, 'body' => '', 'version' => 0,
            'createdby' => $by, 'createdat' => $now, 'updatedby' => $by, 'updatedat' => $now]);
        $id = (int) $db->getInsertId();
        ChangelogWriter::event('canvas', $id, 'canvas.created', ['conversation' => $conversation, 'title' => $title], by: $by);
        try {
            Tabs::add($conversation, 'canvas', $by, canvas: $id);
        } catch (\DomainException) {
            // Tabs managed by its admins: the canvas is there, under no tab until one adds it.
        }
        self::told($conversation, $id, 'created', $by);

        return $id;
    }

    /**
     * A canvas, as it is now, with who is editing it.
     *
     * @return array{id: int, conversationId: int, title: string, body: string, version: int, createdBy: ?int, createdAt: int, updatedBy: ?int, updatedAt: int, lockedBy: ?int, lockedAt: ?int}|null
     */
    public static function get(int $id): ?array
    {
        $row = self::table()->where('canvasid', $id)->limit(1)->getAllOrFail()[0] ?? null;

        return $row === null ? null : self::row($row);
    }

    /**
     * A conversation's canvases, newest changed first, without their bodies.
     *
     * @return list<array<string, mixed>>
     */
    public static function of(int $conversation): array
    {
        return array_map(static fn (array $r): array => array_diff_key(self::row($r), ['body' => 0]),
            self::table()->where('conversationid', $conversation)->orderBy('updatedat', 'desc')->getAllOrFail());
    }

    /**
     * Take, or keep, the lock to edit: true when it is the person's now; false while another's
     * heartbeat is alive. Called again every {@see HEARTBEAT} seconds while editing.
     */
    public static function lock(int $id, int $by, ?int $now = null): bool
    {
        $canvas = self::readable($id);
        self::writer($canvas['conversationId'], $by);
        $now    = $now ?? time();
        $taken  = self::table()->where('canvasid', $id)
            ->where(static fn ($q) => $q->whereNull('lockedby')->orWhere('lockedby', $by)->orWhere('lockedat', '<', $now - self::LOCK))
            ->update(['lockedby' => $by, 'lockedat' => $now]);
        $mine = is_object($taken) && method_exists($taken, 'getAffectedRows') ? (int) $taken->getAffectedRows() > 0 : (bool) $taken;
        // MySQL counts an update that changed nothing as none: the same second's heartbeat.
        $mine = $mine || (self::get($id)['lockedBy'] ?? null) === $by;
        if ($mine && $canvas['lockedBy'] !== $by) {
            self::told($canvas['conversationId'], $id, 'locked', $by);
        }

        return $mine;
    }

    /** Let the lock go, if it is the person's. */
    public static function unlock(int $id, int $by): void
    {
        $canvas = self::readable($id);
        if ($canvas['lockedBy'] === $by) {
            self::table()->where('canvasid', $id)->where('lockedby', $by)->update(['lockedby' => null, 'lockedat' => null]);
            self::told($canvas['conversationId'], $id, 'unlocked', $by);
        }
    }

    /**
     * Save: a new version, with the lock held. The body is cleaned to what a canvas carries; people
     * mentioned for the first time are told.
     *
     * @return int The version
     * @throws \DomainException Without the lock
     */
    public static function save(int $id, string $title, string $body, int $by, ?int $now = null): int
    {
        $canvas = self::readable($id);
        self::writer($canvas['conversationId'], $by);
        $now = $now ?? time();
        if ($canvas['lockedBy'] !== $by || (int) $canvas['lockedAt'] < $now - self::LOCK) {
            if (!self::lock($id, $by, $now)) {
                throw new \DomainException(sprintf(t('%s is editing this canvas.'), People::names([(int) $canvas['lockedBy']])[(int) $canvas['lockedBy']] ?? ''));
            }
        }
        if (strlen($body) > self::BODY) {
            throw new \InvalidArgumentException(t('A canvas is at most %s KB.', (string) intdiv(self::BODY, 1000)));
        }
        $title   = self::title($title);
        $clean   = \Pramnos\Html\SafeHtml::clean($body, self::MARKUP, true);
        $version = $canvas['version'] + 1;
        self::table()->where('canvasid', $id)->update(['title' => $title, 'body' => $clean, 'version' => $version, 'updatedby' => $by, 'updatedat' => $now,
            'lockedat' => $now]);
        ChangelogWriter::event('canvas', $id, 'canvas.saved', ['version' => $version, 'title' => $title, 'body' => $clean], by: $by);
        self::mention($canvas, array_diff(self::mentioned($clean), self::mentioned($canvas['body']), [$by]), $title, $by);
        self::told($canvas['conversationId'], $id, 'saved', $by);

        return $version;
    }

    /**
     * Its versions, newest first: the number, who and when.
     *
     * @return list<array{version: int, by: ?int, at: int, title: string}>
     */
    public static function versions(int $id): array
    {
        self::readable($id);
        $out = [];
        foreach (\Pramnos\Changelog\ChangelogReader::history('canvas', $id, ['events'], 200) as $event) {
            if (($event['event'] ?? null) !== 'canvas.saved') {
                continue;
            }
            $details = is_array($event['details'] ?? null) ? $event['details'] : (array) json_decode((string) ($event['details'] ?? ''), true);
            $out[]   = ['version' => (int) ($details['version'] ?? 0), 'by' => isset($event['userid']) ? (int) $event['userid'] : null,
                'at' => (int) strtotime((string) ($event['created_at'] ?? '')), 'title' => (string) ($details['title'] ?? '')];
        }
        // By its number: two saves in one second keep no order of their own.
        usort($out, static fn (array $a, array $b): int => $b['version'] <=> $a['version']);

        return $out;
    }

    /**
     * One version's title and body, as it was saved.
     *
     * @return array{version: int, title: string, body: string}
     * @throws \InvalidArgumentException For a version there is not
     */
    public static function version(int $id, int $version): array
    {
        self::readable($id);
        foreach (\Pramnos\Changelog\ChangelogReader::history('canvas', $id, ['events'], 200) as $event) {
            $details = is_array($event['details'] ?? null) ? $event['details'] : (array) json_decode((string) ($event['details'] ?? ''), true);
            if (($event['event'] ?? null) === 'canvas.saved' && (int) ($details['version'] ?? 0) === $version) {
                return ['version' => $version, 'title' => (string) ($details['title'] ?? ''), 'body' => (string) ($details['body'] ?? '')];
            }
        }
        throw new \InvalidArgumentException(t('There is no such version.'));
    }

    /** An old version saved as the newest. @return int The new version */
    public static function restore(int $id, int $version, int $by): int
    {
        $old = self::version($id, $version);

        return self::save($id, $old['title'], $old['body'], $by);
    }

    /** Delete a canvas: its author, or whoever manages the conversation. Its versions stay in the changelog. */
    public static function delete(int $id, int $by): void
    {
        $canvas = self::readable($id);
        if ($canvas['createdBy'] !== $by && !Access::moderates(new \Pramnos\User\User($by), $canvas['conversationId'])) {
            throw new \DomainException(t('Only its author, or an admin of the conversation, deletes a canvas.'));
        }
        self::table()->where('canvasid', $id)->delete();
        Tabs::forgetCanvas($canvas['conversationId'], $id, $by);
        ChangelogWriter::event('canvas', $id, 'canvas.deleted', ['title' => $canvas['title']], by: $by);
        self::told($canvas['conversationId'], $id, 'deleted', $by);
    }

    /**
     * The canvases a reader reads holding every word of `q`, in the title or the text.
     *
     * @return list<array{id: int, conversationId: int, title: string, excerpt: string, updatedAt: int}>
     */
    public static function search(object $reader, string $q, int $limit = 20): array
    {
        $words = array_values(array_filter(preg_split('/\s+/u', trim($q)) ?: [], static fn (string $w): bool => mb_strlen($w) > 1));
        if ($words === [] || !Access::canUse($reader)) {
            return [];
        }
        $query = self::table()->orderBy('updatedat', 'desc')->limit(max(1, min(50, $limit)));
        if (!Access::readsAll($reader)) {
            $readable = Conversations::readable((int) $reader->userid);
            $query->whereIn('conversationid', $readable === [] ? [0] : $readable);
        }
        foreach ($words as $word) {
            $like = '%' . addcslashes($word, '%_\\') . '%';
            $query->where(static fn ($w) => $w->whereLike('title', $like)->whereLike('body', $like, 'or'));
        }
        try {
            $rows = $query->getAllOrFail();
        } catch (\Throwable) {
            return [];   // no canvases table here
        }

        return array_map(static function (array $r) use ($words): array {
            $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags(str_replace('<', ' <', (string) $r['body']))));
            $at   = mb_stripos($text, $words[0]);
            $from = $at === false ? 0 : max(0, $at - 60);

            return ['id' => (int) $r['canvasid'], 'conversationId' => (int) $r['conversationid'], 'title' => (string) $r['title'],
                'excerpt' => mb_substr($text, $from, 160), 'updatedAt' => (int) $r['updatedat']];
        }, $rows);
    }

    /**
     * The canvas, for whoever reads its conversation.
     *
     * @return array<string, mixed>
     * @throws \DomainException
     */
    public static function readable(int $id, ?object $reader = null): array
    {
        $canvas = self::get($id) ?? throw new \DomainException(t('That canvas no longer exists.'));
        if ($reader !== null && !Conversations::canRead($canvas['conversationId'], $reader)) {
            throw new \DomainException(t('That canvas no longer exists.'));
        }

        return $canvas;
    }

    /** @throws \DomainException For somebody who does not write in the conversation */
    private static function writer(int $conversation, int $by): void
    {
        $c = Conversations::get($conversation);
        if ($c === null || $c['archivedAt'] !== null || Conversations::member($conversation, $by) === null) {
            throw new \DomainException(t('Only its members edit a conversation\'s canvases.'));
        }
    }

    /**
     * Tell the newly mentioned, once each: in the bell and by push, linked to the canvas.
     *
     * @param array<string, mixed> $canvas
     * @param array<int> $people
     */
    private static function mention(array $canvas, array $people, string $title, int $by): void
    {
        $who = People::names([$by])[$by] ?? '';
        foreach (array_unique($people) as $person) {
            try {
                $user = new \Pramnos\User\User((int) $person);
                if ((int) ($user->userid ?? 0) === (int) $person && Conversations::canRead($canvas['conversationId'], $user)) {
                    $user->notify(new CanvasMention($canvas['id'], $canvas['conversationId'], sprintf(t('%s mentioned you in “%s”'), $who, $title)));
                }
            } catch (\Throwable $e) {
                \Pramnos\Logs\Logger::log('canvas ' . $canvas['id'] . ': ' . $person . ' not told: ' . $e->getMessage(), 'conversations');
            }
        }
    }

    /** Who a body mentions, by account id. @return list<int> */
    private static function mentioned(string $body): array
    {
        preg_match_all('~data-type="mention"[^>]*data-id="(\d+)"|data-id="(\d+)"[^>]*data-type="mention"~', $body, $m);

        return array_values(array_unique(array_map('intval', array_filter(array_merge($m[1], $m[2])))));
    }

    /** A canvas changed, on its conversation's channel. */
    private static function told(int $conversation, int $id, string $what, int $by): void
    {
        Realtime::canvas($conversation, ['canvasId' => $id, 'what' => $what, 'by' => $by]);
    }

    /** A title, or "Untitled". */
    private static function title(string $title): string
    {
        $title = mb_substr(trim($title), 0, 150);

        return $title !== '' ? $title : t('Untitled');
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private static function row(array $r): array
    {
        $int = static fn ($v): ?int => $v === null ? null : (int) $v;

        return ['id' => (int) $r['canvasid'], 'conversationId' => (int) $r['conversationid'], 'title' => (string) $r['title'], 'body' => (string) ($r['body'] ?? ''),
            'version' => (int) $r['version'], 'createdBy' => $int($r['createdby']), 'createdAt' => (int) $r['createdat'], 'updatedBy' => $int($r['updatedby']),
            'updatedAt' => (int) $r['updatedat'], 'lockedBy' => $int($r['lockedby']), 'lockedAt' => $int($r['lockedat'])];
    }

    /** The canvases table. */
    private static function table(): \Pramnos\Database\QueryBuilder
    {
        return Conversations::db()->queryBuilder()->table('chat.conversation_canvases');
    }
}
