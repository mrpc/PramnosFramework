<?php
namespace Pramnos\Document\DocumentTypes;


/**
 * @package     PramnosFramework
 * @subpackage  Document
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
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
     * Load Modernizr
     * @var bool
     */
    public $modernizr = true;

    /**
     * Render the html document and return it's contents
     * @return string
     */
    public function render()
    {
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
        $modern = '';
        if ($this->modernizr === true) {
            $modern = '<script async src="' . sURL . 'media/js/modernizr.min.js"></script>';
        }
        $content = '<!doctype html>
<html ' . $this->extraHtmlTag . ' lang="' . $lang->_('LangShort') . '" xmlns:og="http://ogp.me/ns#"
    xmlns:fb="https://www.facebook.com/2008/fbml">
    <head class="no-js" ' . $this->headContent . '>
        <meta charset="' . $lang->_('CHARSET') . '">
        '. $modern . '
        <title>' . $this->title . '</title>
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
        if ($this->reset === true) {
            $content .= '
        <link  href="' . sURL . 'media/css/reset.css" rel="stylesheet" />
        ';
        }

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
