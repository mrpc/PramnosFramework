<?php

declare(strict_types=1);

namespace Pramnos\DevPanel;

use Pramnos\Application\Controller;
use Pramnos\Application\FeatureRegistry;
use Pramnos\Framework\GitInfo;
use Pramnos\Application\Settings;

/**
 * Developer / Admin Dashboard controller.
 *
 * Activated only when the 'devpanel' feature is enabled in app.php.
 * All actions require an authenticated admin user (configurable minimum
 * usertype, default 90).
 *
 * Actions:
 *   display()     — Overview: DB, PHP, memory, git, migration status, queue stats
 *   db()          — Database panel: sizes, connections, cache hit ratio, TimescaleDB
 *   cache()       — Cache browser: stats, paginated item list, flush action
 *   users()       — User activity: active sessions, login security monitor
 *   performance() — Performance report: slowest endpoints and users
 *   git()         — Git info: full branch/commit details
 *   phpinfo()     — PHP Info page (admin-only phpinfo() wrapper)
 *
 * The controller outputs a self-contained HTML page and exits — it does not
 * depend on the application's theme or document system.  This guarantees the
 * panel looks identical across all host applications.
 *
 */
class DevPanelController extends Controller
{
    /** Minimum usertype required to access any DevPanel action. */
    protected int $minUserType = 90;

    /** Optional policy callback — when set, replaces the usertype check. */
    protected ?\Closure $policyCallback = null;

    /**
     * Sections of the current page that could not be loaded, keyed by name.
     *
     * @var array<string, string> section => error message
     */
    private array $panelErrors = [];

    /**
     * Registered pluggable panels.
     *
     * @var array<string, array{label: string, renderer: callable}>
     */
    private static array $customPanels = [];

    /**
     * Registers a custom panel tab.
     *
     * ```php
     * DevPanelController::registerPanel('myapp', 'My App', function(): string {
     *     return '<p>Custom panel content.</p>';
     * });
     * ```
     *
     * @param string   $slug     URL-safe identifier (used as action name).
     * @param string   $label    Tab label shown in the navigation bar.
     * @param callable $renderer Returns the HTML string for the panel body.
     */
    public static function registerPanel(string $slug, string $label, callable $renderer): void
    {
        static::$customPanels[$slug] = ['label' => $label, 'renderer' => $renderer];
    }

    /**
     * Returns all registered custom panels (for testing and inspection).
     *
     * @return array<string, array{label: string, renderer: callable}>
     */
    public static function getCustomPanels(): array
    {
        return static::$customPanels;
    }

    /**
     * Resets the custom panel registry.  For tests only.
     */
    public static function resetCustomPanels(): void
    {
        static::$customPanels = [];
    }

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        /*
         * What `Controller::exec()` will dispatch.
         *
         * Deliberately *not* derived from `tabs()`, because the two lists mean different
         * things: `adminer` is a tab and not an action, `overview` is a tab whose action is
         * called `display`, and `logs` is an action with no tab — it is the debug toolbar's
         * own JSON endpoint. A test asserts the two agree where they should, which is the
         * only part worth automating: a tab with no action is a 404 in the navigation.
         *
         * `logs` is dispatched like the rest but guards itself: it accepts a signed debug
         * grant as well as an admin user. See logs().
         */
        $this->addAuthAction([
            'display', 'db', 'cache', 'users', 'performance', 'git', 'mcp', 'phpinfo', 'logs',
        ]);

        // Register custom panel slugs as auth actions so Controller::exec() dispatches them.
        foreach (array_keys(static::$customPanels) as $slug) {
            $this->addAuthAction($slug);
        }

        parent::__construct($application);

        $min = (int) static::config('min_usertype', 0);

        if ($min > 0) {
            $this->minUserType = $min;
        }
    }

    /**
     * One of this panel's own settings, from `app/app.php`.
     *
     * ```php
     * 'devpanel' => ['mount' => 'devpanel', 'min_usertype' => 90],
     * ```
     *
     * From the config and nowhere else. Where this panel is mounted and who may open it are
     * properties of the deployment — versioned with the code, next to the line that enables
     * the feature — not rows on an administration screen, which is where they used to live
     * next to a checkbox that opened the panel itself: three editable answers to "may this
     * browser browse the database", on a live server, leaving no trace in the repository.
     *
     * A `devpanel.min_usertype` / `devpanel.mount` row in the settings table is no longer
     * read. It was never reachable from the screen anyway: PHP replaces the `.` in a field
     * name with `_`, so both inputs posted under a name the controller never asked for, and
     * every save wrote the default back. An installation that thought it had set one had not.
     *
     * @param  string $key     `mount` or `min_usertype`
     * @param  mixed  $default Used when the config says nothing
     * @return mixed
     */
    public static function config(string $key, $default = null)
    {
        $configured = \Pramnos\Application\Application::currentInstance()
            ->applicationInfo['devpanel'][$key] ?? null;

        return ($configured === null || $configured === '') ? $default : $configured;
    }

    /**
     * Dispatches calls to registered custom panel slugs.
     *
     * Controller::exec() calls $this->$action($args). Custom panel slugs are not
     * real methods, so PHP routes them here. We look up the renderer in the static
     * registry and output it through renderLayout().
     */
    public function __call(string $name, array $args): mixed
    {
        if (isset(static::$customPanels[$name])) {
            if ($this->guardAccess()) {
                return null;
            }
            $panel   = static::$customPanels[$name];
            $content = (string) ($panel['renderer'])();
            $this->renderLayout($name, $content);
        return null;
        }
        return null;
    }

    // =========================================================================
    // Actions
    // =========================================================================

    /**
     * Overview tab — DB type/version, PHP, memory, git, migration status,
     * queue stats, uptime, last deploy.
     */
    public function display(): mixed
    {
        if ($this->guardAccess()) {
            return null;
        }

        $this->renderLayout('overview', $this->renderOverview());
        return null;
    }

    /**
     * Database panel — cross-DB sizes, connection counts, cache hit ratio.
     * TimescaleDB sub-panel if the extension is present.
     */
    public function db(): mixed
    {
        if ($this->guardAccess()) {
            return null;
        }

        $this->renderLayout('db', $this->renderDb());
        return null;
    }

    /**
     * Cache browser — stats, paginated item list, AJAX flush.
     */
    public function cache(): mixed
    {
        if ($this->guardAccess()) {
            return null;
        }

        // GET: item inspector (AJAX endpoint)
        if (isset($_GET['key'])) {
            $this->handleCacheItemInspect();
        }

        // POST: flush cache (AJAX endpoint)
        if (isset($_POST['action']) && $_POST['action'] === 'flush') {
            $this->handleCacheFlush();
        }

        $this->renderLayout('cache', $this->renderCache());
        return null;
    }

    /**
     * MCP — every registered tool, callable from a form, with the answer on the page.
     *
     * The server speaks JSON-RPC on stdio and blocks on STDIN, so there was no way to look
     * at it. `mcp:call` fixed that for a terminal; this is the same thing for somebody who
     * is already in the panel, and it adds what a terminal cannot show conveniently: the
     * schema as a form, so a tool's arguments are discovered rather than guessed.
     *
     * The POST branch is an AJAX endpoint rather than a page reload, because the useful
     * motion here is call, adjust one argument, call again.
     */
    public function mcp(): mixed
    {
        if ($this->guardAccess()) {
            return null;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->handleMcpCall();
        }

        $this->renderLayout('mcp', $this->renderMcp());
        return null;
    }

    /**
     * User activity — active sessions, token audit, login security monitor.
     */
    public function users(): mixed
    {
        if ($this->guardAccess()) {
            return null;
        }

        $this->renderLayout('users', $this->renderUsers());
        return null;
    }

    /**
     * Performance report — slowest endpoints and users.
     */
    public function performance(): mixed
    {
        if ($this->guardAccess()) {
            return null;
        }

        $this->renderLayout('performance', $this->renderPerformance());
        return null;
    }

    /**
     * Git info — full branch, commit history, remotes.
     */
    public function git(): mixed
    {
        if ($this->guardAccess()) {
            return null;
        }

        $this->renderLayout('git', $this->renderGit());
        return null;
    }

    /**
     * PHP Info — admin-only phpinfo() output.
     */
    public function phpinfo(): mixed
    {
        if ($this->guardAccess()) {
            return null;
        }

        ob_start();
        \phpinfo();
        $phpInfoRaw = ob_get_clean();

        // Strip doctype/html/head/body wrappers — only keep the inner content
        $phpInfoRaw = preg_replace('/^.*<body>/si', '', $phpInfoRaw);
        $phpInfoRaw = preg_replace('/<\/body>.*$/si', '', $phpInfoRaw);

        $this->renderLayout('phpinfo', '<div class="phpinfo-wrapper">' . $phpInfoRaw . '</div>');
        return null;
    }

    /**
     * The log lines one request wrote — JSON, for the debug toolbar.
     *
     * `GET /devpanel/logs?request=<id>`
     *
     * Everything else the toolbar knows travels with the response it describes,
     * which is why it needs no endpoint at all. This exists for the one case
     * that cannot: a request that died. An error page is not a JSON object, so
     * it cannot carry a `_debug` key, and the header that still gets through has
     * room for a count but never for a message. The lines are on disk, named
     * with the request's id, and this hands back the ones carrying it.
     *
     * **Guarded differently from the rest of the panel, on purpose.** The other
     * actions require an admin user (`usertype >= 90`). The person who opened
     * the toolbar on a live server with `debug:token` is a developer holding a
     * signed grant, and usually not an admin user at all — so the grant is
     * accepted here, and the admin path is kept for someone browsing the panel
     * normally. Either way the feature must be enabled and the application in
     * debug mode: {@see guardAccess()} still decides that.
     *
     * The reply is `no-store`: it contains one request's log lines, and a shared
     * cache in front of the application does not know who asked.
     *
     * @return mixed Always null — the response is written directly
     */
    public function logs(): mixed
    {
        if (!FeatureRegistry::isEnabled('devpanel')) {
            $this->renderError(404, 'DevPanel feature is not enabled.');
        }

        // A signed grant is enough; otherwise fall back to the panel's own
        // admin check, which also enforces dev mode.
        if (!\Pramnos\Debug\DebugAccess::isGranted() && $this->guardAccess()) {
            return null;
        }

        $request = new \Pramnos\Http\Request();
        $id      = (string) $request->get('request', '', 'get');

        if (!\Pramnos\Debug\RequestLog::isValidId($id)) {
            return $this->sendJson(
                ['error' => 'A valid request id is required.'],
                400
            );
        }

        $lines = \Pramnos\Debug\RequestLog::forRequest($id);

        return $this->sendJson([
            'request' => $id,
            'count'   => count($lines),
            'lines'   => $lines,
        ]);
    }

    /**
     * Write a JSON reply for the toolbar and stop.
     *
     * @param  array<string, mixed> $data
     * @param  int                  $status
     * @return null
     */
    private function sendJson(array $data, int $status = 200): mixed
    {
        \Pramnos\Framework\Factory::getDocument('json');

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8');
            // One request's log lines, fetched by whoever holds the grant. A
            // shared cache must never serve this to the next person asking for
            // the same URL.
            header('Cache-Control: no-store, private, max-age=0');
        }

        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        // Stop, like every other output path in this controller.
        //
        // `renderLayout()` and `renderError()` both echo and then `terminate()`. This method
        // echoed and **returned null**, which is the same contract with the ending left off —
        // and it was the only outlier in the file.
        //
        // Reported from a consuming application: `/devpanel/logs?request=…` printed its JSON
        // and then the application went on to render a page, because a `null` return told its
        // dispatcher nothing had been produced. The application's own `$output` property is
        // magic — `Base::__get()` answers null for anything unset — and `null !== ''`, so its
        // "did a controller produce output?" guard passed with a null in hand: two
        // `stripos(): Passing null` deprecations, then a fatal on a `string` parameter, printed
        // *after* a perfectly good JSON body.
        //
        // Returning a `Response` was the other candidate and is worse here: that application
        // routes a non-API, non-HTML body through its theme, so the JSON would have come back
        // wrapped in a page. A JSON reply to an XHR is finished when it has been written.
        $this->terminate();

        return null;
    }

    // =========================================================================
    // Panel renderers
    // =========================================================================

    private function renderOverview(): string
    {
        $db        = \Pramnos\Framework\Factory::getDatabase();
        $dbType    = $db ? ucfirst($db->type ?? 'unknown') : 'Not connected';
        $dbVersion = 'unknown';
        if ($db && $db->connected) {
            try {
                $res = $db->execute("SELECT VERSION() AS v");
                $dbVersion = $res ? $res->fields['v'] ?? 'unknown' : 'unknown';
            } catch (\Throwable) {
                $dbVersion = 'error';
            }
        }

        $phpVersion = PHP_VERSION;
        $frameworkVersion = defined('FRAMEWORK_VERSION') ? FRAMEWORK_VERSION : '1.2';
        $memPeak    = $this->humanBytes(memory_get_peak_usage(true));
        $memCurrent = $this->humanBytes(memory_get_usage(true));

        // System uptime from /proc/uptime (Linux)
        $uptime = $this->readProcUptime();

        // Load average
        $load = $this->readProcLoadAvg();

        // RAM from /proc/meminfo
        [$ramTotal, $ramFree, $ramUsed] = $this->readProcMemInfo();

        // Git info
        $git     = new GitInfo($this->detectRepoRoot());
        $branch  = htmlspecialchars($git->getBranch());
        $hash    = htmlspecialchars($git->getShortHash());
        $subject = htmlspecialchars($git->getSubject());
        $author  = htmlspecialchars($git->getAuthor());
        $date    = $git->getDate() ? date('Y-m-d H:i', $git->getDate()) : '—';

        // Migration status
        [$migrPending, $migrApplied, $migrLast] = $this->fetchMigrationStatus();

        // Queue stats
        [$queuePending, $queueRunning, $queueFailed] = $this->fetchQueueStats();

        // Background work: what is buffered, and what is supposed to be draining it.
        [$spoolPending, $spoolDriver, $scheduledTasks] = $this->fetchBackgroundWork();

        $h  = '<div class="grid-2">';

        // ── System card ──────────────────────────────────────────────────────
        $h .= $this->card('System Info', <<<HTML
            <table class="info-table">
                <tr><th>PHP</th><td>{$phpVersion}</td></tr>
                <tr><th>Framework</th><td>PramnosFramework v{$frameworkVersion}</td></tr>
                <tr><th>Peak memory</th><td>{$memPeak}</td></tr>
                <tr><th>Current memory</th><td>{$memCurrent}</td></tr>
                <tr><th>Uptime</th><td>{$uptime}</td></tr>
                <tr><th>Load (1m/5m/15m)</th><td>{$load}</td></tr>
                <tr><th>RAM total</th><td>{$ramTotal}</td></tr>
                <tr><th>RAM used</th><td>{$ramUsed}</td></tr>
                <tr><th>RAM free</th><td>{$ramFree}</td></tr>
            </table>
        HTML);

        // ── Database card ────────────────────────────────────────────────────
        $h .= $this->card('Database', <<<HTML
            <table class="info-table">
                <tr><th>Driver</th><td>{$dbType}</td></tr>
                <tr><th>Version</th><td>{$dbVersion}</td></tr>
            </table>
        HTML);

        // ── Git card ─────────────────────────────────────────────────────────
        $h .= $this->card('Git', <<<HTML
            <table class="info-table">
                <tr><th>Branch</th><td><code>{$branch}</code></td></tr>
                <tr><th>Commit</th><td><code>{$hash}</code></td></tr>
                <tr><th>Subject</th><td>{$subject}</td></tr>
                <tr><th>Author</th><td>{$author}</td></tr>
                <tr><th>Date</th><td>{$date}</td></tr>
            </table>
        HTML);

        // ── Migrations card ──────────────────────────────────────────────────
        $h .= $this->card('Migrations', <<<HTML
            <table class="info-table">
                <tr><th>Applied</th><td>{$migrApplied}</td></tr>
                <tr><th>Pending</th><td><span class="{$this->statusClass($migrPending > 0)}">{$migrPending}</span></td></tr>
                <tr><th>Last applied</th><td>{$migrLast}</td></tr>
            </table>
        HTML);

        // ── Background work card ─────────────────────────────────────────────
        //
        // Rows buffered out of the request path are invisible until something
        // drains them, and nothing says so: an installation whose `schedule:run`
        // is not wired to a cron or a daemon accumulates them for ever while every
        // panel that reads the drained table shows "no data". That happened here —
        // 17 requests in a spool file, a Performance tab that had never had a row,
        // and no way to tell those two facts were the same fact.
        $spoolClass = $this->statusClass($spoolPending > 0);
        $h .= $this->card('Background Work', <<<HTML
            <table class="info-table">
                <tr><th>Write spool</th><td><span class="{$spoolClass}">{$spoolPending}</span> pending ({$spoolDriver})</td></tr>
                <tr><th>Scheduled tasks</th><td>{$scheduledTasks}</td></tr>
            </table>
            <p style="margin-top:.5rem;font-size:.85em;opacity:.8">
                Scheduled tasks run only when <code>schedule:run</code> is executed —
                from cron, or from a supervised daemon.
            </p>
        HTML);

        // ── Queue card ───────────────────────────────────────────────────────
        if (FeatureRegistry::isEnabled('queue')) {
            $failClass = $this->statusClass($queueFailed > 0);
            $h .= $this->card('Queue', <<<HTML
                <table class="info-table">
                    <tr><th>Pending</th><td>{$queuePending}</td></tr>
                    <tr><th>Running</th><td>{$queueRunning}</td></tr>
                    <tr><th>Failed</th><td><span class="{$failClass}">{$queueFailed}</span></td></tr>
                </table>
            HTML);
        }

        $h .= '</div>';
        return $h;
    }

    private function renderDb(): string
    {
        $db = \Pramnos\Framework\Factory::getDatabase();
        if (!$db || !$db->connected) {
            return $this->alert('Database not connected.', 'warning');
        }

        $isPostgres    = $db->type === 'postgresql';
        $isTimescaleDb = false;
        $tables        = [];

        // Table sizes
        try {
            if ($isPostgres) {
                // Every `oid` is qualified, and the row count comes from
                // pg_stat_user_tables. Unqualified, `oid` is ambiguous across the
                // join — both pg_class and pg_namespace have one — so PostgreSQL
                // refused the whole statement with "column reference oid is
                // ambiguous", `execute()` returned false, and the table list has
                // been empty on every PostgreSQL installation since it was
                // written. `n_live_tup` is not a pg_class column at all.
                $res = $db->execute(
                    "SELECT c.relname AS tbl,
                            pg_size_pretty(pg_total_relation_size(c.oid)) AS total,
                            pg_size_pretty(pg_relation_size(c.oid)) AS data,
                            COALESCE(s.n_live_tup, 0) AS rows
                     FROM pg_class c
                     JOIN pg_namespace n ON n.oid = c.relnamespace
                     LEFT JOIN pg_stat_user_tables s ON s.relid = c.oid
                     WHERE c.relkind = 'r' AND n.nspname = 'public'
                     ORDER BY pg_total_relation_size(c.oid) DESC
                     LIMIT 30"
                );
                $tables = $res ? $res->fetchAll() : [];
            } else {
                $dbName = $db->execute('SELECT DATABASE() AS d');
                $dbName = $dbName ? ($dbName->fields['d'] ?? '') : '';
                // `%s`, not `?`: the framework's prepared statements use typed
                // placeholders and count them itself, so a `?` was left in the SQL
                // as a literal while the argument went to bind_param with an empty
                // type string.
                $res    = $db->execute(
                    "SELECT table_name AS tbl,
                            ROUND((data_length + index_length) / 1024, 1) AS total,
                            ROUND(data_length / 1024, 1) AS data,
                            table_rows AS rows
                     FROM information_schema.tables
                     WHERE table_schema = %s
                     ORDER BY (data_length + index_length) DESC
                     LIMIT 30",
                    $dbName
                );
                $tables = $res ? $res->fetchAll() : [];
            }
        } catch (\Throwable $e) {
            return $this->alert('Error querying table stats: ' . htmlspecialchars($e->getMessage()), 'error');
        }

        // TimescaleDB detection
        try {
            $tsRes = $db->execute("SELECT extversion FROM pg_extension WHERE extname = 'timescaledb'");
            if ($tsRes && $tsRes->numRows > 0) {
                $isTimescaleDb = true;
            }
        } catch (\Throwable $ex) {
            $this->panelError('TimescaleDB detection', $ex);
        }

        // PostgreSQL returns pre-formatted sizes from pg_size_pretty ("552 kB"),
        // MySQL returns a number of kilobytes. Only the latter needs a unit, and
        // appending one to both produced "552 kB KB".
        $unit    = $isPostgres ? '' : ' KB';

        $rows = '';
        foreach ($tables as $t) {
            // The name links into Adminer's view of that table, when there is an Adminer to link
            // to. This list answers "what is in here and how big is it"; the next question is
            // always about one table, and it used to mean retyping the name somewhere else.
            $tbl  = static::adminerTableLink((string) ($t['tbl'] ?? ''));
            $tot  = htmlspecialchars((string) ($t['total'] ?? ''));
            $data = htmlspecialchars((string) ($t['data']  ?? ''));
            $rowc = number_format((int) ($t['rows'] ?? 0));
            $rows .= "<tr><td>{$tbl}</td><td class='num'>{$rowc}</td><td class='num'>{$data}{$unit}</td><td class='num'>{$tot}{$unit}</td></tr>";
        }
        $content = <<<HTML
            <h3>Tables (top 30 by size)</h3>
            <table class="data-table">
                <thead><tr><th>Table</th><th class="num">Rows</th><th class="num">Data{$unit}</th><th class="num">Total{$unit}</th></tr></thead>
                <tbody>{$rows}</tbody>
            </table>
        HTML;

        if ($isTimescaleDb) {
            $content .= $this->renderTimescaleDb($db);
        }

        // No "Open Adminer" line: it is a tab of its own now, immediately beside this one, and
        // a link to the neighbouring tab is furniture. The table names below link into it, which
        // is the useful half — this tab answers "what is in here and how big is it", and the
        // next question is always about one specific table.
        return $content;
    }

    /**
     * A link into Adminer for one table, or the plain name when there is no Adminer.
     *
     * The table list answers "what is in here and how big is it"; the next question is always
     * about one specific table, and until this it had to be retyped somewhere else. Nothing is
     * drawn as a link when the package is absent or the visitor would be refused — a link that
     * 404s reads as a broken panel rather than an absent tool.
     */
    public static function adminerTableLink(string $table): string
    {
        $escaped = htmlspecialchars($table, ENT_QUOTES);
        $base    = static::adminerTabUrl();

        if ($base === null || $table === '') {
            return $escaped;
        }

        $connection = \Pramnos\DevPanel\AdminerBridge::chosen();

        if ($connection === []) {
            return $escaped;
        }

        $url = $base . '?' . \Pramnos\DevPanel\AdminerBridge::query($connection)
            . '&table=' . urlencode($table);

        return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . $escaped . '</a>';
    }

    private function renderTimescaleDb(\Pramnos\Database\Database $db): string
    {
        try {
            $res = $db->execute(
                "SELECT hypertable_name, num_chunks, compression_enabled
                 FROM timescaledb_information.hypertables ORDER BY hypertable_name"
            );
            $hypertables = $res ? $res->fetchAll() : [];
        } catch (\Throwable $e) {
            return $this->alert('TimescaleDB query error: ' . htmlspecialchars($e->getMessage()), 'warning');
        }

        $rows = '';
        foreach ($hypertables as $h) {
            $name   = htmlspecialchars($h['hypertable_name'] ?? '');
            $chunks = (int) ($h['num_chunks'] ?? 0);
            $comp   = ($h['compression_enabled'] ?? false) ? '<span class="badge ok">on</span>' : '<span class="badge">off</span>';
            $rows  .= "<tr><td>{$name}</td><td class='num'>{$chunks}</td><td>{$comp}</td></tr>";
        }

        return <<<HTML
            <h3>TimescaleDB Hypertables</h3>
            <table class="data-table">
                <thead><tr><th>Hypertable</th><th class="num">Chunks</th><th>Compression</th></tr></thead>
                <tbody>{$rows}</tbody>
            </table>
        HTML;
    }

    private function renderCache(): string
    {
        if (!FeatureRegistry::isEnabled('cache')) {
            return $this->alert('Cache feature is not enabled.', 'warning');
        }

        try {
            $cache  = \Pramnos\Cache\Cache::getInstance();
            $method = htmlspecialchars($cache->method);
            $stats  = $cache->getStats();
        } catch (\Throwable) {
            return $this->alert('Cache system not available.', 'warning');
        }

        // What the application asked for, beside what it got. They differ for two
        // reasons and both are worth seeing here rather than in a log: the store
        // was unreachable and the chain walked down, or nothing was configured at
        // all and the default answered. An installation running Redis and caching
        // to disk looked exactly like an installation configured for disk.
        $configured = Settings::getSetting('cache');
        $configuredMethod = '';
        if (is_array($configured) && isset($configured['method'])) {
            $configuredMethod = (string) $configured['method'];
        } elseif (is_object($configured) && isset($configured->method)) {
            $configuredMethod = (string) $configured->method;
        }

        $requested = $configuredMethod !== ''
            ? htmlspecialchars($configuredMethod)
            : '<em>not configured — defaulted to '
              . htmlspecialchars($cache->requestedMethod) . '</em>';

        $fellBack = $cache->method !== $cache->requestedMethod
            ? '<span class="badge warn">fell back from ' . htmlspecialchars($cache->requestedMethod) . '</span>'
            : '';

        // Namespace filter from GET parameter
        $ns = isset($_GET['ns']) ? (string) $_GET['ns'] : '';

        // Categories and items
        $categories = $cache->getCategories();
        $items      = $cache->getAllItems($ns, 100);

        $flushButton = <<<HTML
            <form method="POST" data-cache-flush>
                <input type="hidden" name="action" value="flush">
                <button type="submit" class="btn-danger" data-confirm="Flush entire cache?">Flush All Cache</button>
            </form>
        HTML;

        $totalItems = (int) ($stats['items'] ?? 0);
        $totalCats  = (int) ($stats['categories'] ?? 0);

        // Namespace filter bar
        $nsLinks = "<a href='?action=cache' class='tab-link" . ($ns === '' ? ' active' : '') . "'>All</a>";
        foreach ($categories as $cat) {
            $catEnc  = htmlspecialchars(urlencode((string) $cat));
            $catDisp = htmlspecialchars((string) $cat);
            $active  = $ns === (string) $cat ? ' active' : '';
            $nsLinks .= "<a href='?action=cache&amp;ns={$catEnc}' class='tab-link{$active}'>{$catDisp}</a>";
        }

        // Item rows
        $itemRows = '';
        foreach ($items as $item) {
            $key     = htmlspecialchars((string) ($item['key'] ?? ''));
            $keyEnc  = htmlspecialchars(urlencode((string) ($item['key'] ?? '')));
            $nsDisp  = htmlspecialchars((string) ($item['namespace'] ?? $item['type'] ?? ''));
            $size    = number_format((int) ($item['size'] ?? 0));
            $ttl     = isset($item['ttl']) ? ((int) $item['ttl'] === -1 ? 'no-expiry' : (int) $item['ttl'] . ' s') : '—';
            if (isset($item['expired']) && $item['expired']) {
                $ttl = '<span style="color:var(--danger)">expired</span>';
            }
            $created = htmlspecialchars((string) ($item['created_time'] ?? '—'));
            $nsParam = $ns !== '' ? '&amp;ns=' . htmlspecialchars(urlencode($ns)) : '';
            $inspectBtn = "<button class='btn-inspect' data-key='{$keyEnc}' data-ns='" . htmlspecialchars(urlencode($ns)) . "' data-inspect style='padding:2px 8px;cursor:pointer;font-size:0.8em'>Inspect</button>";
            $itemRows .= "<tr><td><code style='font-size:0.85em'>{$key}</code></td><td>{$nsDisp}</td><td class='num'>{$size}</td><td>{$ttl}</td><td>{$created}</td><td>{$inspectBtn}</td></tr>";
        }

        $noItems = $itemRows === '' ? '<tr><td colspan="6" class="empty">No items found</td></tr>' : $itemRows;
        $nsFilter = !empty($categories) ? "<div class='range-bar' style='margin-bottom:1rem'>{$nsLinks}</div>" : '';
        $itemCount = count($items);
        $limitNote = $itemCount >= 100 ? ' <em>(showing first 100)</em>' : '';
        $cacheNonce = \Pramnos\Application\Application::currentInstance()?->cspNonce ?? '';
        $cacheNa    = $cacheNonce !== '' ? ' nonce="' . htmlspecialchars($cacheNonce, ENT_QUOTES) . '"' : '';

        return <<<HTML
            <div class="grid-2">
                {$this->card('Cache Status', <<<INNER
                    <table class="info-table">
                        <tr><th>Adapter</th><td>{$method} {$fellBack}</td></tr>
                        <tr><th>Configured</th><td>{$requested}</td></tr>
                        <tr><th>Total items</th><td>{$totalItems}</td></tr>
                        <tr><th>Namespaces</th><td>{$totalCats}</td></tr>
                    </table>
                    {$flushButton}
                INNER)}
            </div>
            <h3 style="margin-top:1.5rem">Item Browser{$limitNote}</h3>
            {$nsFilter}
            <table class="data-table">
                <thead><tr><th>Key</th><th>Type / NS</th><th class="num">Size (B)</th><th>TTL</th><th>Created</th><th></th></tr></thead>
                <tbody>{$noItems}</tbody>
            </table>
            <div id="inspect-modal" style="display:none;position:fixed;z-index:9999;left:50%;top:10%;transform:translateX(-50%);width:min(900px,90vw);max-height:75vh;overflow:auto;background:var(--bg-card);border:1px solid var(--border);border-radius:6px;padding:1rem;box-shadow:0 8px 32px rgba(0,0,0,.45)">
                <button type="button" id="inspect-close" style="float:right;padding:2px 8px;cursor:pointer">Close</button>
                <strong id="inspect-title">Item content</strong>
                <pre id="inspect-content" style="margin-top:0.5rem;max-height:60vh;overflow:auto;white-space:pre-wrap;word-break:break-all;font-size:0.8em"></pre>
            </div>
            <script{$cacheNa}>
            document.addEventListener('click', function(e) {
                if (e.target.closest('#inspect-close')) {
                    document.getElementById('inspect-modal').style.display = 'none';
                    return;
                }
                var btn = e.target.closest('[data-inspect]');
                if (!btn) return;
                var key = btn.dataset.key;
                var ns  = btn.dataset.ns;
                var url = '?action=cache&key=' + key + (ns ? '&ns=' + ns : '');
                // Fixed-position and scrolled to, because the panel used to be an
                // ordinary div under a table of up to 100 rows: it opened far below
                // the fold, and clicking Inspect looked like nothing happening.
                var modal = document.getElementById('inspect-modal');
                modal.style.display = 'block';
                modal.scrollIntoView({block: 'nearest'});
                document.getElementById('inspect-title').textContent = 'Loading …';
                document.getElementById('inspect-content').textContent = '';
                fetch(url, {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
                    .then(function(r){
                        // A session that has expired answers with the login page,
                        // and `r.json()` on HTML throws a parser error that says
                        // nothing about what happened.
                        return r.text().then(function(body){
                            try { return JSON.parse(body); }
                            catch (err) {
                                throw new Error('Unexpected response (' + r.status + '). Still signed in?');
                            }
                        });
                    })
                    .then(function(d){
                        document.getElementById('inspect-title').textContent = d.ok ? decodeURIComponent(key) : 'Error';
                        document.getElementById('inspect-content').textContent = d.ok ? (d.content || '(empty)') : (d.error || 'unknown error');
                    })
                    .catch(function(e){
                        document.getElementById('inspect-title').textContent = 'Error';
                        document.getElementById('inspect-content').textContent = String(e && e.message ? e.message : e);
                    });
            });

            // Flush cache via AJAX so the POST does not navigate to the JSON body.
            document.addEventListener('submit', function(e) {
                var form = e.target.closest('[data-cache-flush]');
                if (!form) return;
                e.preventDefault();
                var btn = form.querySelector('[data-confirm]');
                var msg = btn ? btn.getAttribute('data-confirm') : 'Are you sure?';
                if (!window.confirm(msg)) return;
                if (btn) { btn.disabled = true; }
                var body = new URLSearchParams(); body.set('action', 'flush');
                fetch(window.location.href, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                }).then(function(r){ return r.json(); }).then(function(d){
                    if (d && d.ok) { window.location.reload(); }
                    else { if (btn) { btn.disabled = false; } alert('Flush failed: ' + ((d && d.error) || 'unknown error')); }
                }).catch(function(err){ if (btn) { btn.disabled = false; } alert('Flush failed: ' + err); });
            });
            </script>
        HTML;
    }

    private function renderUsers(): string
    {
        $db = \Pramnos\Framework\Factory::getDatabase();
        if (!$db || !$db->connected) {
            return $this->alert('Database not connected.', 'warning');
        }

        // Sub-views: token detail and per-user log
        if (isset($_GET['token'])) {
            return $this->renderTokenDetail((int) $_GET['token']);
        }
        if (isset($_GET['user'])) {
            return $this->renderUserLog((int) $_GET['user']);
        }

        // Active sessions (tokens)
        //
        // This panel was empty on every installation, and nothing said why: it
        // queried a table called `tokens` (the framework's is `usertokens`),
        // columns `last_used` and `ip_address` (`lastused`, `ipaddress`), a
        // numeric `tokentype` in a column that holds text, and prefixed all of
        // it with a `PREFIX` constant this framework never defines. Every query
        // threw, and the empty catch below turned a broken panel into a blank
        // one.
        // How recently a session has to have been used to count as active.
        //
        // Validity alone is not enough. A `web_session` token is minted per login
        // and carries no expiry, so "not expired" means "for ever": one
        // installation had 342 of them, all for the same user, all technically
        // active and none of them a session anybody was in. A window is the only
        // thing that makes the word mean what the heading says.
        $windows = [1 => '1h', 6 => '6h', 24 => '24h', 168 => '7d', 720 => '30d', 0 => 'All'];
        // Validated as a string before the cast, because `(int) 'abc'` is 0 and 0
        // is a *valid* window here — the one meaning "no limit". Casting first
        // turned every malformed value into the widest possible answer, which is
        // the one setting this window exists to stop being the default.
        $hours = 24;
        if (isset($_GET['hours'])
            && ctype_digit((string) $_GET['hours'])
            && array_key_exists((int) $_GET['hours'], $windows)
        ) {
            $hours = (int) $_GET['hours'];
        }

        // What counts as active, taken from User::loadByToken(): status 1 and an
        // expiry that has not passed. Without it the panel listed every row the
        // table had ever held — "active" meaning only "never explicitly revoked".
        $now         = time();
        $usedSince   = $hours > 0 ? $now - ($hours * 3600) : 0;

        // A web session cannot outlive the PHP session it belongs to.
        //
        // `web_session` is accepted through `$_SESSION['usertoken']`, so once PHP
        // has expired the session — `session.gc_maxlifetime`, 24 minutes out of
        // the box — the row is unreachable by the browser that owns it, whatever
        // its own expiry says. Listing it as an active session is listing
        // something nobody can use: a login from this morning is not a session,
        // it is a row. API tokens have no such bound and keep the selected
        // window.
        $idleTimeout = (int) ini_get('session.gc_maxlifetime');
        if ($idleTimeout <= 0) {
            $idleTimeout = 1440;
        }
        $webSessionSince = $hours > 0 ? $now - $idleTimeout : 0;

        // The per-type bound, as one clause: a web session used inside the idle
        // timeout, or any other type inside the window.
        $withinItsLifetime = static function ($query) use ($webSessionSince, $usedSince) {
            $query->where(static function ($web) use ($webSessionSince) {
                $web->where('tokentype', \Pramnos\User\Token::TYPE_WEB_SESSION)
                    ->where('lastused', '>=', $webSessionSince);
            })->orWhere(static function ($api) use ($usedSince) {
                $api->where('tokentype', '!=', \Pramnos\User\Token::TYPE_WEB_SESSION)
                    ->where('lastused', '>=', $usedSince);
            });
        };
        $withinItsLifetimeAliased = static function ($query) use ($webSessionSince, $usedSince) {
            $query->where(static function ($web) use ($webSessionSince) {
                $web->where('t.tokentype', \Pramnos\User\Token::TYPE_WEB_SESSION)
                    ->where('t.lastused', '>=', $webSessionSince);
            })->orWhere(static function ($api) use ($usedSince) {
                $api->where('t.tokentype', '!=', \Pramnos\User\Token::TYPE_WEB_SESSION)
                    ->where('t.lastused', '>=', $usedSince);
            });
        };
        $sessionTypes = [
            \Pramnos\User\Token::TYPE_WEB_SESSION,
            \Pramnos\User\Token::TYPE_API,
            \Pramnos\User\Token::TYPE_ACCESS_TOKEN,
        ];
        $stillValid = static function ($query) use ($now) {
            $query->where('expires', 0)
                ->orWhere('expires', '>', $now)
                ->orWhereNull('expires');
        };

        // How many there are, against how many are listed. The list is capped at
        // 50 and said so nowhere, so a reader could not tell 50 sessions from
        // several hundred — and several hundred is itself the finding.
        $sessionCount = 0;
        $perUser      = [];
        try {
            $countQuery = $db->queryBuilder()
                ->table('#PREFIX#usertokens')
                ->where('status', 1)
                ->whereIn('tokentype', $sessionTypes)
                ->where($stillValid);
            if ($usedSince > 0) {
                $countQuery->where($withinItsLifetime);
            }
            $sessionCount = (int) $countQuery->count();

            $qb  = $db->queryBuilder();
            $res = $qb
                ->table('#PREFIX#usertokens AS t')
                ->select([
                    'u.username', 't.tokentype',
                    $qb->raw('COUNT(*) AS sessions'),
                    $qb->raw('MAX(t.lastused) AS lastused'),
                ])
                ->join('#PREFIX#users AS u', 'u.userid', '=', 't.userid')
                ->where('t.status', 1)
                ->whereIn('t.tokentype', $sessionTypes)
                ->where($stillValid)
                ->when($usedSince > 0, fn($q) => $q->where($withinItsLifetimeAliased))
                ->groupBy('u.username', 't.tokentype')
                ->orderBy('sessions', 'desc')
                ->limit(20)
                ->get();
            $perUser = $res ? $res->fetchAll() : [];
        } catch (\Throwable $ex) {
            $this->panelError('session summary', $ex);
        }

        $sessions = [];
        try {
            $res = $db->queryBuilder()
                // Prefixed, like every other table this panel reads: an
                // installation with a table prefix was querying names that do
                // not exist, and the panel reported it as no sessions.
                ->table('#PREFIX#usertokens AS t')
                ->select([
                    't.tokenid', 't.userid', 'u.username',
                    't.lastused', 't.ipaddress', 't.tokentype', 't.applicationid',
                ])
                ->join('#PREFIX#users AS u', 't.userid', '=', 'u.userid')
                ->where('t.status', 1)
                // The heading says "web + API" and the filter left the web out:
                // a browser login is a `web_session` token, which is the only type
                // an application that is not an API issues at all — so this panel
                // was empty on exactly the installations most likely to open it.
                // Taken from the Token constants rather than spelled out again,
                // because a new session type must not silently stop appearing here.
                ->whereIn('t.tokentype', $sessionTypes)
                ->where($stillValid)
                ->when($usedSince > 0, fn($q) => $q->where($withinItsLifetimeAliased))
                ->orderBy('t.lastused', 'desc')
                ->limit(50)
                ->get();
            $sessions = $res ? $res->fetchAll() : [];
        } catch (\Throwable $ex) {
            // A dev panel must never take the page down over one of its
            // sections — but it must say what it could not show.
            $this->panelError('active sessions', $ex);
        }

        // Active lockouts
        //
        // Empty for the same reason as the panel above: the table is
        // `authserver.loginlockouts`, and its columns are `displayvalue`,
        // `lastipaddress`, `lockoutuntil` and `failedattempts` — none of the
        // names this asked for.
        $lockouts = [];
        try {
            $res = $db->queryBuilder()
                ->table('authserver.loginlockouts')
                ->select(['displayvalue', 'lastipaddress', 'lockoutuntil', 'failedattempts'])
                ->where('lockoutuntil', '>', $db->queryBuilder()->raw('NOW()'))
                ->orderBy('lockoutuntil', 'desc')
                ->limit(20)
                ->get();
            $lockouts = $res ? $res->fetchAll() : [];
        } catch (\Throwable $ex) {
            $this->panelError('login lockouts', $ex);
        }

        $sessionRows = '';
        foreach ($sessions as $s) {
            $tid       = (int) ($s['tokenid'] ?? 0);
            $uid       = (int) ($s['userid'] ?? 0);
            $user      = htmlspecialchars($s['username'] ?? '');
            $app       = htmlspecialchars((string) ($s['applicationid'] ?? '—'));
            $ip        = htmlspecialchars($s['ipaddress'] ?? '—');
            $type      = htmlspecialchars((string) ($s['tokentype'] ?? '—'));
            $last      = $this->formatTimestamp($s['lastused'] ?? null);
            $tokenLink = "<a href='?action=users&amp;token={$tid}'>#{$tid}</a>";
            $userLink  = "<a href='?action=users&amp;user={$uid}'>{$user}</a>";
            $sessionRows .= "<tr><td>{$tokenLink}</td><td>{$userLink}</td><td>{$type}</td><td>{$ip}</td><td>{$app}</td><td>{$last}</td></tr>";
        }

        $lockoutRows = '';
        foreach ($lockouts as $l) {
            $id       = htmlspecialchars($l['displayvalue'] ?? '');
            $ip       = htmlspecialchars($l['lastipaddress'] ?? '—');
            $until    = htmlspecialchars((string) ($l['lockoutuntil'] ?? ''));
            $attempts = (int) ($l['failedattempts'] ?? 0);
            $lockoutRows .= "<tr><td>{$id}</td><td>{$ip}</td><td>{$attempts}</td><td>{$until}</td></tr>";
        }

        $summaryRows = '';
        foreach ($perUser as $row) {
            $who      = htmlspecialchars($row['username'] ?? '—');
            $type     = htmlspecialchars((string) ($row['tokentype'] ?? '—'));
            $count    = number_format((int) ($row['sessions'] ?? 0));
            $lastSeen = $this->formatTimestamp($row['lastused'] ?? null);
            $summaryRows .= "<tr><td>{$who}</td><td>{$type}</td><td class='num'>{$count}</td><td>{$lastSeen}</td></tr>";
        }
        $noSummary = $summaryRows === ''
            ? '<tr><td colspan="4" class="empty">No active sessions</td></tr>'
            : $summaryRows;

        $noSessions = $sessionRows === '' ? '<tr><td colspan="6" class="empty">No active sessions</td></tr>' : $sessionRows;
        $noLockouts = $lockoutRows === '' ? '<tr><td colspan="4" class="empty">No active lockouts</td></tr>' : $lockoutRows;

        $shown  = count($sessions);
        $within = $hours > 0
            ? ' used in the last ' . htmlspecialchars((string) $windows[$hours])
            : '';
        $showing = $sessionCount > $shown
            ? "Showing the {$shown} most recent of " . number_format($sessionCount)
              . ' active sessions' . $within . '.'
            : number_format($sessionCount) . ' active session'
              . ($sessionCount === 1 ? '' : 's') . $within . '.';

        if ($hours > 0) {
            $showing .= ' Web sessions are bounded by the PHP session idle timeout ('
                . number_format($idleTimeout / 60, 0) . ' min), whatever the window says —'
                . ' past it the browser has to sign in again.';
        }

        // The window is a link bar rather than a fixed filter: "how many sessions
        // are there really" and "who is here now" are different questions, and the
        // panel should not decide which one the reader is asking.
        $windowLinks = '';
        foreach ($windows as $value => $label) {
            $active      = $value === $hours ? ' active' : '';
            $windowLinks .= "<a href='?action=users&amp;hours={$value}' class='tab-link{$active}'>"
                . htmlspecialchars($label) . '</a>';
        }

        return <<<HTML
            <div class="range-bar">{$windowLinks}</div>
            <h3>Sessions by User</h3>
            <p>{$showing}</p>
            <table class="data-table">
                <thead><tr><th>User</th><th>Type</th><th class="num">Sessions</th><th>Last seen</th></tr></thead>
                <tbody>{$noSummary}</tbody>
            </table>
            <h3 style="margin-top:2rem">Active Sessions (web + API)</h3>
            <table class="data-table">
                <thead><tr><th>Token</th><th>User</th><th>Type</th><th>IP</th><th>Application</th><th>Last seen</th></tr></thead>
                <tbody>{$noSessions}</tbody>
            </table>
            <h3>Login Lockouts</h3>
            <table class="data-table">
                <thead><tr><th>Identifier</th><th>IP</th><th>Attempts</th><th>Locked until</th></tr></thead>
                <tbody>{$noLockouts}</tbody>
            </table>
        HTML;
    }

    /**
     * Paginated action history for a specific token.
     *
     * Fetches tokenactions rows for the given tokenid, ordered newest-first,
     * 50 per page.  Linked from the Active Sessions table via ?token=X.
     */
    private function renderTokenDetail(int $tokenId): string
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $page   = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        $tokenInfo = null;
        try {
            $res = $db->queryBuilder()
                ->table('#PREFIX#usertokens AS t')
                ->select(['t.tokenid', 't.userid', 'u.username', 't.applicationid AS application'])
                ->join('#PREFIX#users AS u', 'u.userid', '=', 't.userid')
                ->where('t.tokenid', $tokenId)
                ->limit(1)
                ->get();
            if ($res && $res->numRows > 0) {
                $tokenInfo = $res->fields;
            }
        } catch (\Throwable $ex) {
            $this->panelError('token detail', $ex);
        }

        if ($tokenInfo === null) {
            return $this->alert("Token #{$tokenId} not found.", 'warning')
                . "<p><a href='?action=users'>← Back to Users</a></p>";
        }

        $actions = [];
        $total   = 0;
        try {
            $total = (int) $db->queryBuilder()
                ->table('#PREFIX#tokenactions')
                ->where('tokenid', $tokenId)
                ->count();

            // Joined to `urls`: `tokenactions.urlid` is a foreign key, so the
            // column headed "URL" printed a row id.
            $res = $db->queryBuilder()
                ->table('#PREFIX#tokenactions AS ta')
                ->select([
                    'u.url', 'ta.method', 'ta.servertime',
                    'ta.execution_time_ms', 'ta.return_status',
                ])
                ->leftJoin('#PREFIX#urls AS u', 'u.urlid', '=', 'ta.urlid')
                ->where('ta.tokenid', $tokenId)
                ->orderBy('ta.servertime', 'desc')
                ->limit($perPage)
                ->offset($offset)
                ->get();
            $actions = $res ? $res->fetchAll() : [];
        } catch (\Throwable $ex) {
            $this->panelError('token activity', $ex);
        }

        $uname = htmlspecialchars($tokenInfo['username'] ?? '');
        $app   = htmlspecialchars($tokenInfo['application'] ?? '—');

        $rows = '';
        foreach ($actions as $a) {
            $url    = htmlspecialchars($a['url'] ?? '—');
            $method = htmlspecialchars($a['method'] ?? '');
            $time   = $this->formatTimestamp($a['servertime'] ?? null);
            $ms     = number_format((float) ($a['execution_time_ms'] ?? 0), 1);
            $status = (int) ($a['return_status'] ?? 0);
            $statusStyle = $status >= 400 ? ' style="color:var(--danger)"' : '';
            $rows .= "<tr><td>{$url}</td><td>{$method}</td><td>{$time}</td><td class='num'>{$ms} ms</td><td class='num'{$statusStyle}>{$status}</td></tr>";
        }

        $noData = $rows === '' ? '<tr><td colspan="5" class="empty">No actions recorded</td></tr>' : $rows;

        $pages     = $total > 0 ? (int) ceil($total / $perPage) : 1;
        $pager     = '';
        for ($i = 1; $i <= $pages; $i++) {
            $active = $i === $page ? ' active' : '';
            $pager .= "<a href='?action=users&amp;token={$tokenId}&amp;page={$i}' class='tab-link{$active}'>{$i}</a>";
        }
        $pagerHtml = $pages > 1 ? "<div class='range-bar'>{$pager}</div>" : '';

        return <<<HTML
            <p><a href='?action=users'>← Back to Users</a></p>
            <h3>Token #{$tokenId} — {$uname} ({$app})</h3>
            <p>Total actions: {$total}</p>
            {$pagerHtml}
            <table class="data-table">
                <thead><tr><th>URL</th><th>Method</th><th>Time</th><th class="num">ms</th><th class="num">Status</th></tr></thead>
                <tbody>{$noData}</tbody>
            </table>
            {$pagerHtml}
        HTML;
    }

    /**
     * Paginated userlog entries for a specific user.
     *
     * Shows audit-log rows from the userlog table (logid, date unix-ts, logtype,
     * log, details) ordered newest-first, 50 per page.
     * Linked from the Active Sessions table via ?user=X.
     */
    private function renderUserLog(int $userId): string
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $page   = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        $userInfo = null;
        try {
            $res = $db->queryBuilder()
                ->table('#PREFIX#users')
                ->select(['userid', 'username'])
                ->where('userid', $userId)
                ->limit(1)
                ->get();
            if ($res && $res->numRows > 0) {
                $userInfo = $res->fields;
            }
        } catch (\Throwable $ex) {
            $this->panelError('user detail', $ex);
        }

        if ($userInfo === null) {
            return $this->alert("User #{$userId} not found.", 'warning')
                . "<p><a href='?action=users'>← Back to Users</a></p>";
        }

        $logs  = [];
        $total = 0;
        try {
            $total = (int) $db->queryBuilder()
                ->table('#PREFIX#userlog')
                ->where('userid', $userId)
                ->count();

            $res = $db->queryBuilder()
                ->table('#PREFIX#userlog')
                ->select(['logid', 'date', 'logtype', 'log', 'details'])
                ->where('userid', $userId)
                ->orderBy('date', 'desc')
                ->orderBy('logid', 'desc')
                ->limit($perPage)
                ->offset($offset)
                ->get();
            $logs = $res ? $res->fetchAll() : [];
        } catch (\Throwable $ex) {
            $this->panelError('user activity log', $ex);
        }

        $uname = htmlspecialchars($userInfo['username'] ?? '');

        $rows = '';
        foreach ($logs as $l) {
            $date    = $this->formatTimestamp($l['date'] ?? null);
            $logtype = (int) ($l['logtype'] ?? 0);
            $log     = htmlspecialchars($l['log'] ?? '—');
            $details = htmlspecialchars(mb_strimwidth($l['details'] ?? '', 0, 120, '…'));
            $rows .= "<tr><td>{$date}</td><td>{$logtype}</td><td>{$log}</td><td>{$details}</td></tr>";
        }

        $noData = $rows === '' ? '<tr><td colspan="4" class="empty">No log entries found</td></tr>' : $rows;

        $pages     = $total > 0 ? (int) ceil($total / $perPage) : 1;
        $pager     = '';
        for ($i = 1; $i <= $pages; $i++) {
            $active = $i === $page ? ' active' : '';
            $pager .= "<a href='?action=users&amp;user={$userId}&amp;page={$i}' class='tab-link{$active}'>{$i}</a>";
        }
        $pagerHtml = $pages > 1 ? "<div class='range-bar'>{$pager}</div>" : '';

        return <<<HTML
            <p><a href='?action=users'>← Back to Users</a></p>
            <h3>User Log — #{$userId} {$uname}</h3>
            <p>Total entries: {$total}</p>
            {$pagerHtml}
            <table class="data-table">
                <thead><tr><th>Date</th><th>Type</th><th>Log</th><th>Details</th></tr></thead>
                <tbody>{$noData}</tbody>
            </table>
            {$pagerHtml}
        HTML;
    }

    private function renderPerformance(): string
    {
        $db = \Pramnos\Framework\Factory::getDatabase();
        if (!$db || !$db->connected) {
            return $this->alert('Database not connected.', 'warning');
        }

        $range   = isset($_GET['range']) ? (int) $_GET['range'] : 24;
        $allowed = [1, 6, 24, 168, 720]; // hours
        if (!in_array($range, $allowed, true)) {
            $range = 24;
        }

        // The window, as a unix timestamp. `tokenactions.servertime` is an integer
        // epoch, and this compared it to NOW() with `NOW() - INTERVAL 24 HOUR` —
        // MySQL's interval syntax, which PostgreSQL rejects outright, and a
        // timestamp compared to an integer, which neither engine will do. Both
        // queries threw on every request, and the panel reported "No data for this
        // period" as if the table were empty.
        $since = time() - ($range * 3600);

        $endpoints = [];
        try {
            // Query builder rather than hand-built SQL: `#PREFIX#tokenactions` was
            // prefixed while `usertokens`, `users` and `applications` in the same
            // statement were not, so on a prefixed installation the join named
            // tables that do not exist. The builder resolves every name the same
            // way, per driver.
            $qb  = $db->queryBuilder();
            $res = $qb
                ->table('#PREFIX#tokenactions AS ta')
                ->select([
                    'u.url AS endpoint',
                    'ta.method',
                    $qb->raw('COUNT(*) AS calls'),
                    $qb->raw('ROUND(AVG(ta.execution_time_ms), 1) AS avg_ms'),
                    $qb->raw('MAX(ta.execution_time_ms) AS max_ms'),
                ])
                // LEFT: an action whose URL row was removed still cost the time it
                // cost, and dropping it would quietly change the numbers above it.
                ->leftJoin('#PREFIX#urls AS u', 'u.urlid', '=', 'ta.urlid')
                ->where('ta.servertime', '>=', $since)
                // Only calls that were timed.
                //
                // A row with no duration has nothing to say about speed, and it
                // did worse than say nothing: `ORDER BY avg_ms DESC` puts NULLs
                // *first* on PostgreSQL, so a table holding any unmeasured rows
                // showed twenty of them at the top of "slowest endpoints", each
                // rendered as 0.0 ms, with the real measurements pushed off the
                // list. Every web request was unmeasured until 2026-08-20, so on
                // an existing installation that was the whole report.
                ->whereNotNull('ta.execution_time_ms')
                ->groupBy('u.url', 'ta.method')
                ->orderBy('avg_ms', 'desc')
                ->limit(20)
                ->get();
            $endpoints = $res ? $res->fetchAll() : [];
        } catch (\Throwable $ex) {
            // Reported as itself: both queries below used to share one catch
            // labelled "slow users", so a failure in this one was logged under the
            // name of the other.
            $this->panelError('slowest endpoints', $ex);
        }

        $slowUsers = [];
        try {
            $qb  = $db->queryBuilder();
            $res = $qb
                ->table('#PREFIX#tokenactions AS ta')
                ->select([
                    't.userid',
                    'us.username',
                    'a.name AS app_name',
                    $qb->raw('COUNT(*) AS calls'),
                    $qb->raw('ROUND(AVG(ta.execution_time_ms), 1) AS avg_ms'),
                    $qb->raw('MAX(ta.execution_time_ms) AS max_ms'),
                ])
                ->join('#PREFIX#usertokens AS t', 't.tokenid', '=', 'ta.tokenid')
                ->join('#PREFIX#users AS us', 'us.userid', '=', 't.userid')
                ->leftJoin('#PREFIX#applications AS a', 'a.appid', '=', 't.applicationid')
                ->where('ta.servertime', '>=', $since)
                ->whereNotNull('ta.execution_time_ms')
                ->groupBy('t.userid', 'us.username', 'a.name')
                ->orderBy('avg_ms', 'desc')
                ->limit(20)
                ->get();
            $slowUsers = $res ? $res->fetchAll() : [];
        } catch (\Throwable $ex) {
            $this->panelError('slowest users', $ex);
        }

        $rows = '';
        foreach ($endpoints as $e) {
            $ep  = htmlspecialchars($e['endpoint'] ?? '');
            $m   = htmlspecialchars($e['method'] ?? '');
            $c   = number_format((int) ($e['calls'] ?? 0));
            $avg = number_format((float) ($e['avg_ms'] ?? 0), 1);
            $max = number_format((float) ($e['max_ms'] ?? 0), 1);
            $rows .= "<tr><td>{$ep}</td><td>{$m}</td><td class='num'>{$c}</td><td class='num'>{$avg} ms</td><td class='num'>{$max} ms</td></tr>";
        }

        // "No data for this period" is three different answers, and which one it is
        // decides what the reader should do next. An empty table looks exactly like
        // a quiet hour, and a *spooled* table looks exactly like an empty one:
        // `Token::addAction()` appends to the WriteSpool rather than inserting, so
        // on an installation that never runs `spool:drain` every request ever made
        // is sitting in a file and this panel is empty for ever.
        $recorded = 0;
        try {
            $recorded = (int) $db->queryBuilder()
                ->table('#PREFIX#tokenactions')
                ->count();
        } catch (\Throwable $ex) {
            $this->panelError('recorded request count', $ex);
        }

        $emptyMessage = 'No data for this period';

        // "Recorded but never timed" is its own answer, and on any installation
        // with history it is the likely one: web requests carried no duration
        // until 2026-08-20, so those rows are in the table and cannot appear in
        // a report about how long things take.
        if ($recorded > 0) {
            try {
                $timed = (int) $db->queryBuilder()
                    ->table('#PREFIX#tokenactions')
                    ->whereNotNull('execution_time_ms')
                    ->count();

                if ($timed === 0) {
                    $emptyMessage = number_format($recorded) . ' request(s) are recorded,'
                        . ' none of them timed. Durations are written from the moment a'
                        . ' request finishes; rows older than that carry none.';
                }
            } catch (\Throwable $ex) {
                $this->panelError('timed request count', $ex);
            }
        }

        if ($recorded === 0) {
            $emptyMessage = 'No requests have been recorded at all.';
            try {
                $spooled = \Pramnos\Database\WriteSpool::pending();
                if ($spooled > 0) {
                    $emptyMessage .= ' ' . number_format($spooled)
                        . ' are waiting in the write spool — run <code>spool:drain</code>'
                        . ' (or schedule it) to write them.';
                }
            } catch (\Throwable $ex) {
                $this->panelError('write spool status', $ex);
            }
        }

        $noData = $rows === '' ? '<tr><td colspan="5" class="empty">' . $emptyMessage . '</td></tr>' : $rows;

        $userRows = '';
        foreach ($slowUsers as $u) {
            $uid  = (int) ($u['userid'] ?? 0);
            $uname = htmlspecialchars($u['username'] ?? '—');
            $app  = htmlspecialchars($u['app_name'] ?? '—');
            $c    = number_format((int) ($u['calls'] ?? 0));
            $avg  = number_format((float) ($u['avg_ms'] ?? 0), 1);
            $max  = number_format((float) ($u['max_ms'] ?? 0), 1);
            $userRows .= "<tr><td>{$uid}</td><td>{$uname}</td><td>{$app}</td><td class='num'>{$c}</td><td class='num'>{$avg} ms</td><td class='num'>{$max} ms</td></tr>";
        }

        $noUserData = $userRows === '' ? '<tr><td colspan="6" class="empty">' . $emptyMessage . '</td></tr>' : $userRows;

        $rangeLinks = '';
        foreach (['1' => '1h', '6' => '6h', '24' => '24h', '168' => '7d', '720' => '30d'] as $h => $label) {
            $active     = (int) $h === $range ? ' active' : '';
            $rangeLinks .= "<a href='?action=performance&range={$h}' class='tab-link{$active}'>{$label}</a>";
        }

        return <<<HTML
            <div class="range-bar">{$rangeLinks}</div>
            <h3>Slowest Endpoints (top 20 by avg ms)</h3>
            <table class="data-table">
                <thead><tr><th>Endpoint</th><th>Method</th><th class="num">Calls</th><th class="num">Avg ms</th><th class="num">Max ms</th></tr></thead>
                <tbody>{$noData}</tbody>
            </table>
            <h3 style="margin-top:2rem">Slowest Users / Applications (top 20 by avg ms)</h3>
            <table class="data-table">
                <thead><tr><th>User ID</th><th>Username</th><th>Application</th><th class="num">Calls</th><th class="num">Avg ms</th><th class="num">Max ms</th></tr></thead>
                <tbody>{$noUserData}</tbody>
            </table>
        HTML;
    }

    private function renderGit(): string
    {
        $git      = new GitInfo($this->detectRepoRoot());
        $branch   = htmlspecialchars($git->getBranch());
        $hash     = htmlspecialchars($git->getHash() ?? '—');
        $short    = htmlspecialchars($git->getShortHash());
        $subject  = htmlspecialchars($git->getSubject());
        $author   = htmlspecialchars($git->getAuthor());
        $ts       = $git->getDate();
        $date     = $ts ? date('Y-m-d H:i:s T', $ts) : '—';
        $branches = array_map('htmlspecialchars', $git->getLocalBranches());
        $remotes  = array_map('htmlspecialchars', $git->getRemotes());

        $branchList = implode('', array_map(
            fn($b) => '<li' . ($b === $branch ? ' class="current"' : '') . '><code>' . $b . '</code></li>',
            $branches,
        ));
        $remoteList = implode('', array_map(fn($r) => "<li><code>{$r}</code></li>", $remotes));

        return <<<HTML
            <div class="grid-2">
                {$this->card('HEAD Commit', <<<INNER
                    <table class="info-table">
                        <tr><th>Branch</th><td><code>{$branch}</code></td></tr>
                        <tr><th>Hash</th><td><code>{$hash}</code></td></tr>
                        <tr><th>Short hash</th><td><code>{$short}</code></td></tr>
                        <tr><th>Subject</th><td>{$subject}</td></tr>
                        <tr><th>Author</th><td>{$author}</td></tr>
                        <tr><th>Date</th><td>{$date}</td></tr>
                    </table>
                INNER)}
                {$this->card('Branches', "<ul class='ref-list'>{$branchList}</ul>")}
                {$this->card('Remotes', $remotes ? "<ul class='ref-list'>{$remoteList}</ul>" : '<p class="empty">No remotes configured.</p>')}
            </div>
        HTML;
    }

    // =========================================================================
    // Data helpers
    // =========================================================================

    private function fetchMigrationStatus(): array
    {
        try {
            $db = \Pramnos\Framework\Factory::getDatabase();
            if (!$db || !$db->connected) {
                return ['—', '—', '—'];
            }

            // `\Pramnos\Database\MigrationLoader`, not `…\Migrations\MigrationLoader`:
            // there is no `Migrations` namespace, so this threw `Class not found`
            // into a `catch (\Throwable)` that returned three em-dashes. The card
            // has shown "— / — / —" on every installation since it was written,
            // and looked like a card for a feature nobody was using.
            $app = $this->application
                ?? \Pramnos\Application\Application::currentInstance();
            if ($app === null) {
                return ['—', '—', '—'];
            }

            // The same directories the console's `migrate:status` and the MCP
            // migration tool resolve, rather than a second answer to the same
            // question: FeatureRegistry::getMigrationPaths() covers registered
            // features only and misses the application's own app/Migrations.
            $all = \Pramnos\Database\MigrationLoader::loadFromDirectories(
                \Pramnos\Database\MigrationLoader::resolveDefaultDirectories(
                    defined('ROOT') ? \ROOT : getcwd()
                ),
                $app
            );

            $runner  = new \Pramnos\Database\MigrationRunner($db);
            $history = [];
            $last    = '—';
            foreach ($runner->getHistory() as $row) {
                $slug = $row['key'] ?? '';
                if ($slug === '') {
                    continue;
                }
                // `__fw_auto_*` rows are the auto-migration fingerprint, not a
                // migration: they carry no batch, so they sort last and became
                // "last applied" — the card named a bookkeeping key nobody can
                // look up.
                if (str_starts_with($slug, '__fw_')) {
                    continue;
                }
                $history[$slug] = $row;
                if ((int) ($row['result'] ?? 0) === 1) {
                    // getHistory() orders by batch then time, so the last row that
                    // ran successfully is the most recent one.
                    $last = $slug;
                }
            }

            // Counted per migration file, not as `count(all) - count(history)`:
            // history also holds migrations no longer in the codebase and rows
            // for failed attempts, either of which made "pending" negative or
            // hid a migration that still needs to run.
            $applied = 0;
            $pending = 0;
            foreach ($all as $migration) {
                $slug = $migration->getSlug();
                if (isset($history[$slug])
                    && (int) ($history[$slug]['result'] ?? 0) === 1
                ) {
                    $applied++;
                    continue;
                }
                $pending++;
            }

            return [$pending, $applied, htmlspecialchars($last)];
        } catch (\Throwable $ex) {
            $this->panelError('migration status', $ex);
            return ['—', '—', '—'];
        }
    }

    /**
     * What is buffered and what is meant to be draining it.
     *
     * @return array{0: int, 1: string, 2: int} pending rows, spool driver, scheduled task count
     */
    private function fetchBackgroundWork(): array
    {
        $pending = 0;
        $driver  = 'unknown';
        $tasks   = 0;

        try {
            $pending = \Pramnos\Database\WriteSpool::pending();
            $driver  = \Pramnos\Database\WriteSpool::driver();
        } catch (\Throwable $ex) {
            $this->panelError('write spool status', $ex);
        }

        try {
            \Pramnos\Scheduling\Scheduler::loadDefinitions();
            $tasks = count(\Pramnos\Scheduling\Scheduler::all());
        } catch (\Throwable $ex) {
            $this->panelError('scheduled tasks', $ex);
        }

        return [$pending, htmlspecialchars($driver), $tasks];
    }

    private function fetchQueueStats(): array
    {
        try {
            $db     = \Pramnos\Framework\Factory::getDatabase();
            $res    = $db->execute(
                // `queueitems`, not `queue_jobs`: no migration has ever created
                // a table by the latter name, so this panel counted nothing.
                'SELECT status, COUNT(*) AS cnt FROM #PREFIX#queueitems GROUP BY status'
            );
            $stats  = ['pending' => 0, 'running' => 0, 'failed' => 0];
            foreach ($res ? $res->fetchAll() : [] as $row) {
                $status = $row['status'] ?? '';
                $cnt    = (int) ($row['cnt'] ?? 0);
                if (isset($stats[$status])) {
                    $stats[$status] = $cnt;
                }
            }
            return [$stats['pending'], $stats['running'], $stats['failed']];
        } catch (\Throwable $ex) {
            $this->panelError('queue statistics', $ex);
            return ['—', '—', '—'];
        }
    }

    private function readProcUptime(): string
    {
        $raw = @file_get_contents('/proc/uptime');
        if ($raw === false) {
            return '—';
        }
        $secs   = (int) explode(' ', trim($raw))[0];
        $days   = intdiv($secs, 86400);
        $hours  = intdiv($secs % 86400, 3600);
        $mins   = intdiv($secs % 3600, 60);
        return "{$days}d {$hours}h {$mins}m";
    }

    private function readProcLoadAvg(): string
    {
        $raw = @file_get_contents('/proc/loadavg');
        if ($raw === false) {
            return '—';
        }
        $parts = explode(' ', trim($raw));
        return ($parts[0] ?? '—') . ' / ' . ($parts[1] ?? '—') . ' / ' . ($parts[2] ?? '—');
    }

    /** Returns [total, free, used] as human-readable strings. */
    private function readProcMemInfo(): array
    {
        $raw = @file_get_contents('/proc/meminfo');
        if ($raw === false) {
            return ['—', '—', '—'];
        }
        $info = [];
        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
                $info[$m[1]] = (int) $m[2];
            }
        }
        $total    = ($info['MemTotal'] ?? 0) * 1024;
        $free     = (($info['MemFree'] ?? 0) + ($info['Buffers'] ?? 0) + ($info['Cached'] ?? 0)) * 1024;
        $used     = $total - $free;
        return [$this->humanBytes($total), $this->humanBytes($free), $this->humanBytes($used)];
    }

    /** Detect the best guess for the repo root (cwd or framework root). */
    private function detectRepoRoot(): string
    {
        // Prefer app ROOT constant if defined
        if (defined('ROOT') && is_dir(constant('ROOT') . '/.git')) {
            return constant('ROOT');
        }
        // Fall back to framework source root
        $frameworkRoot = dirname(__DIR__, 3);
        if (is_dir($frameworkRoot . '/.git')) {
            return $frameworkRoot;
        }
        return getcwd() ?: '/';
    }

    private function handleCacheFlush(): void
    {
        header('Content-Type: application/json');
        try {
            \Pramnos\Cache\Cache::getInstance()->clear();
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        $this->terminate();
    }

    /**
     * AJAX endpoint: returns serialized content of a specific cache item.
     *
     * Expects GET params: key (URL-encoded raw cache key from getAllItems()),
     * optional ns (namespace/category).  Bypasses TTL so expired items are
     * still inspectable.  Content is truncated to 50 KB.
     */
    private function handleCacheItemInspect(): void
    {
        header('Content-Type: application/json');
        $rawKey = urldecode((string) ($_GET['key'] ?? ''));

        if ($rawKey === '') {
            echo json_encode(['ok' => false, 'error' => 'No key specified']);
            $this->terminate();
            return; // terminate() may be a no-op in tests — never fall through
        }

        try {
            $cache   = \Pramnos\Cache\Cache::getInstance();
            $adapter = $cache->getAdapter();

            if ($adapter === null) {
                echo json_encode(['ok' => false, 'error' => 'No cache adapter']);
                $this->terminate();
                return;
            }

            // Redis stores keys with adapter prefix; getAllItems() strips it,
            // so we must re-add it before calling load().
            $loadKey = ($adapter instanceof \Pramnos\Cache\Adapter\RedisAdapter)
                ? $adapter->getPrefix() . $rawKey
                : $rawKey;

            // timeout=0 bypasses the TTL expiry check — we always want to show
            // the item content regardless of whether it has expired.
            $data    = $adapter->load($loadKey, 0);
            $content = $data !== false
                ? substr(var_export($data, true), 0, 50 * 1024)
                : null;

            echo json_encode(['ok' => $data !== false, 'key' => $rawKey, 'content' => $content]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        $this->terminate();
    }

    // =========================================================================
    // MCP
    // =========================================================================

    /**
     * The server this panel talks to.
     *
     * Container first, so a project that registered a tool in a service provider sees it
     * here — the same resolution `mcp:serve` and `mcp:call` do. Falling back to a server
     * built from the framework's defaults means the panel works with the `mcp` feature
     * switched off, which is when somebody is most likely to be wondering why a client
     * cannot see anything.
     */
    private function mcpServer(): \Pramnos\Mcp\McpServer
    {
        $app = $this->application ?? \Pramnos\Application\Application::currentInstance();

        if ($app !== null && $app->getContainer()->has('mcp.server')) {
            /** @var \Pramnos\Mcp\McpServer $server */
            $server = $app->getContainer()->get('mcp.server');

            return $server;
        }

        $server = new \Pramnos\Mcp\McpServer(
            defined('TITLE') && TITLE !== '' ? (string) TITLE : 'Pramnos App',
            defined('VERSION') ? VERSION : '1.0.0'
        );

        \Pramnos\Mcp\McpServiceProvider::registerDefaults($server, $app);

        return $server;
    }

    /**
     * AJAX endpoint: run one tool and hand back both halves of the exchange.
     *
     * The **request** is returned as well as the response, deliberately. Half the value of
     * this screen is seeing what the form actually built — `{"limit": "5"}` and
     * `{"limit": 5}` are different calls, and a schema that rejected the first is otherwise
     * a mystery.
     *
     * Dispatched through `McpServer::dispatch()` rather than by reaching for the tool: a
     * tool that works when called directly and fails through the protocol is a real bug,
     * and it only shows here.
     */
    private function handleMcpCall(): void
    {
        header('Content-Type: application/json');

        // A POST that *executes* something gets a token, even behind the panel's own gate.
        // The other endpoints here read; this one runs whatever a project registered, and a
        // project is free to register a tool that writes.
        $session = \Pramnos\Framework\Factory::getSession();

        if (!$session->verifyCsrfToken((string) ($_POST['csrf'] ?? ''))) {
            http_response_code(419);
            echo json_encode(['ok' => false, 'error' => 'Stale form — reload the page.']);
            $this->terminate();
            return; // terminate() may be a no-op in tests — never fall through
        }

        $tool      = (string) ($_POST['tool'] ?? '');
        $arguments = json_decode((string) ($_POST['arguments'] ?? '{}'), true);

        if (!is_array($arguments)) {
            http_response_code(400);
            echo json_encode([
                'ok'    => false,
                'error' => 'Arguments were not a JSON object: ' . json_last_error_msg(),
            ]);
            $this->terminate();
            return;
        }

        $request = [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => ['name' => $tool, 'arguments' => $arguments],
        ];

        $started = microtime(true);

        try {
            $response = $this->mcpServer()->dispatch($request);
        } catch (\Throwable $exception) {
            // dispatch() catches a throwing *tool* and reports it as `isError`. Reaching
            // here means the protocol layer itself broke, which is worth saying plainly
            // rather than showing as an empty result.
            http_response_code(500);
            echo json_encode([
                'ok'      => false,
                'error'   => 'The server itself threw: ' . $exception->getMessage(),
                'request' => $request,
            ]);
            $this->terminate();
            return;
        }

        // Displayed only after dispatch has had the array: PHP decodes `{}` to an empty
        // *array*, which re-encodes as `[]` — and a page whose whole job is showing what was
        // sent must not show `"arguments": []` for a call that sent an object.
        if ($arguments === []) {
            $request['params']['arguments'] = new \stdClass();
        }

        echo json_encode([
            'ok'       => true,
            'ms'       => round((microtime(true) - $started) * 1000, 1),
            'request'  => $request,
            'response' => $response,
            // A tool that threw comes back as a *successful* JSON-RPC response whose content
            // is the exception message. Said out loud, or the page shows it as the answer.
            'failed'   => !empty($response['result']['isError']) || isset($response['error']),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->terminate();
    }

    /**
     * The MCP panel.
     */
    private function renderMcp(): string
    {
        $server  = $this->mcpServer();
        $tools   = $server->getTools();
        $session = \Pramnos\Framework\Factory::getSession();
        $token   = htmlspecialchars($session->getCsrfToken(), ENT_QUOTES);

        $html = '';

        /*
         * The distinction that catches people out, and it is not "MCP is off".
         *
         * `mcp:serve` works either way: with no container-bound server it builds one from
         * the framework's defaults, so a client sees the nine built-in tools regardless. What
         * the feature adds is the container binding — which is the only place an application
         * can register a tool of its own. So the honest warning is about *your* tools, not
         * about whether anything is served, and saying "no client is being served" would
         * send somebody looking for a broken client.
         */
        if (!FeatureRegistry::isEnabled('mcp')) {
            $html .= '<div class="alert alert-info">The <code>mcp</code> feature is not in '
                . "<code>app.php</code>'s features list. A client still gets the built-in "
                . 'tools below — <code>mcp:serve</code> builds its own server when the '
                . 'container has none — but nothing this application registers in a service '
                . 'provider will be part of it, because there is no <code>mcp.server</code> '
                . 'to add it to.</div>';
        }

        $html .= '<div class="card"><div class="card-title">Server</div><div class="card-body">'
            . '<table class="info-table"><tr><th>Name</th><td><code>'
            . htmlspecialchars($server->getName()) . '</code></td></tr>'
            . '<tr><th>Tools</th><td>' . count($tools) . '</td></tr>'
            . '<tr><th>Resources</th><td>' . count($server->getResources()) . '</td></tr>'
            . '<tr><th>Traffic log</th><td>' . $this->mcpTrafficLogStatus() . '</td></tr>'
            . '</table></div></div>';

        $html .= '<h3>Tools</h3>';

        if ($tools === []) {
            $html .= '<div class="alert alert-info">No tools are registered.</div>';

            return $html;
        }

        foreach ($tools as $tool) {
            $html .= $this->renderMcpTool($tool);
        }

        $html .= $this->renderMcpResources($server);

        return $html . $this->mcpScript($token);
    }

    /**
     * One tool: what it is for, a form built from its schema, and somewhere for the answer.
     *
     * `<details>` rather than tabs or a sidebar: nine tools with nine descriptions is a page
     * you scan, and the one you want stays open while you adjust an argument and call again.
     */
    private function renderMcpTool(\Pramnos\Mcp\McpToolInterface $tool): string
    {
        $name   = $tool->name();
        $id     = 'mcp-' . preg_replace('/[^a-z0-9]+/i', '-', $name);
        $schema = $tool->inputSchema();
        $props  = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        $fields = '';

        foreach ($props as $field => $spec) {
            $fields .= $this->renderMcpField(
                $id,
                (string) $field,
                is_array($spec) ? $spec : [],
                in_array($field, $required, true)
            );
        }

        if ($fields === '') {
            $fields = '<p class="mcp-note">This tool takes no arguments.</p>';
        }

        return '<details class="mcp-tool" id="' . $id . '">'
            . '<summary><code>' . htmlspecialchars($name) . '</code></summary>'
            . '<div class="mcp-tool-body">'
            . '<p class="mcp-desc">' . htmlspecialchars($tool->description()) . '</p>'
            . '<div class="mcp-fields">' . $fields . '</div>'
            . '<div class="mcp-actions">'
            . '<button type="button" class="mcp-run" data-tool="' . htmlspecialchars($name, ENT_QUOTES)
            . '" data-target="' . $id . '">Call</button>'
            . '<label class="mcp-raw"><input type="checkbox" class="mcp-envelope"> '
            . 'show the JSON-RPC envelope</label>'
            . '<span class="mcp-timing"></span>'
            . '</div>'
            . '<pre class="mcp-result" hidden></pre>'
            . '</div></details>';
    }

    /**
     * One field, as the control its type deserves.
     *
     * An enum becomes a `<select>` — the whole reason to render a schema rather than a
     * textarea is that the valid values stop being something you look up. A boolean becomes
     * a tri-state select rather than a checkbox: an unchecked box and an absent argument are
     * the same thing to a form and very different things to a tool, and "leave it out" has
     * to be expressible.
     *
     * @param array<string, mixed> $spec
     */
    private function renderMcpField(string $toolId, string $field, array $spec, bool $required): string
    {
        $type  = (string) ($spec['type'] ?? 'string');
        $desc  = trim((string) ($spec['description'] ?? ''));
        $attr  = ' data-field="' . htmlspecialchars($field, ENT_QUOTES) . '"'
            . ' data-type="' . htmlspecialchars($type, ENT_QUOTES) . '"';
        $label = '<label for="' . $toolId . '-' . htmlspecialchars($field, ENT_QUOTES) . '">'
            . htmlspecialchars($field)
            . ($required ? ' <span class="mcp-required">required</span>' : '')
            . '</label>';
        $id = ' id="' . $toolId . '-' . htmlspecialchars($field, ENT_QUOTES) . '"';

        if (isset($spec['enum']) && is_array($spec['enum'])) {
            $control = '<select' . $id . $attr . '><option value="">— omit —</option>';

            foreach ($spec['enum'] as $option) {
                $control .= '<option>' . htmlspecialchars((string) $option) . '</option>';
            }

            $control .= '</select>';
        } elseif ($type === 'boolean') {
            $control = '<select' . $id . $attr . '>'
                . '<option value="">— omit —</option><option value="true">true</option>'
                . '<option value="false">false</option></select>';
        } elseif ($type === 'array') {
            $items = is_array($spec['items'] ?? null) ? $spec['items'] : [];
            $hint  = isset($items['enum']) && is_array($items['enum'])
                ? implode(', ', array_map('strval', $items['enum']))
                : 'comma separated';
            $control = '<input type="text"' . $id . $attr
                . ' placeholder="' . htmlspecialchars($hint, ENT_QUOTES) . '">';
        } else {
            $control = '<input type="' . ($type === 'integer' || $type === 'number' ? 'number' : 'text')
                . '"' . $id . $attr . ' placeholder="— omit —">';
        }

        return '<div class="mcp-field">' . $label . $control
            . ($desc !== '' ? '<span class="mcp-hint">' . htmlspecialchars($desc) . '</span>' : '')
            . '</div>';
    }

    /**
     * The resources, which are files and therefore either readable or a lie.
     */
    private function renderMcpResources(\Pramnos\Mcp\McpServer $server): string
    {
        $resources = $server->getResources();

        if ($resources === []) {
            return '';
        }

        $rows = '';

        foreach ($resources as $uri => $resource) {
            $rows .= '<tr><td><code>' . htmlspecialchars((string) $uri) . '</code></td>'
                . '<td>' . htmlspecialchars((string) $resource->name) . '</td></tr>';
        }

        return '<h3>Resources</h3><table class="data-table">'
            . '<thead><tr><th>URI</th><th>Name</th></tr></thead><tbody>'
            . $rows . '</tbody></table>';
    }

    /**
     * Whether the traffic log exists, and how to switch it on when it does not.
     *
     * The panel cannot enable it: the log belongs to the `mcp:serve` process the *client*
     * started, which is a different process from this one. Saying so beats a switch that
     * appears to do something.
     */
    private function mcpTrafficLogStatus(): string
    {
        $path = \Pramnos\Logs\LogManager::getLogFilePath('mcp', 'log');

        if (!is_file($path)) {
            return '<span class="badge">off</span> — start the server with '
                . '<code>mcp:serve --log</code> to record what a client sends. '
                . 'It writes to <code>mcp.log</code>, which the '
                . '<a href="' . htmlspecialchars($this->logsUrl(), ENT_QUOTES) . '">log viewer</a> reads.';
        }

        return '<span class="badge ok">' . $this->humanBytes((int) filesize($path)) . '</span> '
            . '<code>mcp.log</code>, last written '
            . htmlspecialchars(date('d/m/Y H:i', (int) filemtime($path))) . ' — readable in the '
            . '<a href="' . htmlspecialchars($this->logsUrl(), ENT_QUOTES) . '">log viewer</a>.';
    }

    /** The administration log viewer, filtered to the MCP traffic. */
    private function logsUrl(): string
    {
        $base = defined('sURL') ? rtrim((string) sURL, '/') : '';

        return $base . '/admin/Logs/viewer?file=mcp.log';
    }

    /**
     * The page's own script.
     *
     * Inline with the CSP nonce, like the panel's stylesheet: this panel is one self-contained
     * document with no asset pipeline behind it, and an external file would need a route.
     */
    private function mcpScript(string $token): string
    {
        $nonce = \Pramnos\Application\Application::currentInstance()?->cspNonce ?? '';
        $na    = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '"' : '';
        $url   = htmlspecialchars(
            (defined('sURL') ? rtrim((string) sURL, '/') : '')
                . '/' . (string) static::config('mount', 'devpanel') . '/mcp',
            ENT_QUOTES
        );

        return <<<HTML
        <script{$na}>
        (function () {
          // Build the arguments object from the fields, skipping the empty ones. An omitted
          // argument and an empty string are different: a schema with a default gets to keep
          // it, and "" is a value a tool may well reject.
          function collect(root) {
            var out = {};
            root.querySelectorAll('[data-field]').forEach(function (el) {
              var raw = el.value;
              if (raw === '' || raw === null) { return; }
              var type = el.getAttribute('data-type');
              if (type === 'integer' || type === 'number') { out[el.getAttribute('data-field')] = Number(raw); }
              else if (type === 'boolean') { out[el.getAttribute('data-field')] = raw === 'true'; }
              else if (type === 'array') {
                out[el.getAttribute('data-field')] = raw.split(',').map(function (s) { return s.trim(); })
                  .filter(function (s) { return s !== ''; });
              } else { out[el.getAttribute('data-field')] = raw; }
            });
            return out;
          }

          document.querySelectorAll('.mcp-run').forEach(function (button) {
            button.addEventListener('click', function () {
              var panel  = document.getElementById(button.getAttribute('data-target'));
              var result = panel.querySelector('.mcp-result');
              var timing = panel.querySelector('.mcp-timing');
              var raw    = panel.querySelector('.mcp-envelope').checked;
              var body   = new URLSearchParams();

              body.set('csrf', '{$token}');
              body.set('tool', button.getAttribute('data-tool'));
              body.set('arguments', JSON.stringify(collect(panel)));

              button.disabled = true;
              timing.textContent = 'calling…';
              result.hidden = false;
              result.className = 'mcp-result';
              result.textContent = '';

              fetch('{$url}', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: body.toString(),
                credentials: 'same-origin'
              }).then(function (response) {
                return response.json().catch(function () {
                  // A non-JSON body here means something upstream printed into the response —
                  // a fatal, a redirect to a login page. Saying that beats "unexpected token".
                  throw new Error('The panel did not answer with JSON (HTTP ' + response.status + ').');
                });
              }).then(function (data) {
                timing.textContent = data.ms !== undefined ? data.ms + ' ms' : '';

                if (!data.ok) {
                  result.className = 'mcp-result failed';
                  result.textContent = data.error || 'The call failed.';
                  return;
                }

                if (data.failed) { result.className = 'mcp-result failed'; }

                if (raw) {
                  result.textContent = JSON.stringify({request: data.request, response: data.response}, null, 2);
                  return;
                }

                var content = (data.response && data.response.result && data.response.result.content) || [];
                var text = content.map(function (block) { return block.text || ''; }).join('\\n');

                if (data.response && data.response.error) {
                  result.className = 'mcp-result failed';
                  text = 'JSON-RPC error ' + data.response.error.code + ': ' + data.response.error.message;
                }

                // What the form actually built, above the answer: {"limit": "5"} and
                // {"limit": 5} are different calls, and a schema that rejected the first
                // is otherwise a mystery.
                var sent = JSON.stringify(data.request.params.arguments);
                result.textContent = '// sent ' + sent + '\\n\\n' + (text || '(empty)')
                  + (data.failed ? '\\n\\n// the tool reported a failure (isError)' : '');
              }).catch(function (error) {
                timing.textContent = '';
                result.className = 'mcp-result failed';
                result.textContent = error.message;
              }).then(function () {
                button.disabled = false;
              });
            });
          });
        })();
        </script>
        HTML;
    }

    // =========================================================================
    // Layout & HTML helpers
    // =========================================================================

    /**
     * Insert the Adminer tab immediately after Database, when there is one to insert.
     *
     * Position is not decoration here: Adminer and the Database tab are the same subject, and
     * the reason this panel's own tab has a link *out* rather than a copy of Adminer is that
     * they belong next to each other.
     *
     * @param  array<string, string> $tabs
     * @return array<string, string>
     */
    /**
     * This panel's tabs, in order. The **only** list.
     *
     * There were two — one in `renderLayout()`, one in `tabStrip()` — and the comment on the
     * second claimed to be the single source while being the copy. Adding a tab meant adding
     * it twice, and forgetting the second gave a page that wore the strip without appearing
     * in it. Same defect the MCP tool catalogue had, one file over.
     *
     * @return array<string, string> slug => label
     */
    protected static function tabs(): array
    {
        $tabs = [
            'overview'    => 'Overview',
            'db'          => 'Database',
            'cache'       => 'Cache',
            'users'       => 'Users',
            'performance' => 'Performance',
            'git'         => 'Git',
            // Between Git and PHP Info: a developer tool, beside the other developer tools.
            'mcp'         => 'MCP',
            'phpinfo'     => 'PHP Info',
        ];

        // Beside Database, not at the end: it is the same subject, and a tool two tabs away
        // from the thing it operates on is a tool people scroll past.
        $tabs = static::withAdminerTab($tabs);

        foreach (static::$customPanels as $slug => $panel) {
            $tabs[$slug] = $panel['label'];
        }

        return $tabs;
    }

    /**
     * Where a tab points.
     *
     * Adminer is a route of its own rather than an action of this panel, and `overview` is
     * the mount point with nothing after it. Everything else is `/<mount>/<slug>`.
     */
    protected static function tabHref(string $slug, string $baseUrl, string $mountPoint): string
    {
        if ($slug === 'adminer') {
            return (string) static::adminerTabUrl();
        }

        return $slug === 'overview'
            ? $baseUrl . '/' . $mountPoint
            : $baseUrl . '/' . $mountPoint . '/' . $slug;
    }

    protected static function withAdminerTab(array $tabs): array
    {
        if (static::adminerTabUrl() === null) {
            return $tabs;
        }

        $ordered = [];

        foreach ($tabs as $key => $label) {
            $ordered[$key] = $label;

            if ($key === 'db') {
                $ordered['adminer'] = 'Adminer';
            }
        }

        // No Database tab to sit beside — appended rather than dropped.
        return isset($ordered['adminer']) ? $ordered : $ordered + ['adminer' => 'Adminer'];
    }

    /**
     * `/adminer`, or null when there is nothing behind it.
     *
     * Null when the package is not installed *or* when this visitor would be refused: a tab
     * that answers 404 is worse than no tab, because the reader concludes the tool is broken
     * rather than absent.
     */
    public static function adminerTabUrl(): ?string
    {
        $controller = '\Pramnos\Application\Controllers\Adminer';

        if (!class_exists($controller)) {
            return null;
        }

        if (!$controller::isAvailable()) {
            return null;
        }

        $base = defined('sURL') ? rtrim((string) sURL, '/') : '';

        return $base . '/adminer';
    }

    /**
     * This panel's tab strip, for a page that is not this panel.
     *
     * Adminer wears it so that it reads as one of the panel's tabs rather than as a different
     * application somebody happened to link to. One list, built here, so a tab added to the
     * panel appears there too instead of being forgotten in a second copy.
     *
     * @param  string $activeTab Which tab to mark, e.g. `adminer`
     * @return string HTML
     */
    public static function tabStrip(string $activeTab): string
    {
        $base       = defined('sURL') ? rtrim((string) sURL, '/') : '';
        $mountPoint = (string) static::config('mount', 'devpanel');

        $html = '';

        foreach (static::tabs() as $key => $label) {
            $html .= '<a href="'
                . htmlspecialchars(static::tabHref($key, $base, $mountPoint), ENT_QUOTES) . '"'
                . ($key === $activeTab ? ' class="active"' : '') . '>'
                . htmlspecialchars($label) . '</a>';
        }

        return $html;
    }

    /**
     * Where "Back" goes, for a page outside this panel.
     *
     * The same remembered referrer the panel's own Back button uses, so a visitor who came from
     * a screen returns to it whichever of the two they went through.
     */
    public static function returnUrlFor(string $mountPoint = ''): string
    {
        $base = defined('sURL') ? rtrim((string) sURL, '/') : '';
        $mount = $mountPoint !== '' ? $mountPoint : (string) static::config('mount', 'devpanel');

        $referrer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $panelUrl = $base . '/' . $mount;
        $adminer  = $base . '/adminer';

        if ($referrer !== ''
            && $base !== ''
            && str_starts_with($referrer, $base)
            && !str_starts_with($referrer, $panelUrl)
            && !str_starts_with($referrer, $adminer)
        ) {
            $_SESSION[static::RETURN_KEY] = $referrer;
        }

        $remembered = (string) ($_SESSION[static::RETURN_KEY] ?? '');

        if ($remembered !== '' && $base !== '' && str_starts_with($remembered, $base)) {
            return $remembered;
        }

        return $base !== '' ? $base : '/';
    }

    /**
     * The session key holding the page the panel was opened from.
     */
    public const RETURN_KEY = 'devpanel_return';

    /**
     * Where "Back" goes: the page this panel was opened from.
     *
     * It went to the site root, which is almost never where anybody came from. You open the
     * panel from the screen you are debugging — usually deep in the administration area,
     * often with a filter in the query string — read one number, press Back and land on the
     * home page, with the way back to what you were doing gone.
     *
     * The referrer is recorded once, on the way in, and only when it is not this panel: the
     * tabs across the top are same-panel navigation, so the original page survives moving
     * between Database, Cache and Users. It lives in the session because the panel is entered
     * from anywhere, including a link in the debug toolbar, and there is no request of ours to
     * thread it through.
     *
     * Only a URL on this site is kept. A recorded referrer is rendered into a link on a page
     * an administrator will click, so a foreign one would make this panel a way to send
     * somebody somewhere else, with the panel's own appearance vouching for it.
     */
    protected function rememberedReturnUrl(string $baseUrl, string $mountPoint): string
    {
        $referrer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $panelUrl = rtrim($baseUrl, '/') . '/' . $mountPoint;

        // Adminer counts as this panel, not as somewhere the visitor came from. It is one of
        // the tabs now, so recording it sent Back from the panel *into* Adminer — the two
        // bouncing off each other, with the screen the person actually came from lost.
        $adminerUrl = rtrim($baseUrl, '/') . '/adminer';

        if ($referrer !== ''
            && $baseUrl !== ''
            && str_starts_with($referrer, $baseUrl)
            && !str_starts_with($referrer, $panelUrl)
            && !str_starts_with($referrer, $adminerUrl)
        ) {
            $_SESSION[static::RETURN_KEY] = $referrer;
        }

        $remembered = (string) ($_SESSION[static::RETURN_KEY] ?? '');

        // Re-checked on the way out rather than trusted because it was checked on the way in:
        // the session outlives the request that wrote it, and a value that stopped being ours
        // — a changed site URL, a session restored from elsewhere — must not become a link.
        if ($remembered !== '' && $baseUrl !== '' && str_starts_with($remembered, $baseUrl)) {
            return htmlspecialchars($remembered, ENT_QUOTES);
        }

        return $baseUrl;
    }

    /**
     * Outputs the full self-contained HTML page and exits.
     */
    protected function renderLayout(string $activeTab, string $content): void
    {
        $title    = 'DevPanel — ' . ucfirst($activeTab);
        $baseUrl  = defined('sURL') ? rtrim((string) sURL, '/') : '';
        $mountPoint = (string) static::config('mount', 'devpanel');

        $returnUrl = $this->rememberedReturnUrl($baseUrl, $mountPoint);

        /*
         * The tabs come from `tabs()` — including Adminer, which is a full page of its own
         * rather than something rendered inside this panel, but wears this strip at the top
         * and marks itself active. A tool reached through a different door is a tool people
         * forget is there.
         *
         * Path-based hrefs, from `tabHref()`: the framework routes `/devpanel/<action>`, not
         * `?action=<action>`, which the URL router ignores.
         */
        $tabHtml = '';

        foreach (static::tabs() as $key => $label) {
            $active = $key === $activeTab ? ' class="active"' : '';
            $href   = htmlspecialchars(
                static::tabHref($key, $baseUrl, $mountPoint),
                ENT_QUOTES
            );

            $tabHtml .= "<a href=\"{$href}\"{$active}>" . htmlspecialchars($label) . "</a>";
        }

        // Whatever the section renderers could not load. Rendered above the
        // content rather than in place of it: the parts that did work are still
        // worth reading, and the parts that did not must not look like emptiness.
        $errors = $this->panelErrorsHtml();

        $css   = $this->panelCss();
        $nonce = \Pramnos\Application\Application::currentInstance()?->cspNonce ?? '';
        $na    = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '"' : '';
        $html  = <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <title>{$title}</title>
          <style{$na}>{$css}</style>
        </head>
        <body>
          <div id="devpanel">
            <header>
              <span class="logo">⚙ DevPanel</span>
              <nav>{$tabHtml}</nav>
              <a href="{$returnUrl}" class="back-btn">&#8592; Back</a>
            </header>
            <main>
              <div class="panel-content">
                {$errors}
                {$content}
              </div>
            </main>
            <footer>PramnosFramework DevPanel · <a href="{$baseUrl}/{$mountPoint}/phpinfo">PHP Info</a> · <a href="{$returnUrl}">&#8592; Back to app</a></footer>
          </div>
        </body>
        </html>
        HTML;

        http_response_code(200);
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow');
        echo $html;
        $this->terminate();
    }

    protected function terminate(): void
    {
        exit;
    }

    protected function renderError(int $code, string $message): never
    {
        http_response_code($code);
        header('Content-Type: text/html; charset=UTF-8');
        echo "<!DOCTYPE html><html><head><title>Error {$code}</title></head><body><h1>Error {$code}</h1><p>"
            . htmlspecialchars($message) . "</p></body></html>";
        $this->terminate();
        throw new \RuntimeException("Terminated: Error {$code}");
    }

    /**
     * Central access guard: feature enabled + dev mode + usertype.
     * Returns true if access is denied (caller should return early).
     */
    protected function guardAccess(): bool
    {
        if (!FeatureRegistry::isEnabled('devpanel')) {
            $this->renderError(404, 'DevPanel feature is not enabled.');
        }
        if (!$this->isDevMode()) {
            $this->renderError(
                403,
                'The DevPanel opens only in a development environment: APP_DEBUG in .env, or '
                . 'the DEVELOPMENT constant. It is deliberately not a setting — a tool that '
                . 'browses the database is not something a checkbox should be able to open on '
                . 'a live server.'
            );
        }
        return $this->guardUserType();
    }

    /**
     * True in a development deployment — the one thing that opens this panel.
     *
     * This used to read the `debug` and `development` **settings** as well: rows in the
     * settings table, one of them a checkbox on the administration screen. Which made the
     * answer to "may this browser open a tool that browses the database, reads the cache and
     * dumps the container" something anybody who reached that screen could tick, on a live
     * server, without a deploy and without a trace in the repository.
     *
     * The environment is the lock instead: `APP_DEBUG` in `.env` or the `DEVELOPMENT`
     * constant, both of which take shell access and a restart, and both of which are visible
     * in the deployment rather than in a table. A developer tool is part of how a server was
     * built, not a runtime preference.
     *
     * It also removes the thing nobody could explain from outside: the panel stayed open with
     * "Debug Mode" unchecked, because the *other* setting — the one with no field on any
     * screen — was true.
     *
     * `getenv('APP_DEBUG')` was asked here directly, and answers "not set" on a project whose
     * `.env` says otherwise: `symfony/dotenv` writes `$_ENV` and never calls `putenv()`. So
     * the panel had been opening through that invisible setting by accident rather than
     * through the environment on purpose.
     */
    private function isDevMode(): bool
    {
        return \Pramnos\Application\Application::isDeveloperEnvironment();
    }

    /**
     * Checks that the current user meets the minimum usertype.
     * Returns true (and redirects) if access should be denied.
     */
    private function guardUserType(): bool
    {
        if ($this->policyCallback !== null) {
            $user = \Pramnos\User\User::getCurrentUser();
            return !($this->policyCallback)($user);
        }

        $user = \Pramnos\User\User::getCurrentUser();
        if ($user === null || (int) ($user->usertype ?? 0) < $this->minUserType) {
            if (defined('sURL')) {
                $this->redirect(sURL);
            }
            return true;
        }
        return false;
    }

    /**
     * Record that one panel could not be drawn.
     *
     * A developer dashboard must not take the whole page down because one of its
     * sections failed — but the alternative used to be an empty `catch`, and an
     * empty section looks exactly like "nothing to show". Three panels here were
     * broken for years behind that resemblance: they queried a table called
     * `tokens` that does not exist, with column names that do not exist either.
     *
     * @param string     $panel     What could not be shown
     * @param \Throwable $exception Why
     */
    /**
     * Record a section that could not be loaded, and say so on the page.
     *
     * A panel must never take the page down over one section — but a section that
     * fails silently is indistinguishable from one with nothing to show, and that
     * is precisely how four broken queries survived here: an empty table reads as
     * "no data", so nobody looks in a log nobody knew was being written.
     *
     * @param string     $panel     What could not be loaded, in the reader's words
     * @param \Throwable $exception The failure
     * @return void
     */
    private function panelError(string $panel, \Throwable $exception): void
    {
        $this->panelErrors[$panel] = $exception->getMessage();

        \Pramnos\Logs\Logger::log(
            'DevPanel could not load ' . $panel . ': ' . $exception->getMessage(),
            'devpanel'
        );
    }

    /**
     * The recorded failures, as an alert per section.
     *
     * @return string HTML, empty when everything loaded
     */
    private function panelErrorsHtml(): string
    {
        $html = '';
        foreach ($this->panelErrors as $panel => $message) {
            $html .= $this->alert(
                'Could not load ' . $panel . ': ' . $message,
                'warning'
            );
        }

        return $html;
    }

    private function card(string $title, string $body): string
    {
        return <<<HTML
            <div class="card">
                <div class="card-title">{$title}</div>
                <div class="card-body">{$body}</div>
            </div>
        HTML;
    }

    private function alert(string $message, string $type = 'info'): string
    {
        return "<div class=\"alert alert-{$type}\">" . htmlspecialchars($message) . "</div>";
    }

    /**
     * Render one of the framework's unix-timestamp columns as a date.
     *
     * `usertokens.lastused`, `tokenactions.servertime` and `userlog.date` are
     * integers, and printing one raw gives the reader a ten-digit number where a
     * date belongs — "1787174877" is not a worse answer than "—", it is a worse
     * answer than the value the column holds.
     *
     * @param mixed $timestamp Unix timestamp, possibly null, '' or 0
     * @return string An escaped date, or an em-dash when there is nothing to show
     */
    private function formatTimestamp($timestamp): string
    {
        if ($timestamp === null || $timestamp === '' || (int) $timestamp <= 0) {
            return '—';
        }

        return htmlspecialchars(date('Y-m-d H:i:s', (int) $timestamp));
    }

    private function statusClass(bool $bad): string
    {
        return $bad ? 'badge warn' : 'badge ok';
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    private function panelCss(): string
    {
        return <<<CSS
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg: #1e1e2e; --bg2: #181825; --surface: #313244; --surface2: #45475a;
            --text: #cdd6f4; --subtext: #a6adc8; --red: #f38ba8; --green: #a6e3a1;
            --yellow: #f9e2af; --blue: #89b4fa; --mauve: #cba6f7;
        }
        body { font-family: system-ui, sans-serif; background: var(--bg); color: var(--text); font-size: 14px; }
        #devpanel { display: flex; flex-direction: column; min-height: 100vh; }
        header { background: var(--bg2); border-bottom: 1px solid var(--surface2);
                 display: flex; align-items: center; padding: 0 16px; gap: 24px; }
        .logo { font-weight: bold; font-size: 16px; color: var(--mauve); padding: 12px 0; }
        nav { display: flex; gap: 2px; }
        nav a { color: var(--subtext); text-decoration: none; padding: 14px 14px; font-size: 13px;
                border-bottom: 3px solid transparent; }
        nav a:hover { color: var(--text); }
        nav a.active { color: var(--blue); border-bottom-color: var(--blue); }
        .back-btn { margin-left: auto; color: var(--subtext); text-decoration: none;
                    font-size: 12px; padding: 5px 10px; border: 1px solid var(--surface2);
                    border-radius: 4px; white-space: nowrap; }
        .back-btn:hover { color: var(--text); border-color: var(--blue); }
        main { flex: 1; padding: 20px; }
        footer { background: var(--bg2); border-top: 1px solid var(--surface2);
                 padding: 8px 16px; font-size: 12px; color: var(--subtext); text-align: center; }
        footer a { color: var(--blue); }
        .panel-content { max-width: 1400px; margin: 0 auto; }
        .grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 16px; }
        .card { background: var(--surface); border-radius: 8px; overflow: hidden; }
        .card-title { background: var(--surface2); padding: 8px 14px; font-weight: 600; font-size: 13px;
                      color: var(--subtext); letter-spacing: 0.05em; text-transform: uppercase; }
        .card-body { padding: 12px 14px; }
        table.info-table { width: 100%; border-collapse: collapse; }
        table.info-table th, table.info-table td { padding: 5px 8px; }
        /*
         * The label hugs its text instead of taking 40% of the row.
         *
         * With a fixed 40% gutter a two-word label sat at the far left and its value in the
         * middle of the card, and the pair read as two unrelated columns — visibly so on the
         * MCP panel, whose labels are short and whose values are one word.
         */
        table.info-table th { text-align: left; color: var(--subtext);
            width: 1%; white-space: nowrap; padding-right: 16px; vertical-align: top; }
        table.info-table td { color: var(--text); }
        table.data-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.data-table th { text-align: left; background: var(--surface); padding: 7px 10px;
                               font-size: 12px; color: var(--subtext); }
        table.data-table td { padding: 6px 10px; border-bottom: 1px solid var(--surface); }
        table.data-table th.num, table.data-table td.num { text-align: right; }
        table.data-table tr:hover td { background: var(--surface2); }
        /*
         * Links anywhere in the panel's content.
         *
         * This started as a rule for links inside a data table: the table names became links
         * into Adminer and arrived as the browser's own default — blue, **purple once
         * visited**, underlined, on a dark panel. Fifteen of those in a column read as a
         * broken stylesheet.
         *
         * Scoped to the table, which was the mistake. The next link went into an info table
         * — "readable in the log viewer" — and arrived unstyled for exactly the same reason:
         * a visited link rendered in a colour nobody can read on `#313244`, sitting next to a
         * green badge. Fixing the instance instead of the class means fixing it again every
         * time, so the selector is now the content area. `nav` and the header keep their own
         * styles above; this is everything a panel renders.
         */
        .panel-content a { color: var(--blue); text-decoration: none;
            border-bottom: 1px dotted color-mix(in srgb, var(--blue) 45%, transparent); }
        .panel-content a:visited { color: var(--blue); }
        .panel-content a:hover { color: var(--text); border-bottom-color: var(--text); }
        .panel-content a:focus-visible { outline: 2px solid var(--blue); outline-offset: 2px; }
        td.empty { text-align: center; color: var(--subtext); font-style: italic; padding: 16px; }
        h3 { color: var(--subtext); font-size: 13px; text-transform: uppercase;
             letter-spacing: 0.05em; margin: 20px 0 6px; }
        h3:first-child { margin-top: 0; }
        code { font-family: 'Cascadia Code', 'Fira Code', monospace; background: var(--surface2);
               padding: 1px 5px; border-radius: 3px; font-size: 12px; }
        .badge { display: inline-block; padding: 1px 7px; border-radius: 10px; font-size: 12px;
                 background: var(--surface2); color: var(--subtext); }
        .badge.ok { background: #1e3a2f; color: var(--green); }
        .badge.warn { background: #3a1e1e; color: var(--red); }
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 12px; }
        .alert-info { background: #1a2a3a; color: var(--blue); }
        .alert-warning { background: #3a2e1e; color: var(--yellow); }
        .alert-error { background: #3a1e1e; color: var(--red); }
        .btn-danger { background: var(--red); color: #1e1e2e; border: none; padding: 6px 14px;
                      border-radius: 5px; cursor: pointer; font-size: 13px; margin-top: 10px; }
        .btn-danger:hover { opacity: 0.85; }
        ul.ref-list { list-style: none; padding: 0; }
        ul.ref-list li { padding: 4px 0; border-bottom: 1px solid var(--surface2); }
        ul.ref-list li.current code { color: var(--green); }
        ul.ref-list li:last-child { border-bottom: none; }
        /*
         * `flex-wrap`, because this bar holds a list nobody chose the length of.
         *
         * It was written for a timespan selector — four fixed chips — and is reused for the
         * cache's namespace filter, which has one chip per namespace an installation happens to
         * have. On a real one that is twenty, several of them named after a table, and the row
         * ran off the side of the panel: the chips past the edge were unreachable, and the page
         * scrolled sideways under everything else.
         */
        .range-bar { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
        .range-bar a { white-space: nowrap; }
        .range-bar a { padding: 4px 12px; border-radius: 4px; background: var(--surface);
                       color: var(--subtext); text-decoration: none; font-size: 12px; }
        .range-bar a.active { background: var(--blue); color: #1e1e2e; }
        .phpinfo-wrapper { background: white; border-radius: 8px; padding: 16px; color: #333; }
        /* MCP panel — a tool per <details>, its schema as a form, the answer underneath. */
        details.mcp-tool { background: var(--surface); border-radius: 6px; margin-bottom: 8px; }
        details.mcp-tool > summary { padding: 9px 14px; cursor: pointer; font-size: 13px;
                                     list-style-position: inside; }
        details.mcp-tool > summary:hover { background: var(--surface2); }
        details.mcp-tool[open] > summary { border-bottom: 1px solid var(--surface2); }
        .mcp-tool-body { padding: 12px 14px; }
        .mcp-desc { color: var(--subtext); font-size: 13px; line-height: 1.5; margin-bottom: 12px; }
        .mcp-note { color: var(--subtext); font-style: italic; font-size: 13px; }
        .mcp-fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                      gap: 10px 14px; }
        .mcp-field { display: flex; flex-direction: column; gap: 3px; }
        .mcp-field label { font-size: 12px; color: var(--subtext); }
        .mcp-required { color: var(--yellow); font-size: 11px; }
        .mcp-field input, .mcp-field select { background: var(--bg2); color: var(--text);
            border: 1px solid var(--surface2); border-radius: 4px; padding: 5px 7px; font-size: 13px;
            font-family: inherit; width: 100%; }
        .mcp-field input:focus, .mcp-field select:focus { outline: none; border-color: var(--blue); }
        .mcp-hint { font-size: 11px; color: var(--subtext); opacity: 0.8; line-height: 1.4; }
        .mcp-actions { display: flex; align-items: center; gap: 12px; margin-top: 12px;
                       flex-wrap: wrap; }
        .mcp-actions button { background: var(--blue); color: var(--bg); border: none;
            padding: 6px 16px; border-radius: 5px; cursor: pointer; font-size: 13px;
            font-family: inherit; }
        .mcp-actions button:hover { opacity: 0.85; }
        .mcp-actions button:disabled { opacity: 0.5; cursor: default; }
        .mcp-raw { font-size: 12px; color: var(--subtext); display: flex; align-items: center;
                   gap: 5px; cursor: pointer; }
        .mcp-timing { font-size: 12px; color: var(--subtext); margin-left: auto; }
        /*
         * `pre` wraps rather than scrolling sideways: these payloads have long single lines
         * (a stack trace, a SQL statement) and a horizontal scrollbar inside a details block
         * is a scrollbar nobody finds.
         */
        pre.mcp-result { margin-top: 12px; background: var(--bg2); border-radius: 5px;
            padding: 10px 12px; font-family: 'Cascadia Code', 'Fira Code', monospace;
            font-size: 12px; line-height: 1.5; max-height: 420px; overflow-y: auto;
            white-space: pre-wrap; word-break: break-word; border-left: 3px solid var(--surface2); }
        pre.mcp-result.failed { border-left-color: var(--red); color: var(--red); }
        CSS;
    }
}
