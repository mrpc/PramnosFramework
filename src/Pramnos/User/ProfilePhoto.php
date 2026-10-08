<?php

declare(strict_types=1);

namespace Pramnos\User;

use Pramnos\Application\Settings;
use Pramnos\Media\MediaObject;

/**
 * A user's profile picture: stored through Media, recorded as `users.photo`, read as a URL.
 *
 * `users.photo` holds a media **usage** id, so the picture is one `mediause` row pointing at one
 * `media` row, and replacing or removing it releases that usage — the image goes when nothing
 * else uses it. Whatever arrives — JPEG, PNG, GIF or WebP — is stored as JPEG: the original at
 * most 1024 pixels on its longer side, and a square crop of {@see SIZE} pixels that is the
 * picture shown.
 *
 * {@see User::load()} turns the usage into `avatarurl`, an absolute URL, so `/me`, the OIDC
 * `picture` claim and every screen that shows an avatar carry it with nothing more to do.
 *
 * ```php
 * $error = ProfilePhoto::set($user, $_FILES['photo']);   // null on success
 * ProfilePhoto::remove($user);
 * ProfilePhoto::adoptFromProvider($user, $providerPictureUrl); // SSO callbacks, opt-in
 * ```
 */
final class ProfilePhoto
{
    /** The side of the square, in pixels. */
    public const SIZE = 256;

    /** The media usage module the picture is recorded under; `specific` is the userid. */
    public const MODULE = 'userphoto';

    /** The longer side of the stored original, in pixels. */
    private const MAX_SIDE = 1024;

    /** The largest file accepted. */
    private const MAX_BYTES = 10 * 1024 * 1024;

    /** The most pixels a picture may declare, so a small file cannot announce a huge image. */
    private const MAX_PIXELS = 40_000_000;

    /** The image types accepted: JPEG, PNG, GIF and WebP, whatever the file was called. */
    private const TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];

    /** The setting that hides the Gravatar button. On unless set to off. */
    public const GRAVATAR_SETTING = 'profile_photo_gravatar';

    /** The setting that lets an SSO sign-in copy the provider's picture. Off unless set. */
    public const ADOPT_SETTING = 'profile_photo_from_provider';

    /** @var array<int, string> URLs already resolved in this request, by usage id */
    private static array $urls = [];

    /**
     * Set the user's picture from an uploaded file: an entry of `$_FILES`.
     *
     * JPEG, PNG, GIF or WebP, judged by the file's own bytes and not by what the browser said it
     * was. Whatever arrives is stored as JPEG ({@see toJpeg()}). The previous picture's usage is
     * released once the new one is recorded, so a failed upload leaves the old picture in place.
     *
     * @param User                 $user
     * @param array<string, mixed> $file A `$_FILES` entry
     * @return string|null Why it was refused, or null when the picture was set
     */
    public static function set(User $user, array $file): ?string
    {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return 'The picture did not arrive. Please choose it again.';
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        // Only a file PHP received in this request: a forged `tmp_name` would otherwise read
        // any file the server can. A test hands in a file of its own, as Media's tests do.
        $testing = defined('UNITTESTING') && UNITTESTING === true;
        if ($tmp === '' || (!$testing && !is_uploaded_file($tmp)) || !is_file($tmp)) {
            return 'The picture did not arrive. Please choose it again.';
        }

        return self::store($user, (string) file_get_contents($tmp), self::media());
    }

    /**
     * Copy a picture from a URL — an SSO provider's — when the user has none and the
     * installation allows it ({@see ADOPT_SETTING}).
     *
     * Copied, never linked: a provider's picture URL expires, and a page whose Content Security
     * Policy allows images from itself only would not show it. The fetch goes through
     * `OutboundUrl`, which refuses private and internal addresses, and what comes back is
     * stored as JPEG like an upload.
     *
     * @param callable|null $fetch fn(string $url): string|false — how the bytes are fetched; a
     *                             test hands in its own
     * @return bool Whether a picture was copied
     */
    public static function adoptFromProvider(User $user, string $url, ?callable $fetch = null): bool
    {
        if ((int) $user->photo > 0 || trim($url) === '' || !self::adoptionAllowed()) {
            return false;
        }

        $fetch ??= static fn (string $url): string|false => \Pramnos\Security\OutboundUrl::fetch($url, self::MAX_BYTES, $reason, 10, 3);
        $bytes = $fetch($url);

        return is_string($bytes) && self::store($user, $bytes, self::media()) === null;
    }

    /**
     * Take the user's picture from Gravatar, because they asked to.
     *
     * Only on the user's own request — the profile screen's button. Gravatar is asked for the
     * picture of a SHA-256 of the account's address, so the hash goes to a third party at the
     * moment, and for the person, who chose it; nobody else's does, and nobody's does by
     * default. The image is fetched by the server and copied like an upload: Gravatar sees
     * neither the visitor's address nor when their pages are read, and the picture does not
     * depend on Gravatar afterwards. `d=404` makes "no Gravatar for this address" an answer of
     * its own rather than Gravatar's generic placeholder stored as the user's face.
     *
     * @param callable|null $fetch fn(string $url, ?int &$status): string|false — how the bytes
     *                             are fetched; a test hands in its own
     * @return string|null Why it did not happen, or null when the picture was set
     */
    public static function fromGravatar(User $user, ?callable $fetch = null): ?string
    {
        if (!self::gravatarAllowed()) {
            return 'Gravatar is not available here.';
        }

        $email = strtolower(trim((string) ($user->email ?? '')));
        if ($email === '') {
            return 'Your account has no email address to look up on Gravatar.';
        }

        $url    = 'https://www.gravatar.com/avatar/' . hash('sha256', $email) . '?s=' . self::MAX_SIDE . '&d=404';
        $fetch ??= static fn (string $url, ?int &$status): string|false
            => \Pramnos\Security\OutboundUrl::fetch($url, self::MAX_BYTES, $reason, 10, 3, $status);
        $status = 0;
        $bytes  = $fetch($url, $status);

        if ($status === 404) {
            return 'There is no Gravatar picture for your email address.';
        }
        if (!is_string($bytes) || $status < 200 || $status >= 300) {
            return 'Gravatar could not be reached. Please try again later.';
        }

        return self::store($user, $bytes, self::media());
    }

    /** Whether the installation offers the Gravatar button: yes, unless the setting turns it off. */
    public static function gravatarAllowed(): bool
    {
        $value = strtolower(trim((string) Settings::getSetting(self::GRAVATAR_SETTING, '')));

        return !in_array($value, ['0', 'no', 'false', 'off'], true);
    }

    /**
     * Remove the user's picture, releasing its usage.
     *
     * @return void
     */
    public static function remove(User $user): void
    {
        $usageId = (int) $user->photo;
        if ($usageId <= 0) {
            return;
        }

        $user->photo = null;
        $user->save();
        self::release($usageId);
    }

    /**
     * Release a picture's usage without touching the user: for erasure, where the row goes too.
     *
     * The image is deleted when nothing else uses it.
     */
    public static function release(int $usageId): void
    {
        if ($usageId <= 0) {
            return;
        }

        unset(self::$urls[$usageId]);
        try {
            (new MediaObject())->removeUsage($usageId);
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log('Could not release profile picture usage ' . $usageId . ': ' . $exception->getMessage());
        }
    }

    /**
     * The picture's absolute URL, or '' when there is none or it cannot be read.
     */
    public static function url(int $usageId): string
    {
        if ($usageId <= 0) {
            return '';
        }

        if (!array_key_exists($usageId, self::$urls)) {
            try {
                $media = new MediaObject();
                $media->loadByUsage($usageId);
                $url = (int) $media->mediaid > 0 ? $media->publicUrl('thumb') : '';
                if ($url !== '' && preg_match('#^https?://#i', $url) !== 1) {
                    // SiteUrl knows the root from APP_URL or the request; a process that has
                    // neither still has the site's own base, which is better than a path.
                    $url = \Pramnos\Http\SiteUrl::to(ltrim($url, '/'));
                    if (preg_match('#^https?://#i', $url) !== 1 && defined('sURL')) {
                        $url = rtrim((string) \sURL, '/') . '/' . ltrim($url, '/');
                    }
                }
                self::$urls[$usageId] = $url;
            } catch (\Throwable) {
                self::$urls[$usageId] = '';
            }
        }

        return self::$urls[$usageId];
    }

    /** Forget the URLs resolved so far: for a long-running process or a test. */
    public static function reset(): void
    {
        self::$urls = [];
    }

    /** A media object set up for a profile picture: a square thumb, no medium. */
    private static function media(): MediaObject
    {
        $media = new MediaObject();
        $media->thumb        = self::SIZE;
        $media->thumbHeight  = self::SIZE;
        $media->medium       = 0;
        $media->allowUpscale = true;

        return $media;
    }

    /**
     * Convert image bytes to a JPEG file and hand it to Media.
     *
     * @return string|null Why it was refused, or null
     */
    private static function store(User $user, string $bytes, MediaObject $media): ?string
    {
        $jpeg = self::toJpeg($bytes, $reason);
        if ($jpeg === null) {
            return $reason;
        }

        $media->addImage($jpeg, self::MODULE, true);
        @unlink($jpeg);
        if ($media->error === false && (int) $media->mediaid === 0 && (int) $media->medialink === 0) {
            // addImage() makes the files and leaves the record to the caller.
            $media->save();
        }

        return self::adopt($user, $media);
    }

    /**
     * The image as a JPEG file: any of {@see TYPES}, flattened onto white, at most 1024 pixels
     * on its longer side. Null, with the reason, when it is not one or is too large.
     *
     * Decoded only after its header has been read: a small file can declare a huge image, and
     * decoding it would take the memory the header announced.
     */
    public static function toJpeg(string $bytes, ?string &$reason = null): ?string
    {
        $reason = null;
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            $reason = 'The picture is empty or larger than ' . (int) (self::MAX_BYTES / 1048576) . ' MB.';

            return null;
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false || !in_array($info[2] ?? 0, self::TYPES, true)) {
            $reason = 'Please choose a JPEG, PNG, GIF or WebP picture.';

            return null;
        }
        if ((int) $info[0] * (int) $info[1] > self::MAX_PIXELS) {
            $reason = 'The picture is too large. Please choose a smaller one.';

            return null;
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            $reason = 'The picture could not be read.';

            return null;
        }

        $width  = imagesx($source);
        $height = imagesy($source);
        $scale  = min(1, self::MAX_SIDE / max($width, $height));
        $outW   = max(1, (int) round($width * $scale));
        $outH   = max(1, (int) round($height * $scale));

        // White underneath: a JPEG has no transparency, and GD fills it black otherwise.
        $out = imagecreatetruecolor($outW, $outH);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $source, 0, 0, 0, 0, $outW, $outH, $width, $height);

        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'photo-' . bin2hex(random_bytes(8)) . '.jpg';
        $written = imagejpeg($out, $file, 88);
        if (!$written) {
            $reason = 'The picture could not be stored.';

            return null;
        }

        return $file;
    }

    /**
     * Record a stored image as the user's picture, and release the one it replaces.
     *
     * @return string|null Why it was refused, or null
     */
    private static function adopt(User $user, MediaObject $media): ?string
    {
        if ($media->error !== false) {
            return 'The picture could not be used: ' . $media->error;
        }

        // The same image uploaded before is not stored twice: Media points at the first copy.
        $mediaId = (int) $media->mediaid ?: (int) $media->medialink;
        if ($mediaId <= 0) {
            return 'The picture could not be stored.';
        }
        if ((int) $media->mediaid !== $mediaId) {
            $media = new MediaObject($mediaId);
        }

        $usageId = (int) $media->addUsage(self::MODULE, (string) (int) $user->userid);
        $previous = (int) $user->photo;

        $user->photo = $usageId;
        $user->save();
        if ($previous > 0 && $previous !== $usageId) {
            self::release($previous);
        }
        unset(self::$urls[$usageId]);

        return null;
    }

    /** Whether the installation lets a sign-in copy a provider's picture. */
    private static function adoptionAllowed(): bool
    {
        return in_array(strtolower(trim((string) Settings::getSetting(self::ADOPT_SETTING, ''))), ['1', 'yes', 'true', 'on'], true);
    }
}
