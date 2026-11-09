<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

/**
 * The team's own emoji: a picture under a name, written `:name:` in a message or reacted with,
 * beside the standard shortcodes ({@see Markdown::EMOJI}). Whoever may use the conversations uses
 * them; whoever has `admin.chat.emoji` manages them ({@see Access::managesEmoji()}).
 *
 * ```php
 * CustomEmoji::add('team-heart', $_FILES['emoji']['tmp_name'], $adminId);
 * CustomEmoji::alias('heart-team', 'team-heart', $adminId);   // another name for the same picture
 * CustomEmoji::rename('team-heart', 'our-heart', $adminId);
 * CustomEmoji::remove('our-heart', $adminId);                // and its aliases; `:our-heart:` reads as text again
 * CustomEmoji::all();                                         // ['our-heart' => '/admin/Conversations/emoji?name=…', …]
 * ```
 *
 * A picture is a PNG, GIF, WebP or JPEG — never an SVG, which can carry a script — no larger
 * than a file sent in a conversation ({@see Files::maxMegabytes()}), and is kept at most 128
 * pixels a side with its proportions. A GIF is made smaller frame by frame where the server has
 * Imagick, so its animation stays, and is kept as it came otherwise. The browser may crop it square
 * first (`\Pramnos\Html\ImageUpload`, aspect 1) — without the crop for a GIF, whose animation a
 * canvas would stop. Each change is told on the team's presence
 * channel as `emoji.changed`, with every name and its picture, and kept in the audit log.
 */
final class CustomEmoji
{
    /** What a name is made of. */
    public const NAME = '/^[a-z0-9_+-]{2,32}$/';

    /** The longest side a picture is kept at, in pixels. */
    public const SIZE = 128;

    /** The pictures accepted: their type => the extension they are kept with. */
    public const TYPES = ['image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/jpeg' => 'jpg'];

    /** The table. */
    private const TABLE = '#PREFIX#conversation_emoji';

    /** @var array<string, array{path: string, mime: string, aliasof: ?string, createdat: int}>|null Read once a request */
    private static ?array $rows = null;

    /**
     * Keep a picture under a new name.
     *
     * @throws \InvalidArgumentException For a name taken or not allowed, or a picture not accepted
     */
    public static function add(string $name, string $tmp, int $by): void
    {
        self::free($name);
        $size = is_file($tmp) ? (int) filesize($tmp) : 0;
        if ($size <= 0) {
            throw new \InvalidArgumentException(t('The file did not arrive.'));
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::TYPES[$mime]) || !is_array(@getimagesize($tmp))) {
            throw new \InvalidArgumentException(t('An emoji is a PNG, GIF, WebP or JPEG picture.'));
        }
        if ($size > Files::maxMegabytes() * 1024 * 1024) {
            throw new \InvalidArgumentException(t('A file is at most %s MB.', (string) Files::maxMegabytes()));
        }
        $relative = 'emoji/' . $name . '-' . bin2hex(random_bytes(4)) . '.' . self::TYPES[$mime];
        $absolute = Files::root() . '/' . $relative;
        $dir      = dirname($absolute);
        if (!(is_dir($dir) || @mkdir($dir, 0770, true) || is_dir($dir))
            || !(is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $absolute) : @copy($tmp, $absolute))) {
            throw new \RuntimeException('The emoji could not be kept under the conversations\' files directory: ' . $absolute);
        }
        self::fit($absolute, $mime);
        Conversations::db()->queryBuilder()->table(self::TABLE)->insert(['name' => $name, 'path' => $relative, 'mime' => $mime,
            'aliasof' => null, 'createdby' => $by, 'createdat' => time()]);
        self::changed('conversations.emoji_added', $by, $name);
    }

    /**
     * Another name for an emoji: the alias shows the same picture, and goes with it.
     *
     * @throws \InvalidArgumentException For a name taken or not allowed, or an emoji there is not
     */
    public static function alias(string $name, string $of, int $by): void
    {
        self::free($name);
        $target = self::rows()[$of] ?? throw new \InvalidArgumentException(t('There is no emoji :%s:.', $of));
        // An alias of an alias is an alias of what it names.
        $of = $target['aliasof'] ?? $of;
        Conversations::db()->queryBuilder()->table(self::TABLE)->insert(['name' => $name, 'path' => '', 'mime' => '',
            'aliasof' => $of, 'createdby' => $by, 'createdat' => time()]);
        self::changed('conversations.emoji_aliased', $by, $name, ['of' => $of]);
    }

    /**
     * A new name for an emoji or an alias; its aliases follow. Messages that wrote the old name
     * read it as text from now on.
     *
     * @throws \InvalidArgumentException For a new name taken or not allowed, or an emoji there is not
     */
    public static function rename(string $from, string $to, int $by): void
    {
        if (!isset(self::rows()[$from])) {
            throw new \InvalidArgumentException(t('There is no emoji :%s:.', $from));
        }
        self::free($to);
        $db = Conversations::db();
        $db->queryBuilder()->table(self::TABLE)->where('name', $from)->update(['name' => $to]);
        $db->queryBuilder()->table(self::TABLE)->where('aliasof', $from)->update(['aliasof' => $to]);
        self::changed('conversations.emoji_renamed', $by, $to, ['from' => $from]);
    }

    /**
     * An emoji gone, with its aliases and its picture; an alias gone alone. Where it was written,
     * `:name:` reads as text; reactions with it stay, as their text.
     *
     * @return bool False when there was none by that name
     */
    public static function remove(string $name, int $by): bool
    {
        $row = self::rows()[$name] ?? null;
        if ($row === null) {
            return false;
        }
        $db = Conversations::db();
        $db->queryBuilder()->table(self::TABLE)->where('name', $name)->delete();
        if ($row['aliasof'] === null) {
            $db->queryBuilder()->table(self::TABLE)->where('aliasof', $name)->delete();
            @unlink(Files::root() . '/' . $row['path']);
        }
        self::changed('conversations.emoji_removed', $by, $name);

        return true;
    }

    /** @return array<string, string> Every name — aliases too — and its picture's address */
    public static function all(): array
    {
        $out  = [];
        $rows = self::rows();
        foreach ($rows as $name => $row) {
            $own = $row['aliasof'] === null ? $name : $row['aliasof'];
            if (isset($rows[$own])) {
                $out[$name] = adminUrl('Conversations/emoji') . '?name=' . rawurlencode($own) . '&v=' . $rows[$own]['createdat'];
            }
        }
        ksort($out);

        return $out;
    }

    /** Whether there is an emoji, or an alias, by this name. */
    public static function exists(string $name): bool
    {
        return isset(self::rows()[$name]);
    }

    /**
     * Send an emoji's picture — anybody who may use the conversations may see it — cached for a
     * year, since a new picture has a new address.
     *
     * @return bool False when there is none by that name
     */
    public static function send(string $name): bool
    {
        $row = self::rows()[$name] ?? null;
        $row = $row !== null && $row['aliasof'] !== null ? (self::rows()[$row['aliasof']] ?? null) : $row;
        $file = $row !== null ? Files::root() . '/' . $row['path'] : '';
        if ($row === null || !is_file($file)) {
            return false;
        }
        header('Content-Type: ' . $row['mime']);
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private, max-age=31536000, immutable');
        header('X-Content-Type-Options: nosniff');
        readfile($file);

        return true;
    }

    /** Read again on the next call: for a test, or after another process changed them. */
    public static function forget(): void
    {
        self::$rows = null;
    }

    /** @throws \InvalidArgumentException For a name that is not allowed or taken */
    private static function free(string $name): void
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new \InvalidArgumentException(t('An emoji\'s name is 2 to 32 small letters, digits, _, + or -.'));
        }
        if (isset(Markdown::EMOJI[$name]) || isset(self::rows()[$name])) {
            throw new \InvalidArgumentException(t('There is an emoji :%s: already.', $name));
        }
    }

    /**
     * A picture larger than SIZE a side made to fit, with its proportions and its transparency: a
     * GIF frame by frame with Imagick, the others with GD. Where neither can, it stays as it came.
     */
    private static function fit(string $absolute, string $mime): void
    {
        $size = @getimagesize($absolute);
        if (!is_array($size) || ($size[0] <= self::SIZE && $size[1] <= self::SIZE)) {
            return;
        }
        $wide = $size[0] >= $size[1];
        [$w, $h] = [$wide ? self::SIZE : max(1, (int) round($size[0] * self::SIZE / $size[1])), $wide ? max(1, (int) round($size[1] * self::SIZE / $size[0])) : self::SIZE];
        if ($mime === 'image/gif') {
            // ponytail: no Imagick, no smaller GIF — GD would keep only its first frame; the page draws it at emoji size.
            if (class_exists(\Imagick::class)) {
                $frames = (new \Imagick($absolute))->coalesceImages();
                foreach ($frames as $frame) {
                    $frame->thumbnailImage($w, $h);
                    $frame->setImagePage($w, $h, 0, 0);
                }
                $frames->deconstructImages()->writeImages($absolute, true);
            }

            return;
        }
        $image  = function_exists('imagecreatefromstring') ? @imagecreatefromstring((string) file_get_contents($absolute)) : false;
        $scaled = $image !== false ? imagescale($image, $w, $h) : false;
        if ($scaled === false) {
            return;
        }
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        match ($mime) {
            'image/png'  => imagepng($scaled, $absolute),
            'image/webp' => imagewebp($scaled, $absolute),
            default      => imagejpeg($scaled, $absolute, 90),
        };
    }

    /** @return array<string, array{path: string, mime: string, aliasof: ?string, createdat: int}> */
    private static function rows(): array
    {
        if (self::$rows !== null) {
            return self::$rows;
        }
        $rows = [];
        try {
            foreach (Conversations::db()->queryBuilder()->table(self::TABLE)->getAllOrFail() as $row) {
                $rows[(string) $row['name']] = ['path' => (string) $row['path'], 'mime' => (string) $row['mime'],
                    'aliasof' => $row['aliasof'] !== null && $row['aliasof'] !== '' ? (string) $row['aliasof'] : null, 'createdat' => (int) $row['createdat']];
            }
        } catch (\Throwable) {
            // No table yet — `migrate` has not run since it was added: no emoji of the team's own.
            return [];
        }

        return self::$rows = $rows;
    }

    /** Read again, kept in the audit log, and told to everyone with the conversations open. */
    private static function changed(string $event, int $by, string $name, array $details = []): void
    {
        self::forget();
        \Pramnos\Auth\AuditLog::record($event, $by, null, ['name' => $name] + $details, 'conversation_emoji', $name);
        $manager = \Pramnos\Broadcasting\BroadcastingManager::currentInstance();
        if ($manager === null) {
            return;
        }
        try {
            $manager->broadcast(Realtime::PRESENCE, 'emoji.changed', ['customEmoji' => (object) self::all()]);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Conversations: emoji.changed was not told: ' . $e->getMessage(), 'broadcasting');
        }
    }
}
