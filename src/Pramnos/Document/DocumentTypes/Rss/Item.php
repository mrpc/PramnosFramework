<?php
namespace Pramnos\Document\DocumentTypes\Rss;


/**
 * Rss Item
 * @package     PramnosFramework
 * @subpackage  Document
 * @copyright   Copyright (C) 2005 - 2011 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Item
{

    /**
     * The title of the item.
     * @var string Required!
     */
    public $title = "";

    /**
     * The URL of the item.
     * @var string Required!
     */
    public $link = "";

    /**
     * The item synopsis.
     * @var string Required!
     */
    public $description = "";

    /**
     * A string that uniquely identifies the item.
     * @var string
     */
    public $guid = "";

    /**
     * Indicates when the item was published
     * @var string
     */
    public $pubDate = "";

    /**
     * Email address of the author of the item.
     * @var string
     */
    public $author = "";

    /**
     * Includes the item in one or more categories.
     * @var string
     */
    public $category = "";
    public $category_domain = "";

    /**
     * URL of a page for comments relating to the item.
     * @var string
     */
    public $comments = "";

    /**
     * Describes a media object that is attached to the item.
     * @var string
     */
    public $enclosure_url = "";
    public $enclosure_length = 0;
    public $enclosure_type = "audio/mpeg";

    /**
     * The RSS channel that the item came from (title)
     * @var string
     */
    public $source_title = "";

    /**
     * The RSS channel that the item came from (url)
     * @var string
     */
    public $source_url = "";

    public function render()
    {
        $content = "
        <item>
            <title><![CDATA[" . $this->title . "]]></title>
            <description><![CDATA[" . $this->description . "]]></description>
            <pubDate>" . $this->pubDate . "</pubDate>
            <guid><![CDATA[" . $this->link . "]]></guid>
        </item>
      ";
        return $content;
    }

}
