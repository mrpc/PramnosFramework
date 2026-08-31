<?php

declare(strict_types=1);

namespace Pramnos\Html;

/**
 * The markup for a cross-entity search box.
 *
 * ```php
 * echo (new \Pramnos\Html\SearchBox())->render();
 * ```
 *
 * Pairs with {@see \Pramnos\Search\Registry} on the server and the `data-pf-omnibox`
 * handler in `assets/js/pf-utils.js` in the browser. This class contributes markup
 * only — the behaviour is delegated from a listener that is already on every scaffolded
 * page, and the styles are already in `style.css`.
 *
 * **It renders no script and enqueues no asset**, for the same reason {@see Input} does
 * not: an element that pushes JavaScript into the document while rendering itself makes
 * echoing a search box change the page's asset list, and the framework's CSP would then
 * need a nonce per widget rather than per document.
 *
 * ## The id is not optional here
 *
 * {@see Input} and {@see Select} deliberately invent no id. This does, because
 * `aria-controls` and `aria-activedescendant` are associations by id and a combobox
 * without them is a text field as far as a screen reader is concerned. One omnibox per
 * page is also the premise — a second one is a different feature — so the collision risk
 * that made the others refuse does not apply. Set {@see $id} if you need two.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
class SearchBox extends \Pramnos\Framework\Base
{
    /** Endpoint returning the grouped results. */
    public string $url = '';

    /** Base id; the input, the results panel and the label derive from it. */
    public string $id = 'pf-omnibox';

    /** Visible placeholder. */
    public string $placeholder = 'Search…';

    /**
     * Accessible name for the input.
     *
     * Rendered as a visually-hidden `<label>` rather than an `aria-label`, so it is
     * translatable by the same mechanism as everything else on the page and survives a
     * translation tool that only looks at element text.
     */
    public string $label = 'Search';

    /**
     * How many characters before the first request.
     *
     * Two, not one: a single letter matches most of the table on every source, so the
     * first keystroke would be the most expensive query and the least useful result.
     */
    public int $minimumCharacters = 2;

    /**
     * Milliseconds of quiet before a request is sent.
     *
     * Without it a ten-character term is ten queries across every registered source.
     */
    public int $debounce = 250;

    /** Text shown while a request is in flight. */
    public string $loadingText = 'Searching…';

    /** Text shown when a term matched nothing anywhere. */
    public string $emptyText = 'No results';

    /** Rendered verbatim on the wrapper, for anything not modelled here. */
    public string $extraAttributes = '';

    /**
     * @param string $url Endpoint; defaults to the scaffolded `/api/admin/search`
     */
    public function __construct(string $url = '')
    {
        parent::__construct();

        $this->url = $url !== '' ? $url : self::defaultUrl();
    }

    /**
     * The scaffolded endpoint's path.
     *
     * The API prefix is a scaffold-time choice recorded in `app/app.php`, so it is read
     * rather than assumed: a project served under `/v1` would otherwise get a search box
     * pointing at a 404, which reads as "search is broken" and not as a wrong path.
     *
     * `applicationInfo` may be an array or an object — `loadApplicationInfo()` returns a
     * config file's value as it found it — so both are handled. Falling back to
     * `/api/1.0` matches the scaffolder's own default rather than producing
     * `/admin/search`, which is not a route any scaffolded project registers.
     */
    public static function defaultUrl(): string
    {
        $info = \Pramnos\Application\Application::currentInstance()?->applicationInfo;

        $prefix = match (true) {
            is_array($info)  => (string) ($info['api_prefix'] ?? '/api/1.0'),
            is_object($info) => (string) ($info->api_prefix ?? '/api/1.0'),
            default          => '/api/1.0',
        };

        $prefix = trim($prefix, '/');

        return '/' . ($prefix === '' ? '' : $prefix . '/') . 'admin/search';
    }

    /**
     * The widget, as markup.
     */
    public function render(): string
    {
        $id        = $this->attr($this->id);
        $inputId   = $id . '-input';
        $resultsId = $id . '-results';

        $out = '<div class="' . $this->attr(ComponentClasses::get('omnibox')) . '" id="' . $id . '"'
            . ' data-pf-omnibox'
            . ' data-pf-omnibox-url="' . $this->attr($this->url) . '"'
            . ' data-pf-omnibox-min="' . max(1, $this->minimumCharacters) . '"'
            . ' data-pf-omnibox-debounce="' . max(0, $this->debounce) . '"'
            . ' data-pf-omnibox-loading="' . $this->attr($this->loadingText) . '"'
            . ' data-pf-omnibox-empty="' . $this->attr($this->emptyText) . '"'
            . ($this->extraAttributes !== '' ? ' ' . $this->extraAttributes : '')
            . '>';

        $out .= '<label class="pf-omnibox-label" for="' . $inputId . '">'
            . $this->attr($this->label) . '</label>';

        // role="combobox" with the ARIA 1.2 pattern: the input owns the popup, the
        // popup is a listbox, and expanded state is maintained by the handler. Without
        // aria-expanded a screen reader never announces that results appeared.
        $out .= '<input type="search" class="pf-omnibox-input" id="' . $inputId . '"'
            . ' name="q" placeholder="' . $this->attr($this->placeholder) . '"'
            . ' autocomplete="off" role="combobox" aria-expanded="false"'
            . ' aria-controls="' . $resultsId . '" aria-autocomplete="list" />';

        // `hidden` rather than a class, so the panel is closed before any stylesheet
        // loads — a results panel that flashes open on every page load is what an
        // unstyled first paint looks like otherwise.
        $out .= '<div class="pf-omnibox-results" id="' . $resultsId . '" role="listbox"'
            . ' aria-label="' . $this->attr($this->label) . '" hidden></div>';

        return $out . '</div>';
    }

    /** `echo $box;` renders it. */
    public function __toString(): string
    {
        return $this->render();
    }

    /** Escape for an attribute or for text content — the same rule serves both. */
    protected function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
