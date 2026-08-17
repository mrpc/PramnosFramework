<?php

namespace Pramnos\Document\DocumentTypes;

/**
 * AMP Document Type
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class Amp extends \Pramnos\Document\Document
{

    /**
     * Canonical Url
     * @var type
     */
    public $canonical = '';


    /**
     * Render the html document and return it's contents
     * @return string
     */
    public function render()
    {

        if ($this->canonical == '') {
            $this->canonical = sURL
                . str_replace(
                    '/format/amp',
                    '',
                    // `\pramnos_request` — a legacy CMS class name which, resolved from
                    // the global namespace, is nothing at all. So an AMP document with no
                    // explicit canonical fatalled on `Class "pramnos_request" not found`,
                    // which is every AMP page that did not set one: this branch exists
                    // precisely to handle that case and could never run. Same shape as
                    // `pramnos_theme::getTheme()` in Theme::getThemeObjects(), found and
                    // fixed on 2026-08-14. The modern class carries the identical property.
                    \Pramnos\Http\Request::$originalRequestNoChange
                );
        }

        $lang = \Pramnos\Framework\Factory::getLanguage();
        if ($this->themeObject !== NULL) {
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
            header('Content-type: text/html; charset=' . $lang->_('CHARSET'));
        }


        $content = '<!DOCTYPE html>
<html amp ' . $this->extraHtmlTag . ' lang="' . $this->escapeHeadValue($lang->_('LangShort')) . '">
    <head ' . $this->headContent . '>
        <meta charset="' . $this->escapeHeadValue($lang->_('CHARSET')) . '">

        <script async src="https://cdn.ampproject.org/v0.js"></script>
        <title>' . $this->escapeHeadValue($this->title) . '</title>
        <style amp-boilerplate>body{-webkit-animation:-amp-start 8s steps(1,end) 0s 1 normal both;-moz-animation:-amp-start 8s steps(1,end) 0s 1 normal both;-ms-animation:-amp-start 8s steps(1,end) 0s 1 normal both;animation:-amp-start 8s steps(1,end) 0s 1 normal both}@-webkit-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-moz-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-ms-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-o-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}</style><noscript><style amp-boilerplate>body{-webkit-animation:none;-moz-animation:none;-ms-animation:none;animation:none}</style></noscript>
        <link rel="canonical" href="' . $this->escapeHeadValue($this->canonical) . '">
        <meta name="viewport" content="width=device-width,minimum-scale=1,initial-scale=1">
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

        // Structured data only: this type already emits its own canonical above, with
        // a computed default, and emitting a second <link rel="canonical"> would be
        // worse than emitting none — two of them is undefined behaviour to a crawler.
        foreach ($this->structuredDataBlocks() as $block) {
            $content .= "\n        " . $block;
        }
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
        return $content;
    }

}
