<?php

declare(strict_types=1);

namespace Pramnos\Media;

/**
 * What a picture is, whether this server can read it, and — where Imagick is installed — a picture
 * GD cannot read (a phone's HEIC, an AVIF) turned into one it can, at upload.
 *
 * Imagick is never required. Without it everything is GD's, as it always was, and a format only
 * Imagick reads is not offered for upload. With it (`class_exists('Imagick')` and the format among
 * `Imagick::queryFormats()`), {@see toWebImage()} converts such a picture to JPEG — PNG where it is
 * transparent — upright, its metadata (GPS among it) left out.
 *
 * ```php
 * Imaging::format($path);          // 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'avif', or null
 * Imaging::readable($path);        // can this server open it, by GD or Imagick?
 * Imaging::uploadExtensions();     // ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', …] — what this server takes
 * Imaging::toWebImage($path);      // a HEIC → its JPEG beside it, or null when nothing needed doing
 * Imaging::normalize($path);       // an iPhone's Display P3 JPEG → sRGB, in place; false when nothing needed doing
 * ```
 *
 * Colours: a photograph in a wide gamut (an iPhone's Display P3) is converted to sRGB, the colours
 * every browser assumes and GD keeps — GD drops the colour profile, and the thumbnails it makes
 * would otherwise shift. The sRGB profile is built here ({@see IccProfile}), so no `sRGB.icc` file
 * is needed on the server.
 *
 * A user's file reaches Imagick only once its first bytes say it is one of the raster formats named
 * here, and Imagick is told which decoder to use rather than left to guess: a PDF, a PostScript, an
 * SVG or an MSL/MVG script named `.jpg` never gets that far. Resource limits stop a small file that
 * claims an enormous picture from taking the server's memory.
 */
final class Imaging
{
    /** The formats Imagick may convert, each with its decoder and the extensions it is uploaded under. */
    private const CONVERTIBLE = [
        'heic' => ['coder' => 'HEIC', 'extensions' => ['heic', 'heif']],
        'avif' => ['coder' => 'AVIF', 'extensions' => ['avif']],
    ];

    /** The mime types a browser sends for them. */
    public const CONVERTIBLE_MIMES = ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence', 'image/avif'];

    /**
     * Imagick's limits for a user's picture: memory, pixels, seconds — and one thread, since
     * ImageMagick takes every core by default, and under PHP-FPM many requests at once would.
     */
    private const LIMITS = ['memory' => 256 * 1024 * 1024, 'area' => 64 * 1000 * 1000, 'time' => 30, 'thread' => 1];

    /** The formats {@see normalize()} rewrites in place, by Imagick's coder. */
    private const NORMALIZED = ['jpeg' => 'JPEG', 'png' => 'PNG', 'webp' => 'WEBP'];

    /** @var list<string>|null Imagick's formats, asked once */
    private static ?array $imagickFormats = null;

    /**
     * What a file's content is, from its first bytes — never from its name.
     *
     * @return string|null 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic' or 'avif'; null for anything else
     */
    public static function format(string $file): ?string
    {
        $head = is_file($file) && is_readable($file) ? (string) @file_get_contents($file, false, null, 0, 32) : '';
        if (strlen($head) < 12) {
            return null;
        }
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'jpeg';
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            return 'png';
        }
        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            return 'gif';
        }
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return 'webp';
        }
        if (str_starts_with($head, 'BM')) {
            return 'bmp';
        }
        // ISO base media: a `ftyp` box whose brand says which.
        if (substr($head, 4, 4) === 'ftyp') {
            $brand = substr($head, 8, 4);
            if (in_array($brand, ['avif', 'avis'], true)) {
                return 'avif';
            }
            if (in_array($brand, ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1'], true)) {
                return 'heic';
            }
        }

        return null;
    }

    /** Whether Imagick is installed. */
    public static function imagick(): bool
    {
        return class_exists(\Imagick::class);
    }

    /** Whether GD reads this format here. */
    public static function gdReads(string $format): bool
    {
        return match ($format) {
            'jpeg' => function_exists('imagecreatefromjpeg'),
            'png'  => function_exists('imagecreatefrompng'),
            'gif'  => function_exists('imagecreatefromgif'),
            'webp' => function_exists('imagecreatefromwebp'),
            'bmp'  => function_exists('imagecreatefrombmp'),
            'avif' => function_exists('imagecreatefromavif') && (imagetypes() & (defined('IMG_AVIF') ? IMG_AVIF : 0)) !== 0,
            default => false,
        };
    }

    /** Whether Imagick converts this format here: one this class lets it, and one it reads. */
    public static function imagickReads(string $format): bool
    {
        if (!isset(self::CONVERTIBLE[$format]) || !self::imagick()) {
            return false;
        }
        try {
            self::$imagickFormats ??= array_map('strtoupper', \Imagick::queryFormats());
        } catch (\Throwable) {
            self::$imagickFormats = [];
        }

        return in_array(self::CONVERTIBLE[$format]['coder'], self::$imagickFormats, true);
    }

    /** Whether this server can open the file at all, by GD or by Imagick. */
    public static function readable(string $file): bool
    {
        $format = self::format($file);

        return $format !== null && (self::gdReads($format) || self::imagickReads($format));
    }

    /**
     * The picture extensions this server takes for upload: GD's, and those Imagick converts here.
     *
     * @return list<string>
     */
    public static function uploadExtensions(): array
    {
        $extensions = ['jpg', 'jpeg', 'png', 'gif'];
        if (self::gdReads('webp')) {
            $extensions[] = 'webp';
        }

        return array_values(array_unique(array_merge($extensions, self::convertibleExtensions())));
    }

    /**
     * The extensions only Imagick reads here, and so converted at upload: `heic`, `heif`, `avif`
     * where Imagick has them; none without it.
     *
     * @return list<string>
     */
    public static function convertibleExtensions(): array
    {
        $out = [];
        foreach (self::CONVERTIBLE as $format => $about) {
            if (!self::gdReads($format) && self::imagickReads($format)) {
                array_push($out, ...$about['extensions']);
            }
        }

        return $out;
    }

    /**
     * A picture GD cannot read, as one it can: JPEG, or PNG where it is transparent — upright, its
     * metadata (GPS among it) left out — written beside it under the new extension, the original
     * removed.
     *
     * @return array{file: string, ext: string, mime: string}|null The new file; null when nothing
     *         needed doing (GD reads it, or it is no picture this class knows) or it could not be done
     */
    public static function toWebImage(string $file): ?array
    {
        $format = self::format($file);
        if ($format === null || self::gdReads($format) || !self::imagickReads($format)) {
            return null;
        }
        try {
            self::limit();
            $image = new \Imagick();
            // Its decoder named, so the content cannot choose another.
            $image->readImage(self::CONVERTIBLE[$format]['coder'] . ':' . $file);
            $image->setIteratorIndex(0);
            self::upright($image);
            self::toSrgb($image);
            $transparent = self::transparent($image);
            $image->stripImage();
            $ext = $transparent ? 'png' : 'jpg';
            $image->setImageFormat($transparent ? 'PNG' : 'JPEG');
            if (!$transparent) {
                $image->setImageCompressionQuality(90);
            }
            $target = self::beside($file, $ext);
            $image->writeImage($target);
            $image->clear();
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Imaging: ' . basename($file) . ' could not be converted: ' . $e->getMessage());

            return null;
        }
        if ($target !== $file) {
            @unlink($file);
        }

        return ['file' => $target, 'ext' => $ext, 'mime' => $transparent ? 'image/png' : 'image/jpeg'];
    }

    /**
     * A picture GD reads, but in a colour profile other than sRGB — an iPhone's Display P3 JPEG —
     * rewritten in place in sRGB, upright, its metadata (GPS among it) left out, so GD's thumbnails
     * keep its colours. Nothing is done to a picture without such a profile, or without Imagick.
     *
     * @return bool Whether it was rewritten
     */
    public static function normalize(string $file): bool
    {
        $format = self::format($file);
        if ($format === null || !isset(self::NORMALIZED[$format]) || !self::imagick()) {
            return false;
        }
        try {
            self::limit();
            $coder = self::NORMALIZED[$format] . ':' . $file;
            // The header alone first: most pictures have nothing to convert.
            $probe = new \Imagick();
            $probe->pingImage($coder);
            $icc = $probe->getImageProfiles('icc', true)['icc'] ?? null;
            $probe->clear();
            if (!is_string($icc) || self::isSrgb($icc)) {
                return false;
            }
            $image = new \Imagick();
            $image->readImage($coder);
            self::upright($image);
            self::toSrgb($image);
            $image->stripImage();
            if ($format === 'jpeg') {
                $image->setImageCompressionQuality(92);
            }
            $image->writeImage($coder);
            $image->clear();
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Imaging: ' . basename($file) . ' kept its colours: ' . $e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * Whether any pixel is less than opaque — not merely whether an alpha channel is there, which a
     * decoder may add to a picture with nothing transparent in it.
     */
    private static function transparent(\Imagick $image): bool
    {
        if (!$image->getImageAlphaChannel()) {
            return false;
        }
        $range = $image->getImageChannelRange(\Imagick::CHANNEL_ALPHA);

        return (float) ($range['minima'] ?? \Imagick::getQuantum()) < (float) \Imagick::getQuantum();
    }

    /** Turned as its EXIF orientation says, and the orientation then reset. */
    private static function upright(\Imagick $image): void
    {
        if (method_exists($image, 'autoOrient')) {
            $image->autoOrient();
        }
    }

    /**
     * In sRGB: converted through its colour profile where it has one that is not sRGB, and from
     * CMYK where it is in it without one.
     */
    private static function toSrgb(\Imagick $image): void
    {
        $icc = $image->getImageProfiles('icc', true)['icc'] ?? null;
        if (is_string($icc) && !self::isSrgb($icc)) {
            $image->profileImage('icc', IccProfile::srgb());
        } elseif ($image->getImageColorspace() === \Imagick::COLORSPACE_CMYK) {
            $image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
        }
    }

    /** Whether a colour profile is sRGB's, by what it calls itself. */
    private static function isSrgb(string $icc): bool
    {
        return stripos(substr($icc, 0, 2048), 'sRGB') !== false;
    }

    /** The file's path with `$ext` for its extension, beside it — not over another file. */
    private static function beside(string $file, string $ext): string
    {
        $dot  = strrpos(basename($file), '.');
        $base = $dot === false ? $file : substr($file, 0, strlen($file) - strlen(basename($file)) + $dot);
        $path = $base . '.' . $ext;
        for ($i = 1; is_file($path) && $path !== $file; $i++) {
            $path = $base . '-' . $i . '.' . $ext;
        }

        return $path;
    }

    /** Imagick's limits for a user's picture, against a small file that claims an enormous one. */
    private static function limit(): void
    {
        foreach ([
            'RESOURCETYPE_MEMORY' => self::LIMITS['memory'],
            'RESOURCETYPE_MAP'    => self::LIMITS['memory'],
            'RESOURCETYPE_AREA'   => self::LIMITS['area'],
            'RESOURCETYPE_TIME'   => self::LIMITS['time'],
            'RESOURCETYPE_THREAD' => self::LIMITS['thread'],
        ] as $constant => $limit) {
            if (defined(\Imagick::class . '::' . $constant)) {
                \Imagick::setResourceLimit(constant(\Imagick::class . '::' . $constant), $limit);
            }
        }
    }
}
