<?php

namespace Pramnos\Media;


/**
 * Thumbnail Class
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
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
    /**
     * Width that was **asked for**, as opposed to {@see $x}, which is what came out.
     *
     * The two differ whenever the source is smaller than the requested box and
     * upscaling is off, which is the default: a request for 177×222 against a
     * 150×150 source produces a 120×150 rendition. Recording only the result meant
     * the next identical request found nothing, rebuilt the image, appended another
     * entry and saved — on every call, without limit, because the resizer prefixes
     * a random number when the filename it wants already exists.
     *
     * `0` means "not recorded": an entry written before this existed, or one
     * belonging to an application's own thumbnail class. Those are matched on
     * {@see $x}/{@see $y} as they always were.
     *
     * @var int
     */
    public $requestedX = 0;
    /**
     * Height that was asked for. See {@see $requestedX}.
     * @var int
     */
    public $requestedY = 0;
}
