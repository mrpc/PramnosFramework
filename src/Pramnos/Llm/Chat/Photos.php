<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Llm\CallLog;

/**
 * A person's photos with the companion: kept as artifacts of kind `photo`, each persona's, tied to
 * a conversation and a day — and never looked at by the model, which knows only that a photo is
 * there and its caption.
 *
 * ```php
 * 'llm' => ['chat' => ['photos' => ['disk' => null, 'max' => 500, 'bytes' => 8388608]]],   // or true
 * ```
 *
 * - **Kept out of reach**: the bytes on a storage disk ({@see \Pramnos\Storage\Storage}, the
 *   default — outside the web root — or `disk`), read back only through an address signed for one
 *   photo and one size, which lasts {@see TTL} seconds — an `<img>` sends no token.
 * - **Cleaned on the way in**: read as a picture or refused, turned upright, written again as
 *   JPEG — every piece of metadata, the place it was taken among it, left behind — with a
 *   thumbnail beside it. HEIC and the like are converted first where Imagick is there.
 * - **Limits**: {@see BYTES} a photo and {@see MAX} a person and persona, unless the application
 *   says otherwise.
 * - **The model never sees pixels**: a photo given to a conversation is a line in it, «[photo:
 *   caption]», and `list_photos` gives the captions and days, so the assistant can offer to look
 *   at Sunday's.
 * - **Only the owner's**: every read is within the person's scope; deleted with the account.
 */
final class Photos
{
    /** The artifact kind of a photo. */
    public const KIND = 'photo';

    /** The most bytes a photo may have, by default: 8 MB. */
    public const BYTES = 8388608;

    /** The most photos a person keeps with one persona, by default. */
    public const MAX = 500;

    /** The longest side a photo is kept at, and its thumbnail's. */
    public const SIDE  = 2560;
    public const THUMB = 480;

    /** How long a photo's address lasts, in seconds. */
    public const TTL = 3600;

    /** Where the signing secret is kept. */
    public const SECRET_SETTING = 'chat_photos_secret';

    public function __construct(private readonly Scope $scope)
    {
    }

    /** Whether the application keeps photos: `'llm' => ['chat' => ['photos' => …]]`. */
    public static function on(): bool
    {
        return !empty(self::config()) || (((array) \Pramnos\Llm\Llm::config('chat', []))['photos'] ?? null) === true;
    }

    /**
     * Keep a photo: its bytes cleaned and stored, an artifact made.
     *
     * @param array{name?: string, caption?: string, conversation_id?: int|null, day?: string|null} $about
     * @return array<string, mixed>|string The photo, or why not
     */
    public function add(string $bytes, array $about = []): array|string
    {
        if ($bytes === '' || strlen($bytes) > self::limit('bytes', self::BYTES)) {
            return 'A photo is 1 byte to ' . round(self::limit('bytes', self::BYTES) / 1048576, 1) . ' MB.';
        }
        if ($this->count() >= self::limit('max', self::MAX)) {
            return 'There are already ' . self::limit('max', self::MAX) . ' photos: delete one first.';
        }
        $clean = self::clean($bytes);
        if ($clean === null) {
            return 'That is not a picture that can be kept.';
        }
        $key   = 'chat-photos/' . $this->scope->userId . '/' . bin2hex(random_bytes(12));
        $disk  = self::disk();
        if (!$disk->put($key . '.jpg', $clean['image']) || !$disk->put($key . '-thumb.jpg', $clean['thumb'])) {
            return 'The photo could not be stored.';
        }
        $caption = mb_substr(trim((string) ($about['caption'] ?? '')), 0, 300);
        $day     = self::day($about['day'] ?? null) ?? date('Y-m-d');
        $title   = $caption !== '' ? $caption : mb_substr(trim((string) ($about['name'] ?? '')), 0, 120);
        $kept    = (new Artifacts($this->scope))->keep(self::KIND, $title !== '' ? $title : 'Photo ' . $day, null, [
            'path' => $key . '.jpg', 'thumb' => $key . '-thumb.jpg', 'width' => $clean['width'], 'height' => $clean['height'],
            'bytes' => strlen($clean['image']), 'caption' => $caption, 'day' => $day, 'taken_at' => $clean['taken_at'],
            'conversation_id' => isset($about['conversation_id']) ? (int) $about['conversation_id'] : null,
        ]);
        $id = (int) $kept['artifact_id'];
        if (isset($about['conversation_id']) && (int) $about['conversation_id'] > 0) {
            $this->tell((int) $about['conversation_id'], $id, $caption);
        }

        return $this->find($id) ?? 'The photo could not be read back.';
    }

    /**
     * The person's photos, newest first: of a conversation, of a day, before an id.
     *
     * @param array{conversation_id?: int, day?: string, before?: int} $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = [], int $limit = 50): array
    {
        $query = $this->scope->apply($this->table())->where('kind', self::KIND)->orderBy('artifact_id', 'desc')->limit(max(1, min(200, $limit)));
        if ((int) ($filters['before'] ?? 0) > 0) {
            $query->where('artifact_id', '<', (int) $filters['before']);
        }
        $out = [];
        foreach ($query->getAllOrFail() as $row) {
            $photo = $this->shape($row);
            if ((isset($filters['day']) && $filters['day'] !== '' && $photo['day'] !== $filters['day'])
                || ((int) ($filters['conversation_id'] ?? 0) > 0 && $photo['conversation_id'] !== (int) $filters['conversation_id'])) {
                continue;
            }
            $out[] = $photo;
        }

        return $out;
    }

    /** One of the person's photos, or null. @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->scope->apply($this->table())->where('kind', self::KIND)->where('artifact_id', $id)->limit(1)->getAllOrFail()[0] ?? null;

        return $row === null ? null : $this->shape($row);
    }

    /**
     * Change a photo's caption, conversation or day.
     *
     * @param array{caption?: string, conversation_id?: int|null, day?: string} $changes
     * @return array<string, mixed>|string|null The photo; why not; null for none
     */
    public function update(int $id, array $changes): array|string|null
    {
        $row = $this->scope->apply($this->table())->where('kind', self::KIND)->where('artifact_id', $id)->limit(1)->getAllOrFail()[0] ?? null;
        if ($row === null) {
            return null;
        }
        $content = (array) json_decode((string) $row['content'], true);
        if (array_key_exists('day', $changes)) {
            $day = self::day($changes['day']);
            if ($day === null) {
                return 'day: Y-m-d.';
            }
            $content['day'] = $day;
        }
        if (array_key_exists('caption', $changes)) {
            $content['caption'] = mb_substr(trim((string) $changes['caption']), 0, 300);
        }
        if (array_key_exists('conversation_id', $changes)) {
            $content['conversation_id'] = $changes['conversation_id'] === null ? null : (int) $changes['conversation_id'];
        }
        $this->scope->apply($this->table())->where('artifact_id', $id)->update(['content' => (string) json_encode($content, JSON_UNESCAPED_UNICODE),
            'title' => mb_substr($content['caption'] !== '' ? $content['caption'] : (string) $row['title'], 0, Artifacts::TITLE), 'updated_at' => CallLog::instant(time())]);

        return $this->find($id);
    }

    /** Delete a photo, its files too. Whether it was there. */
    public function delete(int $id): bool
    {
        $row = $this->scope->apply($this->table())->where('kind', self::KIND)->where('artifact_id', $id)->limit(1)->getAllOrFail()[0] ?? null;
        if ($row === null) {
            return false;
        }
        self::forgetFiles((array) json_decode((string) $row['content'], true));
        $this->scope->apply($this->table())->where('artifact_id', $id)->delete();

        return true;
    }

    /** Delete every photo file a person has, with any persona — before their account goes. Answers how many. */
    public static function eraseFiles(int $userId): int
    {
        $gone = 0;
        try {
            $rows = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('assist.chat_artifacts')->select(['content'])
                ->where('userid', $userId)->where('kind', self::KIND)->getAllOrFail();
        } catch (\Throwable) {
            return 0;
        }
        foreach ($rows as $row) {
            self::forgetFiles((array) json_decode((string) $row['content'], true));
            $gone++;
        }

        return $gone;
    }

    /**
     * A photo's bytes, by a signed address: the image or its thumbnail, while the signature holds.
     *
     * @return array{0: string, 1: string}|null The bytes and their type; null for a bad or old signature
     */
    public static function signed(int $id, string $size, int $expires, string $signature): ?array
    {
        $size = $size === 'thumb' ? 'thumb' : 'full';
        if ($expires < time() || !hash_equals(self::signature($id, $size, $expires), $signature)) {
            return null;
        }
        try {
            $row = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('assist.chat_artifacts')->select(['content'])
                ->where('artifact_id', $id)->where('kind', self::KIND)->limit(1)->getAllOrFail()[0] ?? null;
            $content = $row === null ? [] : (array) json_decode((string) $row['content'], true);
            $path    = (string) ($content[$size === 'thumb' ? 'thumb' : 'path'] ?? '');

            return $path === '' || !self::disk()->exists($path) ? null : [self::disk()->get($path), 'image/jpeg'];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The companion's tool: the person's photos by day or conversation — captions and days, never
     * the pictures.
     *
     * @return array<string, array<string, mixed>>
     */
    public function tools(): array
    {
        return ['list_photos' => [
            'description' => 'The person\'s photos with you, by day (Y-m-d) or conversation: their captions and days — you never see '
                . 'the pictures themselves. Use it to offer to look at a day\'s photos together, or to recall what one was of.',
            'parameters'  => ['type' => 'object', 'properties' => ['day' => ['type' => 'string'], 'conversation_id' => ['type' => 'integer']]],
            'run'         => fn (array $a): string => (string) json_encode(array_map(static fn (array $p): array => ['id' => $p['artifact_id'],
                'caption' => $p['caption'], 'day' => $p['day']], $this->list(['day' => (string) ($a['day'] ?? ''), 'conversation_id' => (int) ($a['conversation_id'] ?? 0)], 30)),
                JSON_UNESCAPED_UNICODE),
        ]];
    }

    /**
     * A picture made safe to keep: upright, at most {@see SIDE}, JPEG — its metadata left behind
     * — with a thumbnail. Null for what is not a picture GD can read.
     *
     * @return array{image: string, thumb: string, width: int, height: int, taken_at: ?string}|null
     */
    public static function clean(string $bytes): ?array
    {
        $taken = null;
        $file  = tempnam(sys_get_temp_dir(), 'pfphoto');
        if ($file === false) {
            return null;
        }
        try {
            file_put_contents($file, $bytes);
            // A HEIC or the like, as JPEG first, where Imagick reads it.
            $converted = \Pramnos\Media\Imaging::toWebImage($file);
            if ($converted !== null) {
                $file  = $converted['file'];
                $bytes = (string) file_get_contents($file);
            }
            $orientation = 1;
            if (function_exists('exif_read_data')) {
                $exif        = @exif_read_data($file);
                $orientation = (int) ($exif['Orientation'] ?? 1);
                $when        = (string) ($exif['DateTimeOriginal'] ?? '');
                $taken       = preg_match('/^(\d{4}):(\d\d):(\d\d) (\d\d:\d\d)/', $when, $m) === 1 ? $m[1] . '-' . $m[2] . '-' . $m[3] . ' ' . $m[4] : null;
            }
            $image = @imagecreatefromstring($bytes);
            if ($image === false) {
                return null;
            }
            $image = match ($orientation) {
                3 => imagerotate($image, 180, 0) ?: $image,
                6 => imagerotate($image, -90, 0) ?: $image,
                8 => imagerotate($image, 90, 0) ?: $image,
                default => $image,
            };
            $full  = self::fit($image, self::SIDE);
            $thumb = self::fit($image, self::THUMB);

            return ['image' => self::jpeg($full, 88), 'thumb' => self::jpeg($thumb, 80), 'width' => imagesx($full), 'height' => imagesy($full), 'taken_at' => $taken];
        } finally {
            @unlink($file);
        }
    }

    /** A photo as answered: its fields and the addresses that read it. @return array<string, mixed> */
    private function shape(array $row): array
    {
        $content = (array) json_decode((string) $row['content'], true);
        $id      = (int) $row['artifact_id'];

        return ['artifact_id' => $id, 'caption' => (string) ($content['caption'] ?? ''), 'day' => (string) ($content['day'] ?? ''),
            'taken_at' => $content['taken_at'] ?? null, 'conversation_id' => isset($content['conversation_id']) ? (int) $content['conversation_id'] : null,
            'width' => (int) ($content['width'] ?? 0), 'height' => (int) ($content['height'] ?? 0), 'created_at' => (string) $row['created_at'],
            'url' => self::address($id, 'full'), 'thumb_url' => self::address($id, 'thumb')];
    }

    /** A signed address for one photo and size: `{chat}/photos/{id}/file?size=…&e=…&s=…`, relative to the API. */
    public static function address(int $id, string $size): string
    {
        $expires = time() + self::TTL;

        return 'photos/' . $id . '/file?' . http_build_query(['size' => $size, 'e' => $expires, 's' => self::signature($id, $size, $expires)]);
    }

    /** The conversation's line about a photo: what the model sees of it. */
    private function tell(int $conversationId, int $id, string $caption): void
    {
        try {
            // Only a conversation of theirs, in this scope.
            if ($this->scope->apply(\Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('assist.chat_conversations'))
                ->where('conversation_id', $conversationId)->count() === 0) {
                return;
            }
            $stamp = CallLog::instant(time());
            \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('assist.chat_messages')->insert([
                'conversation_id' => $conversationId, 'organization_id' => $this->scope->organizationId, 'role' => 'user',
                'content' => '[photo: ' . ($caption !== '' ? $caption : 'no caption') . ']', 'extra' => (string) json_encode(['photo' => $id]),
                'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
        } catch (\Throwable $why) {
            \Pramnos\Logs\Logger::log('photo ' . $id . ' not told to its conversation: ' . $why->getMessage(), 'llm');
        }
    }

    private function count(): int
    {
        return $this->scope->apply($this->table())->where('kind', self::KIND)->count();
    }

    private static function signature(int $id, string $size, int $expires): string
    {
        return hash_hmac('sha256', $id . '|' . $size . '|' . $expires, self::secret());
    }

    private static function secret(): string
    {
        $kept = (string) (\Pramnos\Application\Settings::getSetting(self::SECRET_SETTING) ?: '');
        if ($kept !== '') {
            return $kept;
        }
        $secret = bin2hex(random_bytes(32));
        try {
            \Pramnos\Application\Settings::setSetting(self::SECRET_SETTING, $secret, true);
        } catch (\Throwable) {
            // No settings store: a secret for this process alone.
        }

        return $secret;
    }

    /** @param array<string, mixed> $content */
    private static function forgetFiles(array $content): void
    {
        foreach (['path', 'thumb'] as $key) {
            $path = (string) ($content[$key] ?? '');
            if ($path !== '') {
                try {
                    self::disk()->delete($path);
                } catch (\Throwable) {
                    // Gone already.
                }
            }
        }
    }

    private static function fit(\GdImage $image, int $side): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1, $side / max($w, $h));
        if ($scale >= 1) {
            return $image;
        }
        $out = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
        imagecopyresampled($out, $image, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);

        return $out;
    }

    private static function jpeg(\GdImage $image, int $quality): string
    {
        // A transparent picture on white, not on black.
        $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        ob_start();
        imagejpeg($flat, null, $quality);

        return (string) ob_get_clean();
    }

    private static function day(mixed $day): ?string
    {
        $day = trim((string) $day);
        $at  = \DateTime::createFromFormat('!Y-m-d', $day);

        return $at !== false && $at->format('Y-m-d') === $day ? $day : null;
    }

    private static function disk(): \Pramnos\Storage\StorageInterface
    {
        $name = (string) (self::config()['disk'] ?? '');

        return $name !== '' ? \Pramnos\Storage\Storage::disk($name) : \Pramnos\Storage\Storage::getManager()->defaultDisk();
    }

    private static function limit(string $key, int $default): int
    {
        $given = self::config()[$key] ?? null;

        return is_numeric($given) && (int) $given > 0 ? (int) $given : $default;
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        $photos = ((array) \Pramnos\Llm\Llm::config('chat', []))['photos'] ?? null;

        return is_array($photos) ? $photos : [];
    }

    private function table(): \Pramnos\Database\QueryBuilder
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('assist.chat_artifacts');
    }
}
