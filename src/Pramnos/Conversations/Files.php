<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

use Pramnos\Database\Expression;

/**
 * The files sent in the conversations: each a file on disk, under a tree of its own, never in the
 * database, which keeps its path relative to the tree — so the tree can move to other storage
 * behind a symlink, and nothing in the database changes.
 *
 * ```php
 * $file = Files::store($general, $me, $_FILES['file']['tmp_name'], $_FILES['file']['name']);
 * Messages::post($general, $me, 'Το τιμολόγιο', ['files' => [$file]]);
 * Files::send($file, $user);                       // to a reader: the file, never a public link
 * Files::usage();                                  // bytes per conversation and month, the largest, the oldest
 * Files::prune(365, $me);                          // free the space of old ones; their messages say so
 * ```
 *
 * | setting | where | default |
 * |---|---|---|
 * | the tree | `'conversations' => ['files_path' => …]` | `var/chat-files` |
 * | the largest file | the setting `conversations_max_file_mb`, else `'conversations' => ['max_file_mb' => …]` | 25 MB |
 *
 * Allowed: images, PDF, Office and OpenDocument, CSV, plain text and zip — by what the file is, not
 * what it is called; never a program. An image gets a thumbnail beside it.
 */
final class Files
{
    /** The largest file, in megabytes, when nothing says otherwise. */
    public const DEFAULT_MAX_MB = 25;

    /** The setting an administrator changes it with. */
    public const SETTING = 'conversations_max_file_mb';

    /** What may be sent, by type, with the extensions each may carry. */
    public const TYPES = [
        'image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/gif' => ['gif'], 'image/webp' => ['webp'],
        'application/pdf' => ['pdf'], 'text/plain' => ['txt', 'log', 'md'], 'text/csv' => ['csv'], 'application/csv' => ['csv'],
        'application/zip' => ['zip', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp'],
        'application/msword' => ['doc'], 'application/vnd.ms-excel' => ['xls', 'csv'], 'application/vnd.ms-powerpoint' => ['ppt'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        'application/vnd.oasis.opendocument.text' => ['odt'], 'application/vnd.oasis.opendocument.spreadsheet' => ['ods'],
        'application/vnd.oasis.opendocument.presentation' => ['odp'], 'application/x-zip-compressed' => ['zip'],
    ];

    /** The thumbnail's longest side, in pixels. */
    public const THUMB = 400;

    /**
     * Keep a file sent in a conversation; it is linked to its message when the message is written.
     *
     * @return int The file
     */
    public static function store(int $conversationId, int $by, string $tmp, string $name): int
    {
        if (Conversations::member($conversationId, $by) === null) {
            throw new \InvalidArgumentException(t('Only its members write in a conversation.'));
        }
        $size = is_file($tmp) ? (int) filesize($tmp) : 0;
        if ($size <= 0) {
            throw new \InvalidArgumentException(t('The file did not arrive.'));
        }
        if ($size > self::maxBytes()) {
            throw new \InvalidArgumentException(t('A file is at most %s MB.', (string) self::maxMegabytes()));
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $name = self::name($name);
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, self::TYPES[$mime] ?? [], true)) {
            throw new \InvalidArgumentException(t('Files of this kind are not sent here: images, PDF, Office documents, CSV, text and zip are.'));
        }
        $db  = Conversations::db();
        $now = time();
        $db->queryBuilder()->table('#PREFIX#conversation_files')->insert(['conversationid' => $conversationId, 'uploadedby' => $by, 'name' => $name,
            'path' => '', 'mime' => $mime, 'size' => $size, 'createdat' => $now]);
        $id       = (int) $db->getInsertId();
        $relative = date('Y/m', $now) . '/' . $id . '-' . $name;
        $absolute = self::root() . '/' . $relative;
        $dir = dirname($absolute);
        if (!(is_dir($dir) || @mkdir($dir, 0770, true) || is_dir($dir))
            || !(is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $absolute) : @rename($tmp, $absolute))) {
            // No row without its file: a message would point at nothing.
            $db->queryBuilder()->table('#PREFIX#conversation_files')->where('fileid', $id)->delete();
            throw new \RuntimeException('The file could not be kept under the conversations\' files directory: ' . $absolute);
        }
        $thumb = str_starts_with($mime, 'image/') ? self::thumbnail($absolute, $relative) : null;
        $db->queryBuilder()->table('#PREFIX#conversation_files')->where('fileid', $id)->update(['path' => $relative, 'thumbpath' => $thumb]);

        return $id;
    }

    /**
     * Link files to the message they were sent with — the sender's own, in its conversation, not
     * linked yet.
     *
     * @param list<int> $files
     */
    public static function attach(array $files, int $messageId, int $conversationId, ?int $by): void
    {
        if ($files === []) {
            return;
        }
        Conversations::db()->queryBuilder()->table('#PREFIX#conversation_files')->whereIn('fileid', array_map('intval', $files))
            ->where('conversationid', $conversationId)->where('uploadedby', $by)->whereNull('messageid')->update(['messageid' => $messageId]);
    }

    /**
     * A file, or null.
     *
     * @return array{id: int, messageId: ?int, conversationId: int, uploadedBy: ?int, name: string, path: string, thumbPath: ?string, mime: string, size: int, createdAt: int, removedAt: ?int}|null
     */
    public static function get(int $id): ?array
    {
        $row = Conversations::db()->queryBuilder()->table('#PREFIX#conversation_files')->where('fileid', $id)->limit(1)->getAllOrFail()[0] ?? null;

        return $row === null ? null : self::row($row);
    }

    /**
     * Where a file is on disk, for a reader of its conversation — or null: not theirs to read,
     * removed, or missing.
     */
    public static function pathFor(int $id, object $user, bool $thumbnail = false): ?string
    {
        $file = self::get($id);
        if ($file === null || $file['removedAt'] !== null || !Conversations::canRead($file['conversationId'], $user)) {
            return null;
        }
        $path = self::root() . '/' . ($thumbnail && $file['thumbPath'] !== null ? $file['thumbPath'] : $file['path']);

        return is_file($path) ? $path : null;
    }

    /**
     * Send a file to a reader: its own type, its name, never cached publicly; `inline` for what a
     * browser shows (an image, a PDF), as an attachment otherwise.
     *
     * @return bool Whether it was sent; false is a 404
     */
    public static function send(int $id, object $user, bool $thumbnail = false): bool
    {
        $path = self::pathFor($id, $user, $thumbnail);
        if ($path === null) {
            return false;
        }
        $file   = (array) self::get($id);
        $inline = str_starts_with($file['mime'], 'image/') || $file['mime'] === 'application/pdf';
        if (!headers_sent()) {
            header('Content-Type: ' . ($thumbnail && $file['thumbPath'] !== null ? 'image/jpeg' : $file['mime']));
            header('Content-Length: ' . (string) filesize($path));
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($file['name']));
            header('Cache-Control: private, max-age=86400');
            header('X-Content-Type-Options: nosniff');
        }
        readfile($path);

        return true;
    }

    /**
     * The files sent with messages, oldest first, as a message carries them.
     *
     * @param list<int> $messageIds
     * @return list<array{id: int, messageId: int, conversationId: int, name: string, mime: string, size: int, thumbnail: bool, removedAt: ?int}>
     */
    public static function forMessages(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        return array_map(static fn (array $r): array => ['id' => (int) $r['fileid'], 'messageId' => (int) $r['messageid'],
            'conversationId' => (int) $r['conversationid'], 'name' => (string) $r['name'], 'mime' => (string) $r['mime'], 'size' => (int) $r['size'],
            'thumbnail' => $r['thumbpath'] !== null, 'removedAt' => $r['removedat'] === null ? null : (int) $r['removedat']],
            Conversations::db()->queryBuilder()->table('#PREFIX#conversation_files')->whereIn('messageid', $messageIds)->orderBy('fileid')->getAllOrFail());
    }

    /**
     * The files sent in a conversation and still kept, newest first — what its header lists.
     *
     * @return list<array<string, mixed>>
     */
    public static function inConversation(int $conversationId, int $limit = 100): array
    {
        return array_map(self::row(...), Conversations::db()->queryBuilder()->table('#PREFIX#conversation_files')->where('conversationid', $conversationId)
            ->whereNotNull('messageid')->whereNull('removedat')->orderBy('fileid', 'desc')->limit(max(1, min(500, $limit)))->getAllOrFail());
    }

    /**
     * The files somebody sent, still kept, in the conversations a reader reads — newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function sentBy(int $person, object $reader, int $limit = 50): array
    {
        if (!Access::canUse($reader)) {
            return [];
        }
        $query = Conversations::db()->queryBuilder()->table('#PREFIX#conversation_files')->where('uploadedby', $person)
            ->whereNotNull('messageid')->whereNull('removedat');
        if (!Access::readsAll($reader)) {
            $readable = Conversations::readable((int) $reader->userid);
            if ($readable === []) {
                return [];
            }
            $query->whereIn('conversationid', $readable);
        }

        return array_map(self::row(...), $query->orderBy('fileid', 'desc')->limit(max(1, min(500, $limit)))->getAllOrFail());
    }

    /**
     * Remove a file to free its space: gone from disk, its row and its message's mention of it
     * kept, saying when it was removed.
     */
    public static function remove(int $id): int
    {
        $file = self::get($id);
        if ($file === null || $file['removedAt'] !== null) {
            return 0;
        }
        foreach (array_filter([$file['path'], $file['thumbPath']]) as $relative) {
            $path = self::root() . '/' . $relative;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        Conversations::db()->queryBuilder()->table('#PREFIX#conversation_files')->where('fileid', $id)->update(['removedat' => time()]);

        return $file['size'];
    }

    /**
     * Remove the files sent more than `$days` ago — of one conversation, or of all; 0 is every one.
     *
     * @return array{files: int, bytes: int} What was removed, and the space freed
     */
    public static function prune(int $days, ?int $conversationId = null): array
    {
        $query = Conversations::db()->queryBuilder()->table('#PREFIX#conversation_files')->select(['fileid'])
            ->whereNull('removedat')->where('createdat', '<=', time() - max(0, $days) * 86400);
        if ($conversationId !== null) {
            $query->where('conversationid', $conversationId);
        }
        $report = ['files' => 0, 'bytes' => 0];
        foreach (array_map('intval', array_column($query->getAllOrFail(), 'fileid')) as $id) {
            $report['bytes'] += self::remove($id);
            $report['files']++;
        }

        return $report;
    }

    /**
     * How the files use the space: per conversation and month, the largest and the oldest still
     * kept, and the total.
     *
     * @return array{total: array{files: int, bytes: int}, byConversation: list<array{conversationId: int, month: string, files: int, bytes: int}>, largest: list<array<string, mixed>>, oldest: list<array<string, mixed>>}
     */
    public static function usage(int $top = 20): array
    {
        $db   = Conversations::db();
        $kept = static fn () => $db->queryBuilder()->table('#PREFIX#conversation_files')->whereNull('removedat');
        $rows = $kept()->select(['conversationid', 'createdat', 'size'])->getAllOrFail();
        $by   = [];
        foreach ($rows as $row) {
            $key = (int) $row['conversationid'] . '|' . date('Y-m', (int) $row['createdat']);
            $by[$key] ??= ['conversationId' => (int) $row['conversationid'], 'month' => date('Y-m', (int) $row['createdat']), 'files' => 0, 'bytes' => 0];
            $by[$key]['files']++;
            $by[$key]['bytes'] += (int) $row['size'];
        }
        usort($by, static fn (array $a, array $b): int => [$b['month'], $b['bytes']] <=> [$a['month'], $a['bytes']]);
        $total = $kept()->select([new Expression('COUNT(*) AS n'), new Expression('SUM(size) AS b')])->getAllOrFail()[0] ?? [];

        return [
            'total'          => ['files' => (int) ($total['n'] ?? 0), 'bytes' => (int) ($total['b'] ?? 0)],
            'byConversation' => array_values($by),
            'largest'        => array_map(self::row(...), $kept()->orderBy('size', 'desc')->limit($top)->getAllOrFail()),
            'oldest'         => array_map(self::row(...), $kept()->orderBy('createdat')->limit($top)->getAllOrFail()),
        ];
    }

    /** The largest file, in megabytes: the setting, the application's, or 25. */
    public static function maxMegabytes(): int
    {
        $set = (int) \Pramnos\Application\Settings::getSetting(self::SETTING, 0);
        if ($set > 0) {
            return $set;
        }
        $app = \Pramnos\Application\Application::currentInstance();
        $own = is_object($app) ? (int) ($app->applicationInfo['conversations']['max_file_mb'] ?? 0) : 0;

        return $own > 0 ? $own : self::DEFAULT_MAX_MB;
    }

    /** The tree the files are kept in. */
    public static function root(): string
    {
        $app  = \Pramnos\Application\Application::currentInstance();
        $root = is_object($app) ? (string) ($app->applicationInfo['conversations']['files_path'] ?? '') : '';

        return rtrim($root !== '' ? $root : (defined('VAR_PATH') ? VAR_PATH : ROOT . '/var') . '/chat-files', '/');
    }

    /** The largest file, in bytes. */
    private static function maxBytes(): int
    {
        return self::maxMegabytes() * 1024 * 1024;
    }

    /** A name safe on any disk: no path, no control characters, at most 150 characters. */
    private static function name(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1f\x7f\/:*?"<>|]+/u', '-', $name);
        $name = trim($name, ". \t");

        return mb_substr($name !== '' ? $name : 'file', -150);
    }

    /** A thumbnail beside an image, or null where it cannot be made. */
    private static function thumbnail(string $absolute, string $relative): ?string
    {
        try {
            $thumb  = (string) preg_replace('/\.[^.\/]+$/', '', $relative) . '.thumb.jpg';
            $resize = new \Pramnos\Media\ResizeTools();
            $resize->exportpath = dirname(self::root() . '/' . $thumb) . '/';
            $resize->exportfile = basename($thumb);

            return $resize->resize($absolute, self::THUMB, self::THUMB) && is_file(self::root() . '/' . $thumb) ? $thumb : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private static function row(array $r): array
    {
        return ['id' => (int) $r['fileid'], 'messageId' => $r['messageid'] === null ? null : (int) $r['messageid'], 'conversationId' => (int) $r['conversationid'],
            'uploadedBy' => $r['uploadedby'] === null ? null : (int) $r['uploadedby'], 'name' => (string) $r['name'], 'path' => (string) $r['path'],
            'thumbPath' => $r['thumbpath'] === null ? null : (string) $r['thumbpath'], 'mime' => (string) $r['mime'], 'size' => (int) $r['size'],
            'createdAt' => (int) $r['createdat'], 'removedAt' => $r['removedat'] === null ? null : (int) $r['removedat']];
    }
}
