<?php

namespace Pramnos\Document\DocumentTypes;
/**
 * RSS Document
 * @package     PramnosFramework
 * @subpackage  Document
 * @copyright   Copyright (C) 2005 - 2011 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Rss extends \Pramnos\Document\Document
{

    public $title = "";
    public $link = "";
    public $description = "";
    public $language = "";
    public $copyright = "";
    public $managingEditor = "";
    public $webMaster = "";
    public $pubDate = "";
    public $lastBuildDate = "";
    public $category = "";
    public $generator = "PramnosCMS";
    public $docs = "http://www.rssboard.org/rss-specification";
    public $cloud = "";
    public $ttl = 60;
    public $image = "";
    public $rating = "";
    public $skipHours = "";
    public $skipDays = "";
    private $_items = array();

    /**
     * Create a new RSS Item
     * @return \Pramnos\Document\DocumentTypes\Rss\Item
     */
    public function newItem()
    {
        return new \Pramnos\Document\DocumentTypes\Rss\Item();
    }

    /**
     * Add a new RSS item to the feed
     * @param \Pramnos\Document\DocumentTypes\Rss\Item $item Item to add
     * @return $this
     */
    public function addItem(\Pramnos\Document\DocumentTypes\Rss\Item $item)
    {
        if (!isset($this->_items[$item->link])) {
            $this->_items[$item->link] = $item;
        }
        return $this;
    }

    /**
     * Remove an RSS item from the feed
     * @param \Pramnos\Document\DocumentTypes\Rss\Item $item
     * @return $this
     */
    public function removeItem(\Pramnos\Document\DocumentTypes\Rss\Item $item)
    {
        if (isset($this->_items[$item->link])) {
            unset($this->_items[$item->link]);
        }
        return $this;
    }

    /**
     * Render the RSS feed
     * @return string
     */
    public function render()
    {
        $lang = \Pramnos\Framework\Factory::getLanguage();
        if (!headers_sent()) {
            header('HTTP/1.1 200 OK');
            header(
                'Content-type: application/rss+xml; charset='
                . $lang->_('CHARSET')
            );
        }
        $this->lastBuildDate = date('r', time());
        $this->pubDate = date('r', time());
        $content = '<?xml version="1.0"?>';
        if ($this->title === "") {
            $this->title = \Pramnos\Application\Settings::getSetting(
                'sitename'
            );
        }
        if ($this->link === "") {
            $this->link = sURL;
        }
        if ($this->webMaster === "") {
            $this->webMaster = \Pramnos\Application\Settings::getSetting(
                'admin_mail'
            );
        }
        if ($this->description === "") {
            $this->description = \Pramnos\Application\Settings::getSetting(
                'slogan'
            );
        }


        $content .= "
<rss version=\"2.0\">
    <channel>
        <title><![CDATA[" . $this->title . "]]></title>
        <link><![CDATA[" . $this->link . "]]></link>
        <description><![CDATA[" . $this->description . "]]></description>
        <pubDate>" . $this->pubDate . "</pubDate>
        <lastBuildDate>" . $this->lastBuildDate . "</lastBuildDate>
        <docs>" . $this->docs . "</docs>
        <generator>" . $this->generator . "</generator>
        <webMaster>" . $this->webMaster . "</webMaster>";
        foreach ($this->_items as $item) {
            $content .= $item->render();
        }
        $content .= "
    </channel>
</rss>";
        return $content;
    }

}
