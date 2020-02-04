<?php

namespace Pramnos\Document\DocumentTypes;

/**
 * AMP Document Type
 * @package     PramnosFramework
 * @subpackage  Document
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
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
                    \pramnos_request::$originalRequestNoChange
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



        $this->proccessHeader();
        \Pramnos\Addon\Addon::doAction('send_headers');
        if (!headers_sent()) {
            header('Content-type: text/html; charset=' . $lang->_('CHARSET'));
        }


        $content = '<!DOCTYPE html>
<html amp ' . $this->extraHtmlTag . ' lang="' . $lang->_('LangShort') . '">
    <head ' . $this->headContent . '>
        <meta charset="' . $lang->_('CHARSET') . '">

        <script async src="https://cdn.ampproject.org/v0.js"></script>
        <title>' . $this->title . '</title>
        <style amp-boilerplate>body{-webkit-animation:-amp-start 8s steps(1,end) 0s 1 normal both;-moz-animation:-amp-start 8s steps(1,end) 0s 1 normal both;-ms-animation:-amp-start 8s steps(1,end) 0s 1 normal both;animation:-amp-start 8s steps(1,end) 0s 1 normal both}@-webkit-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-moz-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-ms-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-o-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}</style><noscript><style amp-boilerplate>body{-webkit-animation:none;-moz-animation:none;-ms-animation:none;animation:none}</style></noscript>
        <link rel="canonical" href="' . $this->canonical . '">
        <meta name="viewport" content="width=device-width,minimum-scale=1,initial-scale=1">
        <meta name="description" content="' . $this->description . '" />
        <meta property="og:title" content="' . $this->og_title . '" />
        <meta property="og:type" content="' . $this->og_type . '" />
        <meta property="og:url" content="' . $this->og_url . '" />' . "\n";
        foreach ($this->meta as $meta=>$metavalue) {
            $content .= '        <meta property="'
                . $meta
                . '" content="'
                . $metavalue
                . '" />'
                . "\n";
        }
        foreach ($this->metanames as $meta=>$metavalue) {
            $content .= '        <meta name="'
                . $meta
                . '" content="'
                . $metavalue
                . '" />'
                . "\n";
        }
        if ($this->og_image != "") {
            $content .= '<meta property="og:image" content="'
                . $this->og_image . '"/>';
        }
        $content .= '
        <meta property="og:site_name" content="' . $this->og_site_name . '" />
        <meta property="og:description" content="'
            . $this->og_description . '" />';
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
        return $content;
    }

}
