<?php
namespace Pramnos\Html;
/**
 * HTLM Breadcrumb class
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class Breadcrumb extends \Pramnos\Framework\Base
{
    /**
     * Array of breadcrumbs to add
     * @var array
     */
    public $items = array();
    /**
     *
     * @var type
     */
    public $extraStyle = '';

    /**
     * The class on the `<ol>`, and why it is a property rather than a literal.
     *
     * It was `class="breadcrumb"` — Bootstrap's own name, hardcoded into framework markup. It
     * reads as neutral and is not: a project on Tailwind got an element carrying a name nothing
     * in its stylesheet defines, and one on Bootstrap got styling it never asked this component
     * for.
     *
     * `pf-breadcrumb` instead, which is the convention the rest of the components follow: emit a
     * neutral hook and let each scaffolded theme's stylesheet marry it to that theme's look —
     * `pf-omnibox`, `pf-skip-link`, `pf-visually-hidden` and twenty others already work that way.
     *
     * A caller who wants Bootstrap's class back sets this to `breadcrumb`.
     *
     * @var string
     */
    public string $listClass = 'pf-breadcrumb';

    /**
     * What the navigation landmark is called.
     *
     * A page can hold more than one — this and a pager — and a reader listing the regions hears
     * the labels, not the markup.
     *
     * @var string
     */
    public string $navigationLabel = 'breadcrumb';


    /**
     * Add a breadcrumb item
     *
     * `$label` is rendered as **HTML**, by design and by long-standing contract: callers pass
     * markup — an icon, an emphasised word — and the structured-data name is `strip_tags()`d
     * precisely because of it. A label built from user input has to be escaped by whoever
     * builds it. `$url` and `$title` are attribute values and are escaped here, where there
     * is no such contract to keep.
     *
     * @param string $label  Text of the breadcrumb (rendered as HTML — see above)
     * @param string $url    URL of breadcrumb
     * @param string $title  Meta Title
     */
    public function addItem($label, $url = '', $title = '')
    {
        $this->items[$label] = array(
            'label' => $label,
            'url' => $url,
            'title' => $title
        );
    }

    /**
     * Render and return the actual breadcrumb
     * @return string
     */
    public function render()
    {
        $text = '<nav aria-label="' . htmlspecialchars($this->navigationLabel, ENT_QUOTES, 'UTF-8')
            . '" role="navigation">'
            . '<ol class="' . htmlspecialchars($this->listClass, ENT_QUOTES, 'UTF-8') . '">'
            . "\n";
        /*
         * The structured data is built as an array and encoded, not concatenated.
         *
         * It used to be a string, escaped with `addslashes()`, and that made one apostrophe
         * enough to destroy the whole `BreadcrumbList` — not the entry it appeared in. `\'`
         * is not a valid JSON escape sequence, so `json_decode()` stops at the first one and
         * everything after it is lost. Breadcrumb labels are user data: a person's name, a
         * place, a category title.
         *
         * And nothing sees it. The visible `<ol>` renders identically, the page is 200, and
         * an HTML snapshot comparing the same broken text agrees with itself byte for byte.
         * The only reader that notices is the one that cannot tell you.
         *
         * The URL had the same hole and was not escaped at all. Encoding the structure closes
         * both, and `JSON_HEX_TAG` closes a third: a label containing `</script>` would
         * otherwise end the element it is inside.
         */
        $structured = array(
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => array(),
        );
        $header = count($this->items) + 1;
        $count = 0;
        foreach ($this->items as $item) {
            $count += 1;
            $label = isset($item['label']) ? (string)$item['label'] : '';
            $structured['itemListElement'][] = array(
                '@type'    => 'ListItem',
                'position' => $count,
                'name'     => strip_tags($label),
                'item'     => (string)($item['url'] ?? ''),
            );
            if ($item['title'] == '') {
                $item['title'] = $label;
            }
            $text .= '<li class="breadcrumb-item';
            if ($header == 2) {
                $text .= ' active';
            }
            $text .= '"';
            if ($header == 2) {
                $text .= ' aria-current="page"';
            }
            // Attribute values, so escaped: a title defaults to the label, and a label is
            // routinely built from a name, a place or a category somebody typed. Unescaped,
            // one double quote in any of those ends the attribute and everything after it is
            // markup the visitor chose. The label's own text is deliberately left alone —
            // see addItem().
            $title = htmlspecialchars((string) $item['title'], ENT_QUOTES);
            $url   = htmlspecialchars((string) $item['url'], ENT_QUOTES);

            $text .= '><h'
                . $header
                . ' >';
            if ($item['url'] != '') {
                $text .= '<a title="'
                    . $title
                    . '" href="'
                    . $url
                    . '">';
            } else {
                $text .= '<span title="'
                    . $title
                    . '">';
            }
            $text .= '<span>'
                . $label
                . '</span>';
            if ($item['url'] != '') {
                $text .= '</a>';
            } else {
                $text .= '</span>';
            }
            $text .= '<meta content="'
                . $count
                . '" />'
                . ''
                . "\n";
            $text .= '</h' . $header . "></li>\n";
            $header -= 1;

        }
        $script = '<script type="application/ld+json">'
            . json_encode(
                $structured,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                    | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
            . '</script>';
        $text .= '</ol></nav>';
        return $text . $script;
    }

}
