<?php

namespace Pramnos\Document;
use \Pramnos\Document\DocumentTypes;
/**
 * Basic document functions and factory for all the subclasses
 * @package     PramnosFramework
 * @subpackage  Document
 * @copyright   2005 - 2015 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Document extends \Pramnos\Framework\Base
{

    public $content = '';
    public static $type = 'html';
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
    public $themeObject = NULL;
    public $breadcrumb = NULL;
    protected $_js = array();
    protected $_css = array();
    protected $_queue = array();
    protected $_queueContent = '';

    /**
     * Object constructor. It registers all default scripts and stylesheets.
     */
    public function __construct()
    {
        parent::__construct();
        //Register default scripts
        $this->registerScript(
            'jquery', sURL . 'media/js/jquery/jquery.min.js',
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


        //Bootstrap Date
        $this->registerScript('bootstrap-datepicker',
            sURL . 'plugins/datepicker/bootstrap-datepicker.js',
            array(), '', true
        );

        //jQuery InputMask
        $this->registerScript('jquery-inputmask',
            sURL . 'plugins/input-mask/jquery.inputmask.js',
            array('jquery'), '', true
        );

        //jQuery InputMask Extensions
        $this->registerScript('jquery-inputmask-extensions',
            sURL . 'plugins/input-mask/jquery.inputmask.extensions.js',
            array('jquery-inputmask'), '', true
        );

        //jQuery InputMask Date Extensions
        $this->registerScript('jquery-inputmask-date',
            sURL . 'plugins/input-mask/jquery.inputmask.date.extensions.js',
            array('jquery-inputmask-extensions'), '', true
        );


        //Register default stylesheets

        $this->registerStyle(
            'jquery-ui', sURL . 'media/css/jquery/jquery-ui.css'
        );
        $this->registerStyle(
            'mediamanager', sURL . 'media/css/pramnoscms/media.css'
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
            'slimbox2', sURL . 'media/css/jquery/slimbox2.css'
        );
        $this->registerStyle(
            'thickbox', sURL . 'media/css/jquery/thickbox.css'
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
        $this->registerStyle(
            'spectrum', sURL . 'media/css/jquery/spectrum.css'
        );


        //SPRY
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
        $this->registerStyle(
            'SpryValidationTextarea',
            sURL . 'media/css/SpryAssets/SpryValidationTextarea.css'
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
            'SpryValidationTextField',
            sURL . 'media/css/SpryAssets/SpryValidationTextField.css'
        );
        $this->registerStyle(
            'SpryMenuBarHorizontal',
            sURL . 'media/css/SpryAssets/SpryMenuBarHorizontal.css'
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
     * @return  \Pramnos\Theme\Theme
     */
    public function loadtheme($theme = 'default', $path = '')
    {
        $themeobject = \Pramnos\Theme\Theme::getTheme($theme, $path, false);
        $this->themeObject = $themeobject;
        return $themeobject;
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
        static $instances;
        if (!isset($instances)) {
            $instances = array();
        }

        if ($type == '') {
            $request = \Pramnos\Framework\Factory::getRequest();
            $type = $request->get('format', self::$type, 'GET');
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
                case 'pdf':
                    $instances[$type] = new DocumentTypes\Pdf();
                    $instances[$type]->type = 'pdf';
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
     * @param  boolean           $in_footer Normally scripts are placed in the
     *                                      head section. If this parameter is
     *                                      true the script is placed at the
     *                                      bottom of the body.
     * @return Document
     */
    public function registerScript($handle, $src, $deps = array(),
        $version = '', $in_footer = false)
    {
        if (!is_array($deps)){
            $deps = array($deps);
        }
        $this->_js[$handle] = array(
            'handle' => $handle,
            'src' => $src,
            'deps' => $deps,
            'ver' => $version,
            'in_footer' => $in_footer,
            'loaded' => false
        );
        return $this;
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
     * Proccess JS and CSS queues
     */
    protected function proccessHeader()
    {

        if (isset($this->_queue['css'])) {
            foreach ($this->_queue['css'] as $key => $css) {
                $this->_enqueueStyle($css['handle'], $css['src'], $css['deps'], $css['version'], $css['media']);
                unset($this->_queue['css'][$key]);
            }
            unset($this->_queue['css']);
        }
        if (isset($this->_queue['js'])) {
            foreach ($this->_queue['js'] as $key => $js) {
                $this->_enqueueScript($js['handle'], $js['src'], $js['deps'], $js['version'], $js['in_footer']);
                unset($this->_queue['js'][$key]);
            }
            unset($this->_queue['js']);
        }

        $this->header = $this->_queueContent . $this->header;
        return $this;
    }

    /**
     * The safe and recommended method of adding JavaScript to a generated document is by using  (). This function includes the script if it hasn't already been included, and safely handles dependencies.
     * @param string $handle Name of the script. Should be unique as it is used as a handle for later use with enqueueScript().
     * @param string $src URL to the script.
     * @param array $deps Array of handles of any script that this script depends on; scripts that must be loaded before this script. false if there are no dependencies.
     * @param string $version String specifying the script version number, if it has one.
     * @param boolean $in_footer Normally scripts are placed in the <head> section. If this parameter is true the script is placed at the bottom of the <body>.
     * @return Document
     */
    private function _enqueueScript($handle, $src = '',
        $deps = array(), $version = '', $in_footer = false)
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

                if ($this->_js[$handle]['in_footer'] == true) {
                    $this->foot .= "\n        "
                        . "<script type=\"text/javascript\" src=\""
                        . $script
                        . "\"></script>";
                } else {
                    $this->_queueContent .= "\n        "
                        . "<script type=\"text/javascript\" src=\""
                        . $script
                        . "\"></script>";
                }
                $this->_js[$handle]['loaded'] = true;
            }
            else {
                return $this;
            }
        } elseif ($src != '') {
            $this->registerScript($handle, $src, $deps, $version, $in_footer);
            return $this->_enqueueScript($handle);
        } else {
            throw new Exception('Cannot find script: ' . $handle);
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
                    $this->_enqueueStyle($dep);
                }
                if ($media != '') {
                    $this->_queueContent .= "\n        "
                        . "<link rel=\"stylesheet\" id=\""
                        . $handle . "\" href=\""
                        . $this->_css[$handle]['src']
                        . "\" type=\"text/css\" media=\""
                        . $this->_css[$handle]['media'] . "\" />";
                }
                else {
                    $this->_queueContent .= "\n        "
                        . "<link rel=\"stylesheet\" id=\""
                        . $handle . "\" href=\""
                        . $this->_css[$handle]['src']
                        . "\" type=\"text/css\"  />";
                }
                $this->_css[$handle]['loaded'] = true;
            }
            else {
                return $this;
            }
        }
        elseif ($src != '') {
            $this->registerStyle($handle, $src, $deps, $version, $media);
            return $this->_enqueueStyle($handle);
        }
        else {
            throw new Exception('Cannot find stylesheet: ' . $handle);
        }
        return $this;
    }

    /**
     * The safe and recommended method of adding JavaScript to a generated document is by using enqueueScript(). This function includes the script if it hasn't already been included, and safely handles dependencies.
     * @param string $handle Name of the script. Should be unique as it is used as a handle for later use with enqueueScript().
     * @param string $src URL to the script.
     * @param array $deps Array of handles of any script that this script depends on; scripts that must be loaded before this script. false if there are no dependencies.
     * @param string $version String specifying the script version number, if it has one.
     * @param boolean $in_footer Normally scripts are placed in the <head> section. If this parameter is true the script is placed at the bottom of the <body>.
     * @return Document
     */
    public function enqueueScript($handle, $src = '', $deps = array(),
        $version = '', $in_footer = false)
    {
        $this->_queue['js'][$handle] = array(
            'handle' => $handle,
            'src' => $src,
            'deps' => $deps,
            'version' => $version,
            'in_footer' => $in_footer
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
