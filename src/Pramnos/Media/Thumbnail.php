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
    /**
     * Was this rendition allowed to be larger than the file it came from?
     *
     * One of the three settings that are part of an entry's **identity** rather
     * than notes about it — see {@see $crop} for the others and for what an entry
     * written before they existed reads back as.
     *
     * @var bool
     */
    public $upscaled = false;
    /**
     * Was the source cropped to the box, or fitted into it?
     *
     * Part of the identity, and measured rather than assumed. The same source and
     * the same box, at 155×148 from an 800×400 original:
     *
     *     crop=true,  resample=true   ->  155x148   md5 6116aa5c
     *     crop=true,  resample=false  ->  155x148   md5 6116aa5c
     *     crop=false, resample=true   ->  155x148   md5 d94b13a9
     *     crop=false, resample=false  ->  155x148   md5 6f320215
     *
     * Three different images at identical dimensions. Cropping keeps the aspect
     * and discards the edges; fitting keeps everything and either letterboxes it
     * or squashes it, which is what {@see $resample} chooses between. All of them
     * record the same requested box, so a lookup that ignored these would hand
     * back whichever was written first — and the direction that matters is
     * serving a cropped image to a caller that asked for the whole picture.
     *
     * **What an entry written before these three fields existed reads back as.**
     * The class defaults, which are `MediaObject::get()`'s own defaults — so such
     * an entry is treated as having come from the commonest call. That is an
     * assumption and not a fact: nothing recorded these at the time, so an entry
     * produced with `crop = true`, or by an application that had switched
     * `allowUpscale` on before it was recorded, is indistinguishable from one that
     * was not. The cost of guessing wrong is bounded and self-healing — one
     * request does not find its entry, rebuilds it once and adds one row — and the
     * alternative is to trust dimensions alone, which is the defect these fields
     * exist to close.
     *
     * @var bool
     */
    public $crop = false;
    /**
     * When not cropping: fit the whole image into the box, or stretch it to fill?
     *
     * `true` letterboxes and keeps the aspect; `false` distorts. It changes
     * nothing when {@see $crop} is true, and it is recorded anyway, because an
     * entry has to be identified by what was *asked for* — a lookup cannot know
     * that this particular source made the answer moot.
     *
     * @var bool
     */
    public $resample = true;
}
