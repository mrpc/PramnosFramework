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
 *     'editor'  => 'builtin',            // builtin (the default) | rich | tinymce | none
 *     'rich'    => [
 *         'upload' => '/admin/Media/upload',   // where a picture is POSTed (`file`); answers {"url": …}
 *         'links'  => '/admin/search',         // where the link panel searches (?q=); answers [{title, url}]
 *         'block_toolbar' => true,             // a toolbar over the block at the cursor; off by default
 *     ],
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
 * - **rich** — `pf-rich-editor.js`, TipTap bundled: pictures with captions, uploaded by drop,
 *   paste or choice, a link panel that searches the site, blocks moved with Alt+↑/↓, embeds, and
 *   plugins a site adds of its own (`pfRichEditor.use()`). No CDN and no licence question — MIT.
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
    public const RICH    = 'rich';
    public const TINYMCE = 'tinymce';
    public const NONE    = 'none';

    /** Where TinyMCE comes from unless the application says — the version `assets.json` lists. */
    public const TINYMCE_CDN = 'https://cdn.jsdelivr.net/npm/tinymce@7.2.0/tinymce.min.js';

    /**
     * How the builtin editor's area shows what it holds.
     *
     * A theme's CSS reset — Tailwind's preflight is the common one — makes a heading the size
     * of body text and takes the bullets off a list, so the markup is there and looks like
     * nothing happened. Scoped to the area, so the page around it is untouched; printed as an
     * inline `<style>`, which the document gives the CSP nonce.
     */
    public const BUILTIN_CSS = '.pf-editor-area h2{font-size:1.5em;font-weight:600;line-height:1.3;margin:.6em 0 .3em}'
        . '.pf-editor-area h3{font-size:1.25em;font-weight:600;line-height:1.3;margin:.6em 0 .3em}'
        . '.pf-editor-area p{margin:0 0 .6em}'
        . '.pf-editor-area ul{list-style:disc;padding-left:1.5em;margin:0 0 .6em}'
        . '.pf-editor-area ol{list-style:decimal;padding-left:1.5em;margin:0 0 .6em}'
        . '.pf-editor-area li{display:list-item}'
        . '.pf-editor-area blockquote{border-left:3px solid #ccc;margin:0 0 .6em;padding-left:.8em;color:#555}'
        . '.pf-editor-area a{color:#0b57d0;text-decoration:underline}'
        . '.pf-editor-area strong,.pf-editor-area b{font-weight:700}'
        . '.pf-editor-area em,.pf-editor-area i{font-style:italic}'
        . '.pf-editor-area img{max-width:100%;height:auto}';

    /**
     * How the rich editor and its text look, scoped to `.pf-rich`: a theme's reset — Tailwind's
     * preflight — would otherwise make a heading body text and take the bullets off a list.
     */
    public const RICH_CSS = '.pf-rich{border:1px solid rgba(128,128,128,.35);border-radius:.5rem}'
        . '.pf-rich-toolbar{position:sticky;top:0;z-index:2;display:flex;flex-wrap:wrap;gap:4px;padding:6px;border-bottom:1px solid rgba(128,128,128,.25);background:inherit}'
        . '.pf-rich-button{border:1px solid rgba(128,128,128,.35);border-radius:.35rem;background:none;color:inherit;padding:2px 8px;font-size:.85rem;cursor:pointer}'
        . '.pf-rich-button.is-active{background:rgba(128,128,128,.2);font-weight:600}'
        . '.pf-rich-button:disabled{opacity:.5;cursor:default}'
        . '.pf-rich-spacer{flex:1}'
        . '.pf-rich-panel{display:flex;flex-wrap:wrap;align-items:end;gap:8px;padding:6px;border-bottom:1px solid rgba(128,128,128,.25);font-size:.85rem}'
        . '.pf-rich-panel label{display:flex;flex-direction:column;gap:2px;flex:1;min-width:12rem}'
        . '.pf-rich-input{border:1px solid rgba(128,128,128,.35);border-radius:.35rem;padding:2px 6px;background:transparent;color:inherit;font:inherit}'
        . '.pf-rich-found{list-style:none;margin:0;padding:0;width:100%;max-height:12rem;overflow:auto}'
        . '.pf-rich-found button{background:none;border:0;color:inherit;cursor:pointer;text-align:left;padding:2px 4px;width:100%}'
        . '.pf-rich-area{padding:12px 16px}'
        . '.pf-rich-area.has-block-toolbar{position:relative;padding-top:40px}'
        . '.pf-rich-block{position:absolute;left:16px;z-index:3;display:flex;gap:2px;padding:2px;border:1px solid rgba(128,128,128,.35);border-radius:.4rem;background:#fff;color:#111;box-shadow:0 4px 12px rgba(0,0,0,.15)}'
        . '.pf-rich-block[hidden]{display:none}'
        . '.pf-rich-block-gap{width:1px;margin:0 2px;background:rgba(128,128,128,.35)}'
        . '.pf-rich-text{min-height:12rem;outline:none}'
        . '.pf-rich-text h2{font-size:1.5em;font-weight:600;line-height:1.3;margin:.6em 0 .3em}'
        . '.pf-rich-text h3{font-size:1.25em;font-weight:600;line-height:1.3;margin:.6em 0 .3em}'
        . '.pf-rich-text p{margin:0 0 .6em}'
        . '.pf-rich-text ul{list-style:disc;padding-left:1.5em;margin:0 0 .6em}'
        . '.pf-rich-text ol{list-style:decimal;padding-left:1.5em;margin:0 0 .6em}'
        . '.pf-rich-text li{display:list-item}'
        . '.pf-rich-text blockquote{border-left:3px solid #ccc;margin:0 0 .6em;padding-left:.8em;opacity:.85}'
        . '.pf-rich-text a{color:#0b57d0;text-decoration:underline}'
        . '.pf-rich-text strong{font-weight:700}.pf-rich-text em{font-style:italic}'
        . '.pf-rich-text figure{margin:.6em 0}.pf-rich-text img{max-width:100%;height:auto}'
        . '.pf-rich-text figcaption{font-size:.85em;opacity:.75;text-align:center}'
        . '.pf-rich-text .ProseMirror-selectednode{outline:2px solid #0b57d0}'
        . '.pf-rich-kept{background:rgba(11,87,208,.2)}'
        . '.pf-rich-embed-source{font-size:.85em;opacity:.7}'
        . '.pf-rich-embed{margin:0 0 .6em}.pf-rich-embed.is-video iframe{width:100%;aspect-ratio:16/9;border:0}';

    /** The application's choice, or `builtin`. An unknown value reads as `builtin`. */
    public static function mode(): string
    {
        $mode = (string) (self::config()['editor'] ?? self::BUILTIN);

        return in_array($mode, [self::BUILTIN, self::RICH, self::TINYMCE, self::NONE], true) ? $mode : self::BUILTIN;
    }

    /** The `<script>` tags one mode needs, for the fields marked with it. */
    public static function scripts(string $mode): string
    {
        $attr = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        if ($mode === self::BUILTIN) {
            return '<style>' . self::BUILTIN_CSS . '</style>'
                . '<script src="' . $attr(assetUrl('assets/js/pf-editor.js')) . '"></script>';
        }

        if ($mode === self::RICH) {
            $rich     = (array) (self::config()['rich'] ?? []);
            $defaults = ['labels' => self::richLabels()];
            foreach (['upload', 'links'] as $key) {
                if (is_string($rich[$key] ?? null) && $rich[$key] !== '') {
                    $defaults[$key] = $rich[$key];
                }
            }
            $defaults['blockToolbar'] = (bool) ($rich['block_toolbar'] ?? false);

            return '<style>' . self::RICH_CSS . '</style>'
                . '<script>window.pfRichEditorDefaults = ' . json_encode($defaults, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>'
                . '<script src="' . $attr(assetUrl('assets/js/pf-rich-editor.js')) . '"></script>';
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

    /**
     * The rich editor's controls in the page's language — the English of `LABELS` in
     * `resources/js/rich-editor/core.js`, through `t()`.
     *
     * @return array<string, string>
     */
    private static function richLabels(): array
    {
        $english = [
            'formatting' => 'Formatting', 'bold' => 'Bold', 'italic' => 'Italic', 'heading' => 'Heading',
            'subheading' => 'Subheading', 'bulletList' => 'Bulleted list', 'orderedList' => 'Numbered list',
            'quote' => 'Quote', 'link' => 'Link', 'picture' => 'Picture', 'pictureFile' => 'Upload a picture',
            'pictureUrl' => 'Picture by address', 'pictureAddress' => 'Picture address',
            'moveUp' => 'Move the block up (Alt+↑)', 'moveDown' => 'Move the block down (Alt+↓)',
            'undo' => 'Undo', 'redo' => 'Redo', 'address' => 'Address', 'open' => 'Open ↗',
            'removeLink' => 'Remove link', 'findLink' => 'Find a page on the site — its title or address',
            'altText' => 'Alt text', 'altHint' => 'What the picture shows, for who cannot see it',
            'caption' => 'Caption', 'captionHint' => 'Shown under the picture', 'body' => 'Text',
            'block' => 'Block', 'paragraph' => 'Paragraph',
        ];

        return function_exists('t') ? array_map(static fn (string $words): string => (string) t($words), $english) : $english;
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        $info = \Pramnos\Application\Application::currentInstance()?->applicationInfo;
        $messaging = is_array($info) ? ($info['messaging'] ?? []) : [];

        return is_array($messaging) ? $messaging : [];
    }
}
