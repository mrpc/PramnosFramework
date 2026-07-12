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

        parent::__construct();
    }

    /**
     * Adds a model to the view
     * @param \Pramnos\Application\Model $model
     * @param boolean $default Is this model the main used for this view?
     */
    public function addModel(\Pramnos\Application\Model &$model, $default=true)
    {
        if (is_object($model)){
            $this->models[$model->name] = $model;
            if ($default !== false) {
                $this->defaultModel = $model->name;
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
        if ($model === ''){
            $model = $this->defaultModel;
        }
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
        include $includeFile;
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

        $tplfile = $this->path
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

            $tplInformation = '';
            if ($this->type == 'html') {
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
                if (isset($this->model)){
                    if (method_exists($this->model, 'getJsonList')){
                        $this->output = $this->model->getJsonList();
                        return true;
                    }
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
