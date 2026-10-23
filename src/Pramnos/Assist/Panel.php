<?php

declare(strict_types=1);

namespace Pramnos\Assist;

/**
 * The side panel: Ask about the page you are on, and that page's help.
 *
 * The help is this request's level (see {@see Help::level()}): the administration's pages inside
 * the administration, the site's outside it — where a visitor, signed in or not, gets the panel
 * with the help alone.
 *
 * Drawn by `Analyst::headerButton()`, which every scaffolded header already calls, so a project
 * gets it without touching its layout: the button, the panel as an inert `<template>`, and
 * `assets/js/pf-assist.js`, which opens it beside the page. Without the script the button is the
 * link it always was — to Ask, or to the page's help.
 *
 * Help is the `help` feature's; Ask, the `llm` feature's, below the analyst's floor.
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
        // This request's level: the site's pages outside the administration, never its own.
        $book    = Help::book();
        $help    = Help::enabled() ? $book->forScreen($context['screen'], $context['action']) : null;

        // On Ask itself, the panel would be the page twice: the link it always was.
        if ($ask && $context['screen'] === 'ai') {
            return $before . '<a class="pf-ask" href="' . self::e($askUrl) . '">' . self::analyst() . '</a>' . $after;
        }

        if (!$ask && $help === null) {
            return '';
        }

        $label  = $ask ? self::analyst() : self::e(self::t('Help'));
        $href   = $ask ? $askUrl : ($book->screen() !== '' ? $book->url((string) $help['slug']) : '#');
        $opener = $ask || $book->level() !== HelpBook::SITE
            ? '<a class="pf-ask" href="' . self::e($href) . '" data-pf-assist-open aria-haspopup="dialog"'
                . ($ask ? ' aria-label="' . self::e(self::askAbout()) . '"' : '') . '>' . $label . '</a>'
            : self::siteButton(self::e($href), $label, ['slug' => (string) $help['slug'], 'title' => (string) $help['title']]);

        return $before
            . $opener
            . '<template data-pf-assist>' . self::panel($ask, $askUrl, $help, $context, $book) . '</template>'
            . '<script src="' . self::e(function_exists('assetUrl') ? assetUrl('assets/js/pf-assist.js') : 'assets/js/pf-assist.js') . '" defer></script>'
            . $after;
    }

    /**
     * The site's help button, as `'help' => ['site' => ['button' => …]]` says: the word, by default;
     * `'icon'`, a small muted question mark; or a {@see HelpButton} class, the application's own.
     * Every form keeps `data-pf-assist-open`, which is how the script finds it.
     *
     * @param array{slug: string, title: string} $page
     */
    private static function siteButton(string $href, string $label, array $page): string
    {
        $attributes = 'data-pf-assist-open aria-haspopup="dialog"';
        $app        = \Pramnos\Application\Application::currentInstance();
        $button     = is_object($app) ? ($app->applicationInfo['help']['site']['button'] ?? null) : null;

        if ($button === 'icon') {
            return '<a class="pf-ask pf-help-icon" href="' . $href . '" ' . $attributes . ' aria-label="' . $label . '" title="' . $label . '">?</a>';
        }

        if (is_string($button) && class_exists($button) && is_a($button, HelpButton::class, true)) {
            return (new $button())->render($href, $label, $attributes, $page);
        }

        return '<a class="pf-ask" href="' . $href . '" ' . $attributes . '>' . $label . '</a>';
    }

    /**
     * Whether the theme styles the panel — `'assist' => ['styles' => 'theme']` in app.php — rather
     * than the panel carrying its own inline styles.
     *
     * The panel always carries its `pf-assist-*` classes, and every scaffolded stylesheet styles
     * them. Inline styles win over any class, though, so a theme that wants the panel in its own
     * look (daisyUI's surfaces, Bootstrap's offcanvas) says so here; one that says nothing keeps
     * the inline look it had, whether or not its stylesheet has the rules.
     */
    public static function themed(): bool
    {
        $app = \Pramnos\Application\Application::currentInstance();

        return is_object($app) && ($app->applicationInfo['assist']['styles'] ?? null) === 'theme';
    }

    /**
     * One button that opens the panel, on a tab: `ask` or `help`. For a theme that draws its own
     * buttons — Ask, and a "?" for the page's help — beside one {@see template()}.
     *
     * '' for `help` where the page has none, and for `ask` on Ask's own page. Whether this person
     * may ask is the caller's to decide ({@see \Pramnos\Llm\Analyst\Analyst::canAsk()}).
     *
     * @param string $tab        `ask` or `help`
     * @param string $label      The button's text; "Ask" or "Help" by default, escaped either way
     * @param string $class      Its classes
     * @param string $attributes More attributes, as markup — `aria-label="…" title="…"`
     */
    public static function opener(string $tab, string $label = '', string $class = 'pf-ask', string $attributes = ''): string
    {
        $tab     = $tab === 'help' ? 'help' : 'ask';
        $context = Page::context();
        $book    = Help::book();

        if ($tab === 'ask') {
            if ($context['screen'] === 'ai') {
                return '';
            }
            $href = function_exists('adminUrl') ? adminUrl('Ai') : '#';
        } else {
            $help = Help::enabled() ? $book->forScreen($context['screen'], $context['action']) : null;
            if ($help === null) {
                return '';
            }
            $href = $book->screen() !== '' ? $book->url((string) $help['slug']) : '#';
        }

        return '<a class="' . self::e($class) . '" href="' . self::e($href) . '" data-pf-assist-open="' . $tab . '" aria-haspopup="dialog"'
            . ($attributes !== '' ? ' ' . $attributes : '') . '>'
            . self::e($label !== '' ? $label : ($tab === 'ask' ? self::t('Ask') : self::t('Help'))) . '</a>';
    }

    /**
     * The panel and its script, without a button — for a theme whose buttons are {@see opener()}s.
     * '' where there is nothing to offer here.
     *
     * @param bool $ask Whether this person may ask the analyst
     */
    public static function template(bool $ask, string $askUrl): string
    {
        $context = Page::context();
        $book    = Help::book();
        $help    = Help::enabled() ? $book->forScreen($context['screen'], $context['action']) : null;

        if ((!$ask || $context['screen'] === 'ai') && $help === null) {
            return '';
        }
        $ask = $ask && $context['screen'] !== 'ai';

        return '<template data-pf-assist>' . self::panel($ask, $askUrl, $help, $context, $book) . '</template>'
            . '<script src="' . self::e(function_exists('assetUrl') ? assetUrl('assets/js/pf-assist.js') : 'assets/js/pf-assist.js') . '" defer></script>';
    }

    /**
     * @param array<string, mixed>|null $help
     * @param array<string, string>     $context
     */
    private static function panel(bool $ask, string $askUrl, ?array $help, array $context, HelpBook $book): string
    {
        $themed = self::themed();
        // Its own look, unless the theme gives it one.
        $style  = static fn (string $css): string => $themed ? '' : ' style="' . $css . '"';
        $both   = $ask && $help !== null;
        $chat   = function_exists('assetUrl') ? assetUrl('assets/js/pf-chat.js') : 'assets/js/pf-chat.js';
        $button = 'class="pf-assist-button"' . $style('background:none;border:1px solid rgba(128,128,128,.4);border-radius:6px;padding:2px 8px;color:inherit;cursor:pointer');
        $html   = '<aside class="pf-assist" role="dialog" aria-label="' . self::e($ask ? self::askAbout() : self::t('Help')) . '"'
            . ' data-page="' . self::e((string) json_encode($context, JSON_UNESCAPED_UNICODE)) . '"'
            . ' data-ask="' . self::e($askUrl) . '" data-chat-script="' . self::e($chat) . '"'
            . ' data-say-loading="' . self::e(self::t('Loading…')) . '" data-say-failed="' . self::e(self::t('%s could not be opened here.', \Pramnos\Llm\Analyst\Analyst::name())) . '"'
            . $style('position:fixed;top:0;right:0;bottom:0;width:min(460px,100vw);z-index:1080;display:flex;flex-direction:column;'
                . 'background:Canvas;color:CanvasText;box-shadow:-4px 0 24px rgba(0,0,0,.25);font-size:.95rem') . '>'
            . '<header class="pf-assist-header"' . $style('display:flex;gap:8px;align-items:center;padding:10px 12px;border-bottom:1px solid rgba(128,128,128,.3)') . '>'
            . '<strong class="pf-assist-title"' . $style('flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap') . '>'
            . ($ask && \Pramnos\Llm\Analyst\Analyst::avatar() !== null ? '<img class="pf-assist-avatar" src="' . self::e((string) \Pramnos\Llm\Analyst\Analyst::avatar()) . '" alt="" width="20" height="20"> ' : '')
            . ($context['title'] !== '' ? self::e($context['title']) : ($ask ? self::analyst() : self::e(self::t('Help')))) . '</strong>';

        if ($ask) {
            $html .= '<button type="button" data-pf-assist-new ' . $button . '>' . self::e(self::t('New')) . '</button>'
                . '<a class="pf-assist-link" href="' . self::e($askUrl) . '" title="' . self::e(self::t('Open in %s', \Pramnos\Llm\Analyst\Analyst::name())) . '" aria-label="' . self::e(self::t('Open in %s', \Pramnos\Llm\Analyst\Analyst::name())) . '">↗</a>';
        }

        $html .= '<button type="button" data-pf-assist-close aria-label="' . self::e(self::t('Close')) . '" ' . $button . '>✕</button></header>';

        if ($both) {
            $tab = 'class="pf-assist-button pf-assist-tab"' . $style('background:none;border:1px solid rgba(128,128,128,.4);border-radius:6px;padding:2px 8px;color:inherit;cursor:pointer');
            $html .= '<nav class="pf-assist-tabs"' . $style('display:flex;gap:6px;padding:8px 12px 0') . ' role="tablist">'
                . '<button type="button" role="tab" data-pf-assist-tab="ask" aria-selected="true" ' . $tab . '>' . self::e(\Pramnos\Llm\Analyst\Analyst::name()) . '</button>'
                . '<button type="button" role="tab" data-pf-assist-tab="help" aria-selected="false" ' . $tab . '>' . self::e(self::t('Help')) . '</button>'
                . '</nav>';
        }

        if ($ask) {
            $html .= '<section class="pf-assist-pane" data-pf-assist-pane="ask"' . $style('flex:1;overflow:auto;padding:12px') . '></section>';
        }

        if ($help !== null) {
            $page   = $book->screen() !== '';
            $html  .= '<section class="pf-assist-pane" data-pf-assist-pane="help"' . ($ask ? ' hidden' : '') . $style('flex:1;overflow:auto;padding:12px 16px') . '>'
                . '<div class="pf-help-page">' . ($page ? $book->pageHtml($help) : Help::html((string) $help['body'])) . '</div>'
                . ($page
                    ? '<p class="pf-assist-more"' . $style('margin-top:16px;opacity:.75') . '><a href="' . self::e($book->url((string) $help['slug'])) . '">' . self::e(self::t('Open this page')) . '</a>'
                        . ' · <a href="' . self::e($book->url()) . '">' . self::e(self::t('All help')) . '</a></p>'
                    : '')
                . '</section>';
        }

        return $html . '</aside>';
    }

    private static function t(string $words, string ...$args): string
    {
        return function_exists('t') ? (string) t($words, ...$args) : vsprintf($words, $args);
    }

    /**
     * The analyst as the button and the panel's title show it: its picture, where the
     * application gives one, and its name ({@see \Pramnos\Llm\Analyst\Analyst::name()}).
     */
    private static function analyst(): string
    {
        $avatar = \Pramnos\Llm\Analyst\Analyst::avatar();

        return ($avatar !== null ? '<img class="pf-assist-avatar" src="' . self::e($avatar) . '" alt="" width="20" height="20"'
                . (self::themed() ? '' : ' style="width:20px;height:20px;vertical-align:middle;margin-right:4px"') . '>' : '')
            . self::e(\Pramnos\Llm\Analyst\Analyst::name());
    }

    /** "Ask about this page", in the analyst's name where it has one of its own. */
    private static function askAbout(): string
    {
        $name = \Pramnos\Llm\Analyst\Analyst::name();

        return $name === 'Ask' ? self::t('Ask about this page') : self::t('Ask %s about this page', $name);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
