<?php
/**
 * Rich categorized System Settings page (plain-CSS theme).
 *
 * Variables:
 *   $this->settings  — associative array of setting key => current value
 *   $this->timezones — array of timezone identifiers
 *   $this->success   — success flash message (string)
 *   $this->warning   — warning flash message (string)
 */
$s   = $this->settings ?? [];
$tzs = $this->timezones ?? \DateTimeZone::listIdentifiers();

$defaultSteps = \Pramnos\Application\Controllers\SettingsController::DEFAULT_LOCKOUT_STEPS;
ksort($defaultSteps, SORT_NUMERIC);

$stepsSetting = $s['loginlockoutsteps'] ?? '';
$initialSteps = [];
if (trim($stepsSetting) !== '') {
    $decoded = json_decode($stepsSetting, true);
    if (is_array($decoded)) {
        foreach ($decoded as $t => $d) {
            $t = (int) $t; $d = (int) $d;
            if ($t > 0 && $d > 0) { $initialSteps[$t] = $d; }
        }
    }
}
if (count($initialSteps) === 0) { $initialSteps = $defaultSteps; }
ksort($initialSteps, SORT_NUMERIC);
?>
<div class="page-section">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <h2>System Settings</h2>
        <a href="<?php echo sURL; ?>settings/list" class="btn btn-outline-secondary">Advanced / Raw Settings</a>
    </div>

    <?php if (!empty($this->success)): ?>
        <div style="background:#d4edda;border:1px solid #c3e6cb;padding:10px 16px;border-radius:4px;margin-bottom:12px;color:#155724"><?php echo htmlspecialchars($this->success); ?></div>
    <?php endif; ?>
    <?php if (!empty($this->warning)): ?>
        <div style="background:#fff3cd;border:1px solid #ffeeba;padding:10px 16px;border-radius:4px;margin-bottom:12px;color:#856404"><?php echo htmlspecialchars($this->warning); ?></div>
    <?php endif; ?>

    <form method="post" action="<?php echo sURL; ?>settings/saveSystem" id="settingsForm">
        <input type="hidden" name="settings_active_tab" id="settings_active_tab" value="">

        <!-- Tab nav -->
        <div style="display:flex;border-bottom:2px solid #e0e0e0;margin-bottom:20px;gap:4px">
            <button type="button" class="plain-tab-btn active" data-tab="settings-tab-general" style="padding:8px 16px;border:none;background:none;cursor:pointer;font-size:14px;font-weight:600;border-bottom:2px solid #0d6efd;margin-bottom:-2px;color:#0d6efd">General</button>
            <button type="button" class="plain-tab-btn" data-tab="settings-tab-email" style="padding:8px 16px;border:none;background:none;cursor:pointer;font-size:14px;border-bottom:2px solid transparent;margin-bottom:-2px;color:#666">Email / SMTP</button>
            <button type="button" class="plain-tab-btn" data-tab="settings-tab-security" style="padding:8px 16px;border:none;background:none;cursor:pointer;font-size:14px;border-bottom:2px solid transparent;margin-bottom:-2px;color:#666">Security</button>
            <button type="button" class="plain-tab-btn" data-tab="settings-tab-devpanel" style="padding:8px 16px;border:none;background:none;cursor:pointer;font-size:14px;border-bottom:2px solid transparent;margin-bottom:-2px;color:#666">DevPanel</button>
        </div>

        <!-- General -->
        <div id="settings-tab-general" class="plain-settings-pane">
            <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:16px">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Site Name</label>
                        <input type="text" name="sitename" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" value="<?php echo htmlspecialchars($s['sitename'] ?? ''); ?>">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Site URL</label>
                        <input type="url" name="site_url" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" value="<?php echo htmlspecialchars($s['site_url'] ?? ''); ?>" placeholder="https://example.com/">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Admin Email (From)</label>
                        <input type="email" name="admin_mail" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" value="<?php echo htmlspecialchars($s['admin_mail'] ?? ''); ?>">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Admin Reply-To Email</label>
                        <input type="email" name="admin_replymail" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" value="<?php echo htmlspecialchars($s['admin_replymail'] ?? ''); ?>">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Default Language</label>
                        <input type="text" name="default_language" maxlength="10" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" value="<?php echo htmlspecialchars($s['default_language'] ?? 'en'); ?>" placeholder="en">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Timezone</label>
                        <select name="timezone" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box">
                            <?php
                            $currentTz = $s['timezone'] ?? 'UTC';
                            foreach ($tzs as $tz):
                                $sel = ($currentTz === $tz) ? ' selected' : '';
                            ?>
                                <option value="<?php echo $tz; ?>"<?php echo $sel; ?>><?php echo $tz; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color:#888;font-size:11px">Server time: <?php echo date('H:i'); ?></small>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Debug Mode</label>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                            <input type="checkbox" name="debug" value="yes"
                                <?php echo (($s['debug'] ?? '') === 'yes') ? 'checked' : ''; ?>>
                            <span style="font-size:13px">Enabled</span>
                        </label>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Force HTTPS</label>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                            <input type="checkbox" name="forcessl" value="yes"
                                <?php echo (($s['forcessl'] ?? '') === 'yes') ? 'checked' : ''; ?>>
                            <span style="font-size:13px">Enabled</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Email / SMTP -->
        <div id="settings-tab-email" class="plain-settings-pane" style="display:none">
            <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:16px">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div style="grid-column:1/-1">
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">SMTP Host</label>
                        <input type="text" name="smtp_host" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" value="<?php echo htmlspecialchars($s['smtp_host'] ?? ''); ?>" placeholder="smtp.example.com">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">SMTP Port</label>
                        <input type="number" name="smtp_port" min="1" max="65535" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box"
                            value="<?php echo htmlspecialchars($s['smtp_port'] !== '' ? $s['smtp_port'] : '587'); ?>">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Use TLS/SSL</label>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:6px">
                            <input type="checkbox" name="smtp_tls" value="yes"
                                <?php echo (($s['smtp_tls'] ?? '') === 'yes') ? 'checked' : ''; ?>>
                            <span style="font-size:13px">Enabled</span>
                        </label>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">SMTP Username</label>
                        <input type="text" name="smtp_user" autocomplete="off" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" value="<?php echo htmlspecialchars($s['smtp_user'] ?? ''); ?>">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">SMTP Password</label>
                        <input type="password" name="smtp_pass" autocomplete="new-password" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" value="<?php echo htmlspecialchars($s['smtp_pass'] ?? ''); ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Security -->
        <div id="settings-tab-security" class="plain-settings-pane" style="display:none">
            <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:16px">
                <div style="margin-bottom:12px;max-width:220px">
                    <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Lockout Window (seconds)</label>
                    <input type="number" name="loginlockoutwindowseconds" min="60" max="86400" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box"
                        value="<?php
                            $w = (int) ($s['loginlockoutwindowseconds'] ?? 0);
                            echo $w > 0 ? $w : \Pramnos\Application\Controllers\SettingsController::DEFAULT_LOCKOUT_WINDOW_SECONDS;
                        ?>">
                    <small style="color:#888;font-size:11px">Sliding window for counting failed logins.</small>
                </div>
                <div>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                        <label style="font-weight:600;font-size:13px">Progressive Lockout Rules</label>
                        <button type="button" id="add-lockout-rule" style="padding:4px 12px;border:1px solid #0d6efd;background:none;color:#0d6efd;border-radius:4px;cursor:pointer;font-size:12px">+ Add Rule</button>
                    </div>
                    <div id="lockout-rules-container"></div>
                    <div id="lockout-rules-errors" style="display:none;background:#fde8e8;border:1px solid #f5c6cb;padding:8px 12px;border-radius:4px;margin-top:8px;font-size:12px;color:#721c24"></div>
                    <input type="hidden" name="loginlockoutsteps" id="loginlockoutsteps"
                        value="<?php echo htmlspecialchars((string) json_encode($initialSteps)); ?>">
                    <small style="color:#888;font-size:11px">Durations must increase with each threshold.</small>
                </div>
            </div>
        </div>

        <!-- DevPanel -->
        <div id="settings-tab-devpanel" class="plain-settings-pane" style="display:none">
            <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:16px">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Min Usertype for DevPanel</label>
                        <input type="number" name="devpanel.min_usertype" min="0" max="100" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box"
                            value="<?php
                                $dpu = $s['devpanel.min_usertype'] ?? '';
                                echo htmlspecialchars($dpu !== '' ? $dpu : '90');
                            ?>">
                        <small style="color:#888;font-size:11px">Users below this type cannot access the DevPanel.</small>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">DevPanel Mount Point</label>
                        <input type="text" name="devpanel.mount" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box"
                            value="<?php echo htmlspecialchars($s['devpanel.mount'] !== '' ? $s['devpanel.mount'] : 'devpanel'); ?>">
                        <small style="color:#888;font-size:11px">URL segment where the DevPanel is mounted.</small>
                    </div>
                </div>
            </div>
        </div>

        <div style="display:flex;gap:8px">
            <button type="submit" class="btn btn-primary">Save Settings</button>
            <a href="<?php echo sURL; ?>settings/list" class="btn btn-outline-secondary">Advanced / Raw Settings</a>
        </div>
    </form>
</div>

<style>
.lockout-rule-card { display:flex; align-items:center; gap:10px; border:1px solid #ddd; border-radius:4px; padding:8px 12px; margin-bottom:6px; background:#fff; }
.lockout-rule-card label { font-size:12px; color:#555; white-space:nowrap; }
.lockout-rule-card input { width:80px; padding:4px 8px; border:1px solid #ccc; border-radius:4px; font-size:13px; }
.plain-tab-btn:focus { outline:none; }
</style>

<script>
(function () {
    var defaultSteps = <?php echo json_encode($defaultSteps, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

    function initTabs() {
        var activeInput = document.getElementById('settings_active_tab');
        var btns = document.querySelectorAll('.plain-tab-btn');
        var panes = document.querySelectorAll('.plain-settings-pane');
        function activate(id) {
            btns.forEach(function (b) {
                var isActive = b.getAttribute('data-tab') === id;
                b.style.borderBottomColor = isActive ? '#0d6efd' : 'transparent';
                b.style.color = isActive ? '#0d6efd' : '#666';
                b.style.fontWeight = isActive ? '600' : 'normal';
            });
            panes.forEach(function (p) { p.style.display = p.id === id ? '' : 'none'; });
            if (activeInput) { activeInput.value = id; }
            if (history && history.replaceState) { history.replaceState(null, '', '#' + id); }
        }
        btns.forEach(function (b) { b.addEventListener('click', function () { activate(b.getAttribute('data-tab')); }); });
        var hash = window.location.hash.replace('#', '');
        var valid = Array.from(panes).some(function (p) { return p.id === hash; });
        activate(valid ? hash : 'settings-tab-general');
    }

    function parseInitialSteps() {
        var hidden = document.getElementById('loginlockoutsteps');
        if (!hidden || !hidden.value) { return defaultSteps; }
        try { var p = JSON.parse(hidden.value); if (typeof p === 'object' && p !== null) { return p; } } catch (e) {}
        return defaultSteps;
    }
    function renderCard(attempts, seconds) {
        var card = document.createElement('div'); card.className = 'lockout-rule-card';
        var al = document.createElement('label'); al.textContent = 'Failed attempts:';
        var ai = document.createElement('input'); ai.type = 'number'; ai.min = '1'; ai.className = 'lockout-attempts'; ai.value = attempts || '';
        var sl = document.createElement('label'); sl.textContent = 'Lockout (s):';
        var si = document.createElement('input'); si.type = 'number'; si.min = '1'; si.className = 'lockout-seconds'; si.value = seconds || '';
        var rb = document.createElement('button'); rb.type = 'button';
        rb.textContent = 'Remove'; rb.style.marginLeft = 'auto'; rb.style.padding = '2px 8px'; rb.style.border = '1px solid #dc3545'; rb.style.borderRadius = '4px'; rb.style.color = '#dc3545'; rb.style.background = 'none'; rb.style.cursor = 'pointer'; rb.style.fontSize = '12px';
        rb.addEventListener('click', function () { card.remove(); });
        card.appendChild(al); card.appendChild(ai); card.appendChild(sl); card.appendChild(si); card.appendChild(rb);
        return card;
    }
    function collectRules() {
        var hidden = document.getElementById('loginlockoutsteps');
        var container = document.getElementById('lockout-rules-container');
        var errBox = document.getElementById('lockout-rules-errors');
        if (!hidden || !container) { return true; }
        var errors = [], entries = [], seen = {};
        container.querySelectorAll('.lockout-rule-card').forEach(function (card) {
            var a = parseInt((card.querySelector('.lockout-attempts').value || '').trim(), 10);
            var d = parseInt((card.querySelector('.lockout-seconds').value || '').trim(), 10);
            if (isNaN(a) || isNaN(d) || a <= 0 || d <= 0) { errors.push('All values must be positive integers.'); return; }
            if (seen[a]) { errors.push('Duplicate threshold: ' + a); return; }
            seen[a] = true; entries.push({ a: a, d: d });
        });
        if (entries.length === 0) { errors.push('At least one rule is required.'); }
        entries.sort(function (x, y) { return x.a - y.a; });
        for (var i = 1; i < entries.length; i++) {
            if (entries[i].d <= entries[i - 1].d) { errors.push('Lockout seconds must increase.'); break; }
        }
        if (errors.length > 0) {
            errBox.textContent = [...new Set(errors)].join(' ');
            errBox.style.display = ''; return false;
        }
        errBox.style.display = 'none'; errBox.textContent = '';
        var map = {}; entries.forEach(function (e) { map[e.a] = e.d; });
        hidden.value = JSON.stringify(map); return true;
    }
    function initLockout() {
        var container = document.getElementById('lockout-rules-container');
        var addBtn = document.getElementById('add-lockout-rule');
        var form = document.getElementById('settingsForm');
        if (!container || !addBtn || !form) { return; }
        var steps = parseInitialSteps();
        Object.keys(steps).map(Number).sort(function (a, b) { return a - b; }).forEach(function (k) {
            container.appendChild(renderCard(k, steps[k]));
        });
        addBtn.addEventListener('click', function () { container.appendChild(renderCard('', '')); });
        form.addEventListener('submit', function (e) { if (!collectRules()) { e.preventDefault(); } });
    }

    document.addEventListener('DOMContentLoaded', function () { initTabs(); initLockout(); });
})();
</script>
