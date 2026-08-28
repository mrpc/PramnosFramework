<?php
namespace Pramnos\Application;

use Pramnos\Application\Template\TemplateCompiler;
use Pramnos\Application\Template\TemplateCache;

/**
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class View extends \Pramnos\Framework\Base
{
    /**
     * Array of models
     * @var \Pramnos\Application\Model[]
     */
    protected $models = array();
    /**
     * Default model name
     * @var string
     */
    protected $defaultModel = '';
    /**
     * View path
     * @var string
     */
    protected $path = '';

    /**
     * A subdirectory under {@see $path} that templates live in. Empty by default.
     *
     * The framework's convention is flat — `views/<module>/<tpl>.<type>.php` — and it is
     * what the reference application and all three scaffolded themes use. Nothing here
     * needs this property.
     *
     * It exists for an application migrating off the legacy view layer, whose
     * `pramnos_application_view` built `<path>/tpl/<tpl>.<type>.php`. One such
     * application has 820 templates in 131 `tpl/` directories, and moving them would
     * rewrite every open branch and every `git blame` over the view layer.
     *
     * ```php
     * abstract class AppView extends \Pramnos\Application\View
     * {
     *     protected $tplSubdirectory = 'tpl';
     * }
     * ```
     *
     * Deliberately a declaration rather than a search. Trying `tpl/` when the flat path
     * misses would put a `file_exists()` on every render of every project, for ever, to
     * serve a layout none of them use — and would quietly establish a second convention
     * the framework then owes support for. An application that has the directory says so
     * once.
     *
     * @var string
     */
    protected $tplSubdirectory = '';
    /**
     * View name
     * @var string
     */
    protected $name = '';
    /**
     * View type
     * @var string
     */
    protected $type = 'html';
    /**
     * Model output
     * @var string
     */
    public $output = '';
    /**
     * Current Model
     * @var \Pramnos\Application\Model
     */
    public $model = false;
    /**
     * Current Controller
     * @var \Pramnos\Application\Controller
     */
    public $controller = null;

    /**
     * Current request object
     * @var \Pramnos\Http\Request
     */
    public $request = null;

    /**
     * Validation errors flashed for the current request
     * @var array
     */
    public $errors = array();

    /**
     * Flash messages written with `addMessage()` on the previous request.
     *
     * Distinct from {@see $errors}, which is the per-field output of a validator. These are
     * whole sentences a controller wrote before redirecting — "Application saved", "That id
     * does not exist" — and they are consumed once: a reload does not show them again.
     *
     * Until 2026-08-17 there was nowhere for a template to read them, so controllers passed
     * `?error=…` in the redirect URL instead. Nothing read that either, in either direction.
     *
     * @var array<int, string>
     */
    public $messages = array();

    /**
     * Flash errors written with `addError()` on the previous request.
     *
     * @var array<int, string>
     */
    public $flashErrors = array();

    // =========================================================================
    // Template engine state
    // =========================================================================

    /**
     * Layout template to wrap this view (set by $this->layout() inside a template).
     * Null means no layout — the template output is used as-is.
     * @var string|null
     */
    protected ?string $_layout = null;

    /**
     * Captured section content, keyed by section name.
     * Populated by section() / endsection() pairs; read by yield() in layouts.
     * @var array<string, string>
     */
    protected array $sections = [];

    /**
     * Stack of currently-open section names.
     * Supports nested sections (though uncommon in practice).
     * @var string[]
     */
    protected array $sectionStack = [];

    /**
     * Override directory for the compiled template cache.
     * Empty string means TemplateCache will use its own default (ROOT/var/viewcache).
     * @var string
     */
    protected static string $templateCacheDir = '';

    // =========================================================================
    // Output cache state (PF-9)
    // =========================================================================

    /**
     * Output cache TTL in seconds. Null means caching disabled for this render.
     * Set via withCache(). Consumed (reset to null) after each getTpl() call.
     * @var int|null
     */
    protected ?int $_cacheTtl = null;

    /**
     * Explicit output cache key. Null = auto-generated from view name + tpl + type.
     * @var string|null
     */
    protected ?string $_cacheKey = null;

    /**
     * Enable output caching for the next display() / getTpl() call.
     *
     * The cache key is optional — when omitted it is auto-generated from the
     * view name, template, and type so identical views share the same entry.
     * The TTL resets to null after each getTpl() call (one-shot).
     *
     * @param  int         $ttl Seconds to keep the cached output (default 3600).
     * @param  string|null $key Explicit cache key; null = auto-generate.
     * @return static
     */
    public function withCache(int $ttl = 3600, ?string $key = null): static
    {
        $this->_cacheTtl = $ttl;
        $this->_cacheKey = $key;
        return $this;
    }

    /**
     * Cache the output of an arbitrary callable and return it.
     *
     * Useful inside template files for expensive sub-sections:
     *   <?= $this->cache('sidebar', 600, fn() => $this->insert('sidebar')) ?>
     *
     * Falls back to calling $fn directly when the Cache adapter is unavailable.
     *
     * @param  string   $key Unique cache key.
     * @param  int      $ttl Seconds to keep the cached value.
     * @param  callable $fn  Callable that produces the string to cache.
     * @return string
     */
    public function cache(string $key, int $ttl, callable $fn): string
    {
        try {
            $cacheInstance = \Pramnos\Cache\Cache::getInstance('views');
            return (string) $cacheInstance->remember($key, $ttl, $fn);
        } catch (\Throwable $e) {
            return (string) $fn();
        }
    }

    /**
     * Render and return the view contents
     * @param string $tpl template file to load
     * @param bool $render if is set to true, output will not buffered
     * @return string
     */
    public function display($tpl='', $render=false)
    {
        $this->model =& $this->getModel();
        if ($render == true){
            return $this->getTpl($tpl, '', $render);
        }

        /**
         * Start from empty, because `getTpl()` appends.
         *
         * `$output .= $finalOutput` is deliberate — it lets a caller render several
         * templates into one buffer with successive `getTpl()` calls. `display()`
         * is not that caller: it renders one template and returns the result, and
         * returning `$this->output` gave it everything the view had *ever*
         * rendered.
         *
         * A view is cached per controller and a controller per application, so in
         * any process that serves more than one request — a test client, a worker,
         * a long-running server — the same view object renders again and its
         * caller receives the previous pages in front of this one. Measured in a
         * suite: a login page grew by 2.9 KB on every request and reached 1.7 MB,
         * with one screen's inline script repeated a hundred times in a page that
         * had nothing to do with it. Assertions written against such a response
         * pass on content from a page the test never asked for.
         *
         * `getTpl()` keeps appending; only this entry point resets first.
         */
        $this->output = '';
        $this->getTpl($tpl, '', $render);
        return $this->output;
    }

    /**
     * View constructor
     * @param \Pramnos\Application\Controller $controller Current controller
     * @param string $path
     * @param string $name
     * @param string $type
     */
    public function __construct(\Pramnos\Application\Controller $controller,
        $path='', $name='', $type='html')
    {
        $this->controller = $controller;
        $this->path=$path;
        $this->name=$name;
        $this->type=$type;
        $this->defaultModel=$name;

        $this->request = new \Pramnos\Http\Request();
        $this->errors = $this->request->errors();

        // Read here rather than in the template, and read once: the same one-shot capture as
        // the validation errors above, so a message survives exactly one redirect.
        $this->messages    = $this->request->messages();
        $this->flashErrors = $this->request->flashErrors();

        parent::__construct();
    }

    /**
     * Whether this request is being debugged.
     *
     * Asks `Application::isDeveloperEnvironment()` — the environment variable or the
     * `DEVELOPMENT` constant — rather than reading either here: a second copy of that
     * decision answers differently on the machines where the other signal is the one in use.
     *
     * False when neither is set, which is the safe answer for a disclosure.
     *
     * @return bool
     */
    protected function inDebugMode(): bool
    {
        /*
         * The environment, not the settings.
         *
         * This gates a comment naming the view's file, in the source of every rendered page.
         * `isDebugMode()` reads the `debug` and `development` settings, so a row editable
         * from `/admin/Settings` decided whether every visitor's page — and every crawler's
         * copy of it — told them where the application's files live. That is a disclosure,
         * and a settings row is the wrong lock for one.
         */
        return \Pramnos\Application\Application::isDeveloperEnvironment();
    }

    /**
     * Adds a model to the view
     * @param \Pramnos\Application\Model $model
     * @param boolean $default Is this model the main used for this view?
     */
    public function addModel(\Pramnos\Application\Model &$model, $default=true)
    {
        if (is_object($model)){
            // A model whose `name` is not set (e.g. an unsaved record on an
            // "edit/0" form) yields a null key; PHP 8.5 deprecates null array
            // offsets, so coerce to string ('' for null).
            $key = (string) ($model->name ?? '');
            $this->models[$key] = $model;
            if ($default !== false) {
                $this->defaultModel = $key;
                $this->model =& $this->getModel($this->defaultModel);
            }
        }
    }

    /**
     * Gets a model, if it exists
     * @param string $model Model name
     * @return boolean|\Pramnos\Application\Model
     */
    public function &getModel($model='')
    {
        if ($model === '' || $model === null){
            $model = $this->defaultModel;
        }
        // Guard against a null/collection key — PHP 8.5 deprecates null offsets.
        $model = (string) ($model ?? '');
        if (isset($this->models[$model])
            && is_object($this->models[$model])) {
            return $this->models[$model];
        }
        else {
            $model = false;
            return $model;
        }
    }

    /**
     * Get view type
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * HTML-escape a value for safe output in a template.
     *
     * Delegates to the global e() helper so templates can use either
     * $this->escape($value) or the shorter e($value) form.
     *
     * Usage in .html.php templates:
     *   <?php echo $this->escape($model->title); ?>
     *   <?php echo $this->e($user->bio); ?>
     *
     * @param  mixed  $value    Any scalar, null, or stringable.
     * @param  string $encoding Character encoding (default UTF-8).
     * @return string           HTML-safe string.
     */
    public function escape(mixed $value, string $encoding = 'UTF-8'): string
    {
        return e($value, $encoding);
    }

    /**
     * Short alias for escape() — for brevity in templates.
     */
    public function e(mixed $value, string $encoding = 'UTF-8'): string
    {
        return e($value, $encoding);
    }




    // =========================================================================
    // Template engine — public API (usable in .html.php and .tpl.php)
    // =========================================================================

    /**
     * Declare that this template should be wrapped by a layout.
     *
     * Call at the top of a child template. The layout file is rendered after
     * the child finishes, with all sections already populated.
     *
     * Usage in .html.php:
     *   <?php $this->layout('layouts/main'); ?>
     *
     * Usage in .tpl.php (via @extends directive):
     *   @extends('layouts/main')
     *
     * @param string $layoutName Path relative to the view path or ROOT/views/,
     *                           without extension (e.g. 'layouts/main').
     */
    public function layout(string $layoutName): void
    {
        $this->_layout = $layoutName;
    }

    /**
     * Start capturing output for a named section.
     *
     * Everything echoed until the matching endsection() is captured and stored
     * under $name. The layout template retrieves it via yield($name).
     *
     * Usage in .html.php:
     *   <?php $this->section('content'); ?>
     *     <h1>Hello</h1>
     *   <?php $this->endsection(); ?>
     *
     * @param string $name Section identifier (e.g. 'content', 'sidebar').
     */
    public function section(string $name): void
    {
        $this->sectionStack[] = $name;
        ob_start();
    }

    /**
     * End the most-recently-opened section and store its captured output.
     *
     * Usage in .html.php:
     *   <?php $this->endsection(); ?>
     */
    public function endsection(): void
    {
        if (empty($this->sectionStack)) {
            return;
        }
        $name = array_pop($this->sectionStack);
        $this->sections[$name] = (string) ob_get_clean();
    }

    /**
     * Output a named section (used inside layout templates).
     *
     * Returns $default when the section was not defined by the child template.
     *
     * Usage in layout .html.php:
     *   <?php echo $this->yield('content'); ?>
     *   <?php echo $this->yield('sidebar', '<aside>Default sidebar</aside>'); ?>
     *
     * @param string $name    Section identifier.
     * @param string $default Fallback HTML when the section is absent.
     * @return string         Captured section HTML, or $default.
     */
    public function yield(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    /**
     * Include a sub-template (partial) directly into the current output buffer.
     *
     * The partial receives the same $this (View object) plus any extra $data
     * extracted into local scope. Partials should NOT call layout() — they are
     * always rendered inline.
     *
     * Usage in .html.php:
     *   <?php $this->insert('partials/card', ['item' => $item]); ?>
     *
     * Usage in .tpl.php (via @include directive):
     *   @include('partials/card', ['item' => $item])
     *
     * @param string               $template Template name (without extension).
     * @param array<string, mixed> $data     Extra variables merged into the partial's scope.
     */
    public function insert(string $template, array $data = []): void
    {
        $file = $this->resolveTemplatePath($template);
        if ($file === null) {
            \Pramnos\Logs\Logger::log("Template partial not found: {$template}");
            return;
        }
        $includeFile = $this->getIncludePath($file);

        $model = $this->model;
        $lang  = \Pramnos\Framework\Factory::getLanguage();
        if (!empty($data)) {
            extract($data, EXTR_SKIP);
        }
        $_pdb_partial_start = microtime(true);
        include $includeFile;

        // Record the partial in the DebugBar's ViewsCollector. insert() does a
        // plain include (it does not go through getTpl()), so without this the
        // debug "Views" panel would list only the top-level template and a
        // developer would not see which partial files a page actually rendered
        // — exactly the files they may need to edit when debugging.
        try {
            $vc = \Pramnos\Debug\DebugBar::getInstance()->getCollector('views');
            if ($vc instanceof \Pramnos\Debug\Collectors\ViewsCollector) {
                $vc->record($this->name, $file, (microtime(true) - $_pdb_partial_start) * 1000);
            }
        } catch (\Throwable) {
            // A view helper that cannot resolve is not a reason to blank the page:
                // the surrounding template renders without it.
        }
    }

    // =========================================================================
    // Template engine — cache configuration (static, app-level)
    // =========================================================================

    /**
     * Override the compiled template cache directory.
     *
     * Call once during application bootstrap. Leave unset to use the default
     * (ROOT/var/viewcache).
     *
     * @param string $dir Absolute path to a writable directory.
     */
    public static function setTemplateCacheDir(string $dir): void
    {
        static::$templateCacheDir = $dir;
    }

    /** Return the configured cache directory (empty = use default). */
    public static function getTemplateCacheDir(): string
    {
        return static::$templateCacheDir;
    }

    // =========================================================================
    // Template engine — internal helpers
    // =========================================================================

    /**
     * Return the includable path for $filePath:
     * - .tpl.php files are compiled and the cached compiled path is returned.
     * - All other files are returned as-is.
     */
    private function getIncludePath(string $filePath): string
    {
        if (!str_ends_with($filePath, '.tpl.php')) {
            return $filePath;
        }
        $compiler = new TemplateCompiler();
        $cache    = new TemplateCache(static::$templateCacheDir);
        return $cache->resolve($filePath, fn(string $src) => $compiler->compile($src));
    }

    /**
     * Resolve a template name to an absolute file path.
     *
     * Search order (first match wins):
     *   1. Absolute path given and exists.
     *   2. Relative to the current view's path — tries .html.php then .tpl.php.
     *   3. Relative to ROOT/views/ — tries .html.php then .tpl.php.
     *   4. Theme override (theme/views/{name}.html.php or .tpl.php).
     *
     * @param string $name Template name without extension (e.g. 'layouts/main').
     * @return string|null Absolute path, or null if not found.
     */
    private function resolveTemplatePath(string $name): ?string
    {
        // 1. Absolute path
        if (file_exists($name)) {
            return $name;
        }

        $extensions = ['.html.php', '.tpl.php'];
        $bases      = array_filter([
            $this->path,
            defined('ROOT') ? ROOT . DIRECTORY_SEPARATOR . 'views' : null,
            // The directory holding the view directories — `src/Views` when
            // `$this->path` is `src/Views/Home`. It is where a developer puts a
            // layout or a partial meant to be shared between views, and it was the
            // one place never searched: `$this->layout('layouts/main')` from
            // `src/Views/Home/home.html.php` looked in `src/Views/Home/layouts/`
            // and in `ROOT/views/`, and found neither. Added last so no path that
            // resolved before resolves differently now.
            $this->path !== '' ? dirname($this->path) : null,
        ]);

        // 2 & 3. Relative to view path and ROOT/views/
        foreach ($bases as $base) {
            foreach ($extensions as $ext) {
                $path = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $name . $ext;
                if (file_exists($path)) {
                    return $path;
                }
            }
        }

        // 4. Theme override
        try {
            $doc = \Pramnos\Framework\Factory::getDocument();
            if (is_object($doc)
                && isset($doc->themeObject)
                && is_object($doc->themeObject)
                && $doc->themeObject->allowsViewOverrides()
            ) {
                foreach ($extensions as $ext) {
                    $path = $doc->themeObject->fullpath
                        . DIRECTORY_SEPARATOR . 'views'
                        . DIRECTORY_SEPARATOR . $name . $ext;
                    if (file_exists($path)) {
                        return $path;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Factory may be unavailable in tests — silently skip
        }

        return null;
    }

    /**
     * Gets a tpl file for the current view. Tpl file can be placed in
     * current theme's directory to overide the normal tpl file
     * @param string $tpl
     * @param string $type
     * @param boolean $render
     * @return boolean
     */
    /**
     * Where this view's templates live: its path, plus {@see $tplSubdirectory}.
     *
     * One place, so the theme-override branch below and anything else that resolves a
     * template cannot disagree about it.
     *
     * @return string
     */
    protected function templateDirectory()
    {
        return $this->tplSubdirectory === ''
            ? $this->path
            : $this->path . DS . $this->tplSubdirectory;
    }

    /**
     * The bundled scaffolding's version of a template, when the application has
     * none of its own.
     *
     * `Controller::getView()` already falls back to the bundled theme — but it
     * does so when it cannot find the **view directory**, and the template lookup
     * had no fallback at all. So the unit of inheritance was the whole directory:
     * an application with `src/Views/services/logs.html.php` and no
     * `services.html.php` matched at the directory, failed at the template, and
     * the services list came back as a page shell. 200, no panel, one line in a
     * log nobody reads.
     *
     * That is the shape a project actually wants inverted: keep the three screens
     * you rewrote, inherit the other thirty-six — and get their fixes with the
     * next framework update rather than copying them again.
     *
     * Silent when there is nothing to find, so the caller's existing
     * "cannot find view template" path is unchanged.
     */
    private function scaffoldedTemplate(string $tpl, string $type): ?string
    {
        $app  = \Pramnos\Application\Application::currentInstance();
        $info = is_array($app?->applicationInfo) ? $app->applicationInfo : [];

        $theme = \Pramnos\Application\ScaffoldingHelper::getScaffoldTheme($info);
        $dirs  = $theme !== null
            ? [\Pramnos\Application\ScaffoldingHelper::getThemeDir($theme)]
            : \Pramnos\Application\ScaffoldingHelper::getAvailableThemeDirs();

        foreach ($dirs as $dir) {
            $candidate = $dir . DS . 'views' . DS . $this->name . DS
                . $tpl . '.' . $type . '.php';
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function getTpl($tpl='', $type='', $render=false)
    {
        $doc = \Pramnos\Framework\Factory::getDocument();
        if ($tpl === '') {
            $tpl = $this->name;
        }
        if ($type === '') {
            $type = $this->type;
        }
        $_url = URL . $this->controllerName . '/';
        $model=$this->model;

        // Consume output-cache settings (one-shot: reset after reading so that
        // a second getTpl() call on the same view object is uncached by default).
        $cacheTtl = $this->_cacheTtl;
        $cacheKey = $this->_cacheKey
            ?? 'view::' . $this->name . '::' . $tpl . '::' . ($type !== '' ? $type : $this->type);
        $this->_cacheTtl = null;
        $this->_cacheKey = null;

        // Output-cache read: serve from cache when available.
        if ($cacheTtl !== null) {
            try {
                $cacheInst  = \Pramnos\Cache\Cache::getInstance('views');
                $cachedData = $cacheInst->load($cacheKey, 'views', $cacheTtl);
                if ($cachedData !== false && $cachedData !== null) {
                    try {
                        $vc = \Pramnos\Debug\DebugBar::getInstance()->getCollector('views');
                        if ($vc instanceof \Pramnos\Debug\Collectors\ViewsCollector) {
                            $vc->record($this->name, $tpl . '.' . $type . '.php', 0.0, true);
                        }
                    } catch (\Throwable) {
            // A view helper that cannot resolve is not a reason to blank the page:
                // the surrounding template renders without it.
        }
                    if ($render) {
                        return (string) $cachedData;
                    }
                    $this->output .= (string) $cachedData;
                    return true;
                }
            } catch (\Throwable $ignored) {
                // Cache unavailable — render normally.
                $cacheTtl = null;
            }
        }

        $tplfile = $this->templateDirectory()
            . DS . $tpl . '.' . $type . '.php';

        if (is_object($doc->themeObject)
            && $doc->themeObject->allowsViewOverrides()) {
            $viewTplFile=$doc->themeObject->fullpath . DS . 'views' . DS
                . $this->name . DS . $tpl
                . '.' . $type . '.php';
            if (file_exists($viewTplFile)) {
                $tplfile = $viewTplFile;
            }
        }

        if (!file_exists($tplfile)) {
            $scaffolded = $this->scaffoldedTemplate($tpl, $type);
            if ($scaffolded !== null) {
                $tplfile = $scaffolded;
            }
        }

        if (file_exists($tplfile)) {
            // Reset template-engine state for this render cycle so that
            // consecutive getTpl() calls don't bleed sections into each other.
            $this->_layout      = null;
            $this->sections     = [];
            $this->sectionStack = [];

            $_pdb_view_start = microtime(true);
            ob_start();
            try {
                $lang  = \Pramnos\Framework\Factory::getLanguage();
                $model = $this->model;
                include $this->getIncludePath($tplfile);
            } catch (\Exception $ex) {
                ob_end_clean();
                try {
                    $ec = \Pramnos\Debug\DebugBar::getInstance()->getCollector('exceptions');
                    if ($ec instanceof \Pramnos\Debug\Collectors\ExceptionsCollector) {
                        $ec->record($ex);
                    }
                } catch (\Throwable) {
            // A view helper that cannot resolve is not a reason to blank the page:
                // the surrounding template renders without it.
        }
                \Pramnos\Logs\Logger::log(
                    'Error in view: ' . $this->name . ' and template file: '
                    . $tplfile . '. ' . $ex->getMessage()
                    . ' at line ' . $ex->getLine()
                );
                throw new \Exception(
                    'Error rendering template file. '
                    . 'View: ' . $this->name . ' and template file: '
                    . $tplfile . '. ' . $ex->getMessage()
                    . ' at line ' . $ex->getLine()
                );
            }
            $childOutput = (string) ob_get_clean();

            // Layout resolution: if the template called $this->layout(...),
            // render the layout file with the sections populated.
            if ($this->_layout !== null) {
                $layoutFile = $this->resolveTemplatePath($this->_layout);
                if ($layoutFile === null) {
                    // Silence here is the worst failure in the whole view layer: the
                    // child renders alone, so the page comes back 200 with no <head>,
                    // no layout, and nothing in any log. It looks like a CSS problem.
                    \Pramnos\Logs\Logger::log(
                        'Layout not found: ' . $this->_layout
                        . ' (searched from ' . $this->path . '). The view was '
                        . 'rendered without it.'
                    );
                }
                if ($layoutFile !== null) {
                    ob_start();
                    try {
                        $lang  = \Pramnos\Framework\Factory::getLanguage();
                        $model = $this->model;
                        include $this->getIncludePath($layoutFile);
                    } catch (\Exception $ex) {
                        ob_end_clean();
                        \Pramnos\Logs\Logger::log(
                            'Error in layout: ' . $this->_layout . '. '
                            . $ex->getMessage() . ' at line ' . $ex->getLine()
                        );
                        throw new \Exception(
                            'Error rendering layout: ' . $this->_layout . '. '
                            . $ex->getMessage() . ' at line ' . $ex->getLine()
                        );
                    }
                    $childOutput = (string) ob_get_clean();
                }
            }

            // The template's path, in an HTML comment, in the response — useful
            // while building a page and a disclosure once the page is public. It was
            // emitted unconditionally, so every server-rendered page told anybody
            // reading the source where the application's files live, and search
            // engines indexed it along with everything else. Debug mode only.
            $tplInformation = '';
            if ($this->type == 'html' && $this->inDebugMode()) {
                $tplInformation = "\n<!-- \n"
                    . "View Rendered at: "
                    . date('d/m/Y H:i:s')
                    . "\nView Path: "
                    . str_replace(ROOT, '', $tplfile)
                    . "\n-->";
            }
            $finalOutput = $childOutput . $tplInformation;

            // Record in DebugBar ViewsCollector.
            try {
                $vc = \Pramnos\Debug\DebugBar::getInstance()->getCollector('views');
                if ($vc instanceof \Pramnos\Debug\Collectors\ViewsCollector) {
                    $vc->record($this->name, $tplfile, (microtime(true) - $_pdb_view_start) * 1000);
                }
            } catch (\Throwable) {
            // A view helper that cannot resolve is not a reason to blank the page:
                // the surrounding template renders without it.
        }

            // Output-cache write: store rendered result for subsequent requests.
            if ($cacheTtl !== null) {
                try {
                    $cacheInst = \Pramnos\Cache\Cache::getInstance('views');
                    $cacheInst->save($finalOutput, $cacheKey);
                } catch (\Throwable $ignored) {
                    // Cache save failure is non-fatal.
                }
            }

            if ($render == true){
                return $finalOutput;
            }
            $this->output .= $finalOutput;
            return true;
        } else {
            if (\Pramnos\Http\Request::staticGet(
                'format', '', 'get'
            ) == 'json') {
                // **`is_object()`, not `isset()`.** The property defaults to `false`
                // and `isset()` answers "not null" rather than "not empty", so the
                // guard passed for every view that has no model — and
                // `method_exists(false, …)` is a TypeError on PHP 8.
                //
                // Which made this a fatal in the branch that exists to *recover* from
                // a missing template: the code a few lines below logs "Cannot find view
                // template", so the handler for a missing template was taking the page
                // down instead. Reported from a consuming application's home page, with
                // the stack trace out of its php_error.log.
                //
                // The default stays `false` rather than becoming `null`: `isset()` would
                // then work, but anything comparing `=== false` would change meaning,
                // and this guard wants to ask "have I got an object" either way.
                if (is_object($this->model)
                    && method_exists($this->model, 'getJsonList')) {
                    $this->output = $this->model->getJsonList();
                    return true;
                }
            }
            if ($this->type != 'raw' && $this->type != 'json') {
                \Pramnos\Logs\Logger::log(
                    'Cannot find view template. View:'
                    . $this->name . ', template: '
                    . $tpl . ", type: " . $this->type . "\n"
                    . \Pramnos\General\Helpers::varDumpToString(debug_backtrace())
                );
            }
            return false;
        }
    }


}
