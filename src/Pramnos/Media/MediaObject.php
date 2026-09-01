<?php

namespace Pramnos\Media;

/**
 * Media Object Class
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 *
 *
 * @license    MIT
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
     * What the file actually is, according to its own bytes.
     *
     * Read with `finfo` at upload, where it is also what decides whether the content matches the
     * extension. Recorded because the alternative is re-guessing the type from the extension later,
     * which is the claim that check exists to distrust — and because a security decision nobody
     * wrote down cannot be audited afterwards.
     *
     * `''` when it could not be read: no `fileinfo` extension, or an unreadable file.
     *
     * @var string
     */
    public $mimetype = '';
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
    /**
     * Extra info in JSON format
     * @var string
     */
    public $extrainfo = '';
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
     * Store the file and derive nothing from it.
     *
     * One switch for «I have my own resizer; be the library». Before this, saying it took six
     * assignments, two of which meant the opposite of the other four, and a magic number chosen to be
     * above every real picture — because `max = 0` genuinely meant «do not touch the original» while
     * `medium = 0` meant «derive one for every upload, at a width nobody named».
     *
     * With this on, the `thumbnails` column still holds `original`, `medium` and `thumb`, all three
     * pointing at the untouched file. That is the honest answer for a store that was told not to
     * resize: the entries exist because callers ask for them by name, and they describe what is
     * actually there.
     *
     * @var bool
     */
    public $deriveNothing = false;

    /**
     * May a rendition be larger than the file it is derived from?
     *
     * `false`. Asking a 40×40 logo for 512×512 used to write 512×512 of stretched blur, recorded as a
     * rendition and served as though it were real — a file *larger* than the original it came from.
     * Requests are clamped to the source's own dimensions now, keeping the requested aspect.
     *
     * Passed through to {@see ResizeTools::$allowUpscale}; set it where invented pixels are actually
     * wanted.
     *
     * @var bool
     */
    public $allowUpscale = false;

    /**
     * Upper bound on a size {@see get()} may be asked for, in pixels. `0` leaves it to ResizeTools.
     *
     * Separate from {@see $max} because the two are different questions and sharing one number made
     * the answer to one break the other: `max` is «do not rewrite what I stored», and `get()` was
     * passing it down as «how large may a derived image be». So an application that set `max = 0` to
     * protect its originals got 120-pixel-wide renditions from every `get()`, at any requested size,
     * silently — the one ceiling that read correctly for storage was the one that broke retrieval.
     *
     * @var int
     */
    public $maxRequest = 0;

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
     * Ceiling on the body of a fetch by {@see addRemoteImage()}, in bytes.
     *
     * Ten megabytes: above any station logo, product photo or avatar, and small enough that a URL
     * answering with an endless stream costs this process nothing worth noticing. Raise it for an
     * application that genuinely imports large originals; there is no value that means «no limit»,
     * because a limitless read of somebody else's URL is the bug.
     *
     * @var int
     */
    public $remoteMaxBytes = 10485760;

    /**
     * How many redirects {@see addRemoteImage()} follows, each one checked.
     *
     * Three, not zero, and the reason is that refusing them outright loses pictures a site
     * legitimately holds: an image address that has sat in a catalogue for years is very often an
     * `http://` that now redirects to `https://`, or a path a CDN has since moved. A fetch that
     * refused those would be safe and useless.
     *
     * Safe because every hop is a fresh question, not because three is a small number: a `302` is a
     * second address chosen by the server being fetched, and
     * {@see \Pramnos\Security\OutboundUrl::nextHop()} runs the whole check on it before it is
     * dialled. Set it to `0` where an address is expected to be exact.
     *
     * @var int
     */
    public $remoteMaxRedirects = 3;

    /**
     * What {@see addRemoteImage()} accepts, and the extension each type is stored under.
     *
     * Keyed by the mime read from the *bytes*, so the extension on disk describes the content rather
     * than repeating the URL's claim about it. Everything under `www/uploads/` is served back by the
     * web server, and what a web server does with a file it serves depends on that extension.
     *
     * SVG is deliberately absent. It is markup — it can carry script, and served from the site's own
     * origin that script is same-origin. An application that wants remote SVGs owns that decision and
     * can call {@see addImage()} with a file it fetched and sanitised itself.
     */
    private const REMOTE_IMAGE_TYPES = [
        'image/jpeg'    => 'jpg',
        'image/pjpeg'   => 'jpg',
        'image/png'     => 'png',
        'image/gif'     => 'gif',
        'image/webp'    => 'webp',
        'image/bmp'     => 'bmp',
        'image/x-ms-bmp' => 'bmp',
        'image/x-icon'  => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];

    /**
     * Return an instance
     * @return MediaObject
     */
    public static function getInstance()
    {
        return new MediaObject();
    }

    /**
     * The thumbnails in a stored row, as a list this class can actually walk.
     *
     * `getThumb()` reads `$thumb->reason` on every entry, and two shapes of stored value make that
     * fail rather than return nothing:
     *
     *  - **an object of a class the *reading process* cannot load.** The column holds serialised
     *    objects, and the class name is part of the serialisation, so who can read a row depends on
     *    which classes that process has. An application with its own thumbnail class reads its own
     *    rows perfectly well — real data exists serialised as `O:23:"foreign_media_thumbnail"`, and
     *    the application holding it declares exactly that class with the same properties this one
     *    has, so nothing there was ever broken.
     *
     *    It breaks for a *different* reader: this framework on its own, a second application sharing
     *    the database, a CLI process that does not boot the first application's aliases. There
     *    `unserialize()` yields `__PHP_Incomplete_Class`, and reading *any* property of one of those
     *    is a fatal error rather than a missing thumbnail.
     *  - **an empty or unparsable value.** `unserialize('')` is `false`, and `foreach (false)` warns
     *    and iterates nothing.
     *
     * So the value is normalised on the way in: anything that is not a usable thumbnail is dropped
     * and the caller gets a shorter list, which is what `getThumb()` already handles — it falls
     * back to an empty `Thumbnail`.
     *
     * `unserialize()` is deliberately **not** restricted with `allowed_classes`. It would be better
     * hardening, and it would also discard an application's own thumbnail class that loads
     * perfectly well today — which is a behaviour change for installations that are working. The
     * filter below achieves the part that matters, which is not crashing.
     *
     * @param  mixed $stored The raw column value
     * @return array<int, Thumbnail|object> Entries that can be read
     */
    private static function usableThumbnails($stored): array
    {
        if (!is_string($stored) || $stored === '') {
            return array();
        }

        // JSON is what this class writes now. A leading `[` is enough to tell the two apart —
        // PHP's serialisation of an array always begins `a:`.
        $first = substr(ltrim($stored), 0, 1);

        if ($first === '[' || $first === '{') {
            $decoded = json_decode($stored, true);

            return is_array($decoded) ? self::hydrateThumbnails($decoded) : array();
        }

        return self::readLegacyThumbnails($stored);
    }

    /**
     * Build {@see Thumbnail} objects from the stored JSON.
     *
     * Only the properties the class declares are copied. A payload carrying something else — a key
     * from a future version, or junk — is ignored rather than becoming a dynamic property, which
     * PHP 8.2 deprecates and which nothing would read anyway.
     *
     * @param  array<int, mixed> $rows
     * @return array<int, Thumbnail>
     */
    private static function hydrateThumbnails(array $rows): array
    {
        $thumbnails = array();

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $thumbnail = new Thumbnail();

            foreach (self::THUMBNAIL_FIELDS as $field) {
                if (array_key_exists($field, $row)) {
                    $thumbnail->$field = $row[$field];
                }
            }

            $thumbnails[] = $thumbnail;
        }

        return $thumbnails;
    }

    /**
     * Read a row written before the column held JSON.
     *
     * Returned **as they are**, not converted to {@see Thumbnail}. An application with its own
     * thumbnail class has been getting its own objects back from these rows for years, and
     * rewriting them into this class's type on read would change what `getThumb()` hands its
     * callers — for rows nobody has touched. They convert themselves the next time the media object
     * is saved, because the writer only produces JSON.
     *
     * The filter is what stops the two shapes that break the caller rather than returning nothing:
     * an object of a class the *reading process* cannot load, which deserialises to
     * `__PHP_Incomplete_Class` and fatals on any property access; and a value that does not
     * deserialise at all, where `unserialize('')` is `false` and `foreach (false)` warns.
     *
     * `unserialize()` is deliberately not restricted with `allowed_classes`: that would be better
     * hardening and would also discard the application's own class, which loads perfectly well.
     *
     * @param  string $stored
     * @return array<int, object>
     */
    private static function readLegacyThumbnails(string $stored): array
    {
        $decoded = @unserialize($stored);

        if (!is_array($decoded)) {
            return array();
        }

        $usable = array();

        foreach ($decoded as $thumbnail) {
            if (!is_object($thumbnail) || $thumbnail instanceof \__PHP_Incomplete_Class) {
                continue;
            }

            if (!property_exists($thumbnail, 'reason')) {
                continue;
            }

            $usable[] = $thumbnail;
        }

        return $usable;
    }

    /**
     * The thumbnails, as the column should hold them.
     *
     * JSON of the fields rather than `serialize()` of the objects, and the difference is not
     * cosmetic. PHP's serialisation writes the **class name into the data**, so who can read a row
     * depends on which classes the reading process has: this framework alone, a second application
     * on the same database, or a CLI script that skips the first application's autoloader all get
     * `__PHP_Incomplete_Class` and a fatal on the first property read. It also writes property
     * *names*, so renaming one silently drops every stored value; it hands `unserialize()` the power
     * to instantiate classes and run their magic methods; and nothing outside PHP can read it — no
     * SQL, no `JSON_EXTRACT`, no reporting tool, and no index on «thumbnails wider than 500px».
     *
     * `Thumbnail` is eight scalar properties with no methods and no nesting, so there is nothing
     * about it that JSON cannot carry.
     *
     * Falls back to `serialize()` if the payload cannot be encoded — a filename in some encoding
     * `json_encode()` refuses, say. Losing the thumbnails would be worse than writing the old
     * format for one row, and the reader accepts both.
     *
     * @param  mixed $thumbnails
     * @return string
     */
    private static function encodeThumbnails($thumbnails): string
    {
        if (!is_array($thumbnails)) {
            return '[]';
        }

        $rows = array();

        foreach ($thumbnails as $thumbnail) {
            if (!is_object($thumbnail)) {
                continue;
            }

            $row = array();

            foreach (self::THUMBNAIL_FIELDS as $field) {
                // Read through `isset` rather than `property_exists`, so a legacy object of another
                // class contributes whatever of these it happens to have.
                $row[$field] = isset($thumbnail->$field) ? $thumbnail->$field : null;
            }

            $rows[] = $row;
        }

        $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            \Pramnos\Logs\Logger::log(
                'Thumbnails could not be encoded as JSON (' . json_last_error_msg()
                . '); stored in the legacy format instead.'
            );

            return serialize($thumbnails);
        }

        return $json;
    }

    /**
     * The properties a thumbnail is stored as.
     *
     * Declared once because three things have to agree about them: what is written, what is read
     * back, and what {@see Thumbnail} actually has. Adding a property to that class and forgetting
     * one of the three is how a value starts disappearing on save.
     */
    private const THUMBNAIL_FIELDS = array(
        'filename', 'x', 'y', 'views', 'filesize', 'reason', 'url', 'createdTxt',
    );

    /**
     * Create the md5 hash of the contents of the media file.
     *
     * A file that cannot be read leaves the hash **empty**, and that is the fix rather than a
     * detail. `file_get_contents()` on a missing file returns `false`, and `md5(false)` is
     * `md5('')` — `d41d8cd98f00b204e9800998ecf8427e`, the same value for every missing file. Since
     * `uploadFile()` finds a re-upload with `where md5 = %s and medialink = 0`, every file whose
     * bytes had gone was a duplicate of every other one, and the next upload could be linked to any
     * of them.
     *
     * Not hypothetical: a production library of 4,551 files holds **14** rows carrying exactly that
     * hash. An empty hash matches nothing, which is the honest answer for a file nobody can read.
     *
     * @return MediaObject
     */
    public function createMd5()
    {
        $file = @file_get_contents((string) $this->filename);

        $this->md5 = $file === false ? '' : md5($file);

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
            // Recorded here as well, so the column is populated whichever way a file arrived. No
            // content *check* is added: this entry point takes a file the application already has,
            // not one a visitor uploaded.
            $this->mimetype = (string) self::detectMimeType($this->filename);
            $this->processImage($this->filename, $path);
            return $this;
        } else {
            $this->error = "File doesn't exist";
            return $this;
        }
    }

    /**
     * Fetch an image from a URL and add it to the media library.
     *
     * **This method makes a request from the server.** That is the whole difference between it and
     * {@see addImage()}, which reads a file the application already has, and it is why the two are
     * not interchangeable however similar the call looks. The address, in every realistic use — an
     * importer, a «fetch the logo from their website» button, a picture-by-URL field — came from
     * outside, and the server can reach places whoever supplied it cannot: a cloud metadata endpoint,
     * an unauthenticated service on loopback, the database, the rest of the subnet.
     *
     * So three things happen here that did not before, and each one is a fetch this used to perform:
     *
     *  - **the address is checked and dialled** through {@see \Pramnos\Security\OutboundUrl}, which
     *    resolves the host, refuses every address inside this network, refuses a scheme that is not
     *    http/https, and connects to the address it approved rather than resolving the name a second
     *    time. Redirects are followed up to {@see $remoteMaxRedirects} hops and **each hop is checked
     *    again**, because a redirect is a second address chosen by the server being fetched.
     *  - **the body is capped** at {@see $remoteMaxBytes}, mid-stream, so a URL that answers with a
     *    hundred gigabytes costs this process ten megabytes rather than its memory.
     *  - **the type is read from the bytes**, not from the URL's text. The old extension guess
     *    defaulted to `jpg`, so the stored filename said `jpg` about content nobody had looked at.
     *
     * On refusal it sets {@see $error} and returns `$this`, the same as every other failure in this
     * class — there is nothing for a caller to do differently, and throwing would turn a bad URL in
     * an import of two thousand into an aborted import.
     *
     * @param string $url
     * @param string $module
     * @return MediaObject
     */
    public function addRemoteImage($url, $module = '')
    {
        $this->mediatype = 1;

        if (!is_dir(ROOT . DS . 'www' . DS . 'uploads')) {
            mkdir(ROOT . DS . 'www' . DS . 'uploads');
        }

        $reason = null;
        $image = \Pramnos\Security\OutboundUrl::fetch(
            (string) $url,
            $this->remoteMaxBytes,
            $reason,
            10,
            $this->remoteMaxRedirects
        );

        if ($image === false || $image === '') {
            $this->error = 'Cannot fetch remote image. ' . ($reason ?? 'Empty response.');
            \Pramnos\Logs\Logger::log($this->error . ' URL: ' . $url);
            return $this;
        }

        /*
         * The extension comes from the content, and an unrecognised type is refused.
         *
         * Reading it from the URL was the actual defect, not a style problem: `$ext` fell back to
         * `jpg` for anything the address did not spell out, so the library ended up holding files
         * whose name asserted a type nothing had verified. Anything under `www/uploads/` is served
         * back by the web server, and what a web server does with a file depends on its extension.
         */
        $mime = self::detectMimeTypeOfString($image);
        $ext = self::REMOTE_IMAGE_TYPES[$mime] ?? '';

        if ($ext === '') {
            $this->error = 'The fetched file is not an image this library accepts'
                . ($mime === null ? '.' : ': ' . $mime);
            \Pramnos\Logs\Logger::log($this->error . ' URL: ' . $url);
            return $this;
        }

        $filename = ROOT . DS . 'www' . DS
            . 'uploads' . DS . 'tmp' . self::randomToken()
            . '.' . $ext;

        if (@file_put_contents($filename, $image) === false) {
            $this->error = 'Cannot write the fetched image.';
            \Pramnos\Logs\Logger::log($this->error . ' Path: ' . $filename);
            return $this;
        }

        return $this->addImage($filename, $module, true);
    }

    /**
     * The pixel ceiling to hand `ResizeTools` for a *requested* size.
     *
     * `maxRequest` when set, then `max`, then whatever `ResizeTools` defaults to — and the last step
     * is the fix. `max = 0` means «do not rewrite what I stored», and passing that straight down made
     * every requested width «over the ceiling», so `ResizeTools` substituted its 120-pixel default:
     * a 40×40 source asked for at 512 came back 512 with the defaults and 120 with `max = 0`. Leaving
     * the ceiling alone when there is no storage ceiling keeps the two decisions apart.
     *
     * @return int
     */
    protected function requestCeiling(): int
    {
        if ($this->maxRequest > 0) {
            return (int) $this->maxRequest;
        }

        if ($this->max > 0) {
            return (int) $this->max;
        }

        return (new ResizeTools())->maxsize;
    }

    /**
     * Does this file get raster renditions made from it?
     *
     * Three reasons it might not, and they are not the same reason:
     *
     *  - **the caller said not to.** {@see $deriveNothing}.
     *  - **it is an icon.** A `.ico` holds several sizes already, and rewriting it produces one.
     *  - **it is a vector.** An SVG is already every size, so there is nothing to derive — and what
     *    used to happen instead was worse than nothing: GD cannot read it on an ordinary build, so
     *    the first request for a size replaced the logo with a 500×100 JPEG of its own file path,
     *    stored at a URL ending `.jpg`, recorded in the database as the size that had been asked for.
     *    Nothing raised and nothing logged.
     *
     * The vector check reads the mime rather than `mediatype`. `mediatype` stays `1` for an SVG,
     * because it is an image and because every application's own `mediatype == 1` branch means «this
     * is a picture» — giving vectors a new number would quietly route them into whatever those
     * branches do with an unknown type.
     *
     * @return bool
     */
    protected function derivesRenditions(): bool
    {
        return !$this->deriveNothing
            && $this->_ext != 'ico'
            && !$this->isVector();
    }

    /**
     * Is the stored file a vector?
     *
     * Reads the recorded mime first, and falls back to the extension for rows written before the
     * `mimetype` column was populated — where `''` is «nobody looked», not «not a vector».
     *
     * @return bool
     */
    public function isVector(): bool
    {
        if ($this->mimetype !== '') {
            return str_contains(strtolower($this->mimetype), 'svg');
        }

        $extension = $this->_ext !== ''
            ? $this->_ext
            : strtolower((string) pathinfo((string) $this->filename, PATHINFO_EXTENSION));

        return $extension === 'svg' || $extension === 'svgz';
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
        self::protectUploadDirectory($path);

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
        if ($this->derivesRenditions() && ($this->max != 0
            || $this->maxHeight != 0)) {
            if (($this->max != 0 && $startWidth > $this->max)
                || ($this->maxHeight != 0
                && $startHeight > $this->maxHeight)) {
                rename($file, $file . '.original');
                $thumb = new ResizeTools();
                $thumb->maxsize = $this->requestCeiling();
                $thumb->allowUpscale = $this->allowUpscale;
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
        // Through `createMd5()`, so an unreadable file leaves the hash empty here too rather than
        // taking the md5 of nothing and colliding with every other unreadable file.
        $this->filesize = (int) @filesize((string) $this->filename);
        $this->createMd5();

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



        /*
         * `0` means «skip this rendition», the same as it does for `max`.
         *
         * It used to mean the reverse, and that was the trap: the guard was
         * `$startWidth > $this->medium || $startHeight > $this->mediumHeight`, so `medium = 0` made
         * `$startWidth > 0` true for every picture that has ever existed, and the setting that read
         * as «no medium rendition» produced one for every upload — at `ResizeTools`'
         * `defaultwidth`, a width the caller never named. Only `max` read the way it looked, and the
         * inconsistency between the three was most of the trap.
         *
         * Each axis is now consulted only when it is set, which is what the `max` block already did.
         */
        $needsMedium = ($this->medium != 0 && $startWidth > $this->medium)
            || ($this->mediumHeight != 0 && $startHeight > $this->mediumHeight);

        if ($this->derivesRenditions() && $needsMedium) {
            $thumb = new ResizeTools();
            $thumb->createdTxt = date('d/m/Y H:i:s');
            $thumb->maxsize = $this->requestCeiling();
            $thumb->allowUpscale = $this->allowUpscale;
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

        $needsThumb = ($this->thumb != 0 && $startWidth > $this->thumb)
            || ($this->thumbHeight != 0 && $startHeight > $this->thumbHeight);

        if ($this->derivesRenditions() && $needsThumb) {
            $thumb = new ResizeTools();
            $thumb->createdTxt = date('d/m/Y H:i:s');
            $thumb->maxsize = $this->requestCeiling();
            $thumb->allowUpscale = $this->allowUpscale;
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
            . self::randomToken()
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

        // Everything checked so far describes what the *client* claimed: the
        // extension it chose and the Content-Type it sent. Ask the file itself
        // before it is written anywhere. This only refuses content that is
        // nothing like what the extension promises — a PHP script named .jpg —
        // and stays quiet about the odd-but-valid files real users upload.
        $detected = self::detectMimeType($file['tmp_name']);
        if ($detected !== null && !self::contentMatchesExtension($detected, $ext)) {
            $this->error = '#5 File content does not match its extension: '
                . $detected . ' as .' . $ext;
            return $this;
        }

        // Kept, rather than used and thrown away. Recording what the file turned out to be is what
        // makes the decision above auditable, and saves the next reader guessing from the extension.
        $this->mimetype = (string) $detected;

        $uploadfile = $path . $filename;
        if (file_exists($uploadfile)) {
            $uploadfile = $path . self::randomToken() . $filename;
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
        // Through `createMd5()`, so an unreadable file leaves the hash empty here too rather than
        // taking the md5 of nothing and colliding with every other unreadable file.
        $this->filesize = (int) @filesize((string) $this->filename);
        $this->createMd5();

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
                'value' => self::encodeThumbnails($this->thumbnails),
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
            ),
            array(
                'fieldName' => 'extrainfo',
                'value' => $this->extrainfo,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'mimetype',
                'value' => $this->mimetype,
                'type' => 'string'
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
        if ($this->userid == 0 && isset($_SESSION['uid'])) {
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
            $database->prefix . "mediause", $itemdata,
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
            $this->thumbnails = self::usableThumbnails($result->fields['thumbnails']);
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

        /*
         * A vector is already every size, so the honest answer is the file itself.
         *
         * What happened before: GD cannot read an SVG on an ordinary build, so `loadImageByType()`
         * failed, `makeErrorImg()` drew the source path onto a 500×100 white JPEG, and *that* was
         * saved as the rendition — at a URL ending `.jpg`, recorded in `thumbnails` as the size that
         * had been requested. A station logo became a picture of a file path, and every check
         * downstream accepted it because it was a valid image.
         *
         * Returned rather than cached: there is nothing derived to cache, and adding an entry per
         * requested size would fill the column with rows all describing the same file.
         */
        if ($this->isVector()) {
            $vector = new Thumbnail();
            $vector->createdTxt = date('d/m/Y H:i:s');
            $vector->filename = $this->filename;
            $vector->url = $this->url;
            $vector->x = $this->x;
            $vector->y = $this->y;
            $vector->views = 0;
            $vector->filesize = $this->filesize;
            $vector->reason = 'original';

            return $vector;
        }

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
            $thumb->maxsize = $this->requestCeiling();
            $thumb->exportpath = $this->createPath($this->module);
            $thumb->crop = $crop;
            $thumb->debug = $debug;
            $thumb->allowUpscale = $this->allowUpscale;

            if ($thumb->resize($this->filename, $width, $height) === false) {
                /*
                 * The source could not be read, so there is no rendition — and the original is a
                 * better answer than a picture of an error message, which is what used to be stored
                 * here and handed back.
                 *
                 * Nothing is written to `thumbnails`: a row pointing at a file that was never
                 * created is worse than no row, because the next `get()` at the same size finds it,
                 * fails the `file_exists()` check, deletes it and saves — a write per request for a
                 * rendition that can never exist.
                 */
                $this->error = is_string($thumb->error)
                    ? $thumb->error
                    : 'Cannot create a rendition of ' . $this->filename;
                \Pramnos\Logs\Logger::log($this->error);

                $fallback = new Thumbnail();
                $fallback->createdTxt = date('d/m/Y H:i:s');
                $fallback->filename = $this->filename;
                $fallback->url = $this->url;
                $fallback->x = $this->x;
                $fallback->y = $this->y;
                $fallback->views = 0;
                $fallback->filesize = $this->filesize;
                $fallback->reason = 'original';

                return $fallback;
            }

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

    /**
     * Stop the uploads directory from ever executing what it stores.
     *
     * Uploads land inside the document root, and the extension allow-list is
     * what keeps a script out of there. That list is one line of defence, and
     * one line is thin for a directory whose contents come from outside: a
     * server misconfigured to run PHP by content rather than extension, or an
     * allow-list widened in a hurry, is all it takes.
     *
     * Written once, never overwritten — an application that has tuned this file
     * keeps its version. No-op on nginx, which does not read it; there the same
     * rule belongs in the site config.
     *
     * @param  string $path
     * @return void
     */
    private static function protectUploadDirectory($path): void
    {
        $file = $path . DS . '.htaccess';
        if (file_exists($file) || !is_writable($path)) {
            return;
        }

        @file_put_contents(
            $file,
            "# Uploaded files are data, never code.\n"
            . "php_flag engine off\n"
            . "<IfModule mod_rewrite.c>\n"
            . "    RewriteEngine On\n"
            . "    RewriteRule \\.(php[0-9]?|phtml|phar)$ - [F,L,NC]\n"
            . "</IfModule>\n"
        );
    }

    /**
     * What the file actually is, according to its own bytes.
     *
     * Returns null when the extension is unavailable or the file cannot be
     * read — an inability to look is not evidence of wrongdoing, and refusing
     * every upload on a server without fileinfo would be a worse bug than the
     * one this guards against.
     *
     * @param  string $path
     * @return string|null
     */
    private static function detectMimeType($path): ?string
    {
        if (!function_exists('finfo_open') || !is_readable($path)) {
            return null;
        }

        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        $detected = @finfo_file($finfo, $path);
        // No finfo_close(): deprecated in PHP 8.5, where the handle is freed
        // when it goes out of scope.

        return is_string($detected) && $detected !== '' ? $detected : null;
    }

    /**
     * The mime of content held in memory, rather than of a file on disk.
     *
     * `addRemoteImage()` needs to know what it fetched *before* deciding where to put it, and
     * `finfo_file()` needs a path. Writing the bytes somewhere to ask about them and then moving them
     * is the version of this that leaves an unidentified file on disk for as long as the check takes.
     *
     * @param string $content
     * @return string|null The mime type, or null when `fileinfo` is unavailable or says nothing.
     */
    private static function detectMimeTypeOfString(string $content): ?string
    {
        if (!function_exists('finfo_open') || $content === '') {
            return null;
        }

        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        $detected = @finfo_buffer($finfo, $content);
        // No finfo_close(): deprecated in PHP 8.5, where the handle is freed when it goes out of
        // scope.

        return is_string($detected) && $detected !== '' ? $detected : null;
    }

    /**
     * Is this content plausibly what the extension says it is?
     *
     * Deliberately permissive within a family: browsers, phones and scanners
     * disagree about JPEG and Excel MIME types, and rejecting a valid photo is
     * a real cost paid by real users. What it refuses is content from a
     * different family altogether — which is what a disguised upload looks like.
     *
     * @param  string $detected MIME type read from the file
     * @param  string $ext      Extension the upload will be stored under
     * @return bool
     */
    private static function contentMatchesExtension($detected, $ext): bool
    {
        $families = [
            'jpg'  => ['image/'],
            'jpeg' => ['image/'],
            'png'  => ['image/'],
            'gif'  => ['image/'],
            'bmp'  => ['image/'],
            'ico'  => ['image/', 'application/octet-stream'],
            'pdf'  => ['application/pdf'],
            // Spreadsheets are the loose family on purpose: plenty of tools
            // export CSV or HTML and name it .xls, and refusing those would
            // reject files that have always been accepted here. The check is
            // for content from a different world — a script, an executable —
            // not for pedantry about Excel's many disguises.
            'xls'  => ['application/vnd.ms-excel', 'application/vnd.openxmlformats',
                       'application/zip', 'application/octet-stream', 'text/'],
            'xlsx' => ['application/vnd.ms-excel', 'application/vnd.openxmlformats',
                       'application/zip', 'application/octet-stream', 'text/'],
        ];

        // An extension nobody listed is not this method's business — the
        // extension allow-list above already decides what may be stored.
        if (!isset($families[$ext])) {
            return true;
        }

        foreach ($families[$ext] as $prefix) {
            if (str_starts_with($detected, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Unguessable component for an uploaded file's name.
     *
     * `rand(0, time())` is seeded from a value an attacker knows and drawn from
     * a non-cryptographic generator, so the URLs of files uploaded around a
     * known moment are searchable. For anything not meant to be public, the
     * name is the only thing standing between the file and whoever guesses it.
     *
     * @return string
     */
    private static function randomToken(): string
    {
        return bin2hex(random_bytes(8));
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
