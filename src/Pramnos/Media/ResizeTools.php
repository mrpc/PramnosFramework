<?php
namespace Pramnos\Media;
/**
 * Based on justThumb.php - by Jack-the-ripper (c) Lars Oll�n 2005
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @copyright   Lars Oll�n 2005
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */


class ResizeTools extends \Pramnos\Framework\Base
{




    /**
     * source image path
     * @var string
     */
    public $srcFile = false;
    /**
     * Thumbnail width
     * @var int
     */
    public $thumbW = false;
    /**
     * Thumbnail height
     * @var int
     */
    public $thumbH = false;
    /**
     * Original Width
     * @var int
     */
    public $width = false;
    /**
     * Original Height
     * @var int
     */
    public $height = false;
    /**
     * Original image type
     * @var string
     */
    public $type = false;
    /**
     * Default width of the new image
     * @var int
     */
    public $defaultwidth = 120;
    /**
     * Max size in pixels. If an image has a size greater than this,
     * it will be limited.
     * @var string
     */
    public $maxsize = 1024;
    /**
     * May a requested size be larger than the source?
     *
     * `false`, and that is the fix rather than the option. Asking a 40×40 picture for 512×512 used to
     * write 512×512 of stretched blur — on disk, recorded as a rendition, served to a browser as
     * though it were real, and *larger than the original it came from*, which is the opposite of what
     * a thumbnail is for. The request is clamped to the source's own dimensions now, keeping the
     * requested aspect, so a request above them returns the original size.
     *
     * Set it to `true` where an upscale is genuinely wanted — a fixed-size sprite sheet, a print
     * target — knowing that the pixels are invented.
     *
     * @var bool
     */
    public $allowUpscale = false;

    /**
     * Why the last {@see resize()} produced nothing, or `false` if it produced something.
     *
     * A source GD cannot read — an SVG on an ordinary build, a truncated upload, a file whose
     * extension lies — used to be turned into a 500×100 JPEG of the file path by
     * {@see makeErrorImg()}. That is a valid image file, so every downstream check accepted it and
     * the failure travelled: into the library, into `thumbnails`, into the page. Now nothing is
     * written, `resize()` returns false, and this says what happened.
     *
     * The drawn error image is still available behind {@see $debug}, which is where it always
     * belonged.
     *
     * @var bool|string
     */
    public $error = false;

    /**
     * Set to true to display debug messages
     * @var boolean
     */
    public $debug = false;
    /**
     * Fill color for resample
     * @var string
     */
    public $fillcolor = "FFFFFF";
    /**
     * Allow image crop if both dimensions are set
     * @var boolean
     */
    public $crop = true;
    /**
     * If crop is not allowed, resample image to avoid loosing information
     * @var boolean
     */
    public $resample = true;
    /**
     * If ratio difference between original image and thumbnail is smaller this,
     * do crop instead
     * @var float
     */
    public $resampleLimit = 0.55;
    /**
     * Where to export the new image
     * @var string
     */
    public $exportpath = '';
    /**
     * Filename
     * @var string
     */
    public $exportfile = '';
    /**
     * Export file type
     * @var string
     */
    private $exporttype = 'jpg';
    /**
     * If ratio difference between original image and thumbnail is smaller than
     * $this->resampleLimit, do crop instead
     * @var boolean
     */
    private $forcecrop=false;

    /**
     * Resize an image to wanted dimensions
     * and save it to exportpath (as exportfile)
     * @param string $src Source of the image
     * @param int $width
     * @param int $height
     * @return bool True when a file was written. False when the source could not be read — see
     *              {@see $error}. Callers that ignore the return value behave as they always did,
     *              except that a source GD cannot read no longer leaves a picture of an error message
     *              where the rendition should be.
     */
    public function resize($src = '', $width = 0, $height = 0)
    {
        if ($this->debug == true) {
            echo '<br /> Must resize to: ' . $width . ' x ' . $height;
        }
        if ($src != '') {
            $this->srcFile = $src;
        }
        $this->thumbW = $width != 0 ? ($width) : false;
        $this->thumbH = $height != 0 ? ($height) : false;

        if ($this->debug == true) {
            echo '<br /> Will try to resize to: ' . $this->thumbW . ' x ' . $this->thumbH;
        }

        if (
                ($this->thumbW > $this->maxsize || ($this->thumbW <= 0)) || ($this->thumbH > $this->maxsize || ($this->thumbH <= 0 && $this->thumbH !== false))
        ) {
            $this->thumbW = $this->defaultwidth;
            $this->thumbH = false;

            if ($this->debug == true) {
                echo '<br /> Due to maxsize, I will resize to: ' . $this->thumbW . ' x ' . $this->thumbH;
            }
        }
        if (!$this->thumbW && !$this->thumbH) {
            $this->thumbW = $this->defaultwidth;
        }
        $this->error = false;
        $this->loadInfo();
        $this->thumb = false;

        if ($this->thumb === false) {
            $this->thumb = $this->loadAndResize();
        }

        if ($this->thumb === false) {
            /*
             * Nothing is written, and that is the whole point.
             *
             * The old behaviour drew the error into a real JPEG, which every check downstream then
             * accepted: the row said 128×64, the file was 500×100 of a file path on white, and
             * nothing raised. A caller that gets `false` can fall back to the original — which is
             * what an application actually wants for a vector, or for a file it cannot read.
             */
            return false;
        }

        $this->_setExportPath();
        if ($this->exportfile == '') {
            $f = basename($this->srcFile);
            $f = explode('.', $f);
            $f = $f[0];
            $f .= '-' . $this->thumbW . 'x' . $this->thumbH . '.' . $this->exporttype;
            if (file_exists($this->exportpath . $f)) {
                $f = rand(1, 9999) . '_' . $f;
            }
            if (file_exists($this->exportpath . $f)) {
                $f = md5(time() . '_' . $f) . '.' . $this->exporttype;
            }
            $this->exportfile = $f;
        }
        if ($this->exporttype == 'png') {
            imagepng($this->thumb, $this->exportpath . $this->exportfile);
        } else {
            imagejpeg($this->thumb, $this->exportpath . $this->exportfile);
        }
        unset($this->thumb);

        /*
         * `thumbW`/`thumbH` are re-read from what was written, not left as what was asked for.
         *
         * Every caller copies them into the rendition's `x`/`y`, so while they held the *request* the
         * database disagreed with the file on disk whenever the two diverged — and they diverge
         * exactly when something went wrong, which is when a stored size is worth having.
         */
        $written = @getimagesize($this->exportpath . $this->exportfile);
        if (is_array($written)) {
            $this->thumbW = $written[0];
            $this->thumbH = $written[1];
        }

        return true;
    }

    /**
     * Display the newly created image
     * @param string $src
     * @param int $width
     * @param int $height
     */
    public function display($src = '', $width = 0, $height = 0)
    {
        $this->resize($src, $width, $height);
        if (!headers_sent()) {
            header('Content-type: image/png');
        }
        echo file_get_contents($this->exportpath . $this->exportfile);
    }

    // PRIVATE FUNCTIONS

    /**
     * Set the exportpath (or auto-discover it)
     * @return string
     */
    private function _setExportPath($path = NULL)
    {
        if ($path !== NULL) {
            $this->exportpath = $path;
            return $path;
        }
        if ($this->exportpath == '') {
            if (defined('CACHE_PATH')) {
                $this->exportpath = CACHE_PATH . DS;
            } else {
                $this->exportpath = ROOT . DS . '_cache' . DS;
            }
        }
        return $this->exportpath;
    }

    /**
     * Loads information from the src file and does basic calculations
     */
    private function loadInfo()
    {
        if (file_exists($this->srcFile)) {
            /*
             * `getimagesize()` returns false for a file that is not an image, and the destructuring
             * assignment then warns three times — «Cannot use bool as array» — and leaves `width`,
             * `height` and `type` at whatever they were. The run continues on those values and fails
             * further in, which is why this surfaced as an odd warning from a truncated upload rather
             * than as «that is not an image».
             */
            $size = @getimagesize($this->srcFile);

            if (!is_array($size)) {
                $this->error = 'Not an image: ' . $this->srcFile;
                $this->width = false;
                $this->height = false;
                $this->type = false;

                return;
            }

            list($this->width, $this->height, $this->type) = $size;

            if ($this->thumbH === false && $this->thumbW !== false) {
                if ($this->height != 0 && $this->thumbW != 0 and $this->width != 0) {
                    $this->thumbH = round($this->height * ($this->thumbW / $this->width));
                }
                $this->crop = false;
            } elseif ($this->thumbH !== false && $this->thumbW === false) {
                $this->thumbW = round($this->width * ($this->thumbH / $this->height));
                $this->crop = false;
            } elseif ($this->thumbH === false && $this->thumbW === false) {
                die();
            }

            $this->clampToSource();
        }
    }

    /**
     * Bring a requested box down to the size of the picture that has to fill it.
     *
     * Both dimensions are known by the time this runs — `loadInfo()` has derived whichever one the
     * caller left out — so the comparison is available exactly where the decision belongs, which is
     * why this is here and not in every caller of `resize()`.
     *
     * Scaled by the limiting factor rather than clipped per axis, so the aspect ratio the caller
     * asked for survives: a 40×40 source asked for 512×256 gives 40×20, not 40×40.
     */
    private function clampToSource(): void
    {
        if ($this->allowUpscale
            || !$this->width || !$this->height
            || !$this->thumbW || !$this->thumbH
        ) {
            return;
        }

        $scale = min(
            1.0,
            $this->width / $this->thumbW,
            $this->height / $this->thumbH
        );

        if ($scale >= 1.0) {
            return;
        }

        $this->thumbW = max(1, (int) round($this->thumbW * $scale));
        $this->thumbH = max(1, (int) round($this->thumbH * $scale));

        if ($this->debug == true) {
            echo '<br /> Clamped to the source, so: '
                . $this->thumbW . ' x ' . $this->thumbH;
        }
    }

    /**
     * Calculate original ratio of the image
     * @return float
     */
    private function _calcOriginalRatio()
    {
        if ($this->width != 0 and $this->height != 0) {
            $ratio_original = max($this->width / $this->height,
                    $this->height / $this->width);
        } else {
            $ratio_original = 0;
        }
        if ($this->debug == true) {
            echo '<br />Original Ratio: ' . number_format($ratio_original, 2);
        }
        return $ratio_original;
    }

    /**
     * Calculate and return the ratio of the new image
     * @return float
     */
    private function _calcRatio()
    {
        if ($this->thumbW != 0 and $this->thumbH != 0) {
            $ratio = number_format(max($this->thumbW / $this->thumbH,
                            $this->thumbH / $this->thumbW), 2);
        } else {
            $ratio = 0;
        }

        if ($this->debug == true) {
            echo '<br />New Ratio: ' . $ratio;
        }
        return $ratio;
    }

    /**
     * Calculate and return the difference between original and new ratio.
     * Decide if crop will be forced
     * @param float $ratio
     * @param float $ratio_original
     * @return  float
     */
    private function _calcRatioDiff($ratio, $ratio_original)
    {
        if ($ratio > $ratio_original) {
            $diff = number_format($ratio - $ratio_original, 2);
        } else {
            $diff = number_format($ratio_original - $ratio, 2);
        }
        if ($this->debug == true) {
            echo '<br />Difference: ' . $diff;
            echo '<br />Resample Limit: ' . $this->resampleLimit;
        }
        if ($diff < $this->resampleLimit && $this->resample == true) {
            $this->forcecrop = true;
        }
        return $diff;
    }

    /**
     * Crop the image
     * @param resource $source
     */
    private function _crop(&$source)
    {
        if ($this->debug == true) {
            echo '<br />Will crop';
        }
        if ($this->forcecrop == true && $this->crop == false && $this->debug == true) {
            echo '<br />Forcing crop';
        }
        if ($this->width < $this->thumbW or $this->height < $this->thumbH) {
            ResizeTools::fastimagecopyresampled($this->thumb, $source, 0,
                    0, 0, 0, $this->thumbW, $this->thumbH, $this->width,
                    $this->height);
        } else {

            $ratio_orig = $this->width / $this->height;

            if ($this->thumbW / $this->thumbH > $ratio_orig) {
                $new_height = $this->thumbW / $ratio_orig;
                $new_width = $this->thumbW;
            } else {
                $new_width = $this->thumbH * $ratio_orig;
                $new_height = $this->thumbH;
            }

            $x_mid = $new_width / 2;  //horizontal middle
            $y_mid = $new_height / 2; //vertical middle

            $process = imagecreatetruecolor(round($new_width),
                    round($new_height));

            // A truecolor canvas starts opaque black, and this one is where the source
            // lands *before* it reaches the thumbnail. The thumbnail is prepared for
            // transparency a few lines up (`exporttype == 'png'`); this intermediate was
            // not, so every transparent pixel had already been composited onto black by the
            // time it got there. Reported as "cropped PNGs come back with black corners" —
            // and only on the cropping path, which is why it looked like a crop bug rather
            // than an alpha one.
            //
            // Only for PNG output, so the JPEG path composites exactly as it did: a JPEG has
            // no alpha to keep, and flattening onto black there is the existing behaviour.
            if ($this->exporttype == 'png') {
                imagealphablending($process, false);
                imagesavealpha($process, true);
                imagefill($process, 0, 0,
                    imagecolorallocatealpha($process, 0, 0, 0, 127));
            }

                ResizeTools::fastimagecopyresampled($process, $source, 0, 0,
                    0, 0, $new_width, $new_height, $this->width, $this->height);
            ResizeTools::fastimagecopyresampled($this->thumb, $process, 0,
                    0, ($x_mid - ($this->thumbW / 2)),
                    ($y_mid - ($this->thumbH / 2)), $this->thumbW,
                    $this->thumbH, $this->thumbW, $this->thumbH);
            unset($process);
        }
    }

    /**
     * Resample the image
     * @param resource $source
     */
    private function _resample(&$source)
    {
        // Where to put the original image inside the resampled
        $xpos=0;
        $ypos=0;
        if ($this->debug == true) {
            echo '<br /> Original Size: ' . $this->width . ' x ' . $this->height;
            echo '<br /> Will resamble to: ' . $this->thumbW . ' x ' . $this->thumbH;
        }
        if ($this->thumbH > $this->thumbW) {
            if ($this->debug == true) {
                echo '<br />Height is larger than width';
            }
            $nheight = round($this->height * ($this->thumbW / $this->width));
            $nwidth = $this->thumbW;
            $xpos = $this->thumbW - $nwidth;
            if ($this->debug == true) {
                echo "<br>X Position: " . $xpos;
            }
            if ($xpos > 2) {
                $xpos = (int)($xpos / 2);
            }
        } else {
            if ($this->debug == true) {
                echo '<br />Height is smaller than width';
            }
            #This is wrong - I cannot remember why...
            #$nwidth = round($this->width * ($this->thumbH / $this->height));
            #$nheight = $this->thumbH;
            $nheight = round($this->height * ($this->thumbW / $this->width));
            $nwidth = $this->thumbW;
            $ypos = $this->thumbH - $nheight;
            if ($this->debug == true) {
                echo "<br>Y Position: " . $ypos;
            }
            if ($ypos > 2) {
                $ypos = (int)($ypos / 2);
            }
        }

        $tempThumb = $this->thumb;
        $tempThumb = imageCreateTrueColor($this->thumbW, $this->thumbH);
        if ($this->exporttype == 'png') {
            if ($this->debug == true) {
                echo '<br />PNG type';
            }
            imagecolortransparent($tempThumb,
                    imagecolorallocatealpha($tempThumb, 0, 0, 0, 127));
            imagealphablending($tempThumb, false); // setting alpha blending on
            imagesavealpha($tempThumb, true); // save alphablending setting (important)
        } else {
            if ($this->fillcolor != "000000") {
                $color = $this->hex2array($this->fillcolor);

                // Filling a large image can want more memory than the host allows, so
                // the limit is raised for the one call and put back. Raised only —
                // see raiseMemoryLimit() for what setting it unconditionally did.
                $originalMemory = self::raiseMemoryLimit(256 * 1024 * 1024);

                @imagefill(
                    $this->thumb, 0, 0,
                    imagecolorallocate(
                        $tempThumb, $color[0], $color[1], $color[2]
                    )
                );

                if ($originalMemory !== null) {
                    ini_set('memory_limit', $originalMemory);
                }
            }
        }
        if ($this->debug == true) {
            echo '<br /> Resampled to: ' . $nwidth . ' x ' . $nheight;
        }

        ResizeTools::fastimagecopyresampled($tempThumb, $source, 0, 0, 0,
                0, $nwidth, $nheight, $this->width, $this->height);

        imagecopy($this->thumb, $tempThumb, $xpos, $ypos, 0, 0, $nwidth, $nheight);
        unset($tempThumb);
    }

    /**
     * Do the actual work
     * @return type
     */
    private function loadAndResize()
    {
        $ratio_original = $this->_calcOriginalRatio();
        $ratio = $this->_calcRatio();
        $diff = $this->_calcRatioDiff($ratio, $ratio_original);
        $source = $this->loadImageByType($this->srcFile, $this->type);
        if (!$source) {
            /*
             * GD cannot read this. Say so; do not draw it.
             *
             * `makeErrorImg()` turns «I could not read this» into a valid 500×100 JPEG of the file
             * path, which every downstream check then accepts — so the failure reached the library,
             * the `thumbnails` column and the page, with the row claiming the size that had been
             * asked for. An SVG on an ordinary GD build took exactly that route.
             *
             * The drawing is kept for `debug`, where seeing the path rendered is the point.
             */
            $this->error = 'Cannot read this image: ' . $this->srcFile;

            if ($this->debug == true) {
                $this->thumb = $this->makeErrorImg($this->srcFile);
                return $this->thumb;
            }

            $this->thumb = false;
            return false;
        }
        if ($this->thumbH === false && $this->thumbW === false) {
            throw new \Exception('There is no size to create an image');
        }

        $this->thumb = imageCreateTrueColor($this->thumbW, $this->thumbH);
        if ($this->exporttype == 'png') {
            imagecolortransparent($this->thumb,
                    imagecolorallocatealpha($this->thumb, 0, 0, 0, 127));
            imagealphablending($this->thumb, false); // setting alpha blending on
            imagesavealpha($this->thumb, true); // save alphablending setting (important)
            $this->resample = false;
        }

        if ($this->crop == true or $this->forcecrop == true) {
            $this->_crop($source);
        } elseif ($this->resample == true && $diff > $this->resampleLimit) {
            $this->_resample($source);
        } else {
            if ($this->debug == true) {
                echo '<br />Simple Resize';
            }
            ResizeTools::fastimagecopyresampled($this->thumb, $source, 0,
                    0, 0, 0, $this->thumbW, $this->thumbH, $this->width,
                    $this->height);
        }

        return $this->thumb;
    }

    /**
     * Creates an image containing an error message
     * @param string $msg
     * @return resource
     */
    private function makeErrorImg($msg)
    {
        $thumb = imagecreate(500, 100); /* Create a blank image */
        $bgc = imagecolorallocate($thumb, 255, 255, 255);
        $tc = imagecolorallocate($thumb, 0, 0, 0);
        imagefilledrectangle($thumb, 0, 0, 120, 30, $bgc);
        /* Output an errmsg */
        imagestring($thumb, 1, 5, 5, $msg, $tc);
        return $thumb;
    }

    /**
     * Check the image type returned by loadAndResize, create a temp image to do
     * all the work and set the export type
     * @param string $filename
     * @param string $type
     * @return boolean
     */
    private function loadImageByType($filename, $type)
    {
        switch ($type) {
            case IMG_WBMP:
                $this->exporttype = 'jpg';
                return @imagecreatefromwbmp($filename);
            case IMAGETYPE_GIF:
                $this->exporttype = 'png';
                @$img = imagecreatefromgif($filename);
                return $img;
            case IMAGETYPE_JPEG:
                $this->exporttype = 'jpg';
                return @imagecreatefromjpeg($filename);
            case IMAGETYPE_PNG:
                $this->exporttype = 'png';
                @$img = imagecreatefrompng($filename);
                return $img;
            default:
                return false;
        }
    }

    /**
     * Convert a hex color code to an array of RGB values
     * @param string $rgb
     * @return array
     */
    private function hex2array($rgb)
    {
        return array(
            base_convert(substr($rgb, 0, 2), 16, 10),
            base_convert(substr($rgb, 2, 2), 16, 10),
            base_convert(substr($rgb, 4, 2), 16, 10),
        );
    }

    // STATIC FUNCTIONS

    /**
     * copies a rectangular portion of one image to another image,
     * smoothly interpolating pixel values so that,
     * in particular, reducing the size of an image still retains a
     * great deal of clarity.<br>
     * In other words, fastimagecopyresampled() will take a rectangular area
     * from src_image of width src_w and height src_h at position (src_x,src_y)
     * and place it in a rectangular area of dst_image of width dst_w and
     * height dst_h at position (dst_x,dst_y).<br>
     * If the source and destination coordinates and width and heights differ,
     * appropriate stretching or shrinking of the image fragment will be
     * performed. The coordinates refer to the upper left corner.
     * This function can be used to copy regions within the same image
     * (if dst_image is the same as src_image) but if the regions overlap the
     * results will be unpredictable.<br>
     * ----<br><br>
     * Plug-and-Play fastimagecopyresampled function replaces much slower
     * imagecopyresampled. Just include this function and change all
     * "imagecopyresampled" references to "fastimagecopyresampled".<br>
     * Typically from 30 to 60 times faster when reducing high resolution<br>
     * images down to thumbnail size using the default quality setting.<br>
     * Date: 09/07/07 - Project: FreeRingers.net<br>
     * Freely distributable - These comments must remain.
     * @author Tim Eckel
     * @version 1.1
     * @param resource  $dst_image Destination image link resource.
     * @param resource  $src_image Source image link resource.
     * @param int  $dst_x x-coordinate of destination point.
     * @param int  $dst_y y-coordinate of destination point.
     * @param int  $src_x x-coordinate of source point.
     * @param int  $src_y y-coordinate of source point.
     * @param int  $dst_w Destination width.
     * @param int  $dst_h Destination height.
     * @param int  $src_w Source width.
     * @param int  $src_h Source height.
     * @param integer $quality Optional "quality" parameter (defaults is 4).
     * Fractional values are allowed, for example 1.5. Must be greater than 0.<br>
     * Between 0 and 1 = Fast, but mosaic results, closer to 0 increases
     * the mosaic effect.<br>
     * 1 = Up to 350 times faster. Poor results, looks very similar to<br>
     * imagecopyresized.<br>
     * 2 = Up to 95 times faster.  Images appear a little sharp, some prefer
     * this over a quality of 3.<br>
     * 3 = Up to 60 times faster.  Will give high quality smooth results very
     * close to imagecopyresampled, just faster.<br>
     * 4 = Up to 25 times faster.  Almost identical to imagecopyresampled<br>
     * for most images.<br>
     * 5 = No speedup. Just uses imagecopyresampled, no advantage over
     * imagecopyresampled.
     * @return boolean Returns TRUE on success or FALSE on failure.
     * @license    MIT
     */
    /**
     * Raise `memory_limit` to at least `$bytes`, and report what it was if it changed.
     *
     * ## What was wrong
     *
     * This used to be `ini_set("memory_limit", "256M")`, unconditionally. On a host
     * configured with more than 256M that is a **reduction** — the opposite of the
     * intent — and PHP refuses it outright once the process is already using more than
     * the new value, with:
     *
     *     Failed to set memory limit to 268435456 bytes
     *     (Current memory usage is 279969792 bytes)
     *
     * Which is where it surfaced: four tests in a long-running suite, at 279 MB. In
     * production it is quieter and worse — a request that had 512M available gets 256M
     * for the duration of the fill, which is the shape of the failure the raise exists
     * to prevent.
     *
     * ## Why there is no try/catch any more
     *
     * There were two, both around `ini_set()`, both logging a caught `\Exception`.
     * `ini_set()` does not throw: it returns `false` and raises a PHP warning. So the
     * handlers could never run, the warning went unhandled, and the code read as though
     * failure were covered. That is the part worth naming — a guard that cannot fire is
     * worse than none, because it stops anybody looking.
     *
     * Raising only also means the call cannot fail: usage can never exceed the current
     * limit, so a new limit above the current one is always above usage.
     *
     * @param  int $bytes The floor to guarantee
     * @return string|null The previous value if it was changed, null if nothing was done
     */
    private static function raiseMemoryLimit(int $bytes): ?string
    {
        $current = ini_get('memory_limit');

        if (!is_string($current) || $current === '') {
            // memory_limit always exists
            return null; // @codeCoverageIgnore
        }

        $parsed = \Pramnos\General\Helpers::parseMemoryLimit($current);

        // -1 is unlimited, and anything already at or above the floor needs nothing.
        if ($parsed === -1 || $parsed >= $bytes) {
            return null;
        }

        return ini_set('memory_limit', (string) $bytes) === false ? null : $current;
    }

    public static function fastimagecopyresampled(
    $dst_image, $src_image, $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h, $quality = 4
    )
    {
        if ($quality <= 0) {
            $quality = 1;
        }
        if (empty($src_image) || empty($dst_image)) {
            throw new \Exception('source image is empty');
        }
        if (
                $quality < 5 && (($dst_w * $quality) < $src_w || ($dst_h * $quality) < $src_h)
        ) {
            $tempW = (int)($dst_w * $quality + 1);
            $tempH = (int)($dst_h * $quality + 1);
            $temp = imagecreatetruecolor($tempW, $tempH);
            // Preserve transparency through the intermediate step — otherwise the
            // opaque temp image flattens the alpha channel of PNG/GIF sources.
            imagealphablending($temp, false);
            imagesavealpha($temp, true);
            imagefill($temp, 0, 0, imagecolorallocatealpha($temp, 0, 0, 0, 127));
            imagecopyresized($temp, $src_image, 0, 0, $src_x, $src_y,
                    $tempW, $tempH, $src_w, $src_h);
            imagecopyresampled($dst_image, $temp, (int)$dst_x, (int)$dst_y, 0, 0, (int)$dst_w,
                    (int)$dst_h, (int)($dst_w * $quality), (int)($dst_h * $quality));
            unset($temp);
        } else {
            imagecopyresampled($dst_image, $src_image, (int)$dst_x, (int)$dst_y, (int)$src_x,
                    (int)$src_y, (int)$dst_w, (int)$dst_h, (int)$src_w, (int)$src_h);
        }
        return true;
    }

}
