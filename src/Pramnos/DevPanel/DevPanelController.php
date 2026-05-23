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
 * @package PramnosFramework
 * @subpackage DevPanel
 */
class DevPanelController extends Controller
{
    /** Minimum usertype required to access any DevPanel action. */
    protected int $minUserType = 90;

    /** Optional policy callback — when set, replaces the usertype check. */
    protected ?\Closure $policyCallback = null;

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
        $this->addAuthAction(['display', 'db', 'cache', 'users', 'performance', 'git', 'phpinfo']);

        // Register custom panel slugs as auth actions so Controller::exec() dispatches them.
        foreach (array_keys(static::$customPanels) as $slug) {
            $this->addAuthAction($slug);
        }

        parent::__construct($application);

        // Allow app to set a higher minimum via app.php devpanel.min_usertype
        $min = Settings::getSetting('devpanel.min_usertype');
        if ($min !== false && $min !== null && (int) $min > 0) {
            $this->minUserType = (int) $min;
        }
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
            if (!FeatureRegistry::isEnabled('devpanel')) {
                $this->renderError(404, 'DevPanel feature is not enabled.');
            }
            if ($this->guardUserType()) {
                return null;
            }
            $panel   = static::$customPanels[$name];
            $content = (string) ($panel['renderer'])();
            $this->renderLayout($name, $content);
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
        if (!FeatureRegistry::isEnabled('devpanel')) {
            $this->renderError(404, 'DevPanel feature is not enabled.');
        }
        if ($this->guardUserType()) {
            return null;
        }

        $this->renderLayout('overview', $this->renderOverview());
    }

    /**
     * Database panel — cross-DB sizes, connection counts, cache hit ratio.
     * TimescaleDB sub-panel if the extension is present.
     */
    public function db(): mixed
    {
        if (!FeatureRegistry::isEnabled('devpanel')) {
            $this->renderError(404, 'DevPanel feature is not enabled.');
        }
        if ($this->guardUserType()) {
            return null;
        }

        $this->renderLayout('db', $this->renderDb());
    }

    /**
     * Cache browser — stats, paginated item list, AJAX flush.
     */
    public function cache(): mixed
    {
        if (!FeatureRegistry::isEnabled('devpanel')) {
            $this->renderError(404, 'DevPanel feature is not enabled.');
        }
        if ($this->guardUserType()) {
            return null;
        }

        // POST: flush cache (AJAX endpoint)
        if (isset($_POST['action']) && $_POST['action'] === 'flush') {
            $this->handleCacheFlush();
        }

        $this->renderLayout('cache', $this->renderCache());
    }

    /**
     * User activity — active sessions, token audit, login security monitor.
     */
    public function users(): mixed
    {
        if (!FeatureRegistry::isEnabled('devpanel')) {
            $this->renderError(404, 'DevPanel feature is not enabled.');
        }
        if ($this->guardUserType()) {
            return null;
        }

        $this->renderLayout('users', $this->renderUsers());
    }

    /**
     * Performance report — slowest endpoints and users.
     */
    public function performance(): mixed
    {
        if (!FeatureRegistry::isEnabled('devpanel')) {
            $this->renderError(404, 'DevPanel feature is not enabled.');
        }
        if ($this->guardUserType()) {
            return null;
        }

        $this->renderLayout('performance', $this->renderPerformance());
    }

    /**
     * Git info — full branch, commit history, remotes.
     */
    public function git(): mixed
    {
        if (!FeatureRegistry::isEnabled('devpanel')) {
            $this->renderError(404, 'DevPanel feature is not enabled.');
        }
        if ($this->guardUserType()) {
            return null;
        }

        $this->renderLayout('git', $this->renderGit());
    }

    /**
     * PHP Info — admin-only phpinfo() output.
     */
    public function phpinfo(): mixed
    {
        if (!FeatureRegistry::isEnabled('devpanel')) {
            $this->renderError(404, 'DevPanel feature is not enabled.');
        }
        if ($this->guardUserType()) {
            return null;
        }

        ob_start();
        \phpinfo();
        $phpInfoRaw = ob_get_clean();

        // Strip doctype/html/head/body wrappers — only keep the inner content
        $phpInfoRaw = preg_replace('/^.*<body>/si', '', $phpInfoRaw);
        $phpInfoRaw = preg_replace('/<\/body>.*$/si', '', $phpInfoRaw);

        $this->renderLayout('phpinfo', '<div class="phpinfo-wrapper">' . $phpInfoRaw . '</div>');
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
                $res = $db->execute(
                    "SELECT relname AS tbl,
                            pg_size_pretty(pg_total_relation_size(oid)) AS total,
                            pg_size_pretty(pg_relation_size(oid)) AS data,
                            n_live_tup AS rows
                     FROM pg_class c
                     JOIN pg_namespace n ON n.oid = c.relnamespace
                     WHERE c.relkind = 'r' AND n.nspname = 'public'
                     ORDER BY pg_total_relation_size(oid) DESC
                     LIMIT 30"
                );
                while ($res && !$res->EOF) {
                    $tables[] = $res->fields;
                    $res->moveNext();
                }
            } else {
                $dbName = $db->execute('SELECT DATABASE() AS d');
                $dbName = $dbName ? ($dbName->fields['d'] ?? '') : '';
                $res    = $db->execute(
                    "SELECT table_name AS tbl,
                            ROUND((data_length + index_length) / 1024, 1) AS total,
                            ROUND(data_length / 1024, 1) AS data,
                            table_rows AS rows
                     FROM information_schema.tables
                     WHERE table_schema = ?
                     ORDER BY (data_length + index_length) DESC
                     LIMIT 30",
                    [$dbName]
                );
                while ($res && !$res->EOF) {
                    $tables[] = $res->fields;
                    $res->moveNext();
                }
            }
        } catch (\Throwable $e) {
            return $this->alert('Error querying table stats: ' . htmlspecialchars($e->getMessage()), 'error');
        }

        // TimescaleDB detection
        try {
            $tsRes = $db->execute("SELECT extversion FROM pg_extension WHERE extname = 'timescaledb'");
            if ($tsRes && !$tsRes->EOF) {
                $isTimescaleDb = true;
            }
        } catch (\Throwable) {
        }

        $rows = '';
        foreach ($tables as $t) {
            $tbl  = htmlspecialchars($t['tbl']  ?? '');
            $tot  = htmlspecialchars($t['total'] ?? '');
            $data = htmlspecialchars($t['data']  ?? '');
            $rowc = number_format((int) ($t['rows'] ?? 0));
            $rows .= "<tr><td>{$tbl}</td><td class='num'>{$rowc}</td><td class='num'>{$data} KB</td><td class='num'>{$tot}</td></tr>";
        }

        $unit    = $isPostgres ? '' : ' KB';
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

        return $content;
    }

    private function renderTimescaleDb(\Pramnos\Database\Database $db): string
    {
        $hypertables = [];
        try {
            $res = $db->execute(
                "SELECT hypertable_name, num_chunks, compression_enabled
                 FROM timescaledb_information.hypertables ORDER BY hypertable_name"
            );
            while ($res && !$res->EOF) {
                $hypertables[] = $res->fields;
                $res->moveNext();
            }
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
            $method = $cache->getCacheMethod();
        } catch (\Throwable) {
            return $this->alert('Cache system not available.', 'warning');
        }

        $flushButton = <<<HTML
            <form method="POST" onsubmit="return confirm('Flush entire cache?');">
                <input type="hidden" name="action" value="flush">
                <button type="submit" class="btn-danger">Flush All Cache</button>
            </form>
        HTML;

        return <<<HTML
            <div class="grid-2">
                {$this->card('Cache Status', <<<INNER
                    <table class="info-table">
                        <tr><th>Adapter</th><td>{$method}</td></tr>
                    </table>
                    {$flushButton}
                INNER)}
            </div>
        HTML;
    }

    private function renderUsers(): string
    {
        $db = \Pramnos\Framework\Factory::getDatabase();
        if (!$db || !$db->connected) {
            return $this->alert('Database not connected.', 'warning');
        }

        // Active sessions (tokens)
        $sessions = [];
        try {
            $prefix = defined('PREFIX') ? PREFIX : '';
            $res    = $db->execute(
                "SELECT t.tokenid, u.username, t.last_used, t.ip_address,
                        t.application, t.tokentype
                 FROM {$prefix}tokens t
                 JOIN {$prefix}users u ON u.userid = t.userid
                 WHERE t.status = 1 AND t.tokentype IN (1,3)
                 ORDER BY t.last_used DESC
                 LIMIT 50"
            );
            while ($res && !$res->EOF) {
                $sessions[] = $res->fields;
                $res->moveNext();
            }
        } catch (\Throwable) {
        }

        // Active lockouts
        $lockouts = [];
        try {
            $prefix = defined('PREFIX') ? PREFIX : '';
            $res    = $db->execute(
                "SELECT identifier, ip_address, lockout_until, failed_attempts
                 FROM {$prefix}loginlockouts
                 WHERE lockout_until > NOW()
                 ORDER BY lockout_until DESC
                 LIMIT 20"
            );
            while ($res && !$res->EOF) {
                $lockouts[] = $res->fields;
                $res->moveNext();
            }
        } catch (\Throwable) {
        }

        $sessionRows = '';
        foreach ($sessions as $s) {
            $user    = htmlspecialchars($s['username'] ?? '');
            $app     = htmlspecialchars($s['application'] ?? '—');
            $ip      = htmlspecialchars($s['ip_address'] ?? '—');
            $last    = $s['last_used'] ?? '—';
            $sessionRows .= "<tr><td>{$user}</td><td>{$ip}</td><td>{$app}</td><td>{$last}</td></tr>";
        }

        $lockoutRows = '';
        foreach ($lockouts as $l) {
            $id      = htmlspecialchars($l['identifier'] ?? '');
            $ip      = htmlspecialchars($l['ip_address'] ?? '—');
            $until   = htmlspecialchars($l['lockout_until'] ?? '');
            $attempts= (int) ($l['failed_attempts'] ?? 0);
            $lockoutRows .= "<tr><td>{$id}</td><td>{$ip}</td><td>{$attempts}</td><td>{$until}</td></tr>";
        }

        $noSessions  = $sessionRows  === '' ? '<tr><td colspan="4" class="empty">No active sessions</td></tr>' : $sessionRows;
        $noLockouts  = $lockoutRows  === '' ? '<tr><td colspan="4" class="empty">No active lockouts</td></tr>' : $lockoutRows;

        return <<<HTML
            <h3>Active Sessions (web + API)</h3>
            <table class="data-table">
                <thead><tr><th>User</th><th>IP</th><th>Application</th><th>Last seen</th></tr></thead>
                <tbody>{$noSessions}</tbody>
            </table>
            <h3>Login Lockouts</h3>
            <table class="data-table">
                <thead><tr><th>Identifier</th><th>IP</th><th>Attempts</th><th>Locked until</th></tr></thead>
                <tbody>{$noLockouts}</tbody>
            </table>
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

        $endpoints = [];
        try {
            $prefix = defined('PREFIX') ? PREFIX : '';
            $res    = $db->execute(
                "SELECT urlid AS endpoint, method,
                        COUNT(*) AS calls,
                        ROUND(AVG(execution_time_ms), 1) AS avg_ms,
                        MAX(execution_time_ms) AS max_ms
                 FROM {$prefix}tokenactions
                 WHERE servertime >= NOW() - INTERVAL {$range} HOUR
                 GROUP BY urlid, method
                 ORDER BY avg_ms DESC
                 LIMIT 20"
            );
            while ($res && !$res->EOF) {
                $endpoints[] = $res->fields;
                $res->moveNext();
            }
        } catch (\Throwable) {
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

        $noData = $rows === '' ? '<tr><td colspan="5" class="empty">No data for this period</td></tr>' : $rows;

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
            $db     = \Pramnos\Framework\Factory::getDatabase();
            $loader = new \Pramnos\Database\Migrations\MigrationLoader();
            $runner = new \Pramnos\Database\Migrations\MigrationRunner($db);
            $paths  = [];
            foreach (FeatureRegistry::getEnabled() as $key) {
                $paths = array_merge($paths, FeatureRegistry::getMigrationPaths($key));
            }
            $all     = $loader->loadFromDirectories($paths);
            $history = $runner->getHistory();
            $applied = count($history);
            $pending = count($all) - $applied;
            $last    = !empty($history) ? end($history)['slug'] ?? '—' : '—';
            return [max(0, $pending), $applied, htmlspecialchars($last)];
        } catch (\Throwable) {
            return ['—', '—', '—'];
        }
    }

    private function fetchQueueStats(): array
    {
        try {
            $db     = \Pramnos\Framework\Factory::getDatabase();
            $prefix = defined('PREFIX') ? PREFIX : '';
            $res    = $db->execute(
                "SELECT status, COUNT(*) AS cnt FROM {$prefix}queue_jobs GROUP BY status"
            );
            $stats  = ['pending' => 0, 'running' => 0, 'failed' => 0];
            while ($res && !$res->EOF) {
                $status = $res->fields['status'] ?? '';
                $cnt    = (int) ($res->fields['cnt'] ?? 0);
                if (isset($stats[$status])) {
                    $stats[$status] = $cnt;
                }
                $res->moveNext();
            }
            return [$stats['pending'], $stats['running'], $stats['failed']];
        } catch (\Throwable) {
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
        exit;
    }

    // =========================================================================
    // Layout & HTML helpers
    // =========================================================================

    /**
     * Outputs the full self-contained HTML page and exits.
     */
    private function renderLayout(string $activeTab, string $content): void
    {
        $title    = 'DevPanel — ' . ucfirst($activeTab);
        $baseUrl  = defined('sURL') ? rtrim((string) sURL, '/') : '';
        $mountPoint = Settings::getSetting('devpanel.mount') ?: 'devpanel';

        $tabs = [
            'overview'    => ['Overview',    ''],
            'db'          => ['Database',    'action=db'],
            'cache'       => ['Cache',       'action=cache'],
            'users'       => ['Users',       'action=users'],
            'performance' => ['Performance', 'action=performance'],
            'git'         => ['Git',         'action=git'],
            'phpinfo'     => ['PHP Info',    'action=phpinfo'],
        ];

        // Append registered custom panels to the navigation.
        foreach (static::$customPanels as $slug => $panel) {
            $tabs[$slug] = [$panel['label'], 'action=' . $slug];
        }

        $tabHtml = '';
        foreach ($tabs as $key => [$label, $qs]) {
            $active = $key === $activeTab ? ' class="active"' : '';
            $href   = $baseUrl . '/' . $mountPoint . ($qs ? '?' . $qs : '');
            $tabHtml .= "<a href=\"{$href}\"{$active}>" . htmlspecialchars($label) . "</a>";
        }

        $css  = $this->panelCss();
        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <title>{$title}</title>
          <style>{$css}</style>
        </head>
        <body>
          <div id="devpanel">
            <header>
              <span class="logo">⚙ DevPanel</span>
              <nav>{$tabHtml}</nav>
            </header>
            <main>
              <div class="panel-content">
                {$content}
              </div>
            </main>
            <footer>PramnosFramework DevPanel · <a href="{$baseUrl}/{$mountPoint}?action=phpinfo">PHP Info</a></footer>
          </div>
        </body>
        </html>
        HTML;

        http_response_code(200);
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow');
        echo $html;
        exit;
    }

    private function renderError(int $code, string $message): never
    {
        http_response_code($code);
        header('Content-Type: text/html; charset=UTF-8');
        echo "<!DOCTYPE html><html><head><title>Error {$code}</title></head><body><h1>Error {$code}</h1><p>"
            . htmlspecialchars($message) . "</p></body></html>";
        exit;
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
        table.info-table th { text-align: left; color: var(--subtext); width: 40%; }
        table.info-table td { color: var(--text); }
        table.data-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.data-table th { text-align: left; background: var(--surface); padding: 7px 10px;
                               font-size: 12px; color: var(--subtext); }
        table.data-table td { padding: 6px 10px; border-bottom: 1px solid var(--surface); }
        table.data-table th.num, table.data-table td.num { text-align: right; }
        table.data-table tr:hover td { background: var(--surface2); }
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
        .range-bar { display: flex; gap: 6px; margin-bottom: 12px; }
        .range-bar a { padding: 4px 12px; border-radius: 4px; background: var(--surface);
                       color: var(--subtext); text-decoration: none; font-size: 12px; }
        .range-bar a.active { background: var(--blue); color: #1e1e2e; }
        .phpinfo-wrapper { background: white; border-radius: 8px; padding: 16px; color: #333; }
        CSS;
    }
}
