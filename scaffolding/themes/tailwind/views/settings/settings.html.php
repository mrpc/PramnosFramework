<?php
/**
 * Rich categorized System Settings page (Tailwind theme).
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

$input  = 'w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500';
$label  = 'block text-sm font-semibold text-gray-700 mb-1';
$card   = 'bg-white rounded-xl shadow-sm border border-gray-200 p-5 mb-4';
$btnPri = 'px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded hover:bg-blue-700';
$btnSec = 'px-4 py-2 border border-gray-300 text-gray-700 text-sm font-medium rounded hover:bg-gray-50';
?>
<div class="px-4 py-6">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-semibold">System Settings</h2>
        <a href="<?php echo sURL; ?>settings/list" class="<?php echo $btnSec; ?>">Advanced / Raw</a>
    </div>

    <?php if (!empty($this->success)): ?>
        <div class="bg-green-50 border border-green-300 text-green-800 rounded px-4 py-3 mb-4 text-sm">
            <?php echo htmlspecialchars($this->success); ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($this->warning)): ?>
        <div class="bg-yellow-50 border border-yellow-300 text-yellow-800 rounded px-4 py-3 mb-4 text-sm">
            <?php echo htmlspecialchars($this->warning); ?>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo sURL; ?>settings/saveSystem" id="settingsForm">
        <input type="hidden" name="settings_active_tab" id="settings_active_tab" value="">

        <!-- Tab nav -->
        <div class="flex border-b border-gray-200 mb-4 gap-1">
            <button type="button" class="tab-btn px-4 py-2 text-sm font-medium rounded-t border border-b-0 border-gray-200 bg-white text-blue-600" data-tab="settings-tab-general">General</button>
            <button type="button" class="tab-btn px-4 py-2 text-sm font-medium rounded-t border border-b-0 border-transparent text-gray-600 hover:text-blue-600" data-tab="settings-tab-email">Email / SMTP</button>
            <button type="button" class="tab-btn px-4 py-2 text-sm font-medium rounded-t border border-b-0 border-transparent text-gray-600 hover:text-blue-600" data-tab="settings-tab-security">Security</button>
            <button type="button" class="tab-btn px-4 py-2 text-sm font-medium rounded-t border border-b-0 border-transparent text-gray-600 hover:text-blue-600" data-tab="settings-tab-devpanel">DevPanel</button>
        </div>

        <!-- General -->
        <div id="settings-tab-general" class="settings-pane">
            <div class="<?php echo $card; ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label class="<?php echo $label; ?>">Site Name</label>
                        <input type="text" name="sitename" class="<?php echo $input; ?>" value="<?php echo htmlspecialchars($s['sitename'] ?? ''); ?>"></div>
                    <div><label class="<?php echo $label; ?>">Site URL</label>
                        <input type="url" name="site_url" class="<?php echo $input; ?>" value="<?php echo htmlspecialchars($s['site_url'] ?? ''); ?>" placeholder="https://example.com/"></div>
                    <div><label class="<?php echo $label; ?>">Admin Email (From)</label>
                        <input type="email" name="admin_mail" class="<?php echo $input; ?>" value="<?php echo htmlspecialchars($s['admin_mail'] ?? ''); ?>"></div>
                    <div><label class="<?php echo $label; ?>">Admin Reply-To Email</label>
                        <input type="email" name="admin_replymail" class="<?php echo $input; ?>" value="<?php echo htmlspecialchars($s['admin_replymail'] ?? ''); ?>"></div>
                    <div><label class="<?php echo $label; ?>">Default Language</label>
                        <input type="text" name="default_language" class="<?php echo $input; ?>" maxlength="10" value="<?php echo htmlspecialchars($s['default_language'] ?? 'en'); ?>" placeholder="en"></div>
                    <div><label class="<?php echo $label; ?>">Timezone</label>
                        <select name="timezone" class="<?php echo $input; ?>">
                            <?php
                            $currentTz = $s['timezone'] ?? 'UTC';
                            foreach ($tzs as $tz):
                                $sel = ($currentTz === $tz) ? ' selected' : '';
                            ?>
                                <option value="<?php echo $tz; ?>"<?php echo $sel; ?>><?php echo $tz; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-400 mt-1">Server time: <?php echo date('H:i'); ?></p>
                    </div>
                    <div><label class="<?php echo $label; ?>">Debug Mode</label>
                        <label class="flex items-center gap-2 mt-1 cursor-pointer">
                            <input type="checkbox" name="debug" value="yes" class="w-4 h-4"
                                <?php echo (($s['debug'] ?? '') === 'yes') ? 'checked' : ''; ?>>
                            <span class="text-sm text-gray-600">Enabled</span>
                        </label>
                    </div>
                    <div><label class="<?php echo $label; ?>">Force HTTPS</label>
                        <label class="flex items-center gap-2 mt-1 cursor-pointer">
                            <input type="checkbox" name="forcessl" value="yes" class="w-4 h-4"
                                <?php echo (($s['forcessl'] ?? '') === 'yes') ? 'checked' : ''; ?>>
                            <span class="text-sm text-gray-600">Enabled</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Email / SMTP -->
        <div id="settings-tab-email" class="settings-pane hidden">
            <div class="<?php echo $card; ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2"><label class="<?php echo $label; ?>">SMTP Host</label>
                        <input type="text" name="smtp_host" class="<?php echo $input; ?>" value="<?php echo htmlspecialchars($s['smtp_host'] ?? ''); ?>" placeholder="smtp.example.com"></div>
                    <div><label class="<?php echo $label; ?>">SMTP Port</label>
                        <input type="number" name="smtp_port" class="<?php echo $input; ?>" min="1" max="65535"
                            value="<?php echo htmlspecialchars($s['smtp_port'] !== '' ? $s['smtp_port'] : '587'); ?>"></div>
                    <div><label class="<?php echo $label; ?>">Use TLS/SSL</label>
                        <label class="flex items-center gap-2 mt-1 cursor-pointer">
                            <input type="checkbox" name="smtp_tls" value="yes" class="w-4 h-4"
                                <?php echo (($s['smtp_tls'] ?? '') === 'yes') ? 'checked' : ''; ?>>
                            <span class="text-sm text-gray-600">Enabled</span>
                        </label>
                    </div>
                    <div><label class="<?php echo $label; ?>">SMTP Username</label>
                        <input type="text" name="smtp_user" class="<?php echo $input; ?>" autocomplete="off" value="<?php echo htmlspecialchars($s['smtp_user'] ?? ''); ?>"></div>
                    <div><label class="<?php echo $label; ?>">SMTP Password</label>
                        <input type="password" name="smtp_pass" class="<?php echo $input; ?>" autocomplete="new-password" value="<?php echo htmlspecialchars($s['smtp_pass'] ?? ''); ?>"></div>
                </div>
            </div>
        </div>

        <!-- Security -->
        <div id="settings-tab-security" class="settings-pane hidden">
            <div class="<?php echo $card; ?>">
                <div class="grid grid-cols-1 gap-4">
                    <div class="md:w-48"><label class="<?php echo $label; ?>">Lockout Window (seconds)</label>
                        <input type="number" name="loginlockoutwindowseconds" class="<?php echo $input; ?>" min="60" max="86400"
                            value="<?php
                                $w = (int) ($s['loginlockoutwindowseconds'] ?? 0);
                                echo $w > 0 ? $w : \Pramnos\Application\Controllers\SettingsController::DEFAULT_LOCKOUT_WINDOW_SECONDS;
                            ?>">
                        <p class="text-xs text-gray-400 mt-1">Sliding window for counting failed logins.</p>
                    </div>
                    <div>
                        <div class="flex justify-between items-center mb-2">
                            <label class="<?php echo $label; ?> mb-0">Progressive Lockout Rules</label>
                            <button type="button" id="add-lockout-rule" class="text-xs px-3 py-1 border border-blue-400 text-blue-600 rounded hover:bg-blue-50">+ Add Rule</button>
                        </div>
                        <div id="lockout-rules-container"></div>
                        <div id="lockout-rules-errors" class="hidden mt-2 bg-red-50 border border-red-300 text-red-700 rounded px-3 py-2 text-sm"></div>
                        <input type="hidden" name="loginlockoutsteps" id="loginlockoutsteps"
                            value="<?php echo htmlspecialchars((string) json_encode($initialSteps)); ?>">
                        <p class="text-xs text-gray-400 mt-1">Durations must increase with failed attempt count.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- DevPanel -->
        <div id="settings-tab-devpanel" class="settings-pane hidden">
            <div class="<?php echo $card; ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label class="<?php echo $label; ?>">Minimum Usertype for DevPanel</label>
                        <input type="number" name="devpanel.min_usertype" class="<?php echo $input; ?>" min="0" max="100"
                            value="<?php
                                $dpu = $s['devpanel.min_usertype'] ?? '';
                                echo htmlspecialchars($dpu !== '' ? $dpu : '90');
                            ?>">
                        <p class="text-xs text-gray-400 mt-1">Users below this type cannot access the DevPanel.</p>
                    </div>
                    <div><label class="<?php echo $label; ?>">DevPanel Mount Point</label>
                        <input type="text" name="devpanel.mount" class="<?php echo $input; ?>"
                            value="<?php echo htmlspecialchars($s['devpanel.mount'] !== '' ? $s['devpanel.mount'] : 'devpanel'); ?>">
                        <p class="text-xs text-gray-400 mt-1">URL segment where the DevPanel is mounted.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex gap-3 mt-2">
            <button type="submit" class="<?php echo $btnPri; ?>">Save Settings</button>
            <a href="<?php echo sURL; ?>settings/list" class="<?php echo $btnSec; ?>">Advanced / Raw Settings</a>
        </div>
    </form>
</div>

<style>
.lockout-rule-card { display:flex; align-items:center; gap:12px; background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:10px 12px; margin-bottom:6px; }
.lockout-rule-card label { font-size:12px; color:#6b7280; white-space:nowrap; }
.lockout-rule-card input { width:90px; border:1px solid #d1d5db; border-radius:4px; padding:4px 8px; font-size:13px; }
</style>

<script>
(function () {
    var defaultSteps = <?php echo json_encode($defaultSteps, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

    /* ── Tab switching ─────────────────────────────────────────────────── */
    function initTabs() {
        var activeInput = document.getElementById('settings_active_tab');
        var btns = document.querySelectorAll('.tab-btn');
        var panes = document.querySelectorAll('.settings-pane');
        function activate(id) {
            btns.forEach(function (b) {
                var active = b.getAttribute('data-tab') === id;
                b.classList.toggle('text-blue-600', active);
                b.classList.toggle('bg-white', active);
                b.classList.toggle('border-gray-200', active);
                b.classList.toggle('text-gray-600', !active);
                b.classList.toggle('border-transparent', !active);
            });
            panes.forEach(function (p) { p.classList.toggle('hidden', p.id !== id); });
            if (activeInput) { activeInput.value = id; }
            if (history && history.replaceState) { history.replaceState(null, '', '#' + id); }
        }
        btns.forEach(function (b) {
            b.addEventListener('click', function () { activate(b.getAttribute('data-tab')); });
        });
        var hash = window.location.hash.replace('#', '');
        var valid = Array.from(panes).some(function (p) { return p.id === hash; });
        activate(valid ? hash : 'settings-tab-general');
    }

    /* ── Lockout rules builder ─────────────────────────────────────────── */
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
        rb.className = 'ml-auto text-xs px-2 py-1 border border-red-300 text-red-600 rounded hover:bg-red-50';
        rb.textContent = 'Remove'; rb.addEventListener('click', function () { card.remove(); });
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
            errBox.classList.remove('hidden'); return false;
        }
        errBox.classList.add('hidden'); errBox.textContent = '';
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
