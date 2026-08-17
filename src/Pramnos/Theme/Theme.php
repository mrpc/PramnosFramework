<?php
namespace Pramnos\Theme;


/**
 * Theme Object class
 * @copyright  (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class Theme extends \Pramnos\Framework\Base
{

    /**
     * Theme display name
     * @var string
     */
    public $title = '';

    /**
     * A thumbnail url
     * @var string
     */
    public $thumbnail = '';
    public $author = '';
    public $copyright = '';
    public $url = 'http://www.pramhost.com';
    public $info = '';

    /**
     * The theme directory
     * @var string
     */
    public $theme = "default";
    public $path = '';
    public $fullpath = '';
    /**
     * Application Instance
     * @var \Pramnos\Application\Application
     */
    public $application;

    private $contents = "";

    /**
     * An array containing all the widget areas that will be used to load widgets
     * @var array
     */
    protected $widgetAreas = array();
    protected $menuAreas = array();
    protected $widgets = array();

    /**
     * Whether the stored widgets have been read yet.
     *
     * @var bool
     */
    protected bool $widgetsLoaded = false;

    /**
     * Widget types this application has registered, or null until one is.
     *
     * Null rather than an empty registry so that a project using no widgets never constructs
     * one — see widgets().
     *
     * @var WidgetRegistry|null
     */
    protected ?WidgetRegistry $widgetRegistry = null;

    /**
     * The renderer used for navigation menus, or null for the default.
     *
     * @var MenuWalker|null
     */
    protected ?MenuWalker $menuWalker = null;

    /**
     * Where menu items come from, when the application supplies them.
     *
     * @var callable|null
     */
    protected $menuItemsProvider = null;
    protected $menus = array();
    protected $bannerLocations = array();
    protected $cmsBannerLocations = array();
    protected $elements = array(
        'header' => 'header.php', // To include the header, use get_header().
        'footer' => 'footer.php', // To include the footer, use get_footer().
        'sidebar' => 'sidebar.php', // To include the sidebar, use get_sidebar().
        'search' => 'search.php', // The search results template. Used when a search is performed.
        '404' => '404.php', // The 404 Not Found template. Used when WordPress cannot find a post or page that matches the query.
        'image' => 'image.php', // Image attachment template. Used when viewing a single image attachment. If not present, attachment.php will be used.
        'attachment' => 'attachment.php', // Attachment template. Used when viewing a single attachment.
        'archive' => 'archive.php', // The archive template. Used when a category, author, or date is queried. Note that this template will be overridden by category.php, author.php, and date.php for their respective query types.
        'date' => 'date.php', // The date/time template. Used when a date or time is queried. Year, month, day, hour, minute, second.
        'author' => 'author.php', // The author template. Used when an author is queried.
        'taxonomy' => 'taxonomy.php', // The term template. Used when a term in a custom taxonomy is queried.
        'tag' => 'tag.php', // The tag template. Used when a tag is queried.
        'category' => 'category.php', //The category template. Used when a category is queried.
        'page' => 'page.php', // The page template. Used when an individual Page is queried.
        'single' => 'single.php', // The single post template. Used when a single post is queried. For this and all other query templates, index.php is used if the query template is not present.
        'home' => 'home.php', // The home page template, which is the front page by default. If you use a static front page this is the template for the page with the latest posts.
        'front-page', 'front-page.php', // The front page template, it is only used if you use a static front page.
        'comments' => 'comments.php', // The comments template.
        'style' => 'style.css', // The main stylesheet.
        'dynamicStyle' => 'style.php',
        'index' => 'theme.html.php',
        'login' => 'login.php'
    );
    protected $_contentType = 'index';

    /**
     * The theme's declared settings.
     *
     * Null until the first {@see addSetting()} call. It was declared and **never assigned**
     * from the day this file was transferred from the legacy framework — the line that built
     * it arrived already commented out, naming a class (`pramnos_html_form`) that was not
     * ported — so all five settings methods fatalled on a base theme for the whole life of
     * the file. See {@see \Pramnos\Html\Form\SettingsForm} for what replaced it and why it
     * is deliberately narrow.
     *
     * @var \Pramnos\Html\Form\SettingsForm|null
     */
    protected $_form;

    /**
     * This will be exported
     * @var string
     */
    private $body;
    private static $instances = NULL;
    private static $activeTheme = 'default';
    protected $document = NULL;

    /**
     * If set to true, controllers will first check on themedirectory/views
     * to find views.
     * @var boolean
     */
    protected $allowViewOverrides=false;

    /**
     * Theme class constructor - most of the magic happens here :D
     * @param string $theme Theme to load
     * @param string $path Path of themes
     * @param \Pramnos\Application\Application $application Application instance
     */
    function __construct($theme = 'default', $path = '', $application = null)
    {
        parent::__construct();
        if ($application !== null) {
            $this->application = $application;
        } else {
            $this->application
                = \Pramnos\Application\Application::getInstance();
        }
        $this->theme = $theme;
        if ($theme != 'default') { //Always load the default theme if define theme doesn't exist
            if ($path == '' && !file_exists(ROOT . DS . 'themes' . DS . $theme)) {
                #$theme = 'default';
            } elseif ($path != '' && !file_exists(ROOT . DS . $path . DS . $theme)) {
                #$theme = 'default';
            }
        }
        $this->document = \Pramnos\Framework\Factory::getDocument();
        $this->init();
        $this->loadSettings();

        // Widgets are loaded on first use, not here. Most applications have none, and
        // reading a setting on every theme construction to discover that is a cost paid
        // by everybody for a feature used by a few. See loadWidgets().
        if ($theme != 'default'){
            $this->theme = $theme;
        }
        if ($path == '') {
            $this->path = ROOT . DS . 'themes';
        } else {
            $this->path = $path;
        }

        $this->fullpath = $this->path . DS . $this->theme;
        if ($this->title == '') {
            $this->title = ucfirst($theme);
        }
        if ($this->thumbnail == '') {

            if (file_exists($this->fullpath . DS . 'screenshot.png')) {
                $this->thumbnail = sURL . 'themes' . DS . $this->theme . DS . 'screenshot.png';
            } elseif (file_exists($this->fullpath . DS . 'screenshot.jpg')) {
                $this->thumbnail = sURL . 'themes' . DS . $this->theme . DS . 'screenshot.jpg';
            } else {
                $this->thumbnail = sURL . 'media/img/pramnosframework/nothumbnail.png';
            }
        }
        self::$activeTheme = $theme;
    }

    /**
     * A hook for a theme that wants to load its own settings.
     *
     * This used to read `theme_<name>_settings` and then do nothing with it:
     * the only statement inside the `if` was commented out years ago, when the
     * settings form it fed (`pramnos_html_form`) stopped existing. So every
     * page paid for a query whose result was discarded — and because that key
     * is absent on most installations, the read could not even be served from
     * the settings bulk load.
     *
     * The method stays, and stays called, because a theme may override it; only
     * the query is gone. A theme that wants those settings should read them
     * here, where it also knows what to do with them.
     *
     * @return $this
     */
    protected function loadSettings()
    {
        return $this;
    }

    /**
     * Content type
     * @param string $type
     * @return Theme
     */
    public function setContentType($type)
    {
        $this->_contentType = $type;
        return $this;
    }

    /**
     * Returns current content type
     * @return string
     */
    public function getContentType()
    {
        return $this->_contentType;
    }

    /**
     * Check if current theme allows view overrides
     * @return boolean
     */
    public function allowsViewOverrides()
    {
        return $this->allowViewOverrides;
    }


    /**
     * Returns a theme object
     * @param string $theme Theme to load
     * @param string $path Path of the theme
     * @param boolean $load should we actually load the theme for display,
     *                      or just get it's information?
     * @param \Pramnos\Application\Application $application Application instance
     * @return \classname|Theme
     */
    public static function getTheme($theme = null, $path = '',
        $load = true, $application = null)
    {

        if ($theme === null) {
            $theme = self::$activeTheme;
        } else {
            self::$activeTheme = $theme;
        }

        if (self::$instances === null) {
            self::$instances = array();
        }

        if ($path == '') {
            $path = APP_PATH . DS . 'themes';

        } else {
            $path = ROOT . DS . $path;
        }

        if (!isset(self::$instances[$theme])) {
            if (file_exists($path . DS . $theme . DS . $theme . '.php')) {

                include $path . DS . $theme . DS . $theme . '.php';
                $classname = $theme . '_theme';
                if (class_exists($classname)) {

                    self::$instances[$theme] = new $classname(
                        $theme, $path, $application
                    );
                    if ($load == true) {
                        self::$instances[$theme]->loadtheme();
                    }
                    return self::$instances[$theme];
                } else {
                    self::$instances[$theme] = new Theme(
                        $theme, $path, $application
                    );
                    if ($load == true) {
                        self::$instances[$theme]->loadtheme();
                    }
                    return self::$instances[$theme];
                }
            } else {
                self::$instances[$theme] = new Theme(
                    $theme, $path, $application
                );
                if ($load == true) {
                    self::$instances[$theme]->loadtheme();
                }

                return self::$instances[$theme];
            }
        } else {

            return self::$instances[$theme];
        }
    }

    /**
     * Load the theme and add stuff to the export document
     */
    public function loadtheme()
    {
        $themefile = $this->path . DS
            . $this->theme . DS . "theme."
            . $this->document->getType() . ".php";

        if (isset($this->elements[$this->_contentType])) {
            $themefile = str_replace(
                '.html.php',
                '.' . $this->document->getType() . '.php',
                $this->path . DS . $this->theme
                . DS . $this->elements[$this->_contentType]
            );
            if (!file_exists($themefile)) {
                $themefile = str_replace(
                    '.html.php', '.' . $this->document->getType() . '.php',
                    $this->path . DS . $this->theme
                    . DS . $this->elements['index']
                );
                if (!file_exists($themefile)) {
                    $themefile = $this->path . DS
                        . $this->theme . DS . "theme.html.php";
                }
            }
        }

        if (!function_exists('l')) {

            /**
             * Alias of echo $lang->_('string');
             */
            function l()
            {
                $params = func_get_args();
                $lang = \Pramnos\Framework\Factory::getLanguage();
                echo call_user_func_array(array($lang, '_'), $params);
            }

        }


        if (file_exists($themefile)) {
            ob_start();
            include $themefile; //This way we execute any PHP commands in the file.
            $this->contents = ob_get_contents();
            ob_end_clean();
        }
        if (preg_match("/\<body(.*?)>(.*?)<\/body>/is", $this->contents, $result)) {
            $this->body = $result[2];
        } else {
            $this->body = $this->contents;
        }

        $this->displayInit();
    }

    /**
     * Read the theme file if nothing has read it yet.
     *
     * `Document::loadtheme()` builds the theme object with `$load = false`, so
     * `Theme::loadtheme()` never runs and `$contents` stays empty. `Html::render()` happens
     * to call `loadTheme()` itself, which is why the framework's own path works — and why
     * this was hard to find from outside: an application that assigns `themeObject` and
     * renders through any other route got an object reporting no error and producing the
     * bare default, because `gethead()` and `getfoot()` were splitting an empty string.
     *
     * Loading here makes "the body is loaded lazily" true rather than nearly true. It does
     * **not** short-circuit an explicit `loadtheme()` call: that still re-reads, because the
     * file it picks depends on the content type, and an application that sets a content type
     * and reloads is asking for a different template on purpose.
     *
     * The condition is **nothing read yet**, both `contents` and `body` — not merely an empty
     * `contents`. A caller that assigns `body` itself, bypassing the theme file entirely, has
     * supplied the very thing this would load; reading over it would replace a deliberate
     * value with a file's. Caught immediately by an existing test that does exactly that.
     *
     * @return void
     */
    protected function loadIfUnread(): void
    {
        if ((string) $this->contents === '' && (string) $this->body === '') {
            $this->loadtheme();
        }
    }

    /**
     * Get everything inside <head>
     * @return text
     */
    public function getheader()
    {
        $this->loadIfUnread();

        if (preg_match("/\<head>(.*?)<\/head>/is", $this->contents, $result)) {
            return $result[1];
        } else {
            return '';
        }
    }

    /**
     * Get theme head (everything before [MODULE])
     */
    public function gethead()
    {
        $this->loadIfUnread();

        $return = '';
        $head = substr($this->body, 0, strpos($this->body, "[MODULE]"));
        $return .= $head; //pass html code to the browser
        $return .= "\n<!-- Begin Module Content -->\n"; //add this to make the html code easy to understand
        return $return;
    }

    /**
     * get theme foot (everything after [MODULE])
     */
    public function getfoot()
    {
        $this->loadIfUnread();

        $return = '';
        $foot = substr($this->body, (strpos($this->body, "[MODULE]") + 8));
        $return .= "\n<!-- End of Module Content -->\n";
        $return .= $foot;
        return $return;
    }

    /**
     * Returns an array with all theme directories
     * @param string $path
     * @return array
     */
    public static function getThemes($path = '')
    {
        if ($path == '') {
            $path = ROOT . DS . 'themes';
        }

        if (file_exists($path)) {
            $dh = opendir($path);

            while (false !== ($filename = readdir($dh))) {
                $dirs[] = $filename;
            }
            $return = array();
            foreach ($dirs as $directory) {
                if (is_dir($path . DS . $directory) and substr($directory, 0, 1) !== ".") {
                    $return[] = $directory;
                }
            }
            return $return;
        } else {
            return array();
        }
    }

    /**
     * Returns an array of all theme objects
     * @param string $path
     * @return array
     */
    public static function getThemeObjects($path = '')
    {
        if ($path == '') {
            $dh = opendir(ROOT . DS . 'themes');
        } else {
            $dh = opendir($path);
        }
        $return = array();
        while (false !== ($filename = readdir($dh))) {
            $dirs[] = $filename;
        }
        foreach ($dirs as $directory) {
            if (is_dir(ROOT . DS . 'themes' . DS . $directory)
                    and $directory != ".."
                    and $directory != "."
                    and $directory != "CVS"
                    and $directory != ".svn"
                    and $directory != "default") {
                // `pramnos_theme::getTheme()` until 2026-08-14 — a legacy CMS class name
                // which, inside this namespace, resolved to Pramnos\Theme\pramnos_theme and
                // therefore to a fatal. This method could never have run.
                $return[$directory] = self::getTheme($directory, $path, false);
            }
        }
        return $return;
    }

    /**
     * Adds a field to the form
     * @param string $name Name of the setting
     * @param string $title Title of the setting (will appear in label)
     * @param string $type Type of the setting. Valid options: textfied, checkbox, number, image, textarea, selectbox, email, url
     * @param string $options Options of selectbox, seperate by comma
     * @param string $description  A little description of the setting
     * @param boolean $required Is the setting required?
     * @param string $default Default value
     * @param string $value A value
     * @return pramnos_settings_field
     */
    public function addSetting($name, $title = NULL, $type = 'textfield',
            $options = NULL, $description = NULL, $required = false,
            $default = NULL, $value = NULL)
    {
        if (is_numeric(substr($name, 0, 1))) {
            $name = '_' . $name;
        }

        $this->settingsForm()->addField(
            $name, $title, $type, $options, $description, $required, $default, $value
        );

        return $this;
    }

    /**
     * The settings form, created on first use.
     *
     * Lazily, because most themes declare no settings and the form's constructor should not be
     * paid for on every page render.
     *
     * @return \Pramnos\Html\Form\SettingsForm
     */
    protected function settingsForm(): \Pramnos\Html\Form\SettingsForm
    {
        if ($this->_form === null) {
            $this->_form = new \Pramnos\Html\Form\SettingsForm(
                'settings_' . $this->theme
            );
        }

        return $this->_form;
    }

    /**
     * Check if this theme supports (and has) settings
     * @return boolean
     */
    public function hasSettings()
    {
        return $this->_form !== null && $this->_form->hasFields();
    }

    /**
     * Get a setting value
     * @param string $setting
     * @return string if the setting is set or NULL if it's not
     */
    public function getSetting($setting)
    {
        return $this->_form === null ? null : $this->_form->value($setting);
    }

    /**
     * renders the settings form to display in administration panel
     * @return string
     */
    public function renderSettings()
    {
        $this->cleanUpBanners();

        // No declared settings is an empty string, not a fatal. A theme without settings is
        // the common case, and an administration panel asking every theme what it has must
        // not depend on the answer being non-empty.
        return $this->_form === null ? '' : $this->_form->renderFields();
    }

    /**
     * Save theme settings.
     *
     * `pramnos_settings` was a legacy CMS class name that resolves to nothing here, so this
     * method fatalled on every call — the third instance of that shape found in the
     * framework, after `pramnos_theme::getTheme()` and `pramnos_request` in the AMP renderer.
     * A theme's settings form could be rendered and never saved.
     *
     * The `@return string` it used to declare was wrong as well: it returns nothing, and
     * never did.
     *
     * @return void
     */
    function saveSettings()
    {
        if ($this->_form === null) {
            return;
        }

        $data = $this->_form->getData();

        // An empty array is what `getData()` returns when the CSRF token does not check out.
        // Writing it would blank every setting on a rejected submit, which is a worse outcome
        // than ignoring the request.
        if ($data === []) {
            return;
        }

        \Pramnos\Application\Settings::setSetting(
            'theme_' . $this->theme . '_settings',
            serialize($data)
        );
    }

    /**
     * Used to initialize all theme options (settings, sidebars, etc)
     * @return static
     */
    public function init()
    {
        \Pramnos\Addon\Addon::doAction('after_setup_theme');
        return $this;
    }

    /**
     * Initialization only for displaying the theme. Load all needed js/css.
     * @return Theme
     */
    public function displayInit()
    {
        if (isset($this->elements['style'])) {
            $filename = $this->fullpath . DS . $this->elements['style'];
            if (file_exists($filename) && is_file($filename)) {
                $doc = \Pramnos\Document\Document::getInstance();
                $doc->addCss(
                    sURL . 'themes/' . $this->theme
                    . '/' . $this->elements['style']
                );
            }
        }
        return $this;
    }

    /**
     * Adds a sidebar to the theme, to add widgets on it
     * @param string $name
     * @param string $description
     * @param string $id
     * @param array $options
     * @return Theme
     */
    public function registerWidgetArea($name = "Sidebar", $description = '',
        $id = '', $options = array())
    {
        $name = $this->makeWidgetAreaName($name);
        if ($id == '') {
            $id = 'widgetArea_' . md5($name);
        }
        $sidebar = array(
            'name' => $name,
            'id' => $id,
            'description' => $description,
            'class' => '',
            'before_widget' => '<li class="widget">',
            'after_widget' => '</div></li>',
            'before_title' => '<h2 class="widgettitle">',
            'after_title' => '</h2><div class="widgetContent">'
        );
        $sidebar = array_merge($sidebar, $options);
        $this->widgetAreas[$id] = $sidebar;
        return $this;
    }

    /**
     * Creates a name for the widget area
     * @param $name
     * @param int $count
     * @return string
     */
    protected function makeWidgetAreaName($name, $count = 0)
    {
        foreach ($this->widgetAreas as $widgetArea) {
            if ($widgetArea['name'] == $name && $count == 0) {
                $count++;
                $name = $this->makeWidgetAreaName($name, $count);
                return $name;
            } elseif ($widgetArea['name'] == $name . ' ' . $count) {
                $count++;
                $name = $this->makeWidgetAreaName($name, $count);
                return $name;
            }
        }
        if ($count != 0) {
            $name = $name . ' ' . $count;
        }
        return $name;
    }

    /**
     * check if this theme has any widget areas
     * @return boolean
     */
    public function hasWidgetAreas()
    {
        if (count($this->widgetAreas) != 0) {
            return true;
        } else {
            return false;
        }
    }

    public function renderWidgetArea($widgetArea, $args = array())
    {
        $defaultArgs = array(
            'before_widget' => '',
            'after_widget' => '',
            'before_title' => '<h3>',
            'after_title' => '</h3>'
        );
        if (isset($this->widgetAreas[$widgetArea])) {
            $defaultArgs = array_merge($defaultArgs,
                    $this->widgetAreas[$widgetArea]);
        }
        $args = array_merge($defaultArgs, $args);

        $return = "";
        if (!isset($this->widgetAreas[$widgetArea])) {
            return $return;
        }

        $widgets = $this->getWidgets($widgetArea);
        if ($widgets === array()) {
            // The common case, and the reason this returns before touching the registry:
            // an area with nothing in it costs one array lookup.
            return $return;
        }

        $registry = $this->widgets();
        foreach ($widgets as $widgetData) {
            if (!is_array($widgetData)) {
                continue;
            }

            $widget = $registry->resolve($widgetData);
            if ($widget === null) {
                // A stored record whose type is no longer registered — a removed plugin, a
                // renamed type. Skipped rather than fatal: a sidebar must not take the page
                // down over one stale entry. See WidgetRegistry::unresolved().
                continue;
            }

            $return .= $widget->render(array_merge($args, $widgetData));
        }

        return $return;
    }

    /**
     * The widget type registry for this theme.
     *
     * Constructed on first use, so a project that registers no widgets never builds one.
     *
     * @return WidgetRegistry The registry
     */
    public function widgets(): WidgetRegistry
    {
        if ($this->widgetRegistry === null) {
            $this->widgetRegistry = new WidgetRegistry();
        }

        return $this->widgetRegistry;
    }

    /**
     * Reads the stored widget records, once.
     *
     * A theme with no stored widgets is the normal case, and `getSetting()` answers `false`
     * for it — feeding that to `unserialize()` raises "Error at offset 0", a warning on every
     * page for a condition that is not an error.
     *
     * @return void
     */
    protected function loadWidgets(): void
    {
        if ($this->widgetsLoaded) {
            return;
        }

        $this->widgetsLoaded = true;

        $stored = \Pramnos\Application\Settings::getSetting(
            'theme_' . $this->theme . '_widgets'
        );
        $data = (is_string($stored) && $stored !== '') ? @unserialize($stored) : false;

        $this->widgets = is_array($data) ? $data : array();
    }

    /**
     * Registers a single custom navigation menu in the menu editor.
     * This allows for creation of custom menus in the dashboard for use in your theme.
     * @param string $location Menu location identifier, like a slug.
     * @param string $description The default value to return if no value is returned (ie. the option is not in the database).
     * @param integer|NULL $menuid A menu ID for the menu that you want to be displayed in this position
     * @return static
     */
    public function register_nav_menu($location, $description, $menuid = NULL)
    {
        $this->menuAreas[$location] = array(
            'description' => $description,
            'menuid' => $menuid);
        if ($menuid === NULL) {
            $this->assignMenus();
            if (isset($this->menus[$location])) {
                $this->menuAreas[$location]['menuid'] = $this->menus[$location];
            }
        } else {
            $this->setMenu($location, $menuid);
        }
        return $this;
    }

    /**
     * Check if this theme has any menu areas
     * @return boolean
     */
    public function hasMenuAreas()
    {
        if (count($this->menuAreas) != 0) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Get a list of all menu areas
     * @return array Location=>Description
     */
    public function getMenuAreas()
    {
        $this->assignMenus();
        return $this->menuAreas;
    }

    /**
     * @param   boolean $force
     * Loads the assignedMenus from settings
     */
    protected function assignMenus($force = false)
    {
        if (count($this->menus) == 0 || $force === true) {
            $this->menus = unserialize(\Pramnos\Application\Settings::getSetting('theme_' . $this->theme . '_menus'));
            if (is_array($this->menus)) {
                foreach ($this->menus as $location => $menuid) {
                    if (isset($this->menus[$location])) {
                        $this->menuAreas[$location]['menuid'] = $menuid;
                    }
                }
            } else {
                $this->menus = array();
            }
        }
        $this->cleanUpMenus();
    }

    /**
     * Clean up menus removed from the theme
     */
    protected function cleanUpMenus()
    {
        foreach ($this->menuAreas as $menuArea=>$item){
            if (!isset($item['description'])){
                $this->removeMenuArea($menuArea);
            }
        }
    }

    /**
     * Remove a menuaArea from the theme
     * @param string $menuArea
     * @return \Theme
     */
    public function removeMenuArea($menuArea)
    {
        if (isset($this->menuAreas[$menuArea])){
            unset($this->menuAreas[$menuArea]);
        }
        if (isset($this->menus[$menuArea])){
            unset($this->menus[$menuArea]);
        }
        return $this;
    }

    /**
     * Assigns a menu to a position
     * @param string $position
     * @param string $menuid
     */
    public function setMenu($position, $menuid)
    {
        $this->assignMenus();
        $this->menus[$position] = $menuid;
        \Pramnos\Application\Settings::setSetting('theme_' . $this->theme . '_menus',
                serialize($this->menus));
        if (isset($this->menuAreas[$position])) {
            $this->menuAreas[$position]['menuid'] = $menuid;
        } else {
            return false;
        }
    }

    /**
     * Get the assigned menu for a menu position
     * @param string $position
     * @return integer|boolean Menu ID or false if it isn't set
     */
    public function getMenu($position)
    {
        $this->assignMenus();
        if (isset($this->menus[$position])) {
            return $this->menus[$position];
        } else {
            return false;
        }
    }

    /**
     * Get a list of all registered widget areas of this theme
     * @return array
     */
    public function getWidgetAreas()
    {
        return $this->widgetAreas;
    }

    /**
     * Displays a navigation menu.
     * @param string $location The id of location, specified at menu registration
     * @param array $args An array of arguments, works like wordpress wp_nav_menu. <br />Default args: <br /> 'menu' => '','container' => 'div',<br /> <br />'container_class' => '',<br />'container_id'    => '',<br />'menu_class'      => 'menu',<br />'menu_id'         => '',<br />'echo'            => true,<br />'before'          => '<li class="[HASSUB]parent[/HASSUB] [ACTIVE]active[/ACTIVE]">',<br />'after'           => '</li>',<br />'link_before'     => '',<br />'link_after'      => '',<br />'items_wrap'      => '<ul id="%1$s" class="%2$s">%3$s</ul>'
     * @return string|NULL
     */
    public function displayMenu($location, $args = array())
    {
        $defaults = array(
            'theme_location' => $location,
            'menu' => '',
            'container' => 'div',
            'container_class' => '',
            'container_id' => '',
            'menu_class' => 'menu',
            'menu_id' => '',
            'echo' => true,
            'before' => '<li class="[HASSUB]parent[/HASSUB] [ACTIVE]active[/ACTIVE]">',
            'after' => '</li>',
            'link_before' => '',
            'link_after' => '',
            'items_wrap' => '<ul id="%1$s" class="%2$s">%3$s</ul>'
        );
        $args = array_merge($defaults, $args);
        unset($defaults);
        $echo = $args['echo'];
        $items_wrap = explode('%3$s', $args['items_wrap']);
        $items_wrap[0] = str_replace(array('%1$s', '%2$s'),
                array($args['container_id'],
            $args['container_class']), $items_wrap[0]);
        $options = array();
        $options['premenu'] = '';
        if ($args['container'] != '') {
            $options['premenu'].='<' . $args['container'];
            if ($args['container_class'] != '') {
                $options['premenu'] .= ' class="' . $args['container_class'] . '"';
            }
            if ($args['container_id'] != '') {
                $options['premenu'] .= ' id="' . $args['container_id'] . '"';
            }
            $options['premenu'] .= '>';
        }
        $options['premenu'] .= $items_wrap[0];
        $options['postmenu'] = '';
        if ($args['container'] != '') {
            $options['postmenu'] .= '</' . $args['container'] . '>';
        }
        if (isset($items_wrap[1])) {
            $options['postmenu'].=$items_wrap[1];
        }
        $options['pretopmenu'] = $args['before'];
        $options['posttopmenu'] = $args['after'];
        $options['topmenuoption'] = '<a href="[URL]">' . $args['link_before'] . '[TITLE]' . $args['link_after'] . '</a>';
        $options['submenuoption'] = '<a href="[URL]">' . $args['link_before'] . '[TITLE]' . $args['link_after'] . '</a>';
        $options['submenubodystart'] = '<ul>';
        $options['submenubodyend'] = '</ul>';
        $options['presubmenu'] = $args['before'];
        $options['postsubmenu'] = $args['after'];
        $menuId = $args['menu'] != '' ? $args['menu'] : $this->getMenu($args['theme_location']);

        // Menu storage is an application concern — the framework ships none — so items come
        // from a registered provider. With no provider this returns an empty string.
        //
        // It used to instantiate `pramnoscms_menu` unconditionally: a class from a deprecated
        // CMS that the framework does not ship, and which inside this namespace resolved to
        // Pramnos\Theme\pramnoscms_menu. So this method *fatalled* in every project without
        // it, and the framework's own test had to eval() a fake one to test it at all. The
        // useful half of that class was rendering, which is now MenuWalker.
        $items = $this->menuItems($menuId, $args['theme_location']);

        if ($items !== null) {
            $rendered = $this->menuWalker()->render($items, $options);
        } else {
            $rendered = '';
        }

        unset($args);

        if ($echo === true) {
            echo $rendered;

            return null;
        }

        return $rendered;
    }

    /**
     * The renderer used for navigation menus.
     *
     * Constructed on first use, so a project that never displays a menu never builds one.
     *
     * @return MenuWalker The walker
     */
    public function menuWalker(): MenuWalker
    {
        if ($this->menuWalker === null) {
            $this->menuWalker = new MenuWalker();
        }

        return $this->menuWalker;
    }

    /**
     * Uses a different renderer for navigation menus.
     *
     * Subclass {@see MenuWalker} and override one method rather than reimplementing a nav
     * menu — which is what the extension point documented here for years would have been, had
     * it existed.
     *
     * @param MenuWalker $walker The renderer to use
     * @return static This theme, for chaining
     */
    public function setMenuWalker(MenuWalker $walker): static
    {
        $this->menuWalker = $walker;

        return $this;
    }

    /**
     * Tells the theme where menu items come from.
     *
     * The framework has no menu storage — menus are an application concern, and every project
     * that has them has its own table. The provider is given the menu id and the location, and
     * returns items in the shape {@see MenuWalker} accepts, or null if it has nothing for
     * this menu.
     *
     * ```php
     * $theme->setMenuItemsProvider(
     *     fn ($menuId, $location) => Menu::load($menuId)?->toTree()
     * );
     * ```
     *
     * @param callable|null $provider Receives `($menuId, $location)`; null removes it
     * @return static This theme, for chaining
     */
    public function setMenuItemsProvider(?callable $provider): static
    {
        $this->menuItemsProvider = $provider;

        return $this;
    }

    /**
     * Items for a menu, from the registered provider.
     *
     * @param mixed  $menuId   The assigned menu id, or an empty value
     * @param string $location The registered menu location
     * @return array<int, array<string, mixed>>|null The items, or null when there is no provider
     */
    protected function menuItems(mixed $menuId, string $location): ?array
    {
        if ($this->menuItemsProvider === null) {
            return null;
        }

        $items = ($this->menuItemsProvider)($menuId, $location);

        return is_array($items) ? array_values($items) : null;
    }

    /**
     * @todo    Implement add_theme_support
     * @param string $feature Name for the feature being added. Features list:<br />'post-formats'<br />'post-thumbnails'<br />'custom-background'<br />'custom-header'<br />'automatic-feed-links'<br />'menus'
     * @return Theme
     */
    public function add_theme_support($feature)
    {
        return $this;
    }

    // ALIASES

    /**
     * Builds Sidebar based off of 'name' and 'id' values.
     * @param array $args <br />Default: None<br />name - Sidebar name (default is localized 'Sidebar' and numeric ID).<br />id - Sidebar id - Must be all in lowercase, with no spaces (default is a numeric auto-incremented ID).<br />description - Text description of what/where the sidebar is. Shown on widget management screen. (Since 2.9) (default: empty)<br />class - CSS class name to assign to the widget HTML (default: empty).<br />before_widget - HTML to place before every widget(default: '<li id="%1$s" class="widget %2$s">') Note: uses sprintf for variable substitution<br />after_widget - HTML to place after every widget (default: "</li>\n").<br />before_title - HTML to place before every title (default: <h2 class="widgettitle">).<br />after_title - HTML to place after every title (default: "</h2>\n").
     */
    public function register_sidebar($args = array())
    {
        $defaultArgs = array(
            'name' => 'Sidebar Name',
            'id' => 'unique-sidebar-id',
            'description' => '',
            'class' => '',
            'before_widget' => '<li id="%1$s" class="widget %2$s">',
            'after_widget' => '</li>',
            'before_title' => '<h2 class="widgettitle">',
            'after_title' => '</h2>'
        );
        $args = array_merge($defaultArgs, $args);
        return $this->registerWidgetArea($args['name'], $args['description'],
                        $args['id'], $args);
    }

    /**
     * Clear all widget data from a theme
     */
    public function resetWidgets()
    {
        // Marked loaded as well, so a later getWidgets() does not read the setting back and
        // undo this in memory.
        $this->widgetsLoaded = true;
        $this->widgets = array();
        \Pramnos\Application\Settings::setSetting('theme_' . $this->theme . '_widgets',
                serialize($this->widgets));
    }

    /**
     * Add a widget to a widget area of the theme
     * @param string $widgetAreaID
     * @param array $widgetData
     * @param bool $debug
     * @return bool|string
     */
    public function addWidget($widgetAreaID, $widgetData, $debug = false)
    {
        if (isset($this->widgetAreas[$widgetAreaID])) {
            // Load before mutating. This method serialises the whole collection back to the
            // setting, so adding to an unloaded one would persist just the new widget and
            // silently discard every widget already stored.
            $this->loadWidgets();

            $widget = array();
            $output = "Creating a widget with data: " . $widgetData . ' at ' . $widgetAreaID;
            parse_str($widgetData, $widget);
            $widget['widgetArea'] = $widgetAreaID;
            if (!isset($widget['widgetId'])) {
                return false;
            }
            $this->widgets[$widget['widgetId']] = $widget;
            \Pramnos\Application\Settings::setSetting('theme_' . $this->theme . '_widgets',
                    serialize($this->widgets));
            if ($debug == true) {
                return $output;
            } else {
                return true;
            }
        } else {
            if ($debug == true) {
                return 'There is no such widget area as ' . $widgetAreaID . '. Widget Areas are: ' . \Pramnos\General\Helpers::varDumpToString($this->widgetAreas);
            } else {
                return false;
            }
        }
        return false;
    }

    /**
     * Returns an array of all the widgets attached to this theme
     * @param   string $widgetArea Leave empty to get ALL widgets
     * @return  array|mixed
     */
    public function getWidgets($widgetArea = NULL)
    {
        $this->loadWidgets();
        if (!is_array($this->widgets)) {
            $this->widgets = array();
        }
        if ($widgetArea === NULL) {
            return $this->widgets;
        } else {
            $widgets = array();
            foreach ($this->widgets as $widget) {
                if (isset($widget['widgetArea']) && $widget['widgetArea'] == $widgetArea) {
                    $widgets[] = $widget;
                }
            }
            return $widgets;
        }
    }

    // Functions for theme elements

    /**
     * Include a theme element
     * @param string $element
     * @return boolean
     */
    public function getElement($element)
    {
        if (isset($this->elements[$element])) {
            $filename = $this->fullpath . DS . $this->elements[$element];
            if (file_exists($filename) && is_file($filename)) {
                include ($filename);
                return true;
            }
        }
        return false;
    }

    /**
     * Alias of getElement for header
     * @return boolean
     */
    public function get_header()
    {
        return $this->getElement('header');
    }


    /**
     * Alias of getElement for footer
     * @return boolean
     */
    public function get_footer()
    {
        return $this->getElement('footer');
    }

    /**
     * Alias of getElement for search_form
     * @return boolean
     */
    public function get_search_form()
    {
        return $this->getElement('search_form');
    }

    /**
     * An alias of getElement for the sidebar
     * @return boolean
     */
    public function get_sidebar()
    {
        return $this->getElement('sidebar');
    }





    /**
     * Registers a single custom navigation banner Location
     * @param string $bannerLocation banner location identifier, like a slug.
     * @param string $description The default value to return if no value is returned (ie. the option is not in the database).
     * @param integer|NULL $locationid A location ID for the location that you want to be displayed in this position
     * @return Theme
     */
    public function registerBannerLocation($bannerLocation, $description, $locationid = NULL)
    {
        $this->bannerLocations[$bannerLocation] = array(
            'description' => $description,
            'locationid' => $locationid);
        if ($locationid === NULL) {
            $this->assignBannerLocations();
            if (isset($this->cmsBannerLocations[$bannerLocation])) {
                $this->bannerLocations[$bannerLocation]['locationid'] = $this->cmsBannerLocations[$bannerLocation];
            }
        } else {
            $this->setBannerLocation($bannerLocation, $locationid);
        }
        return $this;
    }

    /**
     * Check if this theme has any banner locations
     * @return boolean
     */
    public function hasBannerLocations()
    {
        if (count($this->bannerLocations) != 0) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Get a list of all banner locations
     * @return array Location=>Description
     */
    public function getbannerLocations()
    {
        $this->assignBannerLocations();
        return $this->bannerLocations;
    }


    /**
     * Loads the assignedBannerLocations from settings
     * @param boolean $force
     */
    protected function assignBannerLocations($force = false)
    {
        if (count($this->cmsBannerLocations) == 0 || $force === true) {
            $this->cmsBannerLocations = unserialize(
                \Pramnos\Application\Settings::getSetting('theme_' . $this->theme . '_bannerLocations')
                    );
            if (is_array($this->cmsBannerLocations)) {
                foreach ($this->cmsBannerLocations as $location => $locationid) {
                    if (isset($this->cmsBannerLocations[$location])) {
                        $this->bannerLocations[$location]['locationid'] = $locationid;
                    }
                }
            } else {
                $this->cmsBannerLocations = array();
            }
        }
        $this->cleanUpBanners();
    }

    /**
     * Assigns a location to a position
     * @param string $bannerLocation
     * @param string $locationid
     */
    public function setBannerLocation($bannerLocation, $locationid)
    {
        $this->assignBannerLocations();
        $this->cmsBannerLocations[$bannerLocation] = $locationid;
        \Pramnos\Application\Settings::setSetting('theme_' . $this->theme . '_bannerLocations',
                serialize($this->cmsBannerLocations));
        if (isset($this->bannerLocations[$bannerLocation])) {
            $this->bannerLocations[$bannerLocation]['locationid'] = $locationid;
        } else {
            return false;
        }

    }

    /**
     * Get the assigned locationid for a banner location
     * @param string $position
     * @return integer|boolean Location ID or false if it isn't set
     */
    public function getCmsLocation($position)
    {
        $this->assignBannerLocations();
        if (isset($this->cmsBannerLocations[$position])) {
            return $this->cmsBannerLocations[$position];
        } else {
            return false;
        }
    }



    /**
     * Clean up menus removed from the theme
     */
    protected function cleanUpBanners()
    {
        foreach ($this->bannerLocations as $bannerLocation=>$item){
            if (!isset($item['description'])){
                $this->removeBannerLocation($bannerLocation);
            }
        }
    }

    /**
     * Remove a bannerLocation from the theme
     * @param string $bannerLocation
     * @return Theme
     */
    public function removeBannerLocation($bannerLocation)
    {
        if (isset($this->bannerLocations[$bannerLocation])){
            unset($this->bannerLocations[$bannerLocation]);
        }
        if (isset($this->cmsBannerLocations[$bannerLocation])){
            unset($this->cmsBannerLocations[$bannerLocation]);
        }
        return $this;
    }


}

// General Functions - used mostly for compatibility with Wordpress

if (!function_exists('get_header')) {

    /**
     * Includes the header.php template file from your current theme's directory.
     */
    function get_header()
    {
        $theme = \Pramnos\Theme\Theme::getTheme();
        $theme->get_header();
    }

}

if (!function_exists('get_footer')) {

    /**
     * Includes the footer.php template file from your current theme's directory.
     */
    function get_footer()
    {
        $theme = \Pramnos\Theme\Theme::getTheme();
        $theme->get_footer();
    }

}


if (!function_exists('get_search_form')) {

    /**
     * Includes the search_form.php template file from your current theme's directory.
     */
    function get_search_form()
    {
        $theme = \Pramnos\Theme\Theme::getTheme();
        $theme->get_search_form();
    }

}


if (!function_exists('get_sidebar')) {

    /**
     * Includes the sidebar.php template file from your current theme's directory.
     */
    function get_sidebar()
    {
        $theme = \Pramnos\Theme\Theme::getTheme();
        $theme->get_sidebar();
    }

}

