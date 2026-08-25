<?php
namespace Pramnos\Document\DocumentTypes;


/**
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class Html extends \Pramnos\Document\Document
{
    /**
     * This will be added inside the "html" tag
     * @var string
     */
    public $extraHtmlTag = '';

    /**
     * This will be added inside the "body" tag
     * @var string
     */
    public $extraBodyTag = '';


    /**
     * Render the html document and return it's contents
     * @return string
     */
    /**
     * Whether to emit the two lines that turn `no-js` into `js`.
     *
     * On for a page a browser renders. A document type meant for something else
     * turns it off — see {@see PrintDocument}, whose own tests pin that a printable
     * page with nothing to run emits no `<script>` at all.
     *
     * @var bool
     */
    protected bool $emitNoJsFlip = true;

    /**
     * The inline script that marks JavaScript as available.
     *
     * `<html>` carries `class="no-js"` so a stylesheet can style the no-JavaScript
     * case; this replaces it the moment scripting runs. Inline and two lines rather
     * than a file, because a round trip to decide whether JavaScript exists would
     * arrive after the page has already been painted — which is the whole failure
     * the class exists to avoid.
     *
     * @return string Empty when this document type does not want it
     */
    /**
     * The exact bytes of the `no-js` flip.
     *
     * A constant because its **hash is in the policy**, and a hash computed from a
     * different string than the one emitted blocks the script. Computed at runtime from
     * this constant rather than written down, so editing the script cannot silently
     * invalidate the policy that allows it.
     */
    public const NO_JS_FLIP = "document.documentElement.className="
        . "document.documentElement.className.replace(/\\bno-js\\b/,'js');";

    protected function noJsFlipScript(): string
    {
        if (!$this->emitNoJsFlip) {
            return '';
        }

        // No nonce, and a marker that keeps the injector from adding one: the policy
        // carries this script's **hash** instead — see
        // {@see \Pramnos\Application\Application::buildCspPolicy()}.
        //
        // Why bother, for 96 bytes: `PageCache::store()` refuses any body carrying the
        // request's nonce, because a nonce reused across visitors is not a nonce. This is
        // frequently the *only* inline script on a page, so nonced it was the whole of
        // what stood between an otherwise static page and the cache — measured that way
        // in a consuming application that had already moved its own scripts into files.
        //
        // A hash rather than a file, because the script has to run before the first
        // paint: an external one in `<head>` is a blocking request to answer a question
        // — does JavaScript exist — that the `no-js` class exists to answer without one.
        return "\n        <script data-pramnos-hashed>" . self::NO_JS_FLIP . "</script>";
    }

    public function render()
    {
        $lang = \Pramnos\Framework\Factory::getLanguage();
        if ($this->themeObject !== null) {
            $this->themeObject->loadTheme();
            $this->header .= $this->themeObject->getheader();
            $this->head .= $this->themeObject->gethead();
            $this->foot .= $this->themeObject->getfoot();
        }
        if ($this->og_title == "") {
            $this->og_title = $this->title;
        }
        if ($this->og_site_name == "") {
            $this->og_site_name = \Pramnos\Application\Settings::getSetting(
                'sitename'
            );
        }
        if ($this->og_site_name == "") {
            $this->og_site_name = $this->title;
        }
        if ($this->og_url == "") {
            $this->og_url = sURL;
        }
        if ($this->og_description == "") {
            $this->og_description = $this->description;
        }



        $this->processHeader();
        \Pramnos\Addon\Addon::doAction('send_headers');
        if (!headers_sent()) {
            $contentCharset = $lang->_('CHARSET');
            if ($contentCharset === 'CHARSET') {
                $contentCharset = 'UTF-8';
            }
            header('Content-type: text/html; charset=' . $contentCharset);
        }

        $langShort = $lang->_('LangShort');
        if ($langShort === 'LangShort') {
            $langShort = 'en';
        }

        $charset = $lang->_('CHARSET');
        if ($charset === 'CHARSET') {
            $charset = 'UTF-8';
        }

        // Everything interpolated below is escaped by escapeHeadValue(): these are
        // attribute values and element text, and they routinely hold database
        // content. headContent / extraHtmlTag / header are deliberately left raw —
        // they exist to carry markup.
        $content = '<!doctype html>
<html class="no-js" ' . $this->extraHtmlTag . ' lang="' . $this->escapeHeadValue($langShort) . '" xmlns:og="http://ogp.me/ns#"
    xmlns:fb="https://www.facebook.com/2008/fbml">
    <head ' . $this->headContent . '>' . $this->noJsFlipScript() . '
        <meta charset="' . $this->escapeHeadValue($charset) . '">
        <title>' . $this->escapeHeadValue($this->title) . '</title>
        <meta name="description" content="' . $this->escapeHeadValue($this->description) . '" />
        <meta property="og:title" content="' . $this->escapeHeadValue($this->og_title) . '" />
        <meta property="og:type" content="' . $this->escapeHeadValue($this->og_type) . '" />
        <meta property="og:url" content="' . $this->escapeHeadValue($this->og_url) . '" />' . "\n";
        foreach ($this->meta as $meta=>$metavalue) {
            $content .= '        <meta property="'
                . $this->escapeHeadValue($meta)
                . '" content="'
                . $this->escapeHeadValue($metavalue)
                . '" />'
                . "\n";
        }
        foreach ($this->metanames as $meta=>$metavalue) {
            $content .= '        <meta name="'
                . $this->escapeHeadValue($meta)
                . '" content="'
                . $this->escapeHeadValue($metavalue)
                . '" />'
                . "\n";
        }
        if ($this->og_image != "") {
            $content .= '<meta property="og:image" content="'
                . $this->escapeHeadValue($this->og_image) . '"/>';
        }
        $content .= '
        <meta property="og:site_name" content="' . $this->escapeHeadValue($this->og_site_name) . '" />
        <meta property="og:description" content="'
            . $this->escapeHeadValue($this->og_description) . '" />';

        // Canonical and structured data, from setCanonical() / addStructuredData().
        // Emitted here rather than left to addHeadContent(), which was the only route
        // before and meant every application escaped the URL itself or did not.
        $content .= $this->seoHeadMarkup();


        $content .= $this->header;
        // Space-separated, and **escaped**. A class name reaches this from
        // `addBodyClass()`, which an application may well feed a slug, a content type or a
        // user's chosen theme — and a `"` in any of those closes the attribute. Every value
        // in `<head>` has been escaped since the same reporter's first pass over this
        // renderer; the class list was missed then because only head values were looked at.
        $bodyclasses = implode(
            ' ',
            array_map(
                fn($class): string => (string) $this->escapeHeadValue($class),
                $this->bodyclasses
            )
        );

        // `extraBodyTag` stays raw, deliberately: it is documented as markup — attributes
        // the application writes itself — so escaping it would turn every use of it into
        // visible text. It is the application's to get right.
        // Cast: `extraBodyTag` is a public property an application may leave as null, and
        // Amp's own tests do exactly that.
        $attributes = trim((string) $this->extraBodyTag);
        if (trim($bodyclasses) !== '') {
            $attributes .= ' class="' . $bodyclasses . '"';
        }

        // No attributes means `<body>`, not `<body >`.
        $attributes = trim($attributes);
        $content .= "\n</head>\n<body" . ($attributes === '' ? '' : ' ' . $attributes) . ">\n";

        $content .= $this->parse($this->head);
        #$content .=parent::getContent();
        $content .= $this->bodyContent();
        $content .= $this->parse($this->foot);
        $content .= "\n</body>\n</html>";

        /**
         * Post-process the rendered HTML to inject CSP nonces into all 
         * inline <script> and <style> tags. This ensures that manually 
         * authored views and themes are automatically covered by the CSP.
         */
        // `currentInstance()`: rendering happens inside a request that has an application,
        // and the `if` below was already written for a null this call could not return.
        $app = \Pramnos\Application\Application::currentInstance();
        if ($app && property_exists($app, 'cspNonce') && $app->cspNonce !== '') {
            // One implementation, on Document. This was an identical copy of the
            // pattern in the other document type — two copies of a security-relevant
            // regex that had to agree with each other.
            $content = $this->injectCspNonces(
                $content,
                htmlspecialchars($app->cspNonce, ENT_QUOTES)
            );
        }

        return $content;
    }

}
