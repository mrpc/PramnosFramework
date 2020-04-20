<?php

namespace Pramnos\Media;


/**
 * Thumbnail Class
 *
 * @package
 * @subpackage
 * @copyright   Copyright (C) 2005 - 2014 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Thumbnail
{
    /**
     * Filename (and path) in local filesystem
     * @var string
     */
    public $filename = '';
    /**
     * Width
     * @var int
     */
    public $x = 0;
    /**
     * Height
     * @var int
     */
    public $y = 0;
    /**
     * Total views
     * @var int
     */
    public $views = 0;
    /**
     * File size in bytes
     * @var int
     */
    public $filesize = 0;
    /**
     * Reason for thumbnail creation
     * @var string
     */
    public $reason = "";
    /**
     * Thumbnail url (relative to site root)
     * @var string
     */
    public $url = '';
    /**
     * Created at
     * @var string
     */
    public $createdTxt = 0;
}
