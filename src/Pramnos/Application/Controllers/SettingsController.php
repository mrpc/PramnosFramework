<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;

/**
 * Admin controller for managing application settings.
 *
 * Provides two interfaces:
 *   - A rich categorized form (display / saveSystem) covering all known
 *     framework settings (General, Email/SMTP, Security, DevPanel).
 *   - A raw key-value DataTable (list / edit / save / delete) for direct
 *     access to all settings stored in `#PREFIX#settings`.
 *
 * Routes:
 *   GET  /settings            — display()     rich categorized settings page
 *   POST /settings/saveSystem — saveSystem()  save categorized settings
 *   GET  /settings/list       — list()        raw DataTable list
 *   GET  /settings/edit/:key  — edit()        create/edit a raw setting
 *   POST /settings/save       — save()        create or update a raw setting
 *   GET  /settings/delete/:key — delete()     remove a raw setting
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class SettingsController extends Controller
{
    /**
     * Default login lockout policy (failed-attempts => lockout-seconds).
     * @var array<int, int>
     */
    public const DEFAULT_LOCKOUT_STEPS = [3 => 60, 5 => 300, 7 => 900, 10 => 3600];

    /** Default lockout sliding window (seconds). */
    public const DEFAULT_LOCKOUT_WINDOW_SECONDS = 900;

    /**
     * Setting keys that may never be modified via the UI (connection credentials, etc.).
     * Subclasses can extend this list.
     * @var string[]
     */
    protected array $readonlyKeys = [
        'hostname', 'database', 'schema', 'user', 'password',
        'collation', 'prefix', 'type', 'cache',
        'securitySalt',
    ];

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'saveSystem', 'list', 'edit', 'save', 'delete']);
        parent::__construct($application);
    }

    /**
     * Rich categorized settings page covering all known framework settings.
     */
    public function display(): mixed
    {
        $doc        = Factory::getDocument();
        $doc->title = 'System Settings';

        $devpanelEnabled = \Pramnos\Application\FeatureRegistry::isEnabled('devpanel');

        $keys = [
            'sitename', 'site_url', 'admin_mail', 'admin_replymail',
            'default_language', 'timezone', 'debug', 'forcessl',
            'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_tls',
            'loginlockoutwindowseconds', 'loginlockoutsteps',
        ];
        if ($devpanelEnabled) {
            $keys[] = 'devpanel.min_usertype';
            $keys[] = 'devpanel.mount';
        }

        $settings = [];
        foreach ($keys as $key) {
            $settings[$key] = (string) Settings::getSetting($key, '');
        }

        $view                   = $this->getView('settings');
        $view->settings         = $settings;
        $view->devpanelEnabled  = $devpanelEnabled;
        $view->timezones        = \DateTimeZone::listIdentifiers();
        $view->success          = $_SESSION['settings_success'] ?? '';
        $view->warning          = $_SESSION['settings_warning'] ?? '';
        unset($_SESSION['settings_success'], $_SESSION['settings_warning']);

        return $view->display();
    }

    /**
     * POST handler for the rich categorized settings form.
     */
    public function saveSystem(): void
    {
        $request = new \Pramnos\Http\Request();

        // General
        Settings::setSetting('sitename',        trim($request->get('sitename', '', 'post')));
        Settings::setSetting('site_url',         trim($request->get('site_url', '', 'post')));
        Settings::setSetting('admin_mail',       trim($request->get('admin_mail', '', 'post')));
        Settings::setSetting('admin_replymail',  trim($request->get('admin_replymail', '', 'post')));
        Settings::setSetting('default_language', trim($request->get('default_language', 'en', 'post')));
        Settings::setSetting('timezone',         trim($request->get('timezone', 'UTC', 'post')));
        Settings::setSetting('debug',            $this->normalizeYesNo($request->get('debug', 'no', 'post')));
        Settings::setSetting('forcessl',         $this->normalizeYesNo($request->get('forcessl', 'no', 'post')));

        // Email / SMTP
        Settings::setSetting('smtp_host', trim($request->get('smtp_host', '', 'post')));
        Settings::setSetting('smtp_port', (string) $this->normalizeIntRange(
            $request->get('smtp_port', '25', 'post'), 1, 65535, 25
        ));
        Settings::setSetting('smtp_user', trim($request->get('smtp_user', '', 'post')));
        Settings::setSetting('smtp_pass', $request->get('smtp_pass', '', 'post'));
        Settings::setSetting('smtp_tls',  $this->normalizeYesNo($request->get('smtp_tls', 'no', 'post')));

        // Security
        Settings::setSetting('loginlockoutwindowseconds', (string) $this->normalizeIntRange(
            $request->get('loginlockoutwindowseconds', (string) self::DEFAULT_LOCKOUT_WINDOW_SECONDS, 'post'),
            60, 86400, self::DEFAULT_LOCKOUT_WINDOW_SECONDS
        ));
        $lockoutErrors = [];
        $rawSteps      = $request->get('loginlockoutsteps', '__KEEP__', 'post');
        if ($rawSteps !== '__KEEP__') {
            $normalizedSteps = $this->normalizeLoginLockoutSteps((string) $rawSteps, $lockoutErrors);
        } else {
            $existing        = (string) Settings::getSetting('loginlockoutsteps');
            $normalizedSteps = trim($existing) !== ''
                ? $existing
                : json_encode(self::DEFAULT_LOCKOUT_STEPS);
        }
        Settings::setSetting('loginlockoutsteps', $normalizedSteps);

        // DevPanel (only when feature is active)
        if (\Pramnos\Application\FeatureRegistry::isEnabled('devpanel')) {
            Settings::setSetting('devpanel.min_usertype', (string) $this->normalizeIntRange(
                $request->get('devpanel.min_usertype', '90', 'post'), 0, 100, 90
            ));
            Settings::setSetting('devpanel.mount', trim($request->get('devpanel.mount', 'devpanel', 'post')));
        }

        if (count($lockoutErrors) > 0) {
            $_SESSION['settings_warning'] = 'Lockout rules adjusted to safe defaults: '
                . implode(' ', array_unique($lockoutErrors));
        }

        $_SESSION['settings_success'] = 'Settings saved.';

        $activeTab    = trim($request->get('settings_active_tab', '', 'post'));
        $allowedTabs  = [
            'settings-tab-general',
            'settings-tab-email',
            'settings-tab-security',
        ];
        if (\Pramnos\Application\FeatureRegistry::isEnabled('devpanel')) {
            $allowedTabs[] = 'settings-tab-devpanel';
        }
        if (in_array($activeTab, $allowedTabs, true)) {
            $this->redirect(sURL . 'settings#' . $activeTab);
            return;
        }
        $this->redirect(sURL . 'settings');
    }

    /**
     * Raw DataTable list of all settings stored in `#PREFIX#settings`.
     */
    public function list(): mixed
    {
        $doc        = Factory::getDocument();
        $doc->title = 'Raw Settings';

        $db   = \Pramnos\Database\Database::getInstance();
        $rows = $db->queryBuilder()
            ->table('#PREFIX#settings')
            ->select(['setting', 'value'])
            ->orderBy('setting')
            ->getAll();

        $settings = [];
        foreach ($rows as $row) {
            $settings[] = [
                'key'      => $row['setting'],
                'value'    => $row['value'],
                'readonly' => in_array($row['setting'], $this->readonlyKeys, true),
            ];
        }

        $view           = $this->getView('settings');
        $view->settings = $settings;
        return $view->display('list');
    }

    /**
     * Create / edit form for a single raw setting.
     *
     * @param string|null $key Setting key to edit; empty for a new setting.
     */
    public function edit(mixed $key = null): mixed
    {
        $doc = Factory::getDocument();

        $key   = trim((string) (\Pramnos\Http\Request::staticGetOption() ?? ''));
        $isNew = ($key === '');
        $value = '';

        if (!$isNew) {
            if (in_array($key, $this->readonlyKeys, true)) {
                $_SESSION['settings_error'] = 'This setting is read-only and cannot be modified.';
                $this->redirect(sURL . 'settings/list');
                return null;
            }
            $value      = (string) Settings::getSetting($key, '');
            $doc->title = 'Edit Setting: ' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
        } else {
            $doc->title = 'New Setting';
        }

        $view         = $this->getView('settings');
        $view->action = 'edit';
        $view->key    = $key;
        $view->value  = $value;
        $view->isNew  = $isNew;
        $view->error  = $_SESSION['settings_error'] ?? '';
        unset($_SESSION['settings_error']);
        return $view->display('edit');
    }

    /**
     * Create or update a raw setting (POST handler).
     */
    public function save(): void
    {
        $key      = trim((string) ($_POST['key']          ?? ''));
        $value    = (string)       ($_POST['value']        ?? '');
        $original = trim((string) ($_POST['original_key'] ?? ''));

        if ($key === '') {
            $_SESSION['settings_error'] = 'Setting key must not be empty.';
            $this->redirect(sURL . 'settings/edit');
            return;
        }

        if (in_array($key, $this->readonlyKeys, true)) {
            $_SESSION['settings_error'] = 'This setting is read-only and cannot be modified.';
            $this->redirect(sURL . 'settings/list');
            return;
        }

        if ($original !== '' && $original !== $key && !in_array($original, $this->readonlyKeys, true)) {
            $this->deleteSetting($original);
        }

        Settings::setSetting($key, $value);
        $this->redirect(sURL . 'settings/list');
    }

    /**
     * Remove a raw setting by key.
     */
    public function delete(mixed $key = null): void
    {
        $key = trim((string) (\Pramnos\Http\Request::staticGetOption() ?? $_GET['key'] ?? ''));

        if ($key === '' || in_array($key, $this->readonlyKeys, true)) {
            $this->redirect(sURL . 'settings/list');
            return;
        }

        $this->deleteSetting($key);
        $this->redirect(sURL . 'settings/list');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** Normalize a checkbox/toggle value to 'yes' or 'no'. */
    protected function normalizeYesNo(string $value): string
    {
        return strtolower(trim($value)) === 'yes' ? 'yes' : 'no';
    }

    /** Clamp an integer setting within [min, max], using $default when blank. */
    protected function normalizeIntRange(string $value, int $min, int $max, int $default): int
    {
        if ($value === '') {
            return $default;
        }
        $int = (int) $value;
        return max($min, min($max, $int));
    }

    /**
     * Normalize a lockout-steps JSON payload. Falls back to DEFAULT_LOCKOUT_STEPS
     * when the value is empty or invalid.
     *
     * @param string $value
     * @param array<int, string>|null $errors Populated with any validation messages.
     * @return string JSON-encoded step map.
     */
    protected function normalizeLoginLockoutSteps(string $value, ?array &$errors = null): string
    {
        $errors  ??= [];
        $trimmed   = trim($value);
        $defaults  = self::DEFAULT_LOCKOUT_STEPS;

        if ($trimmed === '') {
            $errors[] = 'No lockout rules were provided.';
            ksort($defaults, SORT_NUMERIC);
            return (string) json_encode($defaults);
        }

        $parsed  = [];
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            foreach ($decoded as $threshold => $duration) {
                $t = (int) $threshold;
                $d = (int) $duration;
                if ($t > 0 && $d > 0) {
                    $parsed[$t] = $d;
                }
            }
        }

        if (count($parsed) === 0) {
            $errors[] = 'Lockout rules did not contain valid positive values.';
            ksort($defaults, SORT_NUMERIC);
            return (string) json_encode($defaults);
        }

        ksort($parsed, SORT_NUMERIC);

        $prev = null;
        foreach ($parsed as $d) {
            if ($prev !== null && $d <= $prev) {
                $errors[] = 'Lockout durations must increase with each threshold.';
                ksort($defaults, SORT_NUMERIC);
                return (string) json_encode($defaults);
            }
            $prev = $d;
        }

        return (string) json_encode($parsed);
    }

    private function deleteSetting(string $key): void
    {
        $db  = \Pramnos\Database\Database::getInstance();
        $sql = $db->prepareQuery(
            "DELETE FROM `#PREFIX#settings` WHERE `setting` = %s",
            $key
        );
        $db->query($sql);
    }
}
