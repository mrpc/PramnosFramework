<?php

declare(strict_types=1);

namespace Pramnos\Html;

/**
 * Which editor the body of a message is written in, and the scripts that bring it.
 *
 * Chosen per application in `app/app.php`:
 *
 * ```php
 * 'messaging' => [
 *     'editor'  => 'builtin',            // builtin (the default) | tinymce | none
 *     'tinymce' => [
 *         'src'         => '/assets/vendor/tinymce/7.2.0/tinymce.min.js',   // default: the CDN
 *         'license_key' => 'gpl',        // TinyMCE's own requirement; yours to decide
 *     ],
 * ],
 * ```
 *
 * - **builtin** — `pf-editor.js`: bold, italic, headings, lists, links, an image by address and
 *   the HTML, with pasting as text. No dependency and nothing for a Content-Security-Policy to
 *   refuse.
 * - **tinymce** — the full editor. The application decides where it is loaded from and under
 *   which licence, because both are the application's commitments, not the framework's.
 * - **none** — the textarea, as markup.
 *
 * A view marks the field `data-pf-editor="<?= MessageEditor::mode() ?>"` and prints
 * `MessageEditor::scripts()` after it. The message-to-one-user form always uses `builtin`.
 */
final class MessageEditor
{
    public const BUILTIN = 'builtin';
    public const TINYMCE = 'tinymce';
    public const NONE    = 'none';

    /** Where TinyMCE comes from unless the application says — the version `assets.json` lists. */
    public const TINYMCE_CDN = 'https://cdn.jsdelivr.net/npm/tinymce@7.2.0/tinymce.min.js';

    /** The application's choice, or `builtin`. An unknown value reads as `builtin`. */
    public static function mode(): string
    {
        $mode = (string) (self::config()['editor'] ?? self::BUILTIN);

        return in_array($mode, [self::BUILTIN, self::TINYMCE, self::NONE], true) ? $mode : self::BUILTIN;
    }

    /** The `<script>` tags one mode needs, for the fields marked with it. */
    public static function scripts(string $mode): string
    {
        $attr = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        if ($mode === self::BUILTIN) {
            return '<script src="' . $attr((defined('sURL') ? (string) \sURL : '/') . 'assets/js/pf-editor.js') . '"></script>';
        }

        if ($mode === self::TINYMCE) {
            $tinymce = (array) (self::config()['tinymce'] ?? []);
            $options = [
                'selector'     => 'textarea[data-pf-editor="tinymce"]',
                'license_key'  => (string) ($tinymce['license_key'] ?? 'gpl'),
                'menubar'      => false,
                'plugins'      => 'link lists image code',
                'toolbar'      => 'bold italic | blocks | bullist numlist | link image | removeformat | code',
                'convert_urls' => false,
            ];

            return '<script src="' . $attr((string) ($tinymce['src'] ?? self::TINYMCE_CDN)) . '"></script>'
                . '<script>if (window.tinymce) { tinymce.init(' . json_encode($options, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '); }</script>';
        }

        return '';
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        $info = \Pramnos\Application\Application::currentInstance()?->applicationInfo;
        $messaging = is_array($info) ? ($info['messaging'] ?? []) : [];

        return is_array($messaging) ? $messaging : [];
    }
}
