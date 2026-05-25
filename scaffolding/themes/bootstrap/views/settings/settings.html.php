<?php
/**
 * Rich categorized System Settings page (Bootstrap theme).
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
            if ($t > 0 && $d > 0) {
                $initialSteps[$t] = $d;
            }
        }
    }
}
if (count($initialSteps) === 0) {
    $initialSteps = $defaultSteps;
}
ksort($initialSteps, SORT_NUMERIC);
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">System Settings</h2>
        <a href="<?php echo sURL; ?>settings/list" class="btn btn-outline-secondary btn-sm">Advanced / Raw Settings</a>
    </div>

    <?php if (!empty($this->success)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($this->success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($this->warning)): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($this->warning); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo sURL; ?>settings/saveSystem" id="settingsForm">
        <input type="hidden" name="settings_active_tab" id="settings_active_tab" value="">

        <ul class="nav nav-tabs mb-3" id="settingsTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-general-btn" data-bs-toggle="tab" data-bs-target="#settings-tab-general" type="button" role="tab">General</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-email-btn" data-bs-toggle="tab" data-bs-target="#settings-tab-email" type="button" role="tab">Email / SMTP</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-security-btn" data-bs-toggle="tab" data-bs-target="#settings-tab-security" type="button" role="tab">Security</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-devpanel-btn" data-bs-toggle="tab" data-bs-target="#settings-tab-devpanel" type="button" role="tab">DevPanel</button>
            </li>
        </ul>

        <div class="tab-content" id="settingsTabsContent">

            <!-- ── General ─────────────────────────────────────────────────── -->
            <div class="tab-pane fade show active" id="settings-tab-general" role="tabpanel">
                <div class="card mb-3"><div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Site Name</label>
                            <input type="text" name="sitename" class="form-control" value="<?php echo htmlspecialchars($s['sitename'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Site URL</label>
                            <input type="url" name="site_url" class="form-control" value="<?php echo htmlspecialchars($s['site_url'] ?? ''); ?>" placeholder="https://example.com/">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Admin Email (From)</label>
                            <input type="email" name="admin_mail" class="form-control" value="<?php echo htmlspecialchars($s['admin_mail'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Admin Reply-To Email</label>
                            <input type="email" name="admin_replymail" class="form-control" value="<?php echo htmlspecialchars($s['admin_replymail'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Default Language</label>
                            <input type="text" name="default_language" class="form-control" maxlength="10" value="<?php echo htmlspecialchars($s['default_language'] ?? 'en'); ?>" placeholder="en">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Timezone</label>
                            <select name="timezone" class="form-select">
                                <?php
                                $currentTz = $s['timezone'] ?? 'UTC';
                                foreach ($tzs as $tz):
                                    $sel = ($currentTz === $tz) ? ' selected' : '';
                                ?>
                                    <option value="<?php echo $tz; ?>"<?php echo $sel; ?>><?php echo $tz; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Server time: <?php echo date('H:i'); ?></div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold d-block">Debug Mode</label>
                            <div class="form-check form-switch mt-1">
                                <input class="form-check-input" type="checkbox" name="debug" value="yes" id="chk_debug"
                                    <?php echo (($s['debug'] ?? '') === 'yes') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="chk_debug">Enabled</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold d-block">Force HTTPS</label>
                            <div class="form-check form-switch mt-1">
                                <input class="form-check-input" type="checkbox" name="forcessl" value="yes" id="chk_ssl"
                                    <?php echo (($s['forcessl'] ?? '') === 'yes') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="chk_ssl">Enabled</label>
                            </div>
                        </div>
                    </div>
                </div></div>
            </div>

            <!-- ── Email / SMTP ────────────────────────────────────────────── -->
            <div class="tab-pane fade" id="settings-tab-email" role="tabpanel">
                <div class="card mb-3"><div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">SMTP Host</label>
                            <input type="text" name="smtp_host" class="form-control" value="<?php echo htmlspecialchars($s['smtp_host'] ?? ''); ?>" placeholder="smtp.example.com">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">SMTP Port</label>
                            <input type="number" name="smtp_port" class="form-control" min="1" max="65535"
                                value="<?php echo htmlspecialchars($s['smtp_port'] !== '' ? $s['smtp_port'] : '587'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">SMTP Username</label>
                            <input type="text" name="smtp_user" class="form-control" autocomplete="off" value="<?php echo htmlspecialchars($s['smtp_user'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">SMTP Password</label>
                            <input type="password" name="smtp_pass" class="form-control" autocomplete="new-password" value="<?php echo htmlspecialchars($s['smtp_pass'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold d-block">Use TLS/SSL</label>
                            <div class="form-check form-switch mt-1">
                                <input class="form-check-input" type="checkbox" name="smtp_tls" value="yes" id="chk_tls"
                                    <?php echo (($s['smtp_tls'] ?? '') === 'yes') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="chk_tls">Enabled</label>
                            </div>
                        </div>
                    </div>
                </div></div>
            </div>

            <!-- ── Security ───────────────────────────────────────────────── -->
            <div class="tab-pane fade" id="settings-tab-security" role="tabpanel">
                <div class="card mb-3"><div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Security Salt</label>
                            <input type="text" name="securitySalt" class="form-control font-monospace"
                                autocomplete="off"
                                placeholder="Leave blank to keep the current salt"
                                value="">
                            <div class="form-text text-danger">Current salt is not shown. Fill this field only if you want to change it.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Lockout Window (seconds)</label>
                            <input type="number" name="loginlockoutwindowseconds" class="form-control"
                                min="60" max="86400" step="1"
                                value="<?php
                                    $w = (int) ($s['loginlockoutwindowseconds'] ?? 0);
                                    echo $w > 0 ? $w : \Pramnos\Application\Controllers\SettingsController::DEFAULT_LOCKOUT_WINDOW_SECONDS;
                                ?>">
                            <div class="form-text">Sliding time window for counting failed login attempts.</div>
                        </div>
                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label fw-semibold mb-0">Progressive Lockout Rules</label>
                                <button type="button" class="btn btn-sm btn-outline-primary" id="add-lockout-rule">+ Add Rule</button>
                            </div>
                            <div id="lockout-rules-container"></div>
                            <div id="lockout-rules-errors" class="alert alert-danger mt-2" style="display:none"></div>
                            <input type="hidden" name="loginlockoutsteps" id="loginlockoutsteps"
                                value="<?php echo htmlspecialchars((string) json_encode($initialSteps)); ?>">
                            <div class="form-text">Each rule: after N failed attempts within the window, lock out for D seconds. Durations must increase.</div>
                        </div>
                    </div>
                </div></div>
            </div>

            <!-- ── DevPanel ───────────────────────────────────────────────── -->
            <div class="tab-pane fade" id="settings-tab-devpanel" role="tabpanel">
                <div class="card mb-3"><div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Minimum Usertype for DevPanel</label>
                            <input type="number" name="devpanel.min_usertype" class="form-control" min="0" max="100"
                                value="<?php
                                    $dpu = $s['devpanel.min_usertype'] ?? '';
                                    echo htmlspecialchars($dpu !== '' ? $dpu : '90');
                                ?>">
                            <div class="form-text">Users with a usertype below this value cannot access the DevPanel.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">DevPanel Mount Point</label>
                            <input type="text" name="devpanel.mount" class="form-control"
                                value="<?php echo htmlspecialchars($s['devpanel.mount'] !== '' ? $s['devpanel.mount'] : 'devpanel'); ?>">
                            <div class="form-text">URL segment where the DevPanel is mounted (e.g. <code>devpanel</code>).</div>
                        </div>
                    </div>
                </div></div>
            </div>

        </div><!-- /.tab-content -->

        <div class="mt-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary">Save Settings</button>
            <a href="<?php echo sURL; ?>settings/list" class="btn btn-outline-secondary">Advanced / Raw Settings</a>
        </div>
    </form>
</div>

<style>
.lockout-rule-card { border: 1px solid #dee2e6; border-radius: .375rem; padding: .75rem; margin-bottom: .5rem; background: #fff; }
</style>

<script>
(function () {
    var defaultSteps = <?php echo json_encode($defaultSteps, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

    function parseInitialSteps() {
        var hidden = document.getElementById('loginlockoutsteps');
        if (!hidden || !hidden.value) { return defaultSteps; }
        try {
            var p = JSON.parse(hidden.value);
            if (typeof p === 'object' && p !== null) { return p; }
        } catch (e) {}
        return defaultSteps;
    }

    function renderCard(attempts, seconds) {
        var card = document.createElement('div');
        card.className = 'lockout-rule-card d-flex align-items-center gap-3';

        var attLabel = document.createElement('label'); attLabel.textContent = 'Failed attempts:';
        var attInput = document.createElement('input');
        attInput.type = 'number'; attInput.min = '1'; attInput.step = '1';
        attInput.className = 'form-control form-control-sm lockout-attempts'; attInput.style.width = '90px';
        attInput.value = attempts || '';

        var secLabel = document.createElement('label'); secLabel.textContent = 'Lockout (seconds):';
        var secInput = document.createElement('input');
        secInput.type = 'number'; secInput.min = '1'; secInput.step = '1';
        secInput.className = 'form-control form-control-sm lockout-seconds'; secInput.style.width = '110px';
        secInput.value = seconds || '';

        var removeBtn = document.createElement('button');
        removeBtn.type = 'button'; removeBtn.className = 'btn btn-sm btn-outline-danger ms-auto';
        removeBtn.textContent = 'Remove';
        removeBtn.addEventListener('click', function () { card.remove(); });

        card.appendChild(attLabel); card.appendChild(attInput);
        card.appendChild(secLabel); card.appendChild(secInput);
        card.appendChild(removeBtn);
        return card;
    }

    function collectRules() {
        var hidden    = document.getElementById('loginlockoutsteps');
        var container = document.getElementById('lockout-rules-container');
        var errBox    = document.getElementById('lockout-rules-errors');
        if (!hidden || !container) { return true; }

        var errors = [], entries = [], seen = {};
        container.querySelectorAll('.lockout-rule-card').forEach(function (card) {
            var a = parseInt((card.querySelector('.lockout-attempts').value || '').trim(), 10);
            var d = parseInt((card.querySelector('.lockout-seconds').value || '').trim(), 10);
            if (isNaN(a) || isNaN(d) || a <= 0 || d <= 0) { errors.push('All values must be positive integers.'); return; }
            if (seen[a]) { errors.push('Duplicate failed-attempts threshold: ' + a); return; }
            seen[a] = true;
            entries.push({ a: a, d: d });
        });

        if (entries.length === 0) { errors.push('At least one lockout rule is required.'); }

        entries.sort(function (x, y) { return x.a - y.a; });
        for (var i = 1; i < entries.length; i++) {
            if (entries[i].d <= entries[i - 1].d) { errors.push('Lockout seconds must increase with each threshold.'); break; }
        }

        if (errors.length > 0) {
            errBox.innerHTML = '<strong>Please fix lockout rules:</strong><ul class="mb-0"><li>' + [...new Set(errors)].join('</li><li>') + '</li></ul>';
            errBox.style.display = '';
            return false;
        }
        errBox.style.display = 'none'; errBox.innerHTML = '';

        var map = {};
        entries.forEach(function (e) { map[e.a] = e.d; });
        hidden.value = JSON.stringify(map);
        return true;
    }

    function init() {
        var container = document.getElementById('lockout-rules-container');
        var addBtn    = document.getElementById('add-lockout-rule');
        var form      = document.getElementById('settingsForm');
        if (!container || !addBtn || !form) { return; }

        var steps = parseInitialSteps();
        Object.keys(steps).map(Number).sort(function (a, b) { return a - b; }).forEach(function (k) {
            container.appendChild(renderCard(k, steps[k]));
        });

        addBtn.addEventListener('click', function () { container.appendChild(renderCard('', '')); });

        form.addEventListener('submit', function (e) { if (!collectRules()) { e.preventDefault(); } });
    }

    // Tab persistence
    function initTabPersistence() {
        var activeInput = document.getElementById('settings_active_tab');
        var tabEls = document.querySelectorAll('#settingsTabs [data-bs-toggle="tab"]');
        tabEls.forEach(function (btn) {
            btn.addEventListener('shown.bs.tab', function (e) {
                var target = e.target.getAttribute('data-bs-target') || '';
                if (target.startsWith('#') && activeInput) { activeInput.value = target.slice(1); }
                if (history && history.replaceState) { history.replaceState(null, '', target); }
            });
        });
        var hash = window.location.hash;
        if (hash) {
            var matchBtn = document.querySelector('#settingsTabs [data-bs-target="' + hash + '"]');
            if (matchBtn) { matchBtn.click(); }
        }
    }

    document.addEventListener('DOMContentLoaded', function () { init(); initTabPersistence(); });
})();
</script>
