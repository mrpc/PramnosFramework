<?php

namespace Pramnos\Media;

/**
 * Media Object Class
 * @copyright   Copyright (C) 2011-2016 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 *
 *
 */
class MediaObject extends \Pramnos\Framework\Base
{
    /**
     * Primary key in database
     * @var int
     */
    public $mediaid = 0;
    /**
     * Media Type. 0: Generic 1: image, 2:emoticon 3:pdf 4:Flash Media 5:video
     * @var int
     */
    public $mediatype = 0;
    /**
     * Order (used mainly in galleries)
     * @var int
     */
    public $order = 0;
    /**
     * Media name
     * @var string
     */
    public $name = '';
    /**
     * Filename & path in local filesystem
     * @var string
     */
    public $filename = '';
    /**
     * Url (relative to site root)
     * @var string
     */
    public $url = '';
    /**
     * Shortcut - to be used in emoticons
     * @var string
     */
    public $shortcut = '';
    /**
     * Comma separated tags
     * @var string
     */
    public $tags = '';
    /**
     * Date of media creation in unix timestamp
     * @var integer
     */
    public $date = 0;
    /**
     * Owner userid
     * @var int
     */
    public $userid = 0;
    /**
     * Module (controller) that media file belongs to
     * @var string
     */
    public $module = '';
    /**
     * Views counter
     * @var int
     */
    public $views = 0;
    /**
     * Array with media thumbnails
     * @var array
     */
    public $thumbnails = array();
    /**
     * Filesize in bytes
     * @var int
     */
    public $filesize = 0;
    public $description = '';
    /**
     * Width
     * @var integer
     */
    public $x = 0;
    /**
     * Height
     * @var integer
     */
    public $y = 0;
    /**
     * If the media is actually a link to another media, this is the original
     * media mediaid
     * @var int
     */
    public $medialink = 0;
    /**
     * MD5 hash of file's contents, to avoid uploading duplicate stuff
     * @var string
     */
    public $md5 = '';
    /**
     * Number of usages
     * @var int
     */
    public $usages = 0;
    /**
     * Permission to use by other users than owner
     * @var int
     */
    public $otherusers = 0;
    /**
     * Permission to use by other modules than original
     * @var int
     */
    public $othermodules = 0;
    protected $_isnew = true;
    /**
     * Max Thumbnail Width
     * @var int
     */
    public $thumb = 120;
    /**
     * Max Thumbnail Height
     * @var int
     */
    public $thumbHeight = 85;
    /**
     * Max Medium Image Width
     * @var int
     */
    public $medium = 600;
    /**
     * Max Medium Image Height
     * @var int
     */
    public $mediumHeight = 0;
    /**
     * Max Width
     * @var int
     */
    public $max = 1024;
    /**
     * Max Height
     * @var int
     */
    public $maxHeight = 0;
    /**
     * Record errors in image proccess
     * @var boolean|string
     */
    public $error = false;
    /**
     * Should the original media be deleted after import or edit?
     * @var boolean
     */
    public $deleteOriginal = false;
    /**
     * Πρέπει να διορθώνεται αυτόματα η περιστροφή της εικόνας;
     * @var bool
     */
    public $fixOrientation = false;

    /**
     * Usage ID
     * @var int
     */
    public $usageid;
    public $usageDescription = '';
    public $usageTags = '';
    public $usageTitle = '';
    public $usageOrder = 0;
    public $usageSpecific = '';
    public $usageModule = '';
    public $resampleLimit = 0.55;

    protected $_ext = '';

    /**
     * Return an instance
     * @return MediaObject
     */
    public static function getInstance()
    {
        return new MediaObject();
    }

    /**
     * Create the md5 hash of the contents of the media file
     * @return MediaObject
     */
    public function createMd5()
    {
        $file = file_get_contents($this->filename);
        $this->md5 = md5($file);
        return $this;
    }

    /**
     * Gets all usages of a specific media ite,
     * @param integer $mediaid
     * @return MediaObject
     */
    public function getMediaUsages($mediaid = 0)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        if ($mediaid == 0) {
            $mediaid = $this->mediaid;
        }
        if ($mediaid == 0) {
            return array();
        }
        $sql = $database->prepareQuery(
            "select * from `#PREFIX#mediause` where `mediaid` = %d
            order by `order`", $mediaid
        );
        $result = $database->query($sql, true, 60, 'media');
        if ($result->numRows != 0) {
            while ($result->fetch()) {
                $media = new MediaObject();
                $media->loadByUsage($result->fields['usageid']);
                $return[] = $media;
                unset($media);
            }
            $this->usages = count($return);
            return $return;
        }

        return array();
    }


    /**
     * Converts a static path to a newer version after a server change
     * @param string $original
     */
    protected function fixStaticPath($original, $root=NULL)
    {
        if ($root===NULL) {
            $root = ROOT;
        }
        $converted = str_replace(
            substr($original, 0, strpos($original, 'uploads')),
            $root . DS, $original
        );
        if (DS == "/") {
            return str_replace('\\', DS, $converted);
        } else {
            return str_replace('/', DS, $converted);
        }
    }

    /**
     * Check if main media file exists (and try to convert path from previous
     * installs)
     */
    private function _checkFilePath()
    {
        if (!file_exists($this->filename)) {
            if (file_exists($this->fixStaticPath($this->filename))) {
                $this->filename=$this->fixStaticPath($this->filename);
                $this->_checkThumbPaths();
            } else {
                return false;
            }
        }
        return true;
    }

    /**
     * Check all thumbnail filenames and fix them if needed
     */
    private function _checkThumbPaths()
    {
        foreach ($this->thumbnails as $key=>$thumb) {
            if (!file_exists($thumb->filename)
                    && file_exists($this->fixStaticPath($thumb->filename))) {
                $this->thumbnails[$key]->filename=$this->fixStaticPath(
                    $thumb->filename
                );
            }
        }
    }

    /**
     * Get media usages from a specific module
     * @param string $module
     * @param string $specific
     * @param bool  $removeDuplicates If true, no duplicates will be used
     * @return MediaObject[]
     */
    public function getUsages($module='', $specific='',
        $removeDuplicates = false)
    {
        return self::staticGetUsages(
            $module, $specific, $removeDuplicates
        );
    }

    /**
     * Get media usages from a specific module
     * @param string $module
     * @param string $specific
     * @param bool  $removeDuplicates If true, no duplicates will be used
     * @return MediaObject[] An array of MediaObject objects
     */
    public static function staticGetUsages($module = '', $specific = '',
        $removeDuplicates = false)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();

        if ($module == '' && $specific == '') {
            $sql = $database->prepareQuery(
                "select * from `#PREFIX#mediause`
                order by `order`"
            );
        } elseif ($module == '' && $specific != '') {
            $sql = $database->prepareQuery(
                "select * from `#PREFIX#mediause`
                where `specific` = %s
                order by `order`", $specific
            );
        } else {
            if ($specific == '') {
                $sql = $database->prepareQuery(
                    "select * from `#PREFIX#mediause`
                    where `module` = %s
                    order by `order`", $module
                );
            } else {
                $sql = $database->prepareQuery(
                    "select * from `#PREFIX#mediause`
                    where `module` = %s and `specific` = %s
                    order by `order`", $module, $specific
                );
            }
        }
        $result = $database->query($sql, true, 60, 'media');
        if ($result->numRows != 0) {
            while ($result->fetch()) {
                $media = new MediaObject();
                $media->loadByUsage($result->fields['usageid']);
                $return[] = $media;
                unset($media);
            }
            if ($removeDuplicates == true) {
                $existingphotos=array();
                foreach ($return as $key=>$p) {
                    if (isset($existingphotos[$p->url])) {
                        unset($return[$key]);
                    } else {
                        $existingphotos[$p->url]=$key;
                    }
                }
                unset($existingphotos);
            }
            return $return;
        }
        return array();
    }




    /**
     * Get all media objects of the specific type
     * @param   int $type
     * @return  MediaObject
     */
    public function getList($type = 0, $module = '', $userid = '')
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $statement = "";
        if ($userid == '') {
            if (isset($_SESSION['uid'])) {
                $userid = $_SESSION['uid'];
            }
        }
        $user = new \Pramnos\User\User($userid);
        if ($user->usertype != 2) {
            $statement = $database->prepareQuery(
                " and (`userid` = %d or `otherusers` = 1) ",
                $userid
            );
        }
        if ($type != 0) {
            if ($module == '') {
                $sql = $database->prepareQuery(
                    "select * from `#PREFIX#media`
                    where `mediatype` = %d $statement
                    order by `order`", $type
                );
            } else {
                $sql = $database->prepareQuery(
                    "select * from `#PREFIX#media`
                    where `mediatype` = %d
                    and (`module` = %s or `module` = ''
                    or `othermodules` = 1)  $statement
                    order by `order`", $type, $module
                );
            }
        } else {
            if ($module == '') {
                $sql = $database->prepareQuery(
                    "select * from `#PREFIX#media`
                    order by `order`"
                );
            } else {
                $sql = $database->prepareQuery(
                    "select * from `#PREFIX#media`
                    where (`module` = %s or `module` = ''
                    or `othermodules` = 1)   $statement
                    order by `order`", $module
                );
            }
        }
        $result = $database->query($sql, true, 60, 'media');
        if ($result->numRows != 0) {
            while ($result->fetch()) {
                $media = new MediaObject();
                $media->load($result->fields['mediaid']);
                $return[] = $media;
            }
            return $return;
        }
        return array();
    }

    /**
     * Add an existing image to media library
     * @param string $file
     * @param string $module
     * @param boolean $deleteOriginal
     */
    public function addImage($file, $module = '', $deleteOriginal = false)
    {
        $this->mediatype = 1;
        if (file_exists($file)) {
            $path = $this->createPath($module);
            try {
                copy($file, $path . strtolower(basename($file)));
            } catch (\Exception $ex) {
                \Pramnos\Logs\Logger::log("Cannot copy image. " . $ex->getMessage());
                $this->error = "Cannot copy image. " . $ex->getMessage();
                return $this;
            }
            if ($deleteOriginal === true) {
                try {
                    unlink($file);
                } catch (\Exception $ex) {
                    \Pramnos\Logs\Logger::log(
                        "Cannot delete original image. " . $ex->getMessage()
                    );
                }
            }
            $this->filename = $path . strtolower(basename($file));
            $this->processImage($this->filename, $path);
            return $this;
        } else {
            $this->error = "File doesn't exist";
            return $this;
        }
    }

    /**
     * Add a remote image to media library
     * @param string $url
     * @param string $module
     */
    public function addRemoteImage($url, $module = '')
    {
        $this->mediatype = 1;

        if (!is_dir(ROOT . DS . 'www' . DS . 'uploads')) {
            mkdir(ROOT . DS . 'www' . DS . 'uploads');
        }
        $urlParams = explode('?', $url);
        $urlParts=explode('.', $urlParams[0]);

        $ext = '';
        $getlast=explode('.', end($urlParts));
        if (is_array($getlast)) {
           $ext = $getlast[0];
        }

        $possibleExtentions = array(
            'jpg', 'jpeg', 'gif', 'png', 'bmp', 'ico'
         );

        if (!in_array($ext, $possibleExtentions)) {
            $ext = 'jpg';
        }


        $image = \Pramnos\General\Helpers::fileGetContents($url);
        $filename = ROOT . DS . 'www' . DS
            . 'uploads' . DS . 'tmp' . rand(0, time())
            . '.' . $ext;
        $handler = fopen($filename, 'w');
        fwrite($handler, $image);
        fclose($handler);

        return $this->addImage($filename, $module, true);
    }

    /**
     * Create the path to upload a file
     * @param string $module
     * @return string Path
     */
    private function createPath($module = '')
    {
        if (!is_dir(ROOT . DS . 'www' . DS . 'uploads')) {
            mkdir(ROOT . DS . 'www' . DS . 'uploads');
        }
        $path = ROOT . DS . 'www' . DS . 'uploads';

        if ($module != '') {
            $this->module = $module;
        }

        if ($this->module != '') {
            if (!is_dir($path . DS . $this->module)) {
                mkdir($path . DS . $this->module);
            }
            $path .= DS . $this->module;
        }

        if (!is_dir($path . DS . date('Y'))) {
            mkdir($path . DS . date('Y'));
        }
        $path .= DS . date('Y');

        if (!is_dir($path . DS . date('m'))) {
            mkdir($path . DS . date('m'));
        }
        $path .= DS . date('m');

        if (!is_dir($path . DS . date('d'))) {
            mkdir($path . DS . date('d'));
        }
        $path .= DS . date('d');
        return $path . DS;
    }

    /**
     * Do all needed resizes
     * @param string $file
     * @param string $path
     * @return MediaObject
     */
    private function processImage($file, $path)
    {
        if ($this->_ext == '') {
            $this->_ext = strtolower(
                str_replace(".", "", strrchr(basename($file), '.'))
            );
        }
        if (($this->_ext == 'jpg' || $this->_ext == 'jpeg')
            && $this->fixOrientation == true) {
            $this->fixJpegOrientation($file);
        }
        $database = \Pramnos\Framework\Factory::getDatabase();
        $size = @getimagesize($file);
        $startWidth = $size[0];
        $startHeight = $size[1];
        if ($this->_ext != 'ico' && ($this->max != 0
            || $this->maxHeight != 0)) {
            if (($this->max != 0 && $startWidth > $this->max)
                || ($this->maxHeight != 0
                && $startHeight > $this->maxHeight)) {
                rename($file, $file . '.original');
                $thumb = new ResizeTools();
                $thumb->maxsize = $this->max;
                $thumb->exportpath = $path;
                $thumb->exportfile = basename($file);
                $thumb->resize(
                    $file . '.original', $this->max, $this->maxHeight
                );
                if ($this->deleteOriginal === true) {
                    unlink($file . '.original');
                }
                $size = getimagesize($file);
                $startWidth = $size[0];
                $startHeight = $size[1];
            }
        }
        $this->url = str_replace(
            DS, '/',
            str_replace(ROOT . DS . 'www' . DS, '', $this->filename)
        );
        $this->x = $startWidth;
        $this->y = $startHeight;
        $this->date = time();
        $this->filesize = filesize($this->filename);
        $this->md5 = md5(file_get_contents($this->filename));

        $sql = $database->prepareQuery(
            "select * from `#PREFIX#media` "
            . " where `md5` = %s and `medialink` = 0 "
            . " limit 1",
            $this->md5
        );
        $result = $database->query($sql);
        if ($result->numRows != 0) {
            $this->medialink = $result->fields['mediaid'];
            $this->url = $result->fields['url'];
            $tmpMedia = new MediaObject();
            $tmpMedia->load($result->fields['mediaid']);
            if (!file_exists($tmpMedia->filename)
                && $tmpMedia->filename != $file) { // Fixing missing original
                try {
                    \Pramnos\Logs\Logger::log(
                        'Original file is missing. Copying '
                        . $file . ' to ' . $tmpMedia->filename
                    );
                    $copy = copy($file, $tmpMedia->filename);
                    if ($copy) {
                        @unlink($file);
                    } else {
                        $tmpMedia->filename = $file;
                        $tmpMedia->save();
                        \Pramnos\Logs\Logger::log('Cannot copy');
                    }
                } catch (\Exception $ex) {
                    $tmpMedia->filename = $file;
                    $tmpMedia->save();
                    \Pramnos\Logs\Logger::log($ex->getMessage());
                }


            }

            if (file_exists($file . '.original')) {
                if ($this->deleteOriginal === true) {
                    unlink($file . '.original');
                }
            }
            $this->filename=$tmpMedia->filename;
            $this->thumbnails = $tmpMedia->thumbnails;
            $this->filesize = $tmpMedia->filesize;
            return $this;
        }


        $original = new Thumbnail();
        $original->createdTxt = date('d/m/Y H:i:s');
        $original->filename = $this->filename;
        $original->url = $this->url;
        $original->x = $startWidth;
        $original->y = $startHeight;
        $original->views = 0;
        $original->filesize = $this->filesize;
        $original->reason = 'original';
        $this->thumbnails[] = $original;



        if ($this->_ext != 'ico'
            && ($startWidth > $this->medium
            || $startHeight > $this->mediumHeight)) {
            $thumb = new ResizeTools();
            $thumb->createdTxt = date('d/m/Y H:i:s');
            $thumb->maxsize = $this->max;
            $thumb->exportpath = $path;
            $thumb->resize($file, $this->medium, $this->mediumHeight);
            $tfile = $thumb->exportpath . $thumb->exportfile;
            $startWidth = $thumb->thumbW;
            $startHeight = $thumb->thumbH;
            $tmpThumb = new Thumbnail();
            $tmpThumb->filename = $tfile;
            $tmpThumb->url = str_replace(
                DS, '/',
                str_replace(ROOT . DS . 'www' . DS, '', $tmpThumb->filename)
            );
            $tmpThumb->x = $startWidth;
            $tmpThumb->y = $startHeight;
            $tmpThumb->views = 0;
            $tmpThumb->filesize = filesize($tfile);
            $tmpThumb->reason = 'medium';
            $this->thumbnails[] = $tmpThumb;
            $this->filesize = $this->filesize + $tmpThumb->filesize;
            unset($tmpThumb);
        } else {
            $medium = new Thumbnail();
            $medium->createdTxt = date('d/m/Y H:i:s');
            $medium->filename = $this->filename;
            $medium->url = $this->url;
            $medium->x = $startWidth;
            $medium->y = $startHeight;
            $medium->views = 0;
            $medium->filesize = $this->filesize;
            $medium->reason = 'medium';
            $this->thumbnails[] = $medium;
        }

        if ($this->_ext != 'ico'
            && ($startWidth > $this->thumb
            || $startHeight > $this->thumbHeight)) {
            $thumb = new ResizeTools();
            $thumb->createdTxt = date('d/m/Y H:i:s');
            $thumb->maxsize = $this->max;
            $thumb->exportpath = $path;
            $thumb->resize($file, $this->thumb, $this->thumbHeight);
            $tfile = $thumb->exportpath . $thumb->exportfile;
            $startWidth = $thumb->thumbW;
            $startHeight = $thumb->thumbH;
            $tmpThumb = new Thumbnail();
            $tmpThumb->filename = $tfile;
            $tmpThumb->url = str_replace(
                DS, '/',
                str_replace(ROOT . DS . 'www' . DS, '', $tmpThumb->filename)
            );
            $tmpThumb->x = $startWidth;
            $tmpThumb->y = $startHeight;
            $tmpThumb->views = 0;
            $tmpThumb->filesize = filesize($tfile);
            $tmpThumb->reason = 'thumb';
            $this->thumbnails[] = $tmpThumb;
            $this->filesize = $this->filesize + $tmpThumb->filesize;
            unset($tmpThumb);
        } else {
            $thumb = new Thumbnail();
            $thumb->createdTxt = date('d/m/Y H:i:s');
            $thumb->filename = $this->filename;
            $thumb->url = $this->url;
            $thumb->x = $startWidth;
            $thumb->y = $startHeight;
            $thumb->views = 0;
            $thumb->filesize = $this->filesize;
            $thumb->reason = 'thumb';
            $this->thumbnails[] = $thumb;
        }

        return $this;
    }

    /**
     * Add a string to a filename before it's extension
     * @param  string $filename
     * @param  string $stringToAdd
     * @return string
     */
    protected function addStringToFilename($filename, $stringToAdd)
    {
        $fileArray = explode('.', $filename);
        if (count($fileArray) == 1) {
            return $filename . $stringToAdd;
        }
        $fileArray[count($fileArray)-2] .= $stringToAdd;
        return implode('.', $fileArray);
    }

    /**
     * Rotate an image
     * @todo  Delete original images & calculate disk space again
     * @param int $degrees -360 to 360
     * @return boolean
     */
    protected function rotate($degrees)
    {
        if ((int) $degrees == 0) {
            return false;
        }
        $fileExt = '-r' . $degrees;
        $isJpg = false;
        $rotatedImages = array();
        if (file_exists($this->filename)) {
            if (stripos($this->filename, '.jpg') !== false
                || stripos($this->filename, '.jpeg') !== false) {
                $image = imagecreatefromjpeg($this->filename);
            } elseif (stripos($this->filename, '.png') !== false) {
                $image = imagecreatefrompng($this->filename);
            } else {
                $image = imagecreatefromjpeg($this->filename);
                $isJpg = true;
                if (!$image) {
                    return false;
                }
            }
            $image = imagerotate($image, $degrees, 0);
            if (stripos($this->filename, '.jpg') !== false
                || stripos($this->filename, '.jpeg') !== false || $isJpg) {
                imagejpeg(
                    $image,
                    $this->addStringToFilename($this->filename, $fileExt),
                    100
                );
            } else {
                imagepng(
                    $image,
                    $this->addStringToFilename($this->filename, $fileExt)
                );
            }
            $x = $this->x;
            $y = $this->y;
            $this->x = $y;
            $this->y = $x;
            $this->filename = $this->addStringToFilename(
                $this->filename, $fileExt
            );
            $this->url = $this->addStringToFilename($this->url, $fileExt);
            $rotatedImages[$this->filename] = true;
        }
        foreach ($this->thumbnails as $key => $thumbnail) {
            $validFormat = true;
            if (!isset($rotatedImages[$thumbnail->filename])) {
                if (stripos($thumbnail->filename, '.jpg') !== false
                    || stripos($thumbnail->filename, '.jpeg') !== false
                    || $isJpg) {
                    $image = imagecreatefromjpeg($thumbnail->filename);
                } elseif (stripos($thumbnail->filename, '.png') !== false) {
                    $image = imagecreatefrompng($thumbnail->filename);
                } else {
                    $validFormat = false;
                }
            }
            if ($validFormat) {
                $image = imagerotate($image, $degrees, 0);
                if (stripos($thumbnail->filename, '.jpg') !== false
                    || stripos($thumbnail->filename, '.jpeg') !== false
                    || $isJpg) {
                    imagejpeg(
                        $image,
                        $this->addStringToFilename(
                            $thumbnail->filename, $fileExt
                        ),
                        100
                    );
                } elseif (stripos($thumbnail->filename, '.png') !== false)  {
                    imagepng(
                        $image,
                        $this->addStringToFilename(
                            $thumbnail->filename, $fileExt
                        )
                    );
                }
                $x = $thumbnail->x;
                $y = $thumbnail->y;
                $thumbnail->x = $y;
                $thumbnail->y = $x;
                $thumbnail->filename = $this->addStringToFilename(
                    $thumbnail->filename, $fileExt
                );
                $thumbnail->url = $this->addStringToFilename(
                    $thumbnail->url, $fileExt
                );
                $this->thumbnails[$key] = $thumbnail;
                $rotatedImages[$thumbnail->filename] = true;
            }
        }
        $this->save();

        return true;
    }

    /**
     * Rotate 90 degrees to the left
     * @return boolean
     */
    public function rotateLeft()
    {
        return $this->rotate(90);
    }

    /**
     * Rotate 90 degrees to the right
     * @return boolean
     */
    public function rotateRight()
    {
        return $this->rotate(-90);
    }

    /**
     * Update a photo orientation based on exif data
     * @param string $filename
     */
    private function fixJpegOrientation($filename)
    {
        try {
            $exif = @exif_read_data($filename);
        } catch (\Exception $ex) {
            $exif = array(
                'message' => $ex->getMessage(),
                'Orientation' => null
            );
        }

        if (!empty($exif['Orientation'])) {
            $image = imagecreatefromjpeg($filename);
            switch ($exif['Orientation']) {
                case 3:
                    $image = imagerotate($image, 180, 0);
                    imagejpeg($image, $filename, 100);
                    break;
                case 6:
                    $image = imagerotate($image, -90, 0);
                    imagejpeg($image, $filename, 100);
                    break;
                case 8:
                    $image = imagerotate($image, 90, 0);
                    imagejpeg($image, $filename, 100);
                    break;
            }
        }
    }

    /**
     * Make sure we have a valid file to upload
     * @param array $file
     * @return array
     */
    protected function _validateUploadFileInput($file)
    {
        if (!is_array($file)) {
            if (isset($_FILES[$file])) {
                return $this->_validateUploadFileInput($_FILES[$file]);
            }
        }
        if (!isset($file['name'])
            || !isset($file['type'])
            || !isset($file['tmp_name'])) {
            throw new \Exception('Invalid file upload');
        }
        return $file;
    }

    /**
     * Upload a media file and do all the right stuff to it
     * @param array $fileToUpload $_FILES[] file
     * @param string $module
     * @param int $type
     * @return MediaObject
     */
    public function uploadFile($fileToUpload, $module = '', $type = NULL)
    {
        $file = $this->_validateUploadFileInput($fileToUpload);
        if ($type !== NULL) {
            $this->mediatype = $type;
        }
        $path = $this->createPath($module);

        $fchars = array(" ", "&", "!", "(", ")", "#", "$", "^", "*", "?");
        $rchars = array(
            "_", "_and_", "_", "_", "_", "No", "Dollar", "Percent", "",
            "star", "question"
        );
        if (mb_detect_encoding($file['name']) != FALSE) {
            $filename = str_replace(
                $fchars, $rchars,
                mb_strtolower(
                    \Pramnos\General\Helpers::greeklish($file['name']),
                    mb_detect_encoding($file['name'])
                )
            );
        } else {
            $filename = str_replace(
                $fchars, $rchars,
                strtolower(\Pramnos\General\Helpers::greeklish($file['name']))
            );
        }

        $fchars = array("_", "  ");
        $thename = str_replace($fchars, " ", strtolower($file['name']));


        $ext = strtolower(
            str_replace(".", "", strrchr(basename($filename), '.'))
        );
        $filename = time()
            . substr(md5($filename), 0, 5)
            . rand(0, time())
            . '.'
            . $ext;
        $this->_ext=$ext;
        $thename = str_replace("." . $ext, "", $thename);
        if ($this->mediatype == 1 or $this->mediatype == 2) {
            $allowedExtentions = array(
                'jpg', 'jpeg', 'gif', 'png', 'bmp', 'ico'
            );
        } else {
            $allowedExtentions = array(
                'jpg', 'jpeg', 'gif', 'png', 'bmp', 'pdf', 'ico', 'xls', 'xlsx'
            );
        }
        if (array_search($ext, $allowedExtentions) === false) {
            $this->error = "#1 Invalid File Type: " . $ext;
            return $this;
        }
        if ($this->mediatype == 0) {
            if ($ext == 'pdf') {
                $this->mediatype = 3;
            } elseif ($ext == 'xls' || $ext == 'xlsx' 
                || $ext == 'doc' || $ext == 'docx') {
                $this->mediatype = 0;
            } else {
                $this->mediatype = 1;
            }
        }
        if ($file['type'] != "") {
            if ($this->mediatype == 1 or $this->mediatype == 2) {
                switch ($file['type']) {
                    case "image/jpeg":
                    case "image/gif":
                    case "image/png":
                    case "image/vnd.wap.wbmp":
                    case "image/pjpeg":
                    case "image/x-png":
                    case "image/x-icon":
                    case "image/vnd.microsoft.icon":
                        break;
                    default:
                        $this->error = "#2 Invalid MIME type: " . $file['type'];
                        return $this;
                        break;
                }
            } elseif ($this->mediatype == 3) {
                switch ($file['type']) {
                    case "application/pdf":
                        $this->mediatype = 3;
                        break;
                    default:
                        $this->error = "#3 Invalid MIME type: " . $file['type'];
                        return $this;
                        break;
                }
            } elseif ($this->mediatype == 0) {
                switch ($file['type']) {
                    case "image/jpeg":
                    case "image/gif":
                    case "image/x-icon":
                    case "image/png":
                    case "image/vnd.microsoft.icon":
                    case "image/vnd.wap.wbmp":
                    case "image/pjpeg":
                    case "image/x-png":
                        $this->mediatype = 1;
                        break;
                    case "application/pdf":
                        $this->mediatype = 3;
                        break;
                    case "application/vnd.ms-excel":
                    case "application/vnd.oasis.opendocument.text":
                    case "application/xml":
                    case "text/xml":
                    case "application/msword":
                    case "application/vnd.ms-powerpoint":
                    case "application/vnd.openxmlformats-officedocument.presentationml.presentation":
                    case "application/vnd.openxmlformats-officedocument.wordprocessingml.document":
                    case "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet":
                    case "application/vnd.oasis.opendocument.spreadsheet":
                        $this->mediatype = 0;
                        $this->x = 0;
                        $this->y = 0;
                        break;
                    default:
                        $this->error = "#4 Invalid MIME type: " . $file['type'];
                        return $this;
                        break;
                }
            }
        }

        $uploadfile = $path . $filename;
        if (file_exists($uploadfile)) {
            $uploadfile = $path . rand(0, time()) . $filename;
        }

        if ($this->move_uploaded_file($file['tmp_name'], $uploadfile)) {
            #chmod($uploadfile, 0777);
        } else {
            $this->error = "Cannot Move Files";
            return $this;
        }
        if (isset($_SESSION['uid']) && $this->userid == 0) {
            $this->userid = $_SESSION['uid'];
        }
        $this->filename = $uploadfile;
        if ($this->mediatype == 1 or $this->mediatype == 2) {
            $this->processImage($uploadfile, $path);
        }

        $this->url = str_replace(
            DS, '/',
            str_replace(ROOT . DS . 'www' . DS, '', $this->filename)
        );

        // I don't remember why this was here
        #$this->x = 0;
        #$this->y = 0;
        $this->date = time();
        $this->filesize = filesize($this->filename);
        $this->md5 = md5(file_get_contents($this->filename));

        $database = \Pramnos\Framework\Factory::getDatabase();
        $sql = $database->prepareQuery(
            "select * from `#PREFIX#media` where `md5` = %s limit 1",
            $this->md5
        );
        $result = $database->query($sql);
        if ($result->numRows != 0) {
            $this->medialink = $result->fields['mediaid'];
            $this->url = $result->fields['url'];
            $tmpMedia = new MediaObject();
            $tmpMedia->load($result->fields['mediaid']);
            if ($tmpMedia->filename != $this->filename) {
                @unlink($this->filename);
            }
            return $this;
        }
        $this->name = $thename;
        $this->save();
        return $this;
    }

    /**
     * Shortcut of uploadFile, specific for images
     * @param string $file
     * @param string $module
     * @return MediaObject
     */
    public function uploadImage($file, $module = '')
    {
        return $this->uploadFile($file, $module, 1);
    }

    /**
     * Add a usage to the media object
     * @param string $module What module/controller uses this media object
     * @param string $specific More specific info about usage
     * @param string $title Title used for this usage
     * @param string $description Description used for this usage
     * @param string $tags Tags used for this usage
     * @param int $order Order of display
     * @return int Usage ID or false if cannot be created
     * @throws \Exception When media usage cannot be created
     */
    public function addUsage($module = '', $specific = '', $title = '',
        $description = '', $tags = '', $order = 0)
    {
        if ($this->mediaid == 0) {
            throw new \Exception(
                'Cannot add a usage to a non existing media object.'
            );
        }
        if ($module == '') {
            $module = $this->module;
        }
        if ($module == '') {
            throw new \Exception(
                'Cannot add a usage where there is no module.'
            );
        }

        if ($title == '') {
            if ($this->usageTitle == '') {
                $title = $this->name;
            } else {
                $title = $this->usageTitle;
            }
        }

        if ($description == '') {
            if ($this->usageDescription == '') {
                $description = $this->description;
            } else {
                $description = $this->usageDescription;
            }
        }

        if ($order == 0) {
            if ($this->usageOrder == 0) {
                $order = $this->order;
            } else {
                $order = $this->usageOrder;
            }
        }

        if ($tags == "") {
            if ($this->usageTags == '') {
                $tags = $this->tags;
            } else {
                $tags = $this->usageTags;
            }
        }
        $database = \Pramnos\Framework\Factory::getDatabase();
        $itemdata = array(
            array(
                'fieldName' => 'mediaid',
                'value' => $this->mediaid,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'module',
                'value' => $module,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'specific',
                'value' => $specific,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'date',
                'value' => time(),
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'title',
                'value' => $title,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'description',
                'value' => $description,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'tags',
                'value' => $tags,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'order',
                'value' => $order,
                'type' => 'integer'
            )
        );
        $database->cacheflush('media');
        $database->insertDataToTable($database->prefix . "mediause", $itemdata);
        $usageid = $database->getInsertId();
        if ($this->mediaid != 0) {
            $this->usages = $this->usages + 1;
            $sql = $database->prepareQuery(
                "update `#PREFIX#media` set `usages` = %d "
                . "where `mediaid` = %d",
                $this->usages, $this->mediaid
            );
            $database->query($sql);
        }
        return $usageid;
    }

    /**
     * Remove a media usage
     * @param integer $usageid
     * @param boolean $safe Dont delete original if no other usages are found
     * @return MediaObject
     */
    function removeUsage($usageid, $safe = false)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $database->cacheflush('media');
        $sql = $database->prepareQuery(
            "select * from `#PREFIX#mediause` where `usageid` = %d limit 1",
            $usageid
        );
        $result = $database->query($sql);
        if ($result->numRows != 0) {
            $mediaid = $result->fields['mediaid'];
            $sql = $database->prepareQuery(
                "delete from `#PREFIX#mediause` where `usageid` = %d limit 1",
                $usageid
            );
            $database->query($sql);
            $database->query(
                $database->prepareQuery(
                    "update `#PREFIX#media` set `usages` = `usages` - 1 "
                    . "where `mediaid` = %d", $mediaid
                )
            );

            if ($safe === false) {
                $sql = $database->prepareQuery(
                    "select * from `#PREFIX#mediause` where `mediaid` = %d",
                    $mediaid
                );
                $num = $database->query($sql);
                if ($num->numRows == 0) {
                    $temp = new MediaObject();
                    $temp->load($mediaid);
                    $temp->delete();
                    unset($temp);
                }
            }
        }
        $database->cacheflush('media');
        return $this;
    }

    /**
     * Clear all usages and rewrite them updated
     * @param string|array $mediaList An array or a comma separated list
     * @param string $module
     * @param string $specific
     */
    public static function multipleUsageUpdate($mediaList, $module,
        $specific = '')
    {
        if (!is_array($mediaList)) {
            $mediaList = explode(",", $mediaList);
        }
        $temp = new MediaObject();
        $temp->clearUsage($module, $specific, true);
        unset($temp);
        $count = 0;
        foreach ($mediaList as $mediaid) {
            $mediaid = (int)$mediaid;
            if ($mediaid != 0) {
                $themedia = new MediaObject();
                $themedia->load($mediaid);
                $themedia->addUsage($module, $specific, '', '', '', $count);
                $count+=1;
                unset($themedia);
            }
        }
    }

    /**
     * Clear all usages from a specific place
     * @param string    $module Module Prefix
     * @param string    $specific
     * @param boolean   $safe
     */
    public function clearUsage($module, $specific = '', $safe = true)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $database->cacheflush('media');
        $sql = $database->prepareQuery(
            "select * from `#PREFIX#mediause` "
            . "where `module` = %s and `specific` = %s",
            $module, $specific
        );
        $result = $database->query($sql);
        if ($result->numRows != 0) {
            while ($result->fetch()) {
                $media = new MediaObject();
                $media->removeUsage($result->fields['usageid'], $safe);
            }
        }
        return $this;
    }

    /**
     * Load a media object by it's usage id
     * @param int  $usageid
     * @param bool $updateViews Should the views for this media be updated?
     * @return MediaObject
     */
    public function loadByUsage($usageid, $updateViews=false)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $sql = $database->prepareQuery(
            "select * from `#PREFIX#mediause` where `usageid` = %d limit 1",
            $usageid
        );
        $result = $database->query($sql, true, 600, 'media');
        if ($result->numRows != 0) {
            $this->load($result->fields['mediaid']);
            $this->views += 1;
            if ($updateViews == true) {
                $database->query(
                    $database->prepareQuery(
                        "update `#PREFIX#media` set `views` = %d "
                        . "where `mediaid` = %d limit 1",
                        $this->views, $this->mediaid
                    )
                );
            }
            $this->usageid = $result->fields['usageid'];
            $this->usageTitle = $result->fields['title'];
            $this->usageTags = $result->fields['tags'];
            $this->usageDescription = $result->fields['description'];
            $this->usageOrder = $result->fields['order'];
            $this->usageSpecific = $result->fields['specific'];
            $this->usageModule = $result->fields['module'];
            if ($this->usageTitle == '') {
                $this->name = $this->usageTitle;
            }

            if ($this->usageDescription == '') {
                $this->description = $this->usageDescription;
            }

            if ($this->usageTags == '') {
                $this->tags = $this->usageTags;
            }
            $this->order = $this->usageOrder;
        }
        return $this;
    }

    /**
     * Save a media object to the database
     * @param bool $force Force save even when there is an error
     * @return MediaObject
     */
    public function save($force = false)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        if ($this->error != false && $force == false) {
            return $this;
        }
        if ($this->userid == 0 && isset($_SESSION['uid'])) {
            $this->userid = $_SESSION['uid'];
        }
        if ($this->date == 0) {
            $this->date = time();
        }

        $itemdata = array(
            array(
                'fieldName' => 'mediatype',
                'value' => $this->mediatype,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'userid',
                'value' => $this->userid,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'module',
                'value' => $this->module,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'views',
                'value' => $this->views,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'thumbnails',
                'value' => serialize($this->thumbnails),
                'type' => 'string'
            ),
            array(
                'fieldName' => 'filesize',
                'value' => $this->filesize,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'description',
                'value' => $this->description,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'x',
                'value' => $this->x,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'y',
                'value' => $this->y,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'usages',
                'value' => $this->usages,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'md5',
                'value' => $this->md5,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'medialink',
                'value' => $this->medialink,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'order',
                'value' => $this->order,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'name',
                'value' => $this->name,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'filename',
                'value' => $this->filename,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'url',
                'value' => $this->url,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'shortcut',
                'value' => $this->shortcut,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'tags',
                'value' => $this->tags,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'date',
                'value' => $this->date,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'otherusers',
                'value' => $this->otherusers,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'othermodules',
                'value' => $this->othermodules,
                'type' => 'integer'
            )
        );
        $database->cacheflush('media');
        if ($this->_isnew == true) {
            $this->_isnew = false;
            $database->insertDataToTable(
                $database->prefix . "media", $itemdata
            );
            $this->mediaid = $database->getInsertId();
        } else {
            $database->updateTableData(
                $database->prefix . "media", $itemdata,
                "`mediaid` = '" . (int) $this->mediaid . "'", false
            );
        }
        return $this;
    }

    /**
     * Update the usage in database
     * @return MediaObject
     */
    public function saveUsage()
    {
        if (!$this->usageid) {
            return $this;
        }
        $database = \Pramnos\Framework\Factory::getDatabase();
        if ($this->userid == 0) {
            $this->userid = $_SESSION['uid'];
        }
        if ($this->date == 0) {
            $this->date = time();
        }
        $itemdata = array(
            array(
                'fieldName' => 'title',
                'value' => $this->usageTitle,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'description',
                'value' => $this->usageDescription,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'tags',
                'value' => $this->usageTags,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'order',
                'value' => $this->usageOrder,
                'type' => 'integer'
            )
        );
        $database->cacheflush('media');
        $database->updateTableData(
            $database->prefix . "mediause", $itemdata, 'update',
            "`usageid` = '" . (int) $this->usageid . "'", false
        );
        return $this;
    }

    /**
     * Delete the media object
     * @return MediaObject
     */
    public function delete()
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        if ($this->mediaid != 0) {

            $sql = $database->prepareQuery(
                "delete from `#PREFIX#media` where `mediaid` = %d limit 1",
                $this->mediaid
            );
            $database->query($sql);
            if ($this->medialink == 0) {

                $sql = $database->prepareQuery(
                    "select * from `#PREFIX#media` "
                    . "where `medialink` = %d limit 1",
                    $this->mediaid
                );
                $result = $database->query($sql);

                if ($result->numRows == 0) {
                    foreach ($this->thumbnails as $image) {
                        @unlink($image->filename);
                    }
                    @unlink($this->filename);
                } else {

                    $database->query(
                        $database->prepareQuery(
                            "update `#PREFIX#media` set `medialink` = 0 "
                            . "where `mediaid` = %d limit 1",
                            $result->fields['mediaid']
                        )
                    );
                    $database->query(
                        $database->prepareQuery(
                            "update `#PREFIX#media` set `medialink` = %d "
                            . "where `medialink` = %d",
                            $result->fields['mediaid'], $this->mediaid
                        )
                    );
                }
            }
        }
        $this->_isnew = true;
        $this->mediaid = 0;
        $database->cacheflush('media');
        return $this;
    }

    /**
     * Load a media object from the database
     * @param int $mediaidToLoad
     * @return MediaObject
     */
    public function load($mediaidToLoad)
    {
        //I don't even remeber why there is this array thing...
        $mediaIdArray = explode(",", $mediaidToLoad);
        $mediaid = $mediaIdArray[0];
        unset($mediaIdArray, $mediaidToLoad);
        $database = \Pramnos\Framework\Factory::getDatabase();
        $sql = $database->prepareQuery(
            "select * from `#PREFIX#media` where `mediaid` = %d limit 1",
            $mediaid
        );
        $result = $database->query($sql, true, 600, 'media');
        if ($result->numRows != 0) {
            foreach (array_keys($result->fields) as $key) {
                $this->$key = $result->fields[$key];
            }
            $this->thumbnails = unserialize($result->fields['thumbnails']);
            $this->_isnew = false;
        }
        return $this;
    }

    /**
     * Get a thumbnail of the image
     * @return MediaObject_thumbnail
     */
    public function getThumb()
    {
        if ($this->mediatype == 1 or $this->mediatype == 2) {
            foreach ($this->thumbnails as $thumb) {
                if ($thumb->reason == "thumb") {
                    return $thumb;
                }
            }
            foreach ($this->thumbnails as $thumb) {
                if ($thumb->reason == "medium") {
                    return $thumb;
                }
            }
            foreach ($this->thumbnails as $thumb) {
                if ($thumb->reason == "original") {
                    return $thumb;
                }
            }
            return new Thumbnail();
        } elseif ($this->mediatype == 0) {
            $thumb = new Thumbnail();
            $thumb->createdTxt = date('d/m/Y H:i:s');
            $thumb->filename = ROOT . 'www/assets/image/pdf.png';
            $thumb->x = 120;
            $thumb->y = 120;
            $thumb->views = 0;
            $thumb->filesize = 0;
            $thumb->reason = "File Preview";
            $thumb->url = 'assets/image/pdf.png';
            return $thumb;
        } elseif ($this->mediatype == 3) {
            $thumb = new Thumbnail();
            $thumb->createdTxt = date('d/m/Y H:i:s');
            $thumb->filename = ROOT . 'www/assets/image/pdf.png';
            $thumb->x = 120;
            $thumb->y = 120;
            $thumb->views = 0;
            $thumb->filesize = 0;
            $thumb->reason = "PDF Preview";
            $thumb->url = 'assets/image/pdf.png';
            return $thumb;
        }
    }

    /**
     * Get a custom sized image based on the original media
     * @param int $width Width of the new image
     * @param int $height Height of the new image
     * @param boolean $crop Crom image to the new size to retain the same ratio
     * @param boolean $force Force image creation, even if it exists
     * @param boolean $debug Show debug information
     * @param boolean $resample Different way of creating the image
     * @return MediaObject_thumbnail
     * @throws \Exception
     */
    function get($width, $height, $crop = false,
        $force = false, $debug = false, $resample = true)
    {
        if ($debug ==true) {
            echo '<br />Media ID: ' . $this->mediaid
                . '<br />Usage ID: ' . $this->usageid
                . '<br />Linked: ' . $this->medialink;
        }
        $reason = '';
        $existingFile = '';
        if ($this->mediatype == 1 or $this->mediatype == 2) {
            if ($force == false) {
                foreach ($this->thumbnails as $key => $thumb) {
                    if ($thumb->x == $width and $thumb->y == $height) {

                        if (file_exists($thumb->filename)) {
                            return $thumb;
                        } else {
                            if ($debug == true) {
                                echo '<br />Deleting existing '
                                . 'thumbnail because of invalid file';
                            }
                            $reason = $thumb->reason;
                            unset($this->thumbnails[$key]);
                            $this->save();
                        }


                    }
                }
            } else {
                foreach ($this->thumbnails as $key => $thumb) {
                    if ($thumb->x == $width and $thumb->y == $height) {
                        if ($debug == true) {
                            echo '<br />Deleting existing thumbnail';
                        }
                        $reason = $thumb->reason;
                        if ($thumb->filename != $this->filename
                                && $thumb->reason != 'original'
                                && $this->medialink == 0) {
                            @unlink($thumb->filename);
                            $existingFile = $thumb->filename;
                        }
                        if ($thumb->reason != 'original') {
                            unset($this->thumbnails[$key]);
                        }
                        $this->save();
                    }
                }


                if ($debug == true) {
                    echo '<br />forced recreation';
                }
            }
            if ($debug == true) {
                echo '<br />creating image';
            }
            if (!$this->_checkFilePath()) {
                if (!$this->_tryToRecreatePath()) {
                    throw new \Exception(
                        'Media file doesnt exist: ' . $this->filename
                    );
                }

            }
            // Doesn't exist. Create one.
            $thumb = new ResizeTools();
            $thumb->createdTxt = date('d/m/Y H:i:s');
            $thumb->resample = $resample;
            $thumb->resampleLimit = $this->resampleLimit;
            $thumb->maxsize = $this->max;
            $thumb->exportpath = $this->createPath($this->module);
            $thumb->crop = $crop;
            $thumb->debug = $debug;
            $thumb->resize($this->filename, $width, $height);

            $tfile = $thumb->exportpath . $thumb->exportfile;
            if ($existingFile != '') {
                try {
                    if(rename($tfile, $existingFile)) {
                        $tfile = $existingFile;
                    }
                } catch (\Exception $exc) {
                    \Pramnos\Logs\Logger::log($exc->getMessage());
                }
            }

            $tmpWidth = $thumb->thumbW;
            $tmpHeight = $thumb->thumbH;
            $tmThumb = new Thumbnail();
            $tmThumb->createdTxt = date('d/m/Y H:i:s');
            $tmThumb->filename = $tfile;
            $tmThumb->url = str_replace(
                DS, '/',
                str_replace(ROOT . DS . 'www' . DS, '', $tmThumb->filename)
            );
            $tmThumb->x = $tmpWidth;
            $tmThumb->y = $tmpHeight;
            $tmThumb->views = 0;
            $tmThumb->filesize = filesize($tfile);
            if ($reason == '') {
                $tmThumb->reason = 'custom';
            } else {
                $tmThumb->reason = $reason;
            }
            $this->thumbnails[] = $tmThumb;
            $this->filesize = $this->filesize + $tmThumb->filesize;
            $this->save();
            return $tmThumb;
        } elseif ($this->mediatype == 3) {
            $thumb = new Thumbnail();
            $thumb->createdTxt = date('d/m/Y H:i:s');
            $thumb->filename = ROOT .  'www/assets/image/pdf.png';
            $thumb->x = 256;
            $thumb->y = 256;
            $thumb->views = 0;
            $thumb->filesize = 0;
            $thumb->reason = "PDF Preview";
            $thumb->url = 'assets/image/pdf.png';
            return $thumb;
        } else {
            $thumb = new Thumbnail();
            $thumb->createdTxt = date('d/m/Y H:i:s');
            $thumb->filename = ROOT .  'www/assets/image/pdf.png';
            $thumb->x = 256;
            $thumb->y = 256;
            $thumb->views = 0;
            $thumb->filesize = 0;
            $thumb->reason = "File Preview";
            $thumb->url = 'assets/image/pdf.png';
            return $thumb;
        }
    }

    /**
     * If the main file is missing, try to recreate the
     * file using the medium size
     * @return boolean
     */
    private function _tryToRecreatePath()
    {
        $medium = $this->getMedium();
        if (file_exists($medium->url)) {
            $this->url = $medium->url;
            $this->filename = $medium->filename;
            return true;
        }
        return false;
    }

    /**
     * Get a medium size image
     * @return MediaObject_thumbnail
     */
    function getMedium()
    {
        if ($this->mediatype == 1 or $this->mediatype == 2) {
            foreach ($this->thumbnails as $thumb) {
                if ($thumb->reason == "medium") {
                    return $thumb;
                }
            }
            foreach ($this->thumbnails as $thumb) {
                if ($thumb->reason == "original") {
                    return $thumb;
                }
            }
            return new Thumbnail();
        } elseif ($this->mediatype == 3) {
            $thumb = new Thumbnail();
            $thumb->createdTxt = date('d/m/Y H:i:s');
            $thumb->filename = ROOT . 'www/assets/image/pdf.png';
            $thumb->x = 256;
            $thumb->y = 256;
            $thumb->views = 0;
            $thumb->filesize = 0;
            $thumb->reason = "PDF Preview";
            $thumb->url = $this->url;
            return $thumb;
        }
    }

    private function move_uploaded_file($filename, $destination)
    {
        if (defined('UNITTESTING') && UNITTESTING === true) {
            return copy($filename, $destination);
        } else {
            return move_uploaded_file($filename, $destination);
        }
    }

}
