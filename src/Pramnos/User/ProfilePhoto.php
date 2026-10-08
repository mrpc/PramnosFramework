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
 * else uses it. The picture is a square crop ({@see SIZE} pixels) made at upload, in the
 * upload's own format (PNG stays PNG, JPEG stays JPEG, a GIF becomes PNG); the original is kept
 * beside it, capped at 1024 pixels.
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

    /** The setting that lets an SSO sign-in copy the provider's picture. Off unless set. */
    public const ADOPT_SETTING = 'profile_photo_from_provider';

    /** @var array<int, string> URLs already resolved in this request, by usage id */
    private static array $urls = [];

    /**
     * Set the user's picture from an uploaded file: an entry of `$_FILES`.
     *
     * The previous picture's usage is released once the new one is recorded, so a failed
     * upload leaves the old picture in place.
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

        $media = self::media();
        $media->uploadImage($file, self::MODULE);

        return self::adopt($user, $media);
    }

    /**
     * Copy a picture from a URL — an SSO provider's — when the user has none and the
     * installation allows it ({@see ADOPT_SETTING}).
     *
     * Copied, never linked: a provider's picture URL expires, and a page whose Content Security
     * Policy allows images from itself only would not show it. The fetch goes through
     * `OutboundUrl`, which refuses private and internal addresses.
     *
     * @param MediaObject|null $media The media object to fetch with; a test hands in its own
     * @return bool Whether a picture was copied
     */
    public static function adoptFromProvider(User $user, string $url, ?MediaObject $media = null): bool
    {
        if ((int) $user->photo > 0 || trim($url) === '' || !self::adoptionAllowed()) {
            return false;
        }

        $media = self::media($media);
        $media->addRemoteImage($url, self::MODULE);
        if ($media->error === false && (int) $media->mediaid === 0 && (int) $media->medialink === 0) {
            // addRemoteImage() makes the files but leaves the record to the caller.
            $media->save();
        }

        return self::adopt($user, $media) === null;
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

    /** A media object set up for a profile picture: a square thumb, no medium, a capped original. */
    private static function media(?MediaObject $media = null): MediaObject
    {
        $media ??= new MediaObject();
        $media->thumb        = self::SIZE;
        $media->thumbHeight  = self::SIZE;
        $media->medium       = 0;
        $media->max          = 1024;
        $media->allowUpscale = true;

        return $media;
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
