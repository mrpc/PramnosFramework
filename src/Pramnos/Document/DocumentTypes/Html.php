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
<html ' . $this->extraHtmlTag . ' lang="' . $this->escapeHeadValue($langShort) . '" xmlns:og="http://ogp.me/ns#"
    xmlns:fb="https://www.facebook.com/2008/fbml">
    <head class="no-js" ' . $this->headContent . '>
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


        $content .= $this->header;
        $bodyclasses = '';
        $comma = '';
        foreach ($this->bodyclasses as $class) {
            $bodyclasses.= $comma . $class;
            $comma = ' ';
        }
        if (trim($bodyclasses) == '') {
            $content .= "\n</head>\n<body "
                . $this->extraBodyTag
                . ">\n";
        } else {
            $content .= "\n</head>\n<body "
                . $this->extraBodyTag
                . " class=\"" . $bodyclasses . "\">\n";
        }

        $content .= $this->parse($this->head);
        #$content .=parent::getContent();
        $content .= self::_getContent();
        $content .= $this->parse($this->foot);
        $content .= "\n</body>\n</html>";

        /**
         * Post-process the rendered HTML to inject CSP nonces into all 
         * inline <script> and <style> tags. This ensures that manually 
         * authored views and themes are automatically covered by the CSP.
         */
        $app = \Pramnos\Application\Application::getInstance();
        if ($app && property_exists($app, 'cspNonce') && $app->cspNonce !== '') {
            $nonce = htmlspecialchars($app->cspNonce, ENT_QUOTES);

            // Inline <script> tags (no src= attribute)
            $content = preg_replace_callback(
                '/<script(?![^>]*\bsrc\s*=)([^>]*)>/i',
                static function (array $matches) use ($nonce): string {
                    return '<script nonce="' . $nonce . '"' . $matches[1] . '>';
                },
                $content
            );

            // Inline <style> blocks
            $content = preg_replace_callback(
                '/<style([^>]*)>/i',
                static function (array $matches) use ($nonce): string {
                    return '<style nonce="' . $nonce . '"' . $matches[1] . '>';
                },
                $content
            );
        }

        return $content;
    }

}
