<?php

namespace Pramnos\Document;
use \Pramnos\Document\DocumentTypes;
/**
 * Basic document functions and factory for all the subclasses
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class Document extends \Pramnos\Framework\Base
{

    /**
     * Document types this factory can actually build (see getInstance()'s
     * switch). Used to decide whether a `?format=` value names a real document
     * type or is an application-specific value that should be ignored for
     * type selection.
     */
    public const KNOWN_TYPES = ['html', 'amp', 'json', 'rss', 'print', 'pdf', 'raw', 'png'];

    public $content = '';
    public static $type = 'html';

    /**
     * One document object per type, for the life of the process.
     *
     * A property rather than a `static` local inside {@see getInstance()}, so that
     * {@see reset()} can clear it. In production the difference is invisible: a
     * request builds a document and the process ends. In a test run thousands of
     * "requests" share one process, and a document is *mutable* — code and tests
     * both write to `->type` and `->themeObject` — so one test's document answered
     * for every test after it.
     *
     * That is not hypothetical: three separate failures in one working session
     * appeared only in a full run, because an earlier test had left the shared HTML
     * document reporting itself as `raw` or `json`, and the toolbar then declined to
     * inject into a page that was HTML all along.
     *
     * @var array<string, object>
     */
    private static array $instances = [];
    private static $buffer = '';
    public $usetheme = true;
    public $header;
    public $head;
    public $foot;
    public $title;
    public $description;
    public $url;
    public $lang;
    public $encoding;
    public $generator;
    public $mdate;
    public $mime;
    public $css = array();
    public $scripts = array();
    /**
     * Meta Tags by property
     * @public string
     */
    public $meta = array();
    /**
     * Meta Tags by name
     * @public string
     */
    public $metanames = array();
    public $bodyclasses = array();

    public $headContent = "";
    public $og_title = "";
    public $og_type = "website";
    public $og_url = "";
    public $og_image = "";
    public $og_site_name = "";
    public $og_description = "";
    /**
     * Theme object
     * @var \Pramnos\Theme\Theme
     */
    public $themeObject;
    public $breadcrumb = NULL;
    /**
     * Registered scripts
     * @var array
     */
    protected $_js = array();

    /**
     * Registered stylesheets
     * @var array
     */
    protected $_css = array();

    /**
     * Queued assets for enqueuing
     * @var array
     */
    protected $_queue = array();

    /**
     * Temporary buffer for enqueued head assets
     * @var string
     */
    protected $_queueContent = '';
    /**
     * Enqueued Style content (CSS links)
     * @var string
     */
    protected $_cssContent = '';

    /**
     * Enqueued Script content (JS tags for head)
     * @var string
     */
    protected $_jsContent = '';

    /**
     * Flag to prevent double processing of header queue.
     * 
     * @var boolean
     */
    protected $_headerProcessed = false;

    /**
     * Object constructor. It registers all default scripts and stylesheets.
     */
    /**
     * Whether the three CDN-hosted default scripts come from the CDN.
     *
     * `jquery`, `bootstrap-datepicker` and `jquery-inputmask` are registered against
     * `cdnjs.cloudflare.com`; everything else in this constructor is local. That was a
     * deliberate change in April 2020 (commit `0541b22f`, *"load scripts from cdn"*) and it
     * **was never documented as breaking**, which is the actual defect a consumer reported:
     * an application that upgraded across it silently began loading three third-party scripts
     * from a third-party host.
     *
     * That is not a style question. For a site in the EU it is a **GDPR** one — a visitor's IP
     * reaches Cloudflare before any consent is collected — and it is a **CSP** one, because a
     * policy written for a self-hosted application does not list that origin, so the scripts
     * are blocked rather than merely remote.
     *
     * The default stays the CDN, because changing it would break every application that
     * stopped vendoring the files on the strength of that commit. Set
     * `documentAssetSource = 'local'` in the application settings to serve them from `sURL`
     * instead, at the paths the legacy framework used:
     *
     * | Handle | `local` path |
     * | --- | --- |
     * | `jquery` | `media/js/jquery/jquery.min.js` |
     * | `bootstrap-datepicker` | `plugins/datepicker/bootstrap-datepicker.js` |
     * | `jquery-inputmask` | `plugins/input-mask/jquery.inputmask.js` |
     *
     * The files are the application's to provide, as they are for every other local
     * registration here.
     *
     * @return bool True to use the CDN — the default
     */
    /**
     * The handles `documentAssetSource` governs.
     *
     * The three that point at a CDN. Everything else in the constructor is local already, so
     * naming one of them in the setting does nothing — which is worth knowing rather than
     * discovering.
     *
     * @var string[]
     */
    private const CDN_HANDLES = ['jquery', 'bootstrap-datepicker', 'jquery-inputmask'];

    protected static function servesDefaultsFromCdn(): bool
    {
        try {
            $configured = \Pramnos\Application\Settings::getSetting('documentAssetSource');
        } catch (\Throwable) {
            // A document can be built before settings are readable — during install, in a
            // unit test, from the console. The default is what it has always been.
            return true;
        }

        // A list of handles serves exactly those locally and leaves the rest on the CDN. That
        // exists because all-or-nothing was the wrong shape, and a consumer proved it within a
        // day: they had `media/js/jquery/jquery.min.js` vendored and **no `plugins/` directory
        // at all**, so switching to 'local' would have 404'd two of the three scripts. Their
        // choice was between a GDPR problem they wanted to fix and two broken scripts, when what
        // they actually needed was to fix the one they could.
        if (is_array($configured)) {
            // Per-handle: some from the CDN, some not. "Is it the CDN" has no single answer,
            // and the callers that matter ask localHandles() instead.
            return $configured === [];
        }

        return strtolower(trim((string) $configured)) !== 'local';
    }

    /**
     * Handles the application has asked to serve locally, by name.
     *
     * Returned for the array form of `documentAssetSource`. `'local'` on its own is equivalent to
     * naming all three; the list exists for the common case of having vendored some and not
     * others.
     *
     * ```php
     * // I have jquery locally; the other two are still on the CDN
     * 'documentAssetSource' => ['jquery'],
     * ```
     *
     * @return array<string, true> Handle => true
     */
    protected static function localHandles(): array
    {
        try {
            $configured = \Pramnos\Application\Settings::getSetting('documentAssetSource');
        } catch (\Throwable) {
            return [];
        }

        // Settings round-trip a list as an array, an stdClass or a JSON string depending on how
        // it was stored, and a comma-separated string is what somebody types. All four mean the
        // same thing, so all four are accepted rather than three of them producing silence.
        if (is_object($configured)) {
            $configured = (array) $configured;
        }

        if (is_string($configured)) {
            $trimmed = trim($configured);

            if (strtolower($trimmed) === 'local') {
                return array_fill_keys(self::CDN_HANDLES, true);
            }

            if ($trimmed !== '' && $trimmed[0] === '[') {
                $decoded    = json_decode($trimmed, true);
                $configured = is_array($decoded) ? $decoded : [];
            } elseif (str_contains($trimmed, ',')) {
                $configured = array_map('trim', explode(',', $trimmed));
            } else {
                return [];
            }
        }

        if (!is_array($configured)) {
            return [];
        }

        $handles = [];
        foreach ($configured as $handle) {
            if (is_string($handle) && trim($handle) !== '') {
                $handles[trim($handle)] = true;
            }
        }

        return $handles;
    }

    public function __construct()
    {
        parent::__construct();

        $local = self::localHandles();

        //Register default scripts
        $this->registerScript(
            'jquery',
            isset($local['jquery'])
                ? sURL . 'media/js/jquery/jquery.min.js'
                : 'https://cdnjs.cloudflare.com/ajax/libs/jquery/2.2.4/jquery.min.js',
            array(), '', false
        ); //jQuery
        $this->registerScript(
            'jquery-ui', sURL . 'media/js/jquery/ui.min.js',
            array('jquery'), '', true
        ); //jQuery UI
        $this->registerScript(
            'datatables',
            sURL . 'media/js/jquery/jquery.dataTables.min.js',
            array('jquery-ui'), '', true
        ); //DataTables
        $this->registerScript(
            'datatables-bootstrap',
            sURL . 'media/js/jquery/DataTables/bootstrap.js',
            array('jquery-ui'), '', true
        ); //DataTables
        $this->registerScript(
            'tabletools',
            sURL . 'media/js/jquery/DataTables/TableTools.min.js',
            array('datatables'), '', true
        ); //DataTables - tabletools
        $this->registerScript(
            'datatables-responsive',
            sURL . 'media/js/jquery/DataTables/responsive.min.js',
            array('datatables'), '', true
        ); //DataTables - tabletools
        $this->registerScript(
            'zeroclipboard',
            sURL . 'media/js/jquery/DataTables/ZeroClipboard.js',
            array('datatables'), '', true
        ); //DataTables - zeroclipboard
        $this->registerScript(
            'jquery-tmpl', sURL . 'media/js/jquery/jquery.tmpl.min.js',
            array('jquery'), '', true
        );
        $this->registerScript(
            'iframe-transport',
            sURL . 'media/js/jquery/jquery.iframe-transport.js',
            array('jquery'), '', true
        );
        $this->registerScript(
            'jquery-fileupload',
            sURL . 'media/js/jquery/jquery.fileupload.js',
            array('jquery-ui', 'iframe-transport', 'jquery-tmpl'), '', true
        );
        $this->registerScript(
            'jquery-fileupload-fp',
            sURL . 'media/js/jquery/jquery.fileupload-fp.js',
            array('jquery-fileupload'), '', true
        );
        $this->registerScript(
            'jquery-fileupload-ui',
            sURL . 'media/js/jquery/jquery.fileupload-ui.js',
            array('jquery-fileupload'), '', true
        );
        $this->registerScript(
            'jquery-fileupload-jui',
            sURL . 'media/js/jquery/jquery.fileupload-jui.js',
            array(
                'jquery-fileupload',
                'jquery-fileupload-fp',
                'jquery-fileupload-ui'
            ), '', true
        );



        //Bootstrap Date
        $this->registerScript('bootstrap-datepicker',
            isset($local['bootstrap-datepicker'])
                ? sURL . 'plugins/datepicker/bootstrap-datepicker.js'
                : 'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/'
                  . '1.9.0/js/bootstrap-datepicker.min.js',
            array(), '', true
        );

        //jQuery InputMask
        $this->registerScript('jquery-inputmask',
            isset($local['jquery-inputmask'])
                ? sURL . 'plugins/input-mask/jquery.inputmask.js'
                : 'https://cdnjs.cloudflare.com/ajax/libs/jquery.inputmask/'
                  . '3.3.4/jquery.inputmask.bundle.min.js',
            array('jquery'), '', true
        );

        // The 3.3.4 bundle contains the extensions that used to be separate files, so these
        // two handles resolve to it. They were dropped in April 2020 when the bundle replaced
        // inputmask 4.0.9 — correctly, in that there was no longer a second file to fetch, and
        // wrongly in that a template calling enqueueScript('jquery-inputmask-date') had been
        // relying on the handle rather than on the file. Unregistered handles throw.
        $this->registerScript('jquery-inputmask-extensions', '',
            array('jquery-inputmask'), '', true
        );
        $this->registerScript('jquery-inputmask-date', '',
            array('jquery-inputmask-extensions'), '', true
        );


        // Restored 2026-08-14. These were deleted in March 2020 by a commit titled "Minor
        // variable name changes" — 14 insertions, 90 deletions — so their absence was never a
        // decision to drop deprecated libraries, just a casualty. A consumer's admin theme
        // calls enqueueScript('slimbox2') with no src on every page of its panel, and an
        // unregistered handle throws, so the port it was blocking could not begin.
        //
        // The URLs are the ones the legacy framework serves, verbatim. The framework does not
        // ship these files any more than it ships jquery-ui.min.js — a registration is a
        // handle-to-URL mapping, and the application provides the file.
        $this->registerScript(
            'slimbox2', sURL . 'media/js/jquery/slimbox2.js',
            array('jquery'), '', true
        );
        $this->registerScript(
            'thickbox', sURL . 'media/js/jquery/thickbox.js',
            array('jquery')
        );
        $this->registerScript(
            'spectrum', sURL . 'media/js/jquery/spectrum.js',
            array('jquery')
        );

        //SPRY — Adobe's, unmaintained since 2012, and still enqueued by templates that
        //predate that. Registered so those templates load rather than throw; nothing here
        //recommends them.
        $this->registerScript(
            'SpryMenuBar', sURL . 'media/js/SpryAssets/SpryMenuBar.js',
            array('jquery'), '', true
        );
        $this->registerScript(
            'SpryValidationTextArea',
            sURL . 'media/js/SpryAssets/SpryValidationTextArea.min.js',
            array('jquery'), '', true
        );
        $this->registerScript(
            'SpryValidationTextField',
            sURL . 'media/js/SpryAssets/SpryValidationTextField.min.js',
            array('jquery'), '', true
        );
        $this->registerScript(
            'SpryValidationPassword',
            sURL . 'media/js/SpryAssets/SpryValidationPassword.min.js',
            array('jquery'), '', true
        );
        $this->registerScript(
            'SpryValidationConfirm',
            sURL . 'media/js/SpryAssets/SpryValidationConfirm.min.js',
            array('jquery'), '', true
        );
        $this->registerScript(
            'SpryValidationCheckbox',
            sURL . 'media/js/SpryAssets/SpryValidationCheckbox.min.js',
            array('jquery'), '', true
        );

        //Register default stylesheets

        // Restored with the scripts above, for the same reason.
        $this->registerStyle(
            'mediamanager', sURL . 'media/css/pramnoscms/media.css'
        );
        $this->registerStyle(
            'slimbox2', sURL . 'media/css/jquery/slimbox2.css'
        );
        $this->registerStyle(
            'thickbox', sURL . 'media/css/jquery/thickbox.css'
        );
        $this->registerStyle(
            'spectrum', sURL . 'media/css/jquery/spectrum.css'
        );
        $this->registerStyle(
            'SpryValidationTextarea',
            sURL . 'media/css/SpryAssets/SpryValidationTextarea.css'
        );
        $this->registerStyle(
            'SpryValidationTextField',
            sURL . 'media/css/SpryAssets/SpryValidationTextField.css'
        );
        $this->registerStyle(
            'SpryValidationPassword',
            sURL . 'media/css/SpryAssets/SpryValidationPassword.css'
        );
        $this->registerStyle(
            'SpryValidationConfirm',
            sURL . 'media/css/SpryAssets/SpryValidationConfirm.css'
        );
        $this->registerStyle(
            'SpryValidationCheckbox',
            sURL . 'media/css/SpryAssets/SpryValidationCheckbox.css'
        );
        $this->registerStyle(
            'SpryMenuBarHorizontal',
            sURL . 'media/css/SpryAssets/SpryMenuBarHorizontal.css'
        );
        $this->registerStyle(
            'jquery-ui', sURL . 'media/css/jquery/jquery-ui.css'
        );

        $this->registerStyle(
            'jquery-fileupload-ui',
            sURL . 'media/css/jquery/jquery.fileupload-ui.css',
            array('jquery-ui')
        );
        $this->registerStyle(
            'datatables', sURL . 'media/css/jquery/datatables.min.css'
        );
        $this->registerStyle(
            'datatables-ui', sURL . 'media/css/jquery/table_jui.css'
        );
        $this->registerStyle(
            'datatables-bootstrap',
            sURL . 'media/css/jquery/dataTables.bootstrap.css',
            array('datatables')
        );
        $this->registerStyle(
            'datatables-responsive',
            sURL . 'media/css/jquery/dataTables.responsive.css',
            array('datatables')
        );



        $this->registerStyle(
            'tabletools', sURL . 'media/css/jquery/TableTools.css',
            array('datatables')
        );
        $this->registerStyle(
            'tabletools-ui',
            sURL . 'media/css/jquery/TableTools_JUI.css',
            array('datatables', 'jquery-ui')
        );


        $this->breadcrumb = new \Pramnos\Html\Breadcrumb();
    }

    /**
     * Adds an item to the breadcrumb
     * @param string $label  Text of the breadcrumb
     * @param string $url    URL of breadcrumb
     * @param string $title  Meta Title
     */
    public function addBreadcrumbItem($label, $url = '', $title = '')
    {
        $this->breadcrumb->addItem($label, $url, $title);
    }

    /**
     * Load a theme and return a theme object
     * @param string $theme Theme to load
     * @param string $path Path to load theme from
     * @param \Pramnos\Application\Application $application Application instance
     * @return  \Pramnos\Theme\Theme
     */
    public function loadtheme($theme = 'default', $path = '',
        $application = null)
    {
        $themeobject = \Pramnos\Theme\Theme::getTheme(
            $theme, $path, false, $application
        );
        $this->themeObject = $themeobject;
        return $themeobject;
    }



    /**
     * Forget every document built so far, and the default type.
     *
     * For a test run, and for a worker that serves more than one request in a single
     * PHP lifetime — the second is why this is not test-only code. A document carries
     * a theme, a type and its accumulated output; handing the next request the
     * previous one's is a bug waiting for someone to write a worker.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$instances = [];
        self::$type      = 'html';
    }

    /**
     * Factory function for document
     * @staticpublic array $instances
     * @param string $type Type of the document
     * @param boolean $setDefault If you want the document type as default
     * @return object The document object
     */
    public static function &getInstance($type = '', $setDefault = true)
    {
        $instances = &self::$instances;

        $type = (string)($type ?? '');
        if ($type === '') {
            $request = \Pramnos\Framework\Factory::getRequest();
            $type = (string)($request->get('format', self::$type, 'GET') ?? self::$type);
            // The `format` query parameter doubles as a document-type selector,
            // but callers also use it for their own purposes (e.g. the
            // PramnosDataTable adapter sends format=datatables). When it names a
            // type we don't produce, fall back to the current default type rather
            // than silently building an HTML page — otherwise a controller that
            // already prepared a raw/json Response (which set the default to
            // 'raw' via getInstance('raw')) would have its output replaced by an
            // empty themed page at render() time.
            if (!in_array($type, self::KNOWN_TYPES, true)) {
                $type = self::$type;
            }
        } elseif ($setDefault === true) {
            self::$type = $type;
        }
        if (!isset($instances[$type]) || !is_object($instances[$type])) {
            switch ($type) {
                default:
                    $instances[$type] = new DocumentTypes\Html();
                    $instances[$type]->type = 'html';
                    break;
                case 'html':
                    $instances[$type] = new DocumentTypes\Html();
                    $instances[$type]->type = 'html';
                    break;
                case 'amp':
                    $instances[$type] = new DocumentTypes\Amp();
                    $instances[$type]->type = 'amp';
                    break;
                case 'json':
                    $instances[$type] = new DocumentTypes\Json();
                    $instances[$type]->type = 'json';
                    break;
                case 'rss':
                    $instances[$type] = new DocumentTypes\Rss();
                    $instances[$type]->type = 'rss';
                    break;
                case 'print':
                    $instances[$type] = new DocumentTypes\PrintDocument();
                    $instances[$type]->type = 'print';
                    break;
                case 'pdf':
                    // The old TCPDF-backed type. TCPDF is not a dependency of
                    // this framework, so that type raised a fatal error on its
                    // first line — every caller of it was already broken. It
                    // now yields a printable page, which the browser's own
                    // dialog turns into a PDF, so old links produce a document
                    // instead of a stack trace.
                    $instances[$type] = new DocumentTypes\PrintDocument();
                    $instances[$type]->type = 'print';
                    break;
                case 'raw':
                    $instances[$type] = new DocumentTypes\Raw();
                    $instances[$type]->type = 'raw';
                    break;
                case 'png':
                    $instances[$type] = new DocumentTypes\Png();
                    $instances[$type]->type = 'png';
                    break;
            }
        }
        return $instances[$type];
    }

    public function addContent($content = '')
    {
        self::_addContent($content);
    }

    public function setContent($content = '')
    {
        self::_setContent($content);
    }

    public function getContent()
    {
        return self::_getContent();
    }

    /**
     * @todo    Use bodyclasses
     * @param   string $class
     */
    public function addBodyClass($class)
    {
        $this->bodyclasses[] = $class;
    }

    /**
     * Add contend inside the head section of the document
     * @param string $content
     * @return Document
     */
    public function addHeadContent($content)
    {
        $this->header .= "\n" . $content;
        return $this;
    }

    /**
     * Add content (properties) inside the head tag
     * @param string $content
     * @return Document
     */
    public function addHeadTagContent($content)
    {
        $this->headContent .= $content;
        return $this;
    }

    /**
     * Escape a value that a document type is about to put in the `<head>`.
     *
     * Every renderer built the head by concatenation — `content="' . $value . '"` —
     * so a value containing a double quote ended the attribute and everything after
     * it was markup. On a server-rendered page the values are a station name, a
     * user-supplied description, a title from the database: exactly the strings that
     * should never be trusted, in the one place nobody looks.
     *
     * `ENT_QUOTES` because these land in attributes, `ENT_SUBSTITUTE` so invalid
     * UTF-8 becomes U+FFFD instead of an empty string (silently losing a title is
     * worse than showing a replacement character), and **`double_encode: false`**
     * because applications that already escape their own metadata are the ones most
     * likely to be doing the right thing — re-encoding their `&amp;` into `&amp;amp;`
     * would punish them for it.
     *
     * This deliberately does **not** cover `headContent`, `extraHtmlTag`,
     * `extraBodyTag` or `header`: those exist to carry markup, and escaping them
     * would break every application that uses them as documented.
     *
     * @param  mixed  $value Whatever the document type holds for that slot
     * @return string        Safe to interpolate into an attribute or element text
     */
    protected function escapeHeadValue($value)
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }

        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
            false
        );
    }

    /**
     * Add a meta tag to the head section
     * @param  string            $property   The meta property
     * @param  string            $value      The value of the tag
     * @param  boolean           $isName     Use meta name instead of property
     * @return Document
     */
    public function addMetaTag($property, $value, $isName=false)
    {
        if ($isName == true) {
            $this->metanames[$property]=$value;
        } else {
            $this->meta[$property]=$value;
        }

        return $this;
    }

    /**
     * Remove a meta tag from the head section
     * @param  string            $tag The tag to remove
     * @return Document
     */
    public function removeMetaTag($tag)
    {
        if (isset($this->meta[$tag])) {
            unset($this->meta[$tag]);
        }
        return $this;
    }


    /**
     * A safe way of registering javascripts for use with enqueueScript().
     * @param  string            $handle    Name of the script.
     *                                      Should be unique as it is used as
     *                                      a handle for later use with
     *                                      enqueueScript().
     * @param  string            $src       URL to the script.
     * @param  array             $deps      Array of handles of any script
     *                                      that this script depends on;
     *                                      scripts that must be loaded before
     *                                      this script. false if there are no
     *                                      dependencies.
     * @param  string            $version   String specifying the script
     *                                      version number, if it has one.
     * @param  boolean           $footer Normally scripts are placed in the
     *                                      head section. If this parameter is
     *                                      true the script is placed at the
     *                                      bottom of the body.
     * @return Document
     */
    public function registerScript($handle, $src, $deps = array(),
        $version = '', $footer = false)
    {
        if (!is_array($deps)){
            $deps = array($deps);
        }
        $this->_js[$handle] = array(
            'handle' => $handle,
            'src' => $src,
            'deps' => $deps,
            'ver' => $version,
            'footer' => $footer,
            'loaded' => false
        );
        return $this;
    }

    /**
     * Check whether a script handle has been registered (via registerScript()).
     * Safe to call from views before conditionally enqueuing a library.
     *
     * @param string $handle Script handle to test
     * @return bool
     */
    public function isScriptRegistered(string $handle): bool
    {
        return isset($this->_js[$handle]);
    }

    /**
     * Check whether a style handle has been registered (via registerStyle()).
     * Safe to call from views before conditionally enqueuing a library.
     *
     * @param string $handle Style handle to test
     * @return bool
     */
    public function isStyleRegistered(string $handle): bool
    {
        return isset($this->_css[$handle]);
    }

    /**
     * A safe way to register a CSS style file for later use with enqueueStyle().
     * @param string $handle Name of the stylesheet.
     * @param string $src URL to the stylesheet.
     * @param array $deps Array of handles of any stylesheet that this stylesheet depends on; stylesheets that must be loaded before this stylesheet. false if there are no dependencies.
     * @param string $version String specifying the stylesheet version number, if it has one. This parameter is used to ensure that the correct version is sent to the client regardless of caching, and so should be included if a version number is available and makes sense for the stylesheet.
     * @param string $media String specifying the media for which this stylesheet has been defined. Examples: 'all', 'screen', 'handheld', 'print'.
     * @return Document
     */
    public function registerStyle($handle, $src, $deps = array(),
        $version = '', $media = 'all')
    {
        if (!is_array($deps)){
            $deps = array($deps);
        }
        $this->_css[$handle] = array(
            'handle' => $handle,
            'src' => $src,
            'deps' => $deps,
            'version' => $version,
            'media' => $media,
            'loaded' => false
        );
        return $this;
    }

    /**
     * Process JS and CSS queues to prepare them for rendering.
     * 
     * This method resolves dependencies and populates the CSS and JS buffers.
     * It ensures the queue is only processed once.
     * 
     * @return $this
     */
    protected function processHeader()
    {
        if ($this->_headerProcessed) {
            return $this;
        }

        if (isset($this->_queue['css'])) {
            foreach ($this->_queue['css'] as $key => $css) {
                $this->_enqueueStyle($css['handle'], $css['src'], $css['deps'], $css['version'], $css['media']);
                unset($this->_queue['css'][$key]);
            }
            unset($this->_queue['css']);
        }
        if (isset($this->_queue['js'])) {
            foreach ($this->_queue['js'] as $key => $js) {
                $this->_enqueueScript($js['handle'], $js['src'], $js['deps'], $js['version'], $js['footer']);
                unset($this->_queue['js'][$key]);
            }
            unset($this->_queue['js']);
        }

        $this->header = $this->_queueContent . $this->header;
        $this->_headerProcessed = true;
        return $this;
    }

    /**
     * Render and echo the enqueued CSS links.
     * 
     * @return void
     */
    public function renderCss()
    {
        $this->processHeader();
        echo $this->_cssContent;
    }

    /**
     * Render and echo the enqueued JavaScript tags.
     * 
     * Normally called in the footer to output all enqueued scripts,
     * including those specifically marked for the head (if not already rendered)
     * and those marked for the footer.
     * 
     * @return void
     */
    public function renderJs()
    {
        $this->processHeader();
        echo $this->_jsContent;
        echo $this->foot;
    }

    /**
     * The safe and recommended method of adding JavaScript to a generated document is by using  (). This function includes the script if it hasn't already been included, and safely handles dependencies.
     * @param string $handle Name of the script. Should be unique as it is used as a handle for later use with enqueueScript().
     * @param string $src URL to the script.
     * @param array $deps Array of handles of any script that this script depends on; scripts that must be loaded before this script. false if there are no dependencies.
     * @param string $version String specifying the script version number, if it has one.
     * @param boolean $footer Normally scripts are placed in the <head> section. If this parameter is true the script is placed at the bottom of the <body>.
     * @return Document
     */
    private function _enqueueScript($handle, $src = '',
        $deps = array(), $version = '', $footer = false)
    {
        if (isset($this->_js[$handle])) {
            if ($this->_js[$handle]['loaded'] == false) {
                foreach ($this->_js[$handle]['deps'] as $dep) {
                    $this->_enqueueScript($dep);
                }

                $script = $this->_js[$handle]['src'];
                if ($version != '') {
                    $script .= '?v=' . $version;
                }

                if ($this->_js[$handle]['footer'] == true) {
                    $this->foot .= "\n        "
                        . "<script type=\"text/javascript\" src=\""
                        . $script
                        . "\"></script>";
                } else {
                    $tag = "\n        "
                        . "<script type=\"text/javascript\" src=\""
                        . $script
                        . "\"></script>";
                    $this->_queueContent .= $tag;
                    $this->_jsContent .= $tag;
                }
                $this->_js[$handle]['loaded'] = true;
            }
            else {
                return $this;
            }
        } elseif ($src != '') {
            $this->registerScript($handle, $src, $deps, $version, $footer);
            return $this->_enqueueScript($handle);
        } else {
            throw new \Exception('Cannot find script: ' . $handle);
        }

        return $this;
    }

    /**
     * A safe way to add/enqueue a CSS style file to the generated document. If it was first registered with registerStyle() it can now be added using its handle.
     * @param string $handle Name of the stylesheet.
     * @param string $src URL to the stylesheet.
     * @param array $deps Array of handles of any stylesheet that this stylesheet depends on; stylesheets that must be loaded before this stylesheet. false if there are no dependencies.
     * @param string $version String specifying the stylesheet version number, if it has one. This parameter is used to ensure that the correct version is sent to the client regardless of caching, and so should be included if a version number is available and makes sense for the stylesheet.
     * @param string $media String specifying the media for which this stylesheet has been defined. Examples: 'all', 'screen', 'handheld', 'print'.
     * @return Document
     */
    private function _enqueueStyle($handle, $src = '', $deps = array(),
        $version = '', $media = 'all')
    {
        if (isset($this->_css[$handle])) {
            if ($this->_css[$handle]['loaded'] == false) {
                foreach ($this->_css[$handle]['deps'] as $dep) {
                    // Skip deps that have no CSS registration (e.g. JS-only libraries
                    // listed as a CSS dep due to shared requires in the asset catalog).
                    if (isset($this->_css[$dep])) {
                        $this->_enqueueStyle($dep);
                    }
                }
                if ($media != '') {
                    $tag = "\n        "
                        . "<link rel=\"stylesheet\" id=\""
                        . $handle . "\" href=\""
                        . $this->_css[$handle]['src']
                        . "\" type=\"text/css\" media=\""
                        . $this->_css[$handle]['media'] . "\" />";
                } else {
                    $tag = "\n        "
                        . "<link rel=\"stylesheet\" id=\""
                        . $handle . "\" href=\""
                        . $this->_css[$handle]['src']
                        . "\" type=\"text/css\"  />";
                }
                $this->_queueContent .= $tag;
                $this->_cssContent .= $tag;
                $this->_css[$handle]['loaded'] = true;
            } else {
                return $this;
            }
        } elseif ($src != '') {
            $this->registerStyle($handle, $src, $deps, $version, $media);
            return $this->_enqueueStyle($handle);
        } else {
            throw new \Exception('Cannot find stylesheet: ' . $handle);
        }
        return $this;
    }

    /**
     * The safe and recommended method of adding JavaScript to a generated document is by using enqueueScript(). This function includes the script if it hasn't already been included, and safely handles dependencies.
     * @param string $handle Name of the script. Should be unique as it is used as a handle for later use with enqueueScript().
     * @param string $src URL to the script.
     * @param array $deps Array of handles of any script that this script depends on; scripts that must be loaded before this script. false if there are no dependencies.
     * @param string $version String specifying the script version number, if it has one.
     * @param boolean $footer Normally scripts are placed in the <head> section. If this parameter is true the script is placed at the bottom of the <body>.
     * @return Document
     */
    public function enqueueScript($handle, $src = '', $deps = array(),
        $version = '', $footer = false)
    {
        $this->_queue['js'][$handle] = array(
            'handle' => $handle,
            'src' => $src,
            'deps' => $deps,
            'version' => $version,
            'footer' => $footer
        );
        return $this;
    }

    /**
     * A safe way to add/enqueue a CSS style file to the generated document. If it was first registered with registerStyle() it can now be added using its handle.
     * @param string $handle Name of the stylesheet.
     * @param string $src URL to the stylesheet.
     * @param array $deps Array of handles of any stylesheet that this stylesheet depends on; stylesheets that must be loaded before this stylesheet. false if there are no dependencies.
     * @param string $version String specifying the stylesheet version number, if it has one. This parameter is used to ensure that the correct version is sent to the client regardless of caching, and so should be included if a version number is available and makes sense for the stylesheet.
     * @param string $media String specifying the media for which this stylesheet has been defined. Examples: 'all', 'screen', 'handheld', 'print'.
     * @return Document
     */
    public function enqueueStyle($handle, $src = '', $deps = array(),
        $version = '', $media = 'all')
    {
        $this->_queue['css'][$handle] = array(
            'handle' => $handle,
            'src' => $src,
            'deps' => $deps,
            'version' => $version,
            'media' => $media
        );
        return $this;
    }

    /**
     * Add a css link to the header
     * @deprecated since version 1.1
     * @param string $cssfile
     * @param string $media Media
     */
    public function addCss($cssfile, $media = 'all')
    {
        static $count = 0;
        $found = false;
        foreach ($this->_css as $handle => $css) {
            if ($css['src'] == $cssfile) {
                if ($css['loaded'] == false) {
                    $found = true;
                    $this->enqueueStyle($handle);
                }
            }
        }
        if ($found == false) {
            $this->enqueueStyle(
                'auto-' . $count . '-style', $cssfile, array(), '', $media
            );
            $count++;
        }
        return $this;
    }

    /**
     * Add a javascript file to the header
     * @deprecated since version 1.1
     * @param string $jsfile
     * @param int $priority
     */
    public function addJs($jsfile)
    {
        static $count = 0;
        $found = false;
        foreach ($this->_js as $handle => $js) {
            if ($js['src'] == $jsfile && $js['loaded'] == true) {
                $found = true;
                $this->enqueueScript($handle);
            }
        }
        if ($found == false) {
            $this->enqueueScript(md5($jsfile), $jsfile);
            $count++;
        }
        return $this;
    }

    /**
     * Append a block of inline JavaScript to the document footer (after all enqueued scripts).
     *
     * Use this instead of raw <script> tags inside view templates when the code
     * depends on libraries loaded via enqueueScript() — those are output by renderJs()
     * which runs in footer.php, after the view body, so any inline tag inside the view
     * would execute before jQuery/DataTables/etc. are available.
     *
     * @param string $code Raw JavaScript (without <script> tags)
     * @return self
     */
    public function addInlineScript(string $code): self
    {
        $this->foot .= '<script>' . $code . '</script>' . "\n";
        return $this;
    }

    /**
     * Parse text against all active content addon parsers
     * @param   string  $text       Text to parse
     * @param   string  $texttype    Text type (example: forumpost)
     * @param   string  $doctype    Document type
     * @return  string  Parsed text
     */
    public function parse($text, $texttype = '', $doctype = '')
    {
        if ($doctype == '') {
            $doctype = $this->type;
        }
        $addons = \Pramnos\Addon\Addon::getaddons('content');
        foreach ($addons as $addon) {
            if (method_exists($addon, 'onParse')) {
                $text = $addon->onParse($text, $texttype, $doctype);
            }
        }
        return $text;
    }

    /**
     * Render and return theme content
     * @return string
     */
    public function render()
    {
        if ($this->themeObject !== NULL) {
            $this->header .= $this->themeObject->getheader();
            $this->head = $this->themeObject->gethead();
            $this->foot = $this->themeObject->getfoot();
        }

        $content = '';
        $content .= $this->parse($this->header);
        $content .= $this->parse($this->head);
        $content .= $this->content;
        $content .= $this->parse($this->foot);
        \Pramnos\Addon\Addon::doAction('send_headers');
        return $content;
    }

    public static function _addContent($content)
    {
        self::$buffer .=$content;
    }

    public static function _getContent()
    {

        return self::$buffer;
    }

    public static function _setContent($content)
    {
        self::$buffer = $content;
    }

    public static function setType($type)
    {
        self::$type = $type;
    }

    public function getType()
    {
        return $this->type;
    }

}
