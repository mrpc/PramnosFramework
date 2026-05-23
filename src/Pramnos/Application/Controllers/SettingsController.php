<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;

/**
 * Admin controller for managing application key-value settings.
 *
 * Provides a DataTable list of settings from the `#PREFIX#settings` table
 * and CRUD operations (create/update, delete). Applications should extend
 * this class and can override which setting keys are exposed.
 *
 * Routes:
 *   GET  /settings          — display() DataTable list
 *   GET  /settings/edit/:key — edit()   create/edit form
 *   POST /settings/save     — save()   create or update a setting
 *   GET  /settings/delete/:key — delete() remove a setting
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class SettingsController extends Controller
{
    /**
     * Setting keys that may never be modified via the UI (connection credentials, etc.).
     * Subclasses can extend this list.
     *
     * @var string[]
     */
    protected array $readonlyKeys = [
        'hostname', 'database', 'schema', 'user', 'password',
        'collation', 'prefix', 'type', 'cache',
    ];

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'edit', 'save', 'delete']);
        parent::__construct($application);
    }

    /**
     * DataTable list of all settings stored in `#PREFIX#settings`.
     */
    public function display(): mixed
    {
        $doc = Factory::getDocument();
        $doc->title = 'Settings';

        $db = \Pramnos\Database\Database::getInstance();
        $result = $db->query(
            "SELECT `setting`, `value` FROM `#PREFIX#settings` ORDER BY `setting` ASC"
        );

        $settings = [];
        while (!$result->EOF) {
            $settings[] = [
                'key'      => $result->fields['setting'],
                'value'    => $result->fields['value'],
                'readonly' => in_array($result->fields['setting'], $this->readonlyKeys, true),
            ];
            $result->moveNext();
        }

        $view           = $this->getView('settings');
        $view->settings = $settings;
        return $view->display();
    }

    /**
     * Create / edit form for a single setting.
     *
     * @param string|null $key Setting key to edit; empty string for a new setting.
     */
    public function edit(?string $key = null): mixed
    {
        $doc = Factory::getDocument();

        $key      = trim((string) ($key ?? ''));
        $value    = '';
        $isNew    = ($key === '');

        if (!$isNew) {
            if (in_array($key, $this->readonlyKeys, true)) {
                $_SESSION['settings_error'] = 'This setting is read-only and cannot be modified.';
                $this->redirect(sURL . 'settings');
                return null;
            }
            $value    = (string) Settings::getSetting($key, '');
            $doc->title = 'Edit Setting: ' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
        } else {
            $doc->title = 'New Setting';
        }

        $view          = $this->getView('settings');
        $view->action  = 'edit';
        $view->key     = $key;
        $view->value   = $value;
        $view->isNew   = $isNew;
        $view->error   = $_SESSION['settings_error'] ?? '';
        unset($_SESSION['settings_error']);
        return $view->display('edit');
    }

    /**
     * Create or update a setting (POST handler).
     */
    public function save(): void
    {
        $key      = trim((string) ($_POST['key']   ?? ''));
        $value    = (string) ($_POST['value'] ?? '');
        $original = trim((string) ($_POST['original_key'] ?? ''));

        if ($key === '') {
            $_SESSION['settings_error'] = 'Setting key must not be empty.';
            $this->redirect(sURL . 'settings/edit');
            return;
        }

        if (in_array($key, $this->readonlyKeys, true)) {
            $_SESSION['settings_error'] = 'This setting is read-only and cannot be modified.';
            $this->redirect(sURL . 'settings');
            return;
        }

        // If the key was renamed, remove the old entry first
        if ($original !== '' && $original !== $key) {
            if (!in_array($original, $this->readonlyKeys, true)) {
                $this->deleteSetting($original);
            }
        }

        Settings::setSetting($key, $value);
        $this->redirect(sURL . 'settings');
    }

    /**
     * Remove a setting by key (GET with confirmation).
     *
     * @param string|null $key Setting key to delete.
     */
    public function delete(?string $key = null): void
    {
        $key = trim((string) ($key ?? $_GET['key'] ?? ''));

        if ($key === '' || in_array($key, $this->readonlyKeys, true)) {
            $this->redirect(sURL . 'settings');
            return;
        }

        $this->deleteSetting($key);
        $this->redirect(sURL . 'settings');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal
    // ─────────────────────────────────────────────────────────────────────────

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
