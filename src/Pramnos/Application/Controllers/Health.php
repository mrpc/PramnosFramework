<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Health\HealthRegistry;

/**
 * Framework health-check controller.
 *
 * Provides every application with a ready-made HTTP health dashboard and JSON
 * endpoint — no application-level code required.
 *
 * Actions:
 *   display() — HTML dashboard: all check results, DB info, cache stats, PHP version.
 *   check()   — JSON endpoint: `{"status":"ok|degraded|down","checks":{…}}`
 *               Suitable for uptime monitoring (Uptime Robot, Grafana, etc.).
 *   phpinfo() — phpinfo() output (superadmin only: usertype >= 90).
 *
 * All actions require an authenticated user.  phpinfo() additionally requires
 * usertype >= 90 to prevent info leakage.
 *
 * Scaffold wrapper:
 *   pramnos init generates `src/Controllers/Health.php` extending this class.
 *
 * @package PramnosFramework
 * @subpackage Application\Controllers
 */
class Health extends Controller
{
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'phpinfo']);
        // check() is intentionally PUBLIC — monitoring systems call it unauthenticated.
        $this->actions[] = 'check';
        parent::__construct($application);
    }

    // =========================================================================
    // Actions
    // =========================================================================

    /**
     * HTML health dashboard.
     *
     * Shows all registered health checks with colour-coded status badges
     * (ok=green, degraded=yellow, down=red), DB info (type/version), cache
     * adapter, active user count, and PHP version.
     */
    public function display(): mixed
    {
        $report = HealthRegistry::runAll();
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $doc    = \Pramnos\Framework\Factory::getDocument();

        $doc->title = 'Health';

        // DB info
        $dbType    = $db ? ucfirst($db->type ?? 'unknown') : 'not connected';
        $dbVersion = '—';
        if ($db && $db->connected) {
            try {
                $r         = $db->execute('SELECT VERSION() AS v');
                $dbVersion = $r ? ($r->fields['v'] ?? '—') : '—';
            } catch (\Throwable) {
            }
        }

        // Cache info
        $cacheAdapter = '—';
        if (\Pramnos\Application\FeatureRegistry::isEnabled('cache')) {
            try {
                $cacheAdapter = \Pramnos\Cache\Cache::getInstance()->getCacheMethod();
            } catch (\Throwable) {
            }
        }

        // Active users (from sessions table)
        $activeUsers = '—';
        if ($db && $db->connected) {
            try {
                $prefix = defined('PREFIX') ? PREFIX : '';
                $r      = $db->execute(
                    "SELECT COUNT(*) AS cnt FROM {$prefix}tokens WHERE status = 1 AND tokentype IN (1,3)"
                );
                if ($r) {
                    $activeUsers = (string) ($r->fields['cnt'] ?? '—');
                }
            } catch (\Throwable) {
            }
        }

        $overallStatus = $report['status'];
        $checks        = $report['checks'] ?? [];

        // Build HTML
        ob_start();
        ?>
        <div class="health-dashboard">
          <h2>System Health
            <span class="status-badge status-<?php echo htmlspecialchars($overallStatus); ?>">
              <?php echo strtoupper(htmlspecialchars($overallStatus)); ?>
            </span>
          </h2>

          <table class="health-table table table-bordered">
            <thead>
              <tr>
                <th>Check</th>
                <th>Status</th>
                <th>Message</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($checks)): ?>
              <tr><td colspan="3" class="text-muted">No health checks registered.</td></tr>
            <?php else: ?>
              <?php foreach ($checks as $name => $check): ?>
              <tr>
                <td><strong><?php echo htmlspecialchars($name); ?></strong></td>
                <td>
                  <span class="status-badge status-<?php echo htmlspecialchars($check['status']); ?>">
                    <?php echo strtoupper(htmlspecialchars($check['status'])); ?>
                  </span>
                </td>
                <td><?php echo htmlspecialchars($check['message'] ?? ''); ?></td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>

          <h3>System Info</h3>
          <table class="health-info-table table table-bordered">
            <tr><th>PHP Version</th><td><?php echo htmlspecialchars(PHP_VERSION); ?></td></tr>
            <tr><th>Database</th><td><?php echo htmlspecialchars($dbType); ?> <?php echo htmlspecialchars($dbVersion); ?></td></tr>
            <tr><th>Cache Adapter</th><td><?php echo htmlspecialchars($cacheAdapter); ?></td></tr>
            <tr><th>Active Sessions</th><td><?php echo htmlspecialchars($activeUsers); ?></td></tr>
            <tr><th>Memory (peak)</th><td><?php echo $this->humanBytes(memory_get_peak_usage(true)); ?></td></tr>
          </table>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * JSON health endpoint for monitoring systems.
     *
     * Returns HTTP 200 for ok, 503 for degraded/down.
     * Response format:
     *   {"status":"ok|degraded|down","checks":{name:{status,message,details},...}}
     */
    public function check(): mixed
    {
        $report = HealthRegistry::runAll();

        $httpCode = match ($report['status']) {
            'ok'       => 200,
            'degraded' => 503,
            'down'     => 503,
            default    => 503,
        };

        http_response_code($httpCode);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, no-store');
        echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * PHP Info page — admin-only.
     *
     * Requires usertype >= 90 to prevent PHP configuration leakage.
     */
    public function phpinfo(): mixed
    {
        $user = \Pramnos\User\User::getCurrentUser();
        if ($user === null || (int) ($user->usertype ?? 0) < 90) {
            http_response_code(403);
            return '<p>Access denied.</p>';
        }

        ob_start();
        \phpinfo();
        $phpInfoRaw = ob_get_clean();

        $phpInfoRaw = preg_replace('/^.*<body>/si', '', $phpInfoRaw);
        $phpInfoRaw = preg_replace('/<\/body>.*$/si', '', $phpInfoRaw);

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'PHP Info';

        return $phpInfoRaw;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

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
}
