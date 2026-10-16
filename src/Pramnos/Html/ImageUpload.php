<?php

declare(strict_types=1);

namespace Pramnos\Html;

/**
 * A file input whose picture is cropped, turned and made the right size in the browser before it
 * is sent: the markup `pf-image.js` reads, and the scripts it needs.
 *
 * ```php
 * <?php echo \Pramnos\Html\ImageUpload::assets(); ?>
 * <input type="file" name="cover" accept="image/*"
 *        <?php echo \Pramnos\Html\ImageUpload::attributes(['aspect' => '16/9', 'maxWidth' => 1600]); ?>>
 * ```
 *
 * The server still checks and sizes what arrives: a browser without JavaScript, or one that
 * cannot set a file input's files, sends the original.
 */
final class ImageUpload
{
    /** Where `libraries:sync cropperjs` puts Cropper.js, under the web root. */
    public const LIBRARY = 'assets/vendor/cropperjs/1.6.2/';

    /** The options {@see attributes()} takes, and the attribute each becomes. */
    private const OPTIONS = [
        'aspect'    => 'data-aspect',
        'width'     => 'data-width',
        'height'    => 'data-height',
        'maxWidth'  => 'data-max-width',
        'maxHeight' => 'data-max-height',
        'type'      => 'data-type',
        'quality'   => 'data-quality',
        'round'     => 'data-round',
        'preview'   => 'data-preview',
        'submit'    => 'data-submit',
        'crop'      => 'data-crop',
    ];

    /** The dialog's words, translated through `t()` into `data-words`. */
    private const WORDS = ['Crop the picture', 'Rotate left', 'Rotate right', 'Flip', 'Zoom in', 'Zoom out', 'Reset', 'Cancel', 'Use this picture'];

    /**
     * The input's attributes, escaped, with a leading space.
     *
     * Options: `aspect` (`1`, `'16/9'`, `'4:3'` — the crop is held to it), `width`/`height`
     * (exactly), `maxWidth`/`maxHeight` (at most, never enlarged), `type` (`image/jpeg`,
     * `image/png`, `image/webp`), `quality`, `round`, `preview` (a selector for an `<img>`),
     * `submit` (send the form once ready), `crop` (false: only resized). An unknown option is
     * refused, so a misspelt one is not silently ignored.
     *
     * @param array<string, scalar|null> $options
     */
    public static function attributes(array $options = []): string
    {
        $html = ' data-pf-image';
        foreach ($options as $name => $value) {
            if (!isset(self::OPTIONS[$name])) {
                throw new \InvalidArgumentException('ImageUpload: no option "' . $name . '"; it takes ' . implode(', ', array_keys(self::OPTIONS)) . '.');
            }
            if ($value === null || $value === '') {
                continue;
            }
            $html .= ' ' . self::OPTIONS[$name] . '="' . self::e(is_bool($value) ? ($value ? '1' : '0') : (string) $value) . '"';
        }
        $words = [];
        foreach (self::WORDS as $one) {
            $translated = function_exists('t') ? (string) t($one) : $one;
            if ($translated !== $one) {
                $words[$one] = $translated;
            }
        }

        return $words === [] ? $html : $html . " data-words='" . self::e((string) json_encode($words, JSON_UNESCAPED_UNICODE)) . "'";
    }

    /**
     * The tags a page with such an input needs: Cropper.js where it is installed, and
     * `pf-image.js`. Without Cropper.js a picture is only resized, never offered for cropping, so
     * a project that has not synced the library gets a working form rather than a missing script.
     */
    public static function assets(): string
    {
        $html = '';
        if (self::installed()) {
            $html .= '<link rel="stylesheet" href="' . self::e(assetUrl(self::LIBRARY . 'cropper.min.css')) . '">' . "\n"
                . '<script src="' . self::e(assetUrl(self::LIBRARY . 'cropper.min.js')) . '" defer></script>' . "\n";
        }

        return $html . '<script src="' . self::e(assetUrl('assets/js/pf-image.js')) . '" defer></script>' . "\n";
    }

    /** Whether Cropper.js is in this project's web root. */
    public static function installed(): bool
    {
        if (!defined('ROOT')) {
            return false;
        }

        return is_file(\Pramnos\Application\FrameworkScripts::webRoot((string) ROOT) . '/' . self::LIBRARY . 'cropper.min.js');
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
