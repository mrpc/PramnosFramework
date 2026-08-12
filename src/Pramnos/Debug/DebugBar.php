<?php

declare(strict_types=1);

namespace Pramnos\Debug;

use Pramnos\Debug\Collectors\CollectorInterface;
use Pramnos\Debug\Collectors\MigrationsCollector;
use Pramnos\Debug\Collectors\TimeCollector;

/**
 * Debug toolbar for Pramnos applications.
 *
 * Aggregates data from registered collectors and renders a self-contained
 * `<div id="pramnos-debugbar">` HTML widget with inlined CSS and JS. No npm
 * build step or external assets required.
 *
 * Typical usage (via DebugBarServiceProvider):
 *
 *   $bar = DebugBar::getInstance();
 *   $bar->addCollector(new QueryCollector($db));
 *   // ... request runs ...
 *   echo $bar->render(); // injected before </body> by DebugBarMiddleware
 *
 * Named timers (forwarded to the TimeCollector if registered):
 *
 *   DebugBar::startTimer('auth-check');
 *   // ...
 *   DebugBar::stopTimer('auth-check');
 *
 */
class DebugBar
{
    private static ?self $instance = null;

    /** @var array<string, CollectorInterface> */
    private array $collectors = [];

    private ?TimeCollector $timeCollector = null;

    private function __construct() {}

    public static function getInstance(): static
    {
        if (static::$instance === null) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    /** Reset singleton (used in tests). */
    public static function reset(): void
    {
        static::$instance = null;
    }

    // ── Collector Registration ────────────────────────────────────────────────

    public function addCollector(CollectorInterface $collector): static
    {
        $this->collectors[$collector->name()] = $collector;
        if ($collector instanceof TimeCollector) {
            $this->timeCollector = $collector;
        }
        return $this;
    }

    public function getCollector(string $name): ?CollectorInterface
    {
        return $this->collectors[$name] ?? null;
    }

    /** @return array<string, CollectorInterface> */
    public function getCollectors(): array
    {
        return $this->collectors;
    }

    // ── Timer Convenience ──────────────────────────────────────────────────────

    public static function startTimer(string $name): void
    {
        static::getInstance()->timeCollector?->startTimer($name);
    }

    public static function stopTimer(string $name): void
    {
        static::getInstance()->timeCollector?->stopTimer($name);
    }

    /**
     * Records a migration that ran during this request.
     *
     * Adds a timeline segment to the TimeCollector AND an entry to the
     * MigrationsCollector so both the timeline and the Migrations tab reflect it.
     *
     * @param string $slug   Migration slug.
     * @param float  $ms     Execution time in milliseconds.
     * @param string $status 'ran' (success) or 'failed'.
     */
    public static function recordMigration(string $slug, float $ms, string $status = 'ran'): void
    {
        $bar = static::getInstance();
        $bar->timeCollector?->addCompletedSegment('migration:' . $slug, $ms);
        $mc = $bar->getCollector('migrations');
        if ($mc instanceof MigrationsCollector) {
            $mc->record($slug, $ms, $status);
        }
    }

    // ── Rendering ─────────────────────────────────────────────────────────────

    /**
     * Render the debug toolbar HTML widget.
     *
     * Returns a self-contained HTML string suitable for injection before
     * `</body>`. Inline CSS and JavaScript — no external dependencies.
     *
     * @param string $nonce CSP nonce for the inline <style> and <script> tags.
     *                      Pass Application::$cspNonce; leave empty when CSP
     *                      is not configured (dev environments without strict CSP).
     */
    public function render(string $nonce = ''): string
    {
        $tabs      = [];
        $panels    = [];
        $memData   = [];
        $routeData = [];

        // Collectors that are shown inline in the bar, not as clickable tabs.
        $inlineOnly = ['memory'];

        foreach ($this->collectors as $name => $collector) {
            try {
                $data = $collector->collect();
            } catch (\Throwable $e) {
                $data = ['error' => $e->getMessage()];
            }

            // Memory: inline-only — no tab, but data is needed for the info strip.
            if ($name === 'memory') {
                $memData = $data;
                // Still create the panel so the route badge can reference it if needed.
                continue;
            }

            // Route: no tab button — shown as a clickable badge in the info strip.
            if ($name === 'route') {
                $routeData = $data;
                $panels[]  = sprintf(
                    '<div class="pdb-panel" id="pdb-panel-%s" style="display:none">%s</div>',
                    htmlspecialchars($name),
                    $this->renderPanel($name, $data),
                );
                continue;
            }

            $label     = $this->formatTabLabel($name, $data);
            $panelHtml = $this->renderPanel($name, $data);
            $tabs[]    = sprintf(
                '<button class="pdb-tab" data-panel="%s">%s</button>',
                htmlspecialchars($name),
                $label,
            );
            $panels[]  = sprintf(
                '<div class="pdb-panel" id="pdb-panel-%s" style="display:none">%s</div>',
                htmlspecialchars($name),
                $panelHtml,
            );
        }

        if (empty($tabs) && empty($panels)) {
            return '';
        }

        // The AJAX tab is always present and always starts empty: it is filled
        // by the browser, not by PHP. A page's requests are what the toolbar
        // could not see before — everything above describes the one request that
        // built the page, and then the page carries on talking to the server for
        // as long as it is open.
        $tabs[]   = '<button class="pdb-tab" data-panel="ajax" id="pdb-ajax-tab">ajax</button>';
        $panels[] = '<div class="pdb-panel" id="pdb-panel-ajax" style="display:none">'
            . '<p id="pdb-ajax-empty">No requests yet. XHR and fetch calls made by '
            . 'this page will appear here.</p>'
            . '<table class="pdb-table" id="pdb-ajax-table" style="display:none">'
            . '<thead><tr><th></th><th title="When the request was sent">At</th>'
            . '<th>Method</th><th>URL</th><th>Status</th>'
            . '<th title="Time spent in PHP, as the server reported it">Server</th>'
            . '<th title="Wall-clock time in the browser, including the network">Client</th>'
            . '<th>Queries</th></tr></thead>'
            . '<tbody id="pdb-ajax-rows"></tbody></table></div>';

        $tabsHtml   = implode('', $tabs);
        $panelsHtml = implode('', $panels);
        $infoHtml   = $this->renderInfoStrip($memData, $routeData);
        $css        = $this->css();
        $js         = $this->js();
        $na         = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '"' : '';

        return <<<HTML
<style{$na}>{$css}</style>
<div id="pramnos-debugbar">
  <div id="pdb-bar">
    <span id="pdb-brand">&#9881; Pramnos</span>
    {$tabsHtml}
    {$infoHtml}
    <a class="pdb-devpanel" href="/devpanel" title="DevPanel">&#128270; DevPanel</a>
    <button class="pdb-close" id="pdb-close-btn" title="Hide the toolbar">&#x2715;</button>
  </div>
  <div id="pdb-panels">{$panelsHtml}</div>
</div>
<button id="pdb-restore" title="Show the Pramnos toolbar">&#9881;</button>
<script{$na}>{$js}</script>
HTML;
    }

    // ── Internal Panel Renderers ──────────────────────────────────────────────

    private function formatTabLabel(string $name, array $data): string
    {
        return match ($name) {
            'queries'    => (function() use ($data): string {
                                $live   = ($data['count'] ?? 0) - ($data['cached'] ?? 0);
                                $cached = $data['cached'] ?? 0;
                                $ms     = $data['total_ms'] ?? 0;
                                $suffix = $cached > 0 ? " · {$cached} cached" : '';
                                return "SQL ({$live}{$suffix} · {$ms}ms)";
                            })(),
            'timers'     => 'Time (' . ($data['request_ms'] ?? 0) . 'ms)',
            'logs'       => 'Logs (' . ($data['count'] ?? 0) . ')',
            'session'    => 'Session (' . ($data['count'] ?? 0) . ')',
            'views'      => (function() use ($data): string {
                                $total  = $data['count'] ?? 0;
                                $cached = $data['cached'] ?? 0;
                                $suffix = $cached > 0 ? " · {$cached} cached" : '';
                                return "Views ({$total}{$suffix})";
                            })(),
            'models'     => 'Models (' . ($data['count'] ?? 0) . ' · ' . ($data['ops'] ?? 0) . ' ops)',
            'migrations'  => (function() use ($data): string {
                                $n = $data['count_request'] ?? 0;
                                return $n > 0 ? "Migrations ({$n} ran)" : 'Migrations';
                             })(),
            'exceptions' => ($data['count'] ?? 0) > 0
                            ? '⚠ Exceptions (' . $data['count'] . ')'
                            : 'Exceptions (0)',
            default      => ucfirst($name),
        };
    }

    private function renderPanel(string $name, array $data): string
    {
        return match ($name) {
            'queries'    => $this->renderQueries($data),
            'timers'     => $this->renderTimers($data),
            'memory'     => $this->renderMemory($data),
            'route'      => $this->renderRoute($data),
            'logs'       => $this->renderLogs($data),
            'session'    => $this->renderSession($data),
            'views'      => $this->renderViews($data),
            'models'     => $this->renderModels($data),
            'migrations'  => $this->renderMigrations($data),
            'exceptions' => $this->renderExceptions($data),
            default      => '<pre>' . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT)) . '</pre>',
        };
    }

    private function renderInfoStrip(array $memData, array $routeData): string
    {
        $items = [];

        // Route badge — clickable, opens the route panel.
        $method = strtoupper((string) ($routeData['method'] ?? ''));
        $uri    = (string) ($routeData['uri'] ?? '');
        if ($method !== '' && $uri !== '' && $uri !== '(not matched)') {
            $methodCls = match ($method) {
                'GET'            => 'pdb-m-get',
                'POST'           => 'pdb-m-post',
                'PUT', 'PATCH'   => 'pdb-m-put',
                'DELETE'         => 'pdb-m-del',
                default          => '',
            };
            $items[] = sprintf(
                '<button class="pdb-tab pdb-route-badge" data-panel="route">'
                . '<span class="pdb-method %s">%s</span> %s</button>',
                $methodCls,
                htmlspecialchars($method),
                htmlspecialchars($uri),
            );
        }

        // PHP version chip.
        $items[] = '<span class="pdb-chip">PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '</span>';

        // Memory chip — peak / limit.
        $peak  = $memData['peak_human'] ?? '';
        if ($peak !== '') {
            $limit = ini_get('memory_limit');
            $limitStr = ($limit && $limit !== '-1') ? ' / ' . $limit : '';
            $items[] = '<span class="pdb-chip">Mem: ' . htmlspecialchars($peak) . $limitStr . '</span>';
        }

        // Environment chip (APP_ENV or ENVIRONMENT from $_ENV / $_SERVER).
        $env = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? $_ENV['ENVIRONMENT'] ?? $_SERVER['ENVIRONMENT'] ?? null;
        if ($env !== null) {
            $isProd = in_array(strtolower((string) $env), ['prod', 'production'], true);
            $cls    = $isProd ? 'pdb-chip pdb-env-prod' : 'pdb-chip pdb-env-dev';
            $items[] = '<span class="' . $cls . '">' . htmlspecialchars((string) $env) . '</span>';
        }

        return '<div id="pdb-info">' . implode('', $items) . '</div>';
    }

    private function renderQueries(array $data): string
    {
        $rows = '';
        foreach ($data['queries'] ?? [] as $q) {
            $rawSql    = $q['sql'] ?? '';
            $sql       = htmlspecialchars($rawSql);
            $sqlAttr   = htmlspecialchars($rawSql, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $time      = $q['time'] ?? 0;
            $fromCache = (bool) ($q['from_cache'] ?? false);
            $cls       = (!$fromCache && $time > 100) ? 'pdb-slow' : '';
            $timeTd    = $fromCache
                ? '<td class="pdb-time pdb-cached">CACHE</td>'
                : "<td class=\"pdb-time\">{$time}ms</td>";
            $copyBtn   = "<button class=\"pdb-copy\" data-sql=\"{$sqlAttr}\" title=\"Copy SQL\">&#x2398;</button>";
            $rows .= "<tr class=\"{$cls}\">{$timeTd}<td class=\"pdb-sql\">{$copyBtn} {$sql}</td></tr>";
        }
        $count  = $data['count'] ?? 0;
        $cached = $data['cached'] ?? 0;
        $live   = $count - $cached;
        $total  = $data['total_ms'] ?? 0;
        $info   = $cached > 0 ? " ({$live} live · {$cached} from cache)" : '';

        // Copy the lot, annotated with timings, in the order they ran. Copying
        // twenty statements one button at a time is the sort of thing somebody
        // does once and then stops reporting the problem.
        $all = [];
        foreach ($data['queries'] ?? [] as $q) {
            $ms = ($q['from_cache'] ?? false) ? 'CACHE' : (($q['time'] ?? 0) . 'ms');
            $all[] = '-- ' . $ms . "\n" . ($q['sql'] ?? '') . ';';
        }
        $allAttr = htmlspecialchars(
            implode("\n\n", $all),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $copyAll = $count > 0
            ? " <button class=\"pdb-copy pdb-copy-all\" data-sql=\"{$allAttr}\""
                . " title=\"Copy every statement, with its timing\">&#x2398; Copy all</button>"
            : '';

        return "<p><strong>{$count} queries{$info}</strong> — {$total}ms total{$copyAll}</p>"
             . "<table class=\"pdb-table\"><thead><tr><th>Time</th><th>SQL</th></tr></thead><tbody>{$rows}</tbody></table>";
    }

    private function renderTimers(array $data): string
    {
        $totalMs = (float) ($data['request_ms'] ?? 1);
        $named   = $data['named_timers'] ?? [];

        // Segment colors (Catppuccin palette)
        $palette = ['#89b4fa', '#cba6f7', '#a6e3a1', '#f9e2af', '#fab387', '#f38ba8', '#94e2d5'];
        $html    = '<p style="margin:0 0 6px"><strong>Request:</strong> ' . $totalMs . 'ms &nbsp;'
                 . '<span style="color:#6c7086">started ' . ($data['start_time'] ?? '') . '</span></p>';

        if (!empty($named)) {
            // Timeline bar
            $html .= '<div class="pdb-timeline">';
            foreach ($named as $i => $t) {
                $leftPct  = $totalMs > 0 ? min(100, ($t['offset_ms'] / $totalMs) * 100) : 0;
                $widthPct = $totalMs > 0 ? max(0.5, min(100 - $leftPct, ($t['ms'] / $totalMs) * 100)) : 1;
                $color    = $palette[$i % count($palette)];
                $label    = htmlspecialchars($t['name']);
                $title    = "{$label}: {$t['ms']}ms";
                $html    .= sprintf(
                    '<div class="pdb-tl-seg" style="left:%.2f%%;width:%.2f%%;background:%s" title="%s">%s</div>',
                    $leftPct, $widthPct, $color, $title,
                    $widthPct > 4 ? $label : ''
                );
            }
            $html .= '</div>';

            // Legend table
            $rows = '';
            foreach ($named as $i => $t) {
                $color  = $palette[$i % count($palette)];
                $name   = htmlspecialchars($t['name']);
                $pct    = $totalMs > 0 ? round($t['ms'] / $totalMs * 100, 1) : 0;
                $rows  .= "<tr>"
                        . "<td><span style=\"display:inline-block;width:10px;height:10px;border-radius:2px;background:{$color};margin-right:4px\"></span>{$name}</td>"
                        . "<td class=\"pdb-time\">{$t['ms']}ms</td>"
                        . "<td style=\"color:#6c7086\">{$pct}%</td>"
                        . "</tr>";
            }
            $html .= "<table class=\"pdb-table\" style=\"margin-top:8px\">"
                   . "<thead><tr><th>Phase</th><th>Duration</th><th>%</th></tr></thead>"
                   . "<tbody>{$rows}</tbody></table>";
        }
        return $html;
    }

    private function renderMemory(array $data): string
    {
        return '<dl class="pdb-dl">'
             . '<dt>Peak memory</dt><dd>' . ($data['peak_human'] ?? '') . '</dd>'
             . '<dt>Current memory</dt><dd>' . ($data['current_human'] ?? '') . '</dd>'
             . '</dl>';
    }

    private function renderRoute(array $data): string
    {
        $rows = '';
        foreach ($data as $k => $v) {
            $val  = is_array($v) ? implode(', ', $v) : (string) $v;
            $rows .= '<tr><td>' . htmlspecialchars($k) . '</td><td>' . htmlspecialchars($val) . '</td></tr>';
        }
        return "<table class=\"pdb-table\"><tbody>{$rows}</tbody></table>";
    }

    private function renderLogs(array $data): string
    {
        $rows = '';
        foreach ($data['entries'] ?? [] as $e) {
            $level = htmlspecialchars($e['level'] ?? 'info');
            $msg   = htmlspecialchars($e['message'] ?? '');
            $time  = isset($e['time']) ? date('H:i:s', (int) $e['time']) : '';
            $rows .= "<tr><td>{$time}</td><td class=\"pdb-level-{$level}\">{$level}</td><td>{$msg}</td></tr>";
        }
        return "<table class=\"pdb-table\"><thead><tr><th>Time</th><th>Level</th><th>Message</th></tr></thead><tbody>{$rows}</tbody></table>";
    }

    private function renderSession(array $data): string
    {
        if (!($data['active'] ?? false)) {
            return '<p>No active session.</p>';
        }
        $rows = '';
        foreach ($data['data'] ?? [] as $k => $v) {
            $rows .= '<tr><td>' . htmlspecialchars((string) $k) . '</td><td>' . htmlspecialchars((string) $v) . '</td></tr>';
        }
        return '<p><strong>Session ID:</strong> ' . htmlspecialchars($data['session_id'] ?? '') . '</p>'
             . "<table class=\"pdb-table\"><thead><tr><th>Key</th><th>Value</th></tr></thead><tbody>{$rows}</tbody></table>";
    }

    private function renderViews(array $data): string
    {
        $rows = '';
        foreach ($data['views'] ?? [] as $v) {
            $view      = htmlspecialchars($v['view'] ?? '');
            $tpl       = htmlspecialchars($v['template'] ?? '');
            $ms        = $v['render_ms'] ?? 0;
            $fromCache = (bool) ($v['from_cache'] ?? false);
            $cls       = (!$fromCache && $ms > 50) ? 'pdb-slow' : '';
            $timeTd    = $fromCache
                ? '<td class="pdb-time pdb-cached">CACHE</td>'
                : "<td class=\"pdb-time\">{$ms}ms</td>";
            $rows .= "<tr class=\"{$cls}\">{$timeTd}<td>{$view}</td><td class=\"pdb-sql\">{$tpl}</td></tr>";
        }
        $count  = $data['count'] ?? 0;
        $cached = $data['cached'] ?? 0;
        $live   = $count - $cached;
        $info   = $cached > 0 ? " ({$live} rendered · {$cached} from cache)" : '';
        $empty  = $rows === '' ? '<tr><td colspan="3" style="color:#6c7086">No views rendered</td></tr>' : $rows;
        return "<p><strong>{$count} template(s){$info}</strong></p>"
             . "<table class=\"pdb-table\"><thead><tr><th>Time</th><th>View</th><th>Template</th></tr></thead><tbody>{$empty}</tbody></table>";
    }

    private function renderModels(array $data): string
    {
        $rows = '';
        foreach ($data['operations'] ?? [] as $op) {
            $cls   = htmlspecialchars($op['class'] ?? '');
            $table = htmlspecialchars($op['table'] ?? '');
            $oper  = htmlspecialchars($op['op'] ?? '');
            $key   = htmlspecialchars((string) ($op['key'] ?? '—'));
            $rows .= "<tr><td>{$cls}</td><td>{$table}</td><td>{$oper}</td><td>{$key}</td></tr>";
        }
        $classes = $data['count'] ?? 0;
        $ops     = $data['ops'] ?? 0;
        $empty   = $rows === '' ? '<tr><td colspan="4" style="color:#6c7086">No model operations</td></tr>' : $rows;
        return "<p><strong>{$classes} model class(es)</strong> — {$ops} operation(s)</p>"
             . "<table class=\"pdb-table\"><thead><tr><th>Class</th><th>Table</th><th>Op</th><th>Key</th></tr></thead><tbody>{$empty}</tbody></table>";
    }

    private function renderMigrations(array $data): string
    {
        $ranNow = $data['this_request'] ?? [];

        if (empty($ranNow)) {
            return '<p style="color:#6c7086">No migrations ran this request.</p>';
        }

        $rows = '';
        foreach ($ranNow as $m) {
            $slug   = htmlspecialchars($m['slug'] ?? '');
            $ms     = (float) ($m['ms'] ?? 0);
            $status = $m['status'] ?? 'ran';
            $cls    = $status === 'failed' ? 'pdb-slow' : '';
            $timeTd = $status === 'failed'
                ? '<td class="pdb-time" style="color:#f38ba8">FAILED</td>'
                : "<td class=\"pdb-time\">{$ms}ms</td>";
            $rows .= "<tr class=\"{$cls}\">{$timeTd}<td>{$slug}</td></tr>";
        }

        $n = count($ranNow);
        return "<p><strong>{$n} migration(s) ran this request</strong></p>"
             . "<table class=\"pdb-table\"><thead><tr><th>Time</th><th>Migration</th></tr></thead><tbody>{$rows}</tbody></table>";
    }

    private function renderExceptions(array $data): string
    {
        $rows = '';
        foreach ($data['items'] ?? [] as $item) {
            $type = $item['type'] === 'php_error' ? 'PHP' : 'EXC';
            $cls  = htmlspecialchars($item['class'] ?? '');
            $msg  = htmlspecialchars($item['message'] ?? '');
            $file = htmlspecialchars($item['file'] ?? '');
            $line = (int) ($item['line'] ?? 0);
            $rows .= "<tr><td style=\"color:#f38ba8;white-space:nowrap\">{$type}</td><td style=\"color:#fab387\">{$cls}</td><td>{$msg}</td><td class=\"pdb-sql\">{$file}:{$line}</td></tr>";
        }
        $count = $data['count'] ?? 0;
        $empty = $rows === '' ? '<tr><td colspan="4" style="color:#a6e3a1">No exceptions</td></tr>' : $rows;
        return "<p><strong>{$count} exception(s) / error(s)</strong></p>"
             . "<table class=\"pdb-table\"><thead><tr><th>Type</th><th>Class</th><th>Message</th><th>Location</th></tr></thead><tbody>{$empty}</tbody></table>";
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    private function css(): string
    {
        return '
#pramnos-debugbar{position:fixed;bottom:0;left:0;right:0;z-index:99999;font:12px/1.4 monospace;color:#cdd6f4;background:#1e1e2e;border-top:2px solid #89b4fa}
#pdb-bar{display:flex;align-items:center;padding:0 8px;height:28px;gap:4px;overflow-x:auto;white-space:nowrap}
#pdb-brand{color:#89b4fa;font-weight:bold;margin-right:8px;flex-shrink:0}
.pdb-tab{background:none;border:none;color:#cdd6f4;cursor:pointer;padding:2px 8px;border-radius:4px;font:inherit}
.pdb-tab:hover,.pdb-tab.pdb-active{background:#313244;color:#89b4fa}
#pdb-info{display:flex;align-items:center;gap:5px;margin-left:auto;flex-shrink:0}
.pdb-chip{font-size:10px;color:#6c7086;padding:1px 6px;background:#313244;border-radius:3px;white-space:nowrap}
.pdb-env-prod{color:#f38ba8!important}
.pdb-env-dev{color:#a6e3a1!important}
.pdb-route-badge{font-size:11px;background:#313244!important;border-radius:4px;padding:1px 7px!important}
.pdb-route-badge:hover{background:#45475a!important;color:#cdd6f4!important}
.pdb-method{font-size:9px;font-weight:bold;padding:0 3px;border-radius:2px;margin-right:3px}
.pdb-m-get{color:#a6e3a1}
.pdb-m-post{color:#fab387}
.pdb-m-put{color:#f9e2af}
.pdb-m-del{color:#f38ba8}
.pdb-devpanel{color:#a6e3a1;text-decoration:none;padding:2px 8px;font:inherit;flex-shrink:0;margin-left:6px}
.pdb-devpanel:hover{color:#cba6f7}
.pdb-close{background:none;border:none;color:#f38ba8;cursor:pointer;margin-left:4px;font:inherit;flex-shrink:0}
#pdb-restore{position:fixed;right:8px;bottom:8px;z-index:99998;display:none;background:#1e1e2e;color:#89b4fa;border:1px solid #313244;border-radius:6px;padding:2px 7px;cursor:pointer;font:12px/1.4 monospace;box-shadow:0 2px 6px rgba(0,0,0,.4)}
#pdb-restore:hover{color:#cba6f7;border-color:#cba6f7}
#pdb-panels{max-height:300px;overflow-y:auto;padding:8px 12px;background:#181825;border-top:1px solid #313244;display:none}
.pdb-table{width:100%;border-collapse:collapse;font-size:11px}
.pdb-table th{background:#313244;padding:4px 8px;text-align:left;color:#89b4fa}
.pdb-table td{padding:3px 8px;border-bottom:1px solid #1e1e2e;vertical-align:top}
.pdb-table .pdb-sql{font-size:10.5px;word-break:break-all}
.pdb-table .pdb-time{white-space:nowrap;color:#a6e3a1;min-width:50px}
.pdb-slow .pdb-time{color:#f38ba8}
.pdb-cached{color:#a6e3a1!important;font-size:9px;letter-spacing:.05em;font-weight:bold}
.pdb-copy{background:none;border:1px solid #45475a;color:#6c7086;cursor:pointer;font:10px monospace;padding:0 3px;border-radius:2px;line-height:14px;vertical-align:middle}
.pdb-copy-all{color:#cba6f7;border-color:#45475a;padding:1px 6px;margin-left:6px}
.pdb-copy-all:hover{background:#313244;border-color:#cba6f7}
.pdb-copy:hover{background:#313244;color:#cba6f7;border-color:#cba6f7}
.pdb-copy.pdb-copied{color:#a6e3a1;border-color:#a6e3a1}
.pdb-dl{display:grid;grid-template-columns:150px 1fr;gap:4px 12px}
.pdb-dl dt{color:#89b4fa}
.pdb-level-error{color:#f38ba8}
.pdb-level-warn,.pdb-level-warning{color:#fab387}
#pdb-panels p{margin:0 0 6px}
.pdb-timeline{position:relative;height:20px;background:#313244;border-radius:4px;margin:6px 0 4px;overflow:hidden}
.pdb-tl-seg{position:absolute;top:0;height:100%;border-radius:2px;font-size:9px;line-height:20px;padding:0 3px;white-space:nowrap;overflow:hidden;opacity:.9;color:#1e1e2e;font-weight:bold}
.pdb-tl-seg:hover{opacity:1;z-index:1}
.pdb-ajax-row{cursor:pointer}
.pdb-ajax-row:hover td{background:#1e1e2e}
.pdb-ajax-url{word-break:break-all;max-width:420px}
.pdb-ajax-toggle{color:#6c7086;width:10px;display:inline-block}
.pdb-ajax-detail td{background:#11111b;padding:6px 10px}
.pdb-s-2{color:#a6e3a1}
.pdb-s-3{color:#89b4fa}
.pdb-s-4{color:#fab387}
.pdb-s-5{color:#f38ba8}
.pdb-s-0{color:#f38ba8}
.pdb-tab-count{background:#45475a;color:#cdd6f4;border-radius:8px;padding:0 5px;margin-left:4px;font-size:10px}
';
    }

    private function js(): string
    {
        return '
(function(){
  function pdbCopy(sql,btn){
    // Remember what the button said. Restoring a hardcoded glyph works for the
    // per-statement buttons and silently erases the label of any button that
    // has one — "Copy all" became a bare tick the first time it was used.
    var original=btn.innerHTML;
    var done=function(){btn.classList.add("pdb-copied");btn.textContent="✓";setTimeout(function(){btn.classList.remove("pdb-copied");btn.innerHTML=original;},1500);};
    if(navigator.clipboard){navigator.clipboard.writeText(sql).then(done).catch(function(){done();});}
    else{var ta=document.createElement("textarea");ta.value=sql;ta.style.cssText="position:fixed;opacity:0";document.body.appendChild(ta);ta.select();try{document.execCommand("copy");}catch (e) {
            // The toolbar annotates a response; it never breaks one.
        }document.body.removeChild(ta);done();}
  }
  document.addEventListener("click",function(e){
    var btn=e.target.closest(".pdb-copy");
    if(btn){e.stopPropagation();pdbCopy(btn.dataset.sql,btn);}
  });
  document.querySelectorAll(".pdb-tab").forEach(function(btn){
    btn.addEventListener("click",function(){
      var name=btn.dataset.panel;
      var panels=document.querySelectorAll(".pdb-panel");
      var tabs=document.querySelectorAll(".pdb-tab");
      var panelEl=document.getElementById("pdb-panel-"+name);
      var panelsDiv=document.getElementById("pdb-panels");
      var isOpen=panelsDiv.style.display!=="none"&&panelEl.style.display!=="none";
      panels.forEach(function(p){p.style.display="none"});
      tabs.forEach(function(t){t.classList.remove("pdb-active")});
      if(isOpen){panelsDiv.style.display="none";return;}
      panelEl.style.display="block";
      panelsDiv.style.display="block";
      btn.classList.add("pdb-active");
    });
  });
  // The ✕ hides the whole toolbar, and the ⚙ handle brings it back. It used to
  // toggle #pdb-panels from an inline style that started empty, so the first
  // click set display:none on something the stylesheet already hid and the
  // second handed it back to the stylesheet: the button did nothing, twice.
  // Closing just the open panel is what clicking its own tab already does.
  var PDB_HIDDEN="pramnos.debugbar.hidden";
  function pdbHiddenStored(){
    // Storage can throw outright (private mode, a blocked origin); a toolbar
    // never breaks the page it measures.
    try{return localStorage.getItem(PDB_HIDDEN)==="1";}catch(e){return false;}
  }
  function pdbSetHidden(hidden){
    var bar=document.getElementById("pramnos-debugbar");
    var handle=document.getElementById("pdb-restore");
    if(!bar)return;
    bar.style.display=hidden?"none":"";
    if(handle)handle.style.display=hidden?"block":"none";
    // Give the page back the strip the bar occupied; a gap under a hidden bar
    // is a layout bug nothing on screen explains.
    document.body.style.paddingBottom=hidden?"":"36px";
    // Not being able to remember the choice is no reason to ignore it.
    try{hidden?localStorage.setItem(PDB_HIDDEN,"1"):localStorage.removeItem(PDB_HIDDEN);}catch(e){/* storage unavailable */}
  }
  var closeBtn=document.getElementById("pdb-close-btn");
  if(closeBtn){closeBtn.addEventListener("click",function(){pdbSetHidden(true);});}
  var restoreBtn=document.getElementById("pdb-restore");
  if(restoreBtn){restoreBtn.addEventListener("click",function(){pdbSetHidden(false);});}
  // Apply the remembered choice on load. Hiding it on one page and having it
  // return on the next reads as the button not working — which it was.
  pdbSetHidden(pdbHiddenStored());
})();
' . $this->ajaxJs();
    }

    /**
     * The part of the toolbar that watches what the page does after it loads.
     *
     * Everything else in this toolbar describes one request: the one that built
     * the page. But a page is rarely finished when it renders — a datatable
     * pages and sorts, a form saves, a widget polls, a SPA does nothing else at
     * all. Those requests were invisible here, and they are the ones running the
     * queries nobody is watching.
     *
     * So `fetch` and `XMLHttpRequest` are wrapped, and each response is read for
     * what the server attached to it: the full payload from a JSON body`s
     * `_debug` key, or the summary from the `X-Pramnos-Debug` header when the
     * response has no body to put it in.
     *
     * Three rules this code follows without exception, because it runs inside
     * somebody else`s application:
     *
     *  - the original `fetch`/`XMLHttpRequest` is always called, with the
     *    original arguments, and its result is always returned unchanged;
     *  - a response body is only ever read through `clone()`, so the
     *    application still gets to consume it;
     *  - every piece of it is wrapped in try/catch. A toolbar that breaks the
     *    page it is measuring is worse than no toolbar.
     */
    private function ajaxJs(): string
    {
        return '
(function(){
  var rows=[],open={};
  function esc(s){var d=document.createElement("div");d.textContent=String(s==null?"":s);return d.innerHTML;}
  // esc() escapes text nodes, which leaves quotes alone — fine inside an
  // element, wrong inside an attribute, where an unescaped quote ends the
  // attribute and everything after it becomes markup.
  function escAttr(s){return esc(s).replace(/"/g,"&quot;").replace(/\'/g,"&#39;");}
  function copyButton(text){
    return "<button class=\'pdb-copy\' title=\'Copy\' data-sql=\'"+escAttr(text)+"\'>⎘</button>";
  }
  function shorten(u){try{var s=String(u);if(s.indexOf(location.origin)===0)s=s.slice(location.origin.length);return s.length>90?s.slice(0,90)+"…":s;}catch(e){return String(u);}}
  function parseHeader(v){try{return v?JSON.parse(v):null;}catch(e){return null;}}
  var BODY_LIMIT=8192;
  // Field names whose values are masked before they are ever put on screen.
  // The body never leaves the browser, but a panel gets screenshotted and
  // screen-shared, and a password in a bug report is a password that has to be
  // changed.
  // Built from a source string rather than written as a literal: this file is a
  // PHP single-quoted string, where a backslash in a regex does not survive the
  // trip. Kept deliberately simple — a JSON string value, and a query-string
  // value — because a masker that is hard to read is a masker nobody trusts.
  var SECRET_WORDS="pass|secret|token|apikey|api_key|authorization|cvv";
  var SECRET_JSON=new RegExp("(\"[^\"]*(?:" + SECRET_WORDS + ")[^\"]*\"\\s*:\\s*)\"[^\"]*\"","gi");
  var SECRET_QUERY=new RegExp("([?&][^=&]*(?:" + SECRET_WORDS + ")[^=&]*=)[^&]*","gi");
  function maskSecrets(text){
    try{
      return String(text).replace(SECRET_JSON,"$1\"***\"").replace(SECRET_QUERY,"$1***");
    }catch(e){return text;}
  }
  /**
   * Whatever the caller passed as a request body, as text.
   *
   * Read synchronously and without consuming anything: a string is already
   * text, URLSearchParams and FormData can be walked, and everything else is
   * described rather than decoded — reading a Blob is asynchronous and the
   * application owns that object.
   */
  function captureBody(body){
    try{
      if(body==null)return null;
      if(typeof body==="string")return body.length>BODY_LIMIT?body.slice(0,BODY_LIMIT)+"\\n… truncated":body;
      if(typeof URLSearchParams!=="undefined"&&body instanceof URLSearchParams)return body.toString();
      if(typeof FormData!=="undefined"&&body instanceof FormData){
        var parts=[];
        body.forEach(function(v,k){parts.push(k+"="+(typeof v==="string"?v:"[file]"));});
        return parts.join("&");
      }
      if(typeof Blob!=="undefined"&&body instanceof Blob)return "[Blob, "+body.size+" bytes]";
      if(body.byteLength!=null)return "[binary, "+body.byteLength+" bytes]";
      return null;
    }catch(e){return null;}
  }
  /**
   * Turn a form-urlencoded body into the structure it encodes.
   *
   * `columns%5B0%5D%5Bdata%5D=0` is a nested value written flat and then
   * percent-escaped twice over. A datatables request is fifty of those, and as
   * raw text it is unreadable — which is the same as not being shown.
   */
  function decodeForm(text){
    var out={};
    var pairs=String(text).split("&");
    for(var i=0;i<pairs.length;i++){
      if(pairs[i]==="")continue;
      var eq=pairs[i].indexOf("=");
      var rawKey=eq<0?pairs[i]:pairs[i].slice(0,eq);
      var rawVal=eq<0?"":pairs[i].slice(eq+1);
      var key,val;
      try{key=decodeURIComponent(rawKey.replace(/\+/g," "));}catch(e){key=rawKey;}
      try{val=decodeURIComponent(rawVal.replace(/\+/g," "));}catch(e){val=rawVal;}
      // name[a][b] -> ["name","a","b"]
      var path=[],m=/^([^\[]*)/.exec(key);
      if(m)path.push(m[1]);
      var re=/\[([^\]]*)\]/g,part;
      while((part=re.exec(key))!==null)path.push(part[1]);
      var node=out;
      for(var p=0;p<path.length;p++){
        var seg=path[p]===""?String(Object.keys(node).length):path[p];
        if(p===path.length-1){node[seg]=val;}
        else{
          if(typeof node[seg]!=="object"||node[seg]===null)node[seg]={};
          node=node[seg];
        }
      }
    }
    return out;
  }
  /** Does this look like a form-urlencoded body rather than JSON or text? */
  function looksLikeForm(text){
    var t=String(text);
    return t.indexOf("=")>-1&&t.charAt(0)!=="{"&&t.charAt(0)!=="[";
  }
  /** Pretty-print JSON and form bodies; leave anything else alone. */
  function formatBody(text){
    try{
      var t=String(text).trim();
      if(t.charAt(0)==="{"||t.charAt(0)==="[")return JSON.stringify(JSON.parse(t),null,2);
      if(looksLikeForm(t))return JSON.stringify(decodeForm(t),null,2);
    }catch(e){/* a body that will not parse is not a body we can show */}
    return text;
  }
  /** A human size for the collapsed summary line. */
  function sizeOf(text){
    var n=String(text).length;
    return n<1024?(n+" B"):((n/1024).toFixed(1)+" KB");
  }
  function pad(n,w){var s=String(n);while(s.length<w)s="0"+s;return s;}
  // Local wall-clock, to the millisecond: the point of the column is lining a
  // request up against a server log, and a relative offset cannot do that.
  function clockTime(d){
    try{return pad(d.getHours(),2)+":"+pad(d.getMinutes(),2)+":"+pad(d.getSeconds(),2)+"."+pad(d.getMilliseconds(),3);}
    catch(e){return "";}
  }
  function bodyDebug(t){try{if(!t)return null;var s=String(t);var i=s.indexOf("{");if(i!==0)return null;var o=JSON.parse(s);return(o&&o._debug)?o._debug:null;}catch(e){return null;}}
  function queryCount(e){
    if(e.debug&&e.debug.queries){var q=e.debug.queries;if(typeof q.count==="number")return q.count;if(q.queries&&q.queries.length!=null)return q.queries.length;}
    if(e.summary&&typeof e.summary.queries==="number")return e.summary.queries;
    return null;
  }
  function serverMs(e){
    if(e.debug&&typeof e.debug.time==="number")return e.debug.time;
    if(e.summary&&typeof e.summary.time==="number")return e.summary.time;
    if(typeof e.timing==="number")return e.timing;
    return null;
  }
  // Server-Timing is the third source, and the one that survives when the
  // others do not: a proxy that strips unknown headers usually keeps this one,
  // and it is present even on a response with no body to carry a payload.
  function parseServerTiming(v){
    if(!v)return null;
    var m=/(?:^|,)\s*app;dur=([0-9.]+)/.exec(String(v));
    return m?parseFloat(m[1]):null;
  }
  // Peak memory in MB, from whichever source has it as a number. The `memory`
  // key of the full payload is not one: a collector registered under the same
  // name overwrites it with its own object, which is how this line used to
  // render "[object Object]MB".
  function memoryMb(e){
    if(e.debug&&e.debug.request&&typeof e.debug.request.memory==="number")return e.debug.request.memory;
    if(e.summary&&typeof e.summary.memory==="number")return e.summary.memory;
    return null;
  }
  function detailHtml(e){
    var out="";
    var sm=serverMs(e),mem=memoryMb(e);
    out+="<div>"+esc(e.method)+" "+esc(e.url)+"</div>";
    out+="<div style=\'color:#6c7086\'>"+esc(clockTime(e.startedAt))+" · client "+e.ms+"ms"+
      (sm!=null?(" · server "+sm+"ms"):"")+
      (mem!=null?(" · "+mem+"MB"):"")+
      (e.summary&&e.summary.route?(" · "+esc(e.summary.route)):"")+"</div>";
    // The request body, as the caller handed it to fetch/XHR. It never left the
    // browser, so showing it costs nothing and adds no header — but obvious
    // secrets are masked first, because this panel gets screenshotted.
    if(e.body){
      var shown=maskSecrets(formatBody(e.body));
      // Collapsed: a datatables body is two kilobytes of column metadata, and
      // it would push everything worth reading off the screen. <details> keeps
      // it one click away without any script of its own — and the click never
      // reaches the row toggle, because a detail row is not a .pdb-ajax-row.
      out+="<div style=\'margin-top:8px\'>"+
        "<details><summary style=\'cursor:pointer;color:#89b4fa\'>Request body · "+
        esc(sizeOf(e.body))+"</summary>"+
        "<div style=\'margin:4px 0 0\'>"+copyButton(shown)+"</div>"+
        "<pre style=\'margin:2px 0 0;white-space:pre-wrap;word-break:break-all;background:#1e1e2e;padding:6px;border-radius:3px;max-height:260px;overflow:auto\'>"+
        esc(shown)+"</pre></details></div>";
    }
    var q=e.debug&&e.debug.queries?(e.debug.queries.queries||e.debug.queries.statements||[]):null;
    if(q&&q.length){
      // Everything at once, annotated and in order — the form somebody pastes
      // into a bug report. One button per statement is fine for looking and
      // useless for reporting.
      var all=[];
      for(var j=0;j<q.length;j++){
        var r=q[j],s=r.sql||r.query||r.statement||"";
        var ms=r.time!=null?r.time:(r.duration!=null?r.duration:"");
        // A cached statement took no time because it did not run. Reporting
        // that as "0ms" reads as "instant" rather than "never happened", and
        // the difference is the whole point of looking.
        all.push("-- "+(r.from_cache?"CACHE":(ms+"ms"))+"\\n"+s+";");
      }
      var cachedCount=0;
      for(var c=0;c<q.length;c++){if(q[c].from_cache)cachedCount++;}
      out+="<div style=\'margin-top:8px;color:#89b4fa\'>"+q.length+" queries"+
        (cachedCount?(" <span style=\'color:#a6e3a1\'>("+(q.length-cachedCount)+" live · "+cachedCount+" from cache)</span>"):"")+" "+
        "<button class=\'pdb-copy pdb-copy-all\' title=\'Copy every statement, with its timing\' data-sql=\'"+
        escAttr(all.join("\\n\\n"))+"\'>⎘ Copy all</button></div>";
      out+="<table class=\'pdb-table\' style=\'margin-top:4px\'><tbody>";
      for(var i=0;i<q.length;i++){
        var row=q[i];
        var sql=row.sql||row.query||row.statement||"";
        var t=row.time!=null?row.time:(row.duration!=null?row.duration:"");
        var slow=(!row.from_cache&&typeof t==="number"&&t>100)?" pdb-slow":"";
        var cell=row.from_cache
          ? "<td class=\'pdb-time pdb-cached\'>CACHE</td>"
          : "<td class=\'pdb-time"+slow+"\'>"+esc(t)+"ms</td>";
        out+="<tr>"+cell+"<td class=\'pdb-sql\'>"+esc(sql)+
          " "+copyButton(sql)+"</td></tr>";
      }
      out+="</tbody></table>";
      if(e.debug.queries.truncated)out+="<p style=\'color:#6c7086\'>+"+e.debug.queries.truncated+" more not carried</p>";
    }else if(!e.debug&&!e.summary&&e.timing==null){
      // Nothing at all came back. Saying why beats an empty row: every one of
      // these is a different fix, and the panel is where somebody looks first.
      out+="<p style=\'color:#6c7086;margin-top:6px\'>No debug data on this response. "+
        "Either it did not go through this application, or the toolbar was not "+
        "active for it — a cross-origin call, a cached response, or a route that "+
        "returns before the debug headers are sent.</p>";
    }else if(!e.debug){
      out+="<p style=\'color:#6c7086;margin-top:6px\'>Summary only — this response had no JSON object to carry the full payload.</p>";
    }
    return out;
  }
  function render(){
    try{
      var tbody=document.getElementById("pdb-ajax-rows");
      if(!tbody)return;
      var table=document.getElementById("pdb-ajax-table"),empty=document.getElementById("pdb-ajax-empty"),tab=document.getElementById("pdb-ajax-tab");
      if(rows.length){if(table)table.style.display="";if(empty)empty.style.display="none";}
      if(tab)tab.innerHTML="ajax"+(rows.length?" <span class=\'pdb-tab-count\'>"+rows.length+"</span>":"");
      var html="";
      // Newest first. The panel is read while the page is being used, and the
      // request you just triggered is the one you are looking for — at the
      // bottom of a growing list it scrolls away from you.
      //
      // The index still identifies the entry, so `open` and the click handler
      // keep working against the order rows were captured in, not the order
      // they are drawn.
      for(var i=rows.length-1;i>=0;i--){
        // Per row, so that one entry the panel cannot format costs that row
        // rather than the whole table — which is exactly what a missing field
        // did once already.
        try{
        var e=rows[i],qc=queryCount(e),sm=serverMs(e);
        if(!e.startedAt)e.startedAt=new Date();
        html+="<tr class=\'pdb-ajax-row\' data-i=\'"+i+"\'>"+
          "<td class=\'pdb-ajax-toggle\'>"+(open[i]?"▾":"▸")+"</td>"+
          "<td class=\'pdb-ajax-at\' title=\'"+esc(e.startedAt.toISOString())+"\'>"+esc(clockTime(e.startedAt))+"</td>"+
          "<td>"+esc(e.method)+"</td>"+
          "<td class=\'pdb-ajax-url\'>"+esc(shorten(e.url))+"</td>"+
          "<td class=\'pdb-s-"+Math.floor((e.status||0)/100)+"\'>"+(e.status||"—")+"</td>"+
          "<td class=\'pdb-time\'>"+(sm!=null?(sm+"ms"):"<span title=\'The response carried no debug data — see the row detail\'>—</span>")+"</td>"+
          "<td class=\'pdb-time\'>"+e.ms+"ms</td>"+
          "<td>"+(qc!=null?qc:"—")+"</td></tr>";
        if(open[i])html+="<tr class=\'pdb-ajax-detail\'><td colspan=\'8\'>"+detailHtml(e)+"</td></tr>";
        }catch(rowError){
          html+="<tr><td colspan=\'8\' style=\'color:#f38ba8\'>row "+i+": "+esc(rowError.message)+"</td></tr>";
        }
      }
      tbody.innerHTML=html;
    }catch(x){/* instrumentation never interrupts the page it measures */}
  }
  document.addEventListener("click",function(ev){
    var tr=ev.target.closest?ev.target.closest(".pdb-ajax-row"):null;
    if(!tr)return;
    var i=tr.getAttribute("data-i");
    open[i]=!open[i];
    render();
  });
  function record(e){rows.push(e);render();}
  var of=window.fetch;
  if(typeof of==="function"){
    window.fetch=function(){
      var args=arguments,t0=(window.performance?performance.now():Date.now()),startedAt=new Date(),url="",method="GET",body=null;
      try{
        var first=args[0];
        url=(first&&typeof first==="object"&&first.url)?first.url:String(first);
        method=(args[1]&&args[1].method)||(first&&first.method)||"GET";
        // Only the init object: a Request instance owns its body and reading it
        // would consume the stream the application is about to send.
        body=captureBody(args[1]&&args[1].body);
      }catch(x){/* instrumentation never interrupts the page it measures */}
      return of.apply(this,args).then(function(res){
        try{
          var e={method:String(method).toUpperCase(),url:url,status:res.status,ms:Math.round((window.performance?performance.now():Date.now())-t0),startedAt:startedAt,body:body,summary:null,debug:null,timing:null};
          try{e.summary=parseHeader(res.headers.get("X-Pramnos-Debug"));}catch(x){/* a header a proxy stripped is simply absent */}
          try{e.timing=parseServerTiming(res.headers.get("Server-Timing"));}catch(x){/* same */}
          var ct="";try{ct=res.headers.get("content-type")||"";}catch(x){/* instrumentation never interrupts the page it measures */}
          record(e);
          if(ct.indexOf("json")!==-1&&typeof res.clone==="function"){
            res.clone().text().then(function(t){e.debug=bodyDebug(t);render();},function(){});
          }
        }catch(x){/* instrumentation never interrupts the page it measures */}
        return res;
      },function(err){
        try{record({method:String(method).toUpperCase(),url:url,status:0,ms:Math.round((window.performance?performance.now():Date.now())-t0),startedAt:startedAt,body:body,summary:null,debug:null,timing:null});}catch(x){/* instrumentation never interrupts the page it measures */}
        throw err;
      });
    };
  }
  if(window.XMLHttpRequest&&XMLHttpRequest.prototype){
    var oo=XMLHttpRequest.prototype.open,os=XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open=function(m,u){try{this.__pdb={method:String(m||"GET").toUpperCase(),url:String(u||"")};}catch(x){/* instrumentation never interrupts the page it measures */}return oo.apply(this,arguments);};
    XMLHttpRequest.prototype.send=function(sendBody){
      var self=this,t0=(window.performance?performance.now():Date.now()),startedAt=new Date(),body=captureBody(sendBody);
      try{
        self.addEventListener("loadend",function(){
          try{
            var i=self.__pdb||{},e={method:i.method||"GET",url:i.url||"",status:self.status,ms:Math.round((window.performance?performance.now():Date.now())-t0),startedAt:startedAt,body:body,summary:null,debug:null,timing:null};
            try{e.summary=parseHeader(self.getResponseHeader("X-Pramnos-Debug"));}catch(x){/* instrumentation never interrupts the page it measures */}
            try{e.timing=parseServerTiming(self.getResponseHeader("Server-Timing"));}catch(x){/* instrumentation never interrupts the page it measures */}
            try{
              if(self.responseType===""||self.responseType==="text")e.debug=bodyDebug(self.responseText);
              else if(self.responseType==="json"&&self.response&&self.response._debug)e.debug=self.response._debug;
            }catch(x){/* instrumentation never interrupts the page it measures */}
            record(e);
          }catch(x){/* instrumentation never interrupts the page it measures */}
        });
      }catch(x){/* instrumentation never interrupts the page it measures */}
      return os.apply(this,arguments);
    };
  }
})();
';
    }
}
