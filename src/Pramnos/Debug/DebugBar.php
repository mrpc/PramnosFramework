<?php

declare(strict_types=1);

namespace Pramnos\Debug;

use Pramnos\Debug\Collectors\CollectorInterface;
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
 * @package PramnosFramework
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

    // ── Timer Convenience ──────────────────────────────────────────────────────

    public static function startTimer(string $name): void
    {
        static::getInstance()->timeCollector?->startTimer($name);
    }

    public static function stopTimer(string $name): void
    {
        static::getInstance()->timeCollector?->stopTimer($name);
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
        $tabs    = [];
        $panels  = [];

        foreach ($this->collectors as $name => $collector) {
            try {
                $data = $collector->collect();
            } catch (\Throwable $e) {
                $data = ['error' => $e->getMessage()];
            }

            $label      = $this->formatTabLabel($name, $data);
            $panelHtml  = $this->renderPanel($name, $data);
            $tabs[]     = sprintf(
                '<button class="pdb-tab" data-panel="%s">%s</button>',
                htmlspecialchars($name),
                $label,
            );
            $panels[]   = sprintf(
                '<div class="pdb-panel" id="pdb-panel-%s" style="display:none">%s</div>',
                htmlspecialchars($name),
                $panelHtml,
            );
        }

        if (empty($tabs)) {
            return '';
        }

        $tabsHtml   = implode('', $tabs);
        $panelsHtml = implode('', $panels);
        $css        = $this->css();
        $js         = $this->js();
        $na         = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '"' : '';

        return <<<HTML
<style{$na}>{$css}</style>
<script{$na}>document.body.style.paddingBottom='36px';</script>
<div id="pramnos-debugbar">
  <div id="pdb-bar">
    <span id="pdb-brand">&#9881; Pramnos</span>
    {$tabsHtml}
    <a class="pdb-devpanel" href="/devpanel" title="DevPanel">&#128270; DevPanel</a>
    <button class="pdb-close" id="pdb-close-btn">&#x2715;</button>
  </div>
  <div id="pdb-panels">{$panelsHtml}</div>
</div>
<script{$na}>{$js}</script>
HTML;
    }

    // ── Internal Panel Renderers ──────────────────────────────────────────────

    private function formatTabLabel(string $name, array $data): string
    {
        return match ($name) {
            'queries'    => 'SQL (' . ($data['count'] ?? 0) . ' · ' . ($data['total_ms'] ?? 0) . 'ms)',
            'timers'     => 'Time (' . ($data['request_ms'] ?? 0) . 'ms)',
            'memory'     => 'Mem (' . ($data['peak_human'] ?? '') . ')',
            'logs'       => 'Logs (' . ($data['count'] ?? 0) . ')',
            'session'    => 'Session (' . ($data['count'] ?? 0) . ')',
            'views'      => 'Views (' . ($data['count'] ?? 0) . ')',
            'models'     => 'Models (' . ($data['count'] ?? 0) . ' · ' . ($data['ops'] ?? 0) . ' ops)',
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
            'exceptions' => $this->renderExceptions($data),
            default      => '<pre>' . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT)) . '</pre>',
        };
    }

    private function renderQueries(array $data): string
    {
        $rows = '';
        foreach ($data['queries'] ?? [] as $q) {
            $sql  = htmlspecialchars($q['sql'] ?? '');
            $time = $q['time'] ?? 0;
            $cls  = $time > 100 ? 'pdb-slow' : '';
            $rows .= "<tr class=\"{$cls}\"><td class=\"pdb-time\">{$time}ms</td><td class=\"pdb-sql\">{$sql}</td></tr>";
        }
        $count = $data['count'] ?? 0;
        $total = $data['total_ms'] ?? 0;
        return "<p><strong>{$count} queries</strong> — {$total}ms total</p>"
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
            $view = htmlspecialchars($v['view'] ?? '');
            $tpl  = htmlspecialchars($v['template'] ?? '');
            $ms   = $v['render_ms'] ?? 0;
            $cls  = $ms > 50 ? 'pdb-slow' : '';
            $rows .= "<tr class=\"{$cls}\"><td class=\"pdb-time\">{$ms}ms</td><td>{$view}</td><td class=\"pdb-sql\">{$tpl}</td></tr>";
        }
        $count = $data['count'] ?? 0;
        $empty = $rows === '' ? '<tr><td colspan="3" style="color:#6c7086">No views rendered</td></tr>' : $rows;
        return "<p><strong>{$count} template(s) rendered</strong></p>"
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
.pdb-devpanel{color:#a6e3a1;text-decoration:none;padding:2px 8px;font:inherit;flex-shrink:0;margin-left:auto}
.pdb-devpanel:hover{color:#cba6f7}
.pdb-close{background:none;border:none;color:#f38ba8;cursor:pointer;margin-left:4px;font:inherit;flex-shrink:0}
#pdb-panels{max-height:300px;overflow-y:auto;padding:8px 12px;background:#181825;border-top:1px solid #313244;display:none}
.pdb-table{width:100%;border-collapse:collapse;font-size:11px}
.pdb-table th{background:#313244;padding:4px 8px;text-align:left;color:#89b4fa}
.pdb-table td{padding:3px 8px;border-bottom:1px solid #1e1e2e;vertical-align:top}
.pdb-table .pdb-sql{font-size:10.5px;word-break:break-all}
.pdb-table .pdb-time{white-space:nowrap;color:#a6e3a1;min-width:50px}
.pdb-slow .pdb-time{color:#f38ba8}
.pdb-dl{display:grid;grid-template-columns:150px 1fr;gap:4px 12px}
.pdb-dl dt{color:#89b4fa}
.pdb-level-error{color:#f38ba8}
.pdb-level-warn,.pdb-level-warning{color:#fab387}
#pdb-panels p{margin:0 0 6px}
.pdb-timeline{position:relative;height:20px;background:#313244;border-radius:4px;margin:6px 0 4px;overflow:hidden}
.pdb-tl-seg{position:absolute;top:0;height:100%;border-radius:2px;font-size:9px;line-height:20px;padding:0 3px;white-space:nowrap;overflow:hidden;opacity:.9;color:#1e1e2e;font-weight:bold}
.pdb-tl-seg:hover{opacity:1;z-index:1}
';
    }

    private function js(): string
    {
        return '
(function(){
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
  var closeBtn=document.getElementById("pdb-close-btn");
  if(closeBtn){closeBtn.addEventListener("click",function(){
    var d=document.getElementById("pdb-panels");
    d.style.display=d.style.display==="none"?"":"none";
    if(d.style.display==="none")document.querySelectorAll(".pdb-tab").forEach(function(t){t.classList.remove("pdb-active");});
  });}
})();
';
    }
}
