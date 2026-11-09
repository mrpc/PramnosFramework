<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

/**
 * A channel's, a system channel's or a group's own picture, as Slack's; a direct conversation's is
 * its person's.
 *
 * ```php
 * Images::set($id, $_FILES['image']['tmp_name'], $by);   // a PNG, WebP, JPEG or GIF; kept square, 256 a side
 * Images::clear($id, $by);
 * Images::url($conversation);                             // '/admin/Conversations/image?id=…&v=…', or null
 * ```
 *
 * Who changes it: in a group any member, as with its name; in a channel its owner or a moderator
 * ({@see Access::moderates()}). An application can give defaults by a channel's name —
 * `'conversations' => ['images' => ['general' => '/path/to/general.png']]` — and a picture set on
 * the channel wins. Never an SVG, which can carry a script. Each change is told to the members as
 * `conversation.changed` and kept in the changelog. The address is readable without a session
 * where `conversations.images_public` says so, for a desktop application that has no cookie.
 */
final class Images
{
    /** The side a picture is kept at, in pixels. */
    public const SIZE = 256;

    /** The kinds that have a picture of their own. */
    public const KINDS = ['channel', 'private', 'system', 'group'];

    /**
     * Give a conversation a picture: cropped square from its middle, 256 a side.
     *
     * @throws \DomainException For a conversation that has none, or that `$by` may not change
     * @throws \InvalidArgumentException For a file that is not a picture accepted
     */
    public static function set(int $id, string $tmp, int $by): void
    {
        $conversation = self::changeable($id, $by);
        $size = is_file($tmp) ? (int) filesize($tmp) : 0;
        if ($size <= 0) {
            throw new \InvalidArgumentException(t('The file did not arrive.'));
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(CustomEmoji::TYPES[$mime]) || !is_array(@getimagesize($tmp))) {
            throw new \InvalidArgumentException(t('A picture is a PNG, GIF, WebP or JPEG.'));
        }
        if ($size > Files::maxMegabytes() * 1024 * 1024) {
            throw new \InvalidArgumentException(t('A file is at most %s MB.', (string) Files::maxMegabytes()));
        }
        $relative = 'images/conversation-' . $id . '-' . bin2hex(random_bytes(4)) . '.' . CustomEmoji::TYPES[$mime];
        $absolute = Files::root() . '/' . $relative;
        $dir      = dirname($absolute);
        if (!(is_dir($dir) || @mkdir($dir, 0770, true) || is_dir($dir))
            || !(is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $absolute) : @copy($tmp, $absolute))) {
            throw new \RuntimeException('The picture could not be kept under the conversations\' files directory: ' . $absolute);
        }
        self::square($absolute, $mime);
        self::store($conversation, $relative, $by);
    }

    /**
     * Take a conversation's picture away: its default, or its sign, again.
     *
     * @throws \DomainException For a conversation that `$by` may not change
     */
    public static function clear(int $id, int $by): void
    {
        self::store(self::changeable($id, $by), null, $by);
    }

    /**
     * A conversation's picture's address, versioned, or null for none.
     *
     * @param array<string, mixed> $conversation A row, as kept
     */
    public static function url(array $conversation): ?string
    {
        $file = self::file($conversation);
        if ($file === null) {
            return null;
        }

        return adminUrl('Conversations/image') . '?id=' . (int) $conversation['conversationid'] . '&v=' . (int) ($conversation['imageat'] ?? 0 ?: @filemtime($file));
    }

    /**
     * Send a conversation's picture, cached for a year: a new picture has a new address.
     *
     * @return bool False where it has none
     */
    public static function send(int $id): bool
    {
        $row  = Conversations::db()->queryBuilder()->table('chat.conversations')->where('conversationid', $id)->limit(1)->getAllOrFail()[0] ?? null;
        $file = $row === null ? null : self::file($row);
        if ($file === null) {
            return false;
        }
        header('Content-Type: ' . (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file));
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private, max-age=31536000, immutable');
        header('X-Content-Type-Options: nosniff');
        readfile($file);

        return true;
    }

    /** Whether its address is read without a session (`conversations.images_public`). */
    public static function public(): bool
    {
        return !empty(Conversations::settings()['images_public']);
    }

    /** @param array<string, mixed> $row The picture's file: its own, else the application's default for its name. */
    private static function file(array $row): ?string
    {
        if (!in_array((string) ($row['kind'] ?? ''), self::KINDS, true)) {
            return null;
        }
        $own = (string) ($row['image'] ?? '');
        if ($own !== '' && is_file(Files::root() . '/' . $own)) {
            return Files::root() . '/' . $own;
        }
        $defaults = (array) (Conversations::settings()['images'] ?? []);
        $default  = (string) ($defaults[(string) ($row['slug'] ?? '')] ?? '');
        if ($default === '' || !is_file($default)) {
            return null;
        }

        return isset(CustomEmoji::TYPES[(string) (new \finfo(FILEINFO_MIME_TYPE))->file($default)]) ? $default : null;
    }

    /**
     * The conversation, where it takes a picture and `$by` may change it.
     *
     * @return array<string, mixed>
     * @throws \DomainException Otherwise
     */
    private static function changeable(int $id, int $by): array
    {
        $conversation = Conversations::get($id);
        if ($conversation === null || !in_array($conversation['kind'], self::KINDS, true)) {
            throw new \DomainException(t('Only a channel or a group has a picture.'));
        }
        $may = $conversation['kind'] === 'group'
            ? Conversations::member($id, $by) !== null
            : Access::moderates(new \Pramnos\User\User($by), $id);
        if (!$may) {
            throw new \DomainException(t('Only its owner or a moderator changes its picture.'));
        }

        return $conversation;
    }

    /** Keep the new picture (or none), the old file gone; told and logged. @param array<string, mixed> $conversation */
    private static function store(array $conversation, ?string $relative, int $by): void
    {
        $table = static fn () => Conversations::db()->queryBuilder()->table('chat.conversations');
        $old   = (string) ($table()->where('conversationid', $conversation['id'])->value('image') ?? '');
        $table()->where('conversationid', $conversation['id'])->update(['image' => $relative, 'imageat' => $relative === null ? null : time()]);
        if ($old !== '' && $old !== $relative) {
            @unlink(Files::root() . '/' . $old);
        }
        \Pramnos\Changelog\ChangelogWriter::event('conversation', $conversation['id'], $relative === null ? 'conversation.image_cleared' : 'conversation.image_set', [], by: $by);
        Realtime::conversationChanged($conversation['id'], Conversations::members($conversation['id']));
    }

    /**
     * Crop a picture square from its middle and make it SIZE a side, keeping transparency: a GIF
     * frame by frame with Imagick, the others with GD. Where neither can, it stays as it came.
     */
    private static function square(string $absolute, string $mime): void
    {
        $size = @getimagesize($absolute);
        if (!is_array($size)) {
            return;
        }
        $side = min($size[0], $size[1]);
        $x    = intdiv($size[0] - $side, 2);
        $y    = intdiv($size[1] - $side, 2);
        $to   = min(self::SIZE, $side);
        if ($mime === 'image/gif') {
            // ponytail: no Imagick, the GIF kept as it came — GD would keep only its first frame.
            if (class_exists(\Imagick::class)) {
                $frames = (new \Imagick($absolute))->coalesceImages();
                foreach ($frames as $frame) {
                    $frame->cropImage($side, $side, $x, $y);
                    $frame->thumbnailImage($to, $to);
                    $frame->setImagePage($to, $to, 0, 0);
                }
                $frames->deconstructImages()->writeImages($absolute, true);
            }

            return;
        }
        $image = function_exists('imagecreatefromstring') ? @imagecreatefromstring((string) file_get_contents($absolute)) : false;
        if ($image === false) {
            return;
        }
        $square = imagecreatetruecolor($to, $to);
        imagealphablending($square, false);
        imagesavealpha($square, true);
        imagefill($square, 0, 0, imagecolorallocatealpha($square, 0, 0, 0, 127));
        imagecopyresampled($square, $image, 0, 0, $x, $y, $to, $to, $side, $side);
        match ($mime) {
            'image/png'  => imagepng($square, $absolute),
            'image/webp' => imagewebp($square, $absolute),
            default      => imagejpeg($square, $absolute, 90),
        };
    }
}
