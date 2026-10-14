<?php

declare(strict_types=1);

namespace Pramnos\Assist;

/**
 * The side panel of the administration: Ask about the page you are on, and that page's help.
 *
 * Drawn by `Analyst::headerButton()`, which every scaffolded header already calls, so a project
 * gets it without touching its layout: the button, the panel as an inert `<template>`, and
 * `assets/js/pf-assist.js`, which opens it beside the page. Without the script the button is the
 * link it always was — to Ask, or to the page's help.
 *
 * Ask in the panel is the analyst's own conversation page, fetched and run by `pf-chat.js`, with
 * the page sent along each question (see {@see Page}). Where the analyst is not available — the
 * `llm` feature off, or below its floor — the panel is the help alone; where the page has no help,
 * it is Ask alone; with neither, there is no button.
 */
final class Panel
{
    /**
     * The button, the panel and its script — or '' when there is nothing to offer here.
     *
     * @param bool $ask Whether this person may ask the analyst
     */
    public static function render(bool $ask, string $askUrl, string $before = '', string $after = ''): string
    {
        $context = Page::context();
        $help    = Help::forScreen($context['screen'], $context['action']);

        // On Ask itself, the panel would be the page twice: the link it always was.
        if ($ask && $context['screen'] === 'ai') {
            return $before . '<a class="pf-ask" href="' . self::e($askUrl) . '">' . self::e(self::t('Ask')) . '</a>' . $after;
        }

        if (!$ask && $help === null) {
            return '';
        }

        $label = $ask ? self::t('Ask') : self::t('Help');
        $href  = $ask ? $askUrl : (Help::screen() !== '' ? Help::url((string) $help['slug']) : '#');

        return $before
            . '<a class="pf-ask" href="' . self::e($href) . '" data-pf-assist-open aria-haspopup="dialog">' . self::e($label) . '</a>'
            . '<template data-pf-assist>' . self::panel($ask, $askUrl, $help, $context) . '</template>'
            . '<script src="' . self::e(function_exists('assetUrl') ? assetUrl('assets/js/pf-assist.js') : 'assets/js/pf-assist.js') . '" defer></script>'
            . $after;
    }

    /**
     * @param array<string, mixed>|null $help
     * @param array<string, string>     $context
     */
    private static function panel(bool $ask, string $askUrl, ?array $help, array $context): string
    {
        $both   = $ask && $help !== null;
        $chat   = function_exists('assetUrl') ? assetUrl('assets/js/pf-chat.js') : 'assets/js/pf-chat.js';
        $button = 'style="background:none;border:1px solid rgba(128,128,128,.4);border-radius:6px;padding:2px 8px;color:inherit;cursor:pointer"';
        $html   = '<aside class="pf-assist" role="dialog" aria-label="' . self::e($ask ? self::t('Ask about this page') : self::t('Help')) . '"'
            . ' data-page="' . self::e((string) json_encode($context, JSON_UNESCAPED_UNICODE)) . '"'
            . ' data-ask="' . self::e($askUrl) . '" data-chat-script="' . self::e($chat) . '"'
            . ' data-say-loading="' . self::e(self::t('Loading…')) . '" data-say-failed="' . self::e(self::t('Ask could not be opened here.')) . '"'
            . ' style="position:fixed;top:0;right:0;bottom:0;width:min(460px,100vw);z-index:1080;display:flex;flex-direction:column;'
            . 'background:Canvas;color:CanvasText;box-shadow:-4px 0 24px rgba(0,0,0,.25);font-size:.95rem">'
            . '<header style="display:flex;gap:8px;align-items:center;padding:10px 12px;border-bottom:1px solid rgba(128,128,128,.3)">'
            . '<strong style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'
            . self::e($context['title'] !== '' ? $context['title'] : ($ask ? self::t('Ask') : self::t('Help'))) . '</strong>';

        if ($ask) {
            $html .= '<button type="button" data-pf-assist-new ' . $button . '>' . self::e(self::t('New')) . '</button>'
                . '<a href="' . self::e($askUrl) . '" title="' . self::e(self::t('Open in Ask')) . '" aria-label="' . self::e(self::t('Open in Ask')) . '">↗</a>';
        }

        $html .= '<button type="button" data-pf-assist-close aria-label="' . self::e(self::t('Close')) . '" ' . $button . '>✕</button></header>';

        if ($both) {
            $html .= '<nav style="display:flex;gap:6px;padding:8px 12px 0" role="tablist">'
                . '<button type="button" role="tab" data-pf-assist-tab="ask" aria-selected="true" ' . $button . '>' . self::e(self::t('Ask')) . '</button>'
                . '<button type="button" role="tab" data-pf-assist-tab="help" aria-selected="false" ' . $button . '>' . self::e(self::t('Help')) . '</button>'
                . '</nav>';
        }

        if ($ask) {
            $html .= '<section data-pf-assist-pane="ask" style="flex:1;overflow:auto;padding:12px"></section>';
        }

        if ($help !== null) {
            $page   = Help::screen() !== '';
            $html  .= '<section data-pf-assist-pane="help"' . ($ask ? ' hidden' : '') . ' style="flex:1;overflow:auto;padding:12px 16px">'
                . '<div class="pf-help-page">' . ($page ? Help::pageHtml($help) : Help::html((string) $help['body'])) . '</div>'
                . ($page
                    ? '<p style="margin-top:16px;opacity:.75"><a href="' . self::e(Help::url((string) $help['slug'])) . '">' . self::e(self::t('Open this page')) . '</a>'
                        . ' · <a href="' . self::e(Help::url()) . '">' . self::e(self::t('All help')) . '</a></p>'
                    : '')
                . '</section>';
        }

        return $html . '</aside>';
    }

    private static function t(string $words): string
    {
        return function_exists('t') ? (string) t($words) : $words;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
