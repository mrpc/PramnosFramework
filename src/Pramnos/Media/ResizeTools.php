<?php
namespace Pramnos\Media;
/**
 * Based on justThumb.php - by Jack-the-ripper (c) Lars Oll�n 2005
 * @package     PramnosFramework
 * @subpackage  Thumbnail
 * @copyright   Copyright (C) 2005 - 2013 Yannis - Pastis Glaros, Pramnos Hosting
 * @copyright   Lars Oll�n 2005
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
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
        $this->loadInfo();
        $this->thumb = false;

        if ($this->thumb === false) {
            $this->thumb = $this->loadAndResize();
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
        imagedestroy($this->thumb);
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
            list($this->width, $this->height, $this->type) = getimagesize($this->srcFile);

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

                ResizeTools::fastimagecopyresampled($process, $source, 0, 0,
                    0, 0, $new_width, $new_height, $this->width, $this->height);
            $thumb = imagecreatetruecolor($this->thumbW, $this->thumbH);
            ResizeTools::fastimagecopyresampled($this->thumb, $process, 0,
                    0, ($x_mid - ($this->thumbW / 2)),
                    ($y_mid - ($this->thumbH / 2)), $this->thumbW,
                    $this->thumbH, $this->thumbW, $this->thumbH);

            imagedestroy($process);
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
                $originalMemory=  ini_get('memory_limit');
                try {
                    ini_set("memory_limit", "256M");
                } catch (\Exception $ex) {
                    \Pramnos\Logs\Logger ::log($ex->getMessage());
                }

                @imagefill(
                    $this->thumb, 0, 0,
                    imagecolorallocate(
                        $tempThumb, $color[0], $color[1], $color[2]
                    )
                );
                try {
                    ini_set("memory_limit", $originalMemory);
                } catch (\Exception $ex) {
                    \Pramnos\Logs\Logger::log($ex->getMessage());
                }
            }
        }
        if ($this->debug == true) {
            echo '<br /> Resampled to: ' . $nwidth . ' x ' . $nheight;
        }

        ResizeTools::fastimagecopyresampled($tempThumb, $source, 0, 0, 0,
                0, $nwidth, $nheight, $this->width, $this->height);

        imagecopy($this->thumb, $tempThumb, $xpos, $ypos, 0, 0, $nwidth, $nheight);
        @imagedestroy($tempThumb);
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
        if (!$source) { //In case of an error loading the original image, produce an error image
            $this->thumb = $this->makeErrorImg($this->srcFile);
            return $this->thumb;
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
     */
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
            $temp = imagecreatetruecolor($dst_w * $quality + 1,
                    $dst_h * $quality + 1);
            imagecopyresized($temp, $src_image, 0, 0, $src_x, $src_y,
                    $dst_w * $quality + 1, $dst_h * $quality + 1, $src_w, $src_h);
            imagecopyresampled($dst_image, $temp, $dst_x, $dst_y, 0, 0, $dst_w,
                    $dst_h, $dst_w * $quality, $dst_h * $quality);
            imagedestroy($temp);
        } else {
            imagecopyresampled($dst_image, $src_image, $dst_x, $dst_y, $src_x,
                    $src_y, $dst_w, $dst_h, $src_w, $src_h);
        }
        return true;
    }

}
