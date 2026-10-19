<?php

declare(strict_types=1);

namespace Pramnos\Email;

/**
 * Where a sent message's body is kept, and how it is given back exactly as it was sent.
 *
 * ```php
 * // app/app.php — the default is 'auto'
 * 'email' => ['bodies' => [
 *     'store' => 'auto',          // database where mail_bodies exists (TimescaleDB), else files
 *     'path'  => 'var/emails',    // files: under the application's root, or an absolute folder
 *     'disk'  => null,            // files on a storage disk instead — 's3' as app.php names it
 * ]],
 * ```
 *
 * - **database** — the `mail_bodies` hypertable, compressed: many bodies of the same templates
 *   together are about a seventeenth of their size. The `mails` row's `path` is `db:`.
 * - **files** — one gzipped file per distinct body and hour, named by its md5, the layout
 *   applications have kept for years: `var/emails/2026/10/19/13/<md5>.body.gz`. Identical bodies
 *   in the same hour are one file.
 * - **content** — in the row's `content` column, as before.
 *
 * **What is kept is the body before what differs per recipient.** The tracking pixel and tracked
 * links are added as the message is sent, and the unsubscribe link the wrapper prints is kept as
 * a placeholder — so a newsletter to a hundred thousand people is one body. Reading puts them back
 * ({@see Body::of()}): the link from the address and the list, the tracking from the message's
 * tracking row, its click links from the moment it was sent as ({@see Tracking::linkSentAt()}) —
 * byte for byte what was sent, as long as the site's address and the signing secret are the same.
 */
final class BodyStore
{
    public const DATABASE = 'database';

    public const FILES = 'files';

    public const CONTENT = 'content';

    /** The `path` of a row whose body is in `mail_bodies`. */
    public const IN_DATABASE = 'db:';

    /** The extension of a body kept before what differs per recipient. */
    public const REBUILD_EXTENSION = '.body.gz';

    /** @var string|null This process's answer to 'auto' */
    private static ?string $resolved = null;

    /** @var bool|null Whether `mails` has `bodyvars`, asked once a process */
    private static ?bool $bodyVars = null;

    /** Whether `mails` can hold a templated message's values — its migration has run. */
    public static function hasBodyVars(): bool
    {
        return self::$bodyVars ??= \Pramnos\Framework\Factory::getDatabase()->schema()->hasColumn('mails', 'bodyvars');
    }

    /** Where bodies are kept: one of the constants, 'auto' resolved. */
    public static function mode(): string
    {
        $chosen = (string) (self::config()['store'] ?? 'auto');
        if (in_array($chosen, [self::DATABASE, self::FILES, self::CONTENT], true)) {
            return $chosen;
        }
        // Asked once a process. A database that cannot answer throws, and the body is kept in its
        // row ({@see \Pramnos\Email\Email::keptBody()}).
        return self::$resolved ??= \Pramnos\Framework\Factory::getDatabase()->schema()->hasTable('mail_bodies') ? self::DATABASE : self::FILES;
    }

    /** Forget the answer to 'auto' — after a migration made the table, or between tests. */
    public static function forget(): void
    {
        self::$resolved = null;
        self::$bodyVars = null;
    }

    /**
     * A body as it is kept: the unsubscribe link of this recipient and list, where it is the one
     * the framework issues, replaced by a placeholder naming the list. A link of the application's
     * own is left as it is.
     */
    public static function prepare(string $body, string $recipient, string $unsubscribe, string $list): string
    {
        if ($unsubscribe === '' || $list === '' || $unsubscribe !== self::unsubscribeUrl($recipient, $list)) {
            return $body;
        }
        // The link is `…/unsubscribe?u=<urlencoded token>`: escaping it into an attribute changes
        // nothing, so the one form is every form a template prints it in.
        return str_replace($unsubscribe, self::placeholder($list), $body);
    }

    /**
     * A kept body as it was sent to `$recipient`: a template filled with its values, the
     * unsubscribe link put back, and — for a tracked message — its links wrapped and its pixel,
     * as of the moment it was sent.
     *
     * @param array<string, scalar>|null $values A templated message's values
     */
    public static function rebuild(string $kept, string $recipient, int $mailId, int $sentAt, ?array $values = null): string
    {
        $kept        = $values !== null ? TemplateValues::fill($kept, $values) : $kept;
        $unsubscribe = '';
        $body        = (string) preg_replace_callback('/\{\{pf-unsubscribe:([^}]*)\}\}/', static function (array $m) use ($recipient, &$unsubscribe): string {
            return $unsubscribe = self::unsubscribeUrl($recipient, rawurldecode($m[1]));
        }, $kept);
        $trackingId = $mailId > 0 ? Tracking::idForMail($mailId) : null;
        if ($trackingId === null) {
            return $body;
        }

        return Tracking::wrapLinksSentAt($body, $trackingId, $unsubscribe, $sentAt) . Tracking::pixel($trackingId);
    }

    /**
     * Keep a body as a file — on the store's disk, or in its folder — and answer the row's `path`:
     * relative to the application's root as applications write it, `disk:<name>:<path>` on a disk.
     */
    public static function putFile(string $kept, int $sentAt): string
    {
        $relative = date('Y/m/d/H', $sentAt) . '/' . md5($kept) . self::REBUILD_EXTENSION;
        $disk     = self::config()['disk'] ?? null;
        if (is_string($disk) && $disk !== '') {
            $store = \Pramnos\Storage\Storage::getManager()->disk($disk);
            if (!$store->exists($relative)) {
                $store->put($relative, (string) gzencode($kept, 6));
            }

            return 'disk:' . $disk . ':' . $relative;
        }
        $file = self::root() . '/' . $relative;
        if (!is_file($file)) {
            if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0775, true) && !is_dir(dirname($file))) {
                throw new \RuntimeException('Cannot create ' . dirname($file) . ' for a mail body');
            }
            // Written whole and renamed: a reader never finds half a body.
            $temporary = $file . '.' . bin2hex(random_bytes(4));
            if (@file_put_contents($temporary, (string) gzencode($kept, 6)) === false || !@rename($temporary, $file)) {
                @unlink($temporary);
                throw new \RuntimeException('Cannot write a mail body to ' . $file);
            }
        }
        $base = defined('ROOT') ? rtrim((string) realpath((string) ROOT), '/') : '';

        return $base !== '' && str_starts_with($file, $base . '/') ? substr($file, strlen($base)) : $file;
    }

    /** Keep a body in `mail_bodies`, for the `mails` row `$mailId`. */
    public static function putRow(int $mailId, int $sentAt, string $body, bool $rebuild = true): void
    {
        \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#mail_bodies')->insert([
            'mail_id' => $mailId,
            'sent_at' => gmdate('Y-m-d H:i:s+00', $sentAt),
            'rebuild' => $rebuild,
            'body'    => $body,
        ]);
    }

    /**
     * A row's body from `mail_bodies`, and whether it is kept before what differs per recipient;
     * null where there is none.
     *
     * @return array{body: string, rebuild: bool}|null
     */
    public static function getRow(int $mailId, int $sentAt): ?array
    {
        $row = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#mail_bodies')
            ->select(['body', 'rebuild'])
            ->where('mail_id', $mailId)
            // The row's own moment: the chunk it is in, and no other, is read.
            ->where('sent_at', gmdate('Y-m-d H:i:s+00', $sentAt))
            ->first();

        return $row && $row->numRows > 0
            ? ['body' => (string) $row->fields['body'], 'rebuild' => in_array($row->fields['rebuild'], [true, 't', 1, '1'], true)]
            : null;
    }

    /**
     * Remove the kept bodies of these `mails` rows — their `mail_bodies` rows, their files — and
     * answer how many went. A file another row still names is left: identical bodies of the same
     * hour are one file, and one row's erasure is not the others'.
     *
     * Called before the rows themselves are emptied or deleted, by {@see Retention} and
     * {@see MailPersonalData}. A body that cannot be removed is logged and left; the rows go on.
     *
     * @param list<array{id: int|string, date: int|string, path: string}> $rows
     */
    public static function discard(array $rows): int
    {
        $db      = \Pramnos\Framework\Factory::getDatabase();
        $ids     = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $removed = 0;

        $inDatabase = array_values(array_filter($rows, static fn (array $row): bool => (string) $row['path'] === self::IN_DATABASE));
        if ($inDatabase !== [] && $db->schema()->hasTable('mail_bodies')) {
            $dates = array_map(static fn (array $row): int => (int) $row['date'], $inDatabase);
            $result = $db->queryBuilder()->table('#PREFIX#mail_bodies')
                ->whereIn('mail_id', array_map(static fn (array $row): int => (int) $row['id'], $inDatabase))
                // The rows' own moments: only the chunks they are in are read.
                ->whereBetween('sent_at', [gmdate('Y-m-d H:i:s+00', min($dates)), gmdate('Y-m-d H:i:s+00', max($dates))])
                ->delete();
            $removed += $result ? (int) $result->getAffectedRows() : 0;
        }

        $files = [];
        foreach ($rows as $row) {
            $path = (string) $row['path'];
            if ($path !== '' && $path !== self::IN_DATABASE) {
                $files[$path][] = (int) $row['date'];
            }
        }
        foreach ($files as $path => $dates) {
            // The rows that share a file were sent in the same hour; a day either side reads the
            // date index and allows for a clock or a time zone.
            $others = $db->queryBuilder()->table('#PREFIX#mails')->where('path', $path)
                ->whereBetween('date', [min($dates) - 86400, max($dates) + 86400])
                ->whereIn('id', $ids, 'and', true)
                ->count();
            if ($others === 0 && self::removeFile($path)) {
                $removed++;
            }
        }

        return $removed;
    }

    /** Remove one kept file — on its disk, or under the root — and say whether it went. */
    private static function removeFile(string $path): bool
    {
        try {
            if (str_starts_with($path, 'disk:')) {
                [, $disk, $relative] = array_pad(explode(':', $path, 3), 3, '');

                return (bool) \Pramnos\Storage\Storage::getManager()->disk($disk)->delete($relative);
            }
            // Only a body file, inside the roots a body is read from: a `path` is a column, not
            // an instruction to delete whatever it names.
            $file = Body::file($path);

            return $file !== null && @unlink($file);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('A mail body could not be removed (' . $path . '): ' . $e->getMessage(), 'mail');

            return false;
        }
    }

    /** The folder files are kept in, without its trailing slash. */
    public static function root(): string
    {
        $path = (string) (self::config()['path'] ?? 'var/emails');
        $base = defined('ROOT') ? rtrim((string) ROOT, '/') : '';

        return rtrim(str_starts_with($path, '/') ? $path : $base . '/' . $path, '/');
    }

    /** The unsubscribe link the framework issues for this address and list. */
    private static function unsubscribeUrl(string $recipient, string $list): string
    {
        $token = Unsubscribe::token($recipient, $list);

        return $token === '' ? '' : Unsubscribe::url($token);
    }

    /** The placeholder for a list's unsubscribe link. */
    private static function placeholder(string $list): string
    {
        return '{{pf-unsubscribe:' . rawurlencode($list) . '}}';
    }

    /** `'email' => ['bodies' => [...]]` in app.php. @return array<string, mixed> */
    private static function config(): array
    {
        $app    = \Pramnos\Application\Application::currentInstance();
        $config = is_object($app) ? ($app->applicationInfo['email']['bodies'] ?? []) : [];

        return is_array($config) ? $config : [];
    }
}
