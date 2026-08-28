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
$s               = $this->settings ?? [];
$tzs             = $this->timezones ?? \DateTimeZone::listIdentifiers();
$devpanelEnabled = $this->devpanelEnabled ?? false;

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

$input  = 'w-full border border-base-300 rounded-sm px-3 py-2 text-sm focus:outline-hidden focus:ring-2 focus:ring-primary';
$label  = 'block text-sm font-semibold text-base-content mb-1';
$card   = 'bg-base-100 rounded-xl shadow-xs border border-base-300 p-5 mb-4';
$btnPri = 'px-4 py-2 bg-primary text-white text-sm font-medium rounded-sm hover:bg-primary';
$btnSec = 'px-4 py-2 border border-base-300 text-base-content text-sm font-medium rounded-sm hover:bg-base-200';
?>
<div class="px-4 py-6">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-semibold">System Settings</h2>
        <a href="<?php echo adminUrl('settings/list'); ?>" class="<?php echo $btnSec; ?>">Advanced / Raw</a>
    </div>

    <?php if (!empty($this->success)): ?>
        <div class="alert alert-success mb-4">
            <?php echo htmlspecialchars($this->success); ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($this->warning)): ?>
        <div class="alert alert-warning mb-4">
            <?php echo htmlspecialchars($this->warning); ?>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo adminUrl('settings/saveSystem'); ?>" id="settingsForm">
        <input type="hidden" name="settings_active_tab" id="settings_active_tab" value="">

        <!-- Tab nav -->
        <div class="flex border-b border-base-300 mb-4 gap-1">
            <button type="button" class="tab-btn px-4 py-2 text-sm font-medium rounded-t border border-b-0 border-base-300 bg-base-100 text-primary" data-tab="settings-tab-general">General</button>
            <button type="button" class="tab-btn px-4 py-2 text-sm font-medium rounded-t border border-b-0 border-transparent text-base-content/80 hover:text-primary" data-tab="settings-tab-email">Email / SMTP</button>
            <button type="button" class="tab-btn px-4 py-2 text-sm font-medium rounded-t border border-b-0 border-transparent text-base-content/80 hover:text-primary" data-tab="settings-tab-security">Security</button>
            <?php if ($devpanelEnabled): ?><button type="button" class="tab-btn px-4 py-2 text-sm font-medium rounded-t border border-b-0 border-transparent text-base-content/80 hover:text-primary" data-tab="settings-tab-devpanel">DevPanel</button><?php endif; ?>
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
                        <p class="text-xs text-base-content/60 mt-1">Server time: <?php echo date('H:i'); ?></p>
                    </div>
                    <div><label class="<?php echo $label; ?>">Force HTTPS</label>
                        <label class="flex items-center gap-2 mt-1 cursor-pointer">
                            <input type="checkbox" name="forcessl" value="yes" class="w-4 h-4"
                                <?php echo (($s['forcessl'] ?? '') === 'yes') ? 'checked' : ''; ?>>
                            <span class="text-sm text-base-content/80">Enabled</span>
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
                            <span class="text-sm text-base-content/80">Enabled</span>
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
                        <p class="text-xs text-base-content/60 mt-1">Sliding window for counting failed logins.</p>
                    </div>
                    <div>
                        <div class="flex justify-between items-center mb-2">
                            <label class="<?php echo $label; ?> mb-0">Progressive Lockout Rules</label>
                            <button type="button" id="add-lockout-rule" class="btn btn-outline btn-primary btn-xs">+ Add Rule</button>
                        </div>
                        <div id="lockout-rules-container"></div>
                        <div id="lockout-rules-errors" class="alert alert-error hidden mt-2"></div>
                        <input type="hidden" name="loginlockoutsteps" id="loginlockoutsteps"
                            value="<?php echo htmlspecialchars((string) json_encode($initialSteps)); ?>">
                        <p class="text-xs text-base-content/60 mt-1">Durations must increase with failed attempt count.</p>
                    </div>
                    <div>
                        <?php
                        /**
                         * New-sign-in alerts.
                         *
                         * The feature was per-user opt-in and nothing else: an operator
                         * could not turn it on for everybody, and could not turn it off
                         * during an incident generating thousands of sign-ins.
                         */
                        $policyKey   = \Pramnos\Auth\NewSignInAlert::POLICY_SETTING;
                        $policyValue = (string) ($s[$policyKey] ?? '') ?: 'optin';
                        ?>
                        <label class="<?php echo $label; ?>" for="<?php echo $policyKey; ?>">
                            New sign-in alerts
                        </label>
                        <select class="select select-sm w-full" id="<?php echo $policyKey; ?>"
                                name="<?php echo $policyKey; ?>">
                            <option value="optin" <?php echo $policyValue === 'optin' ? 'selected' : ''; ?>>
                                Each user decides (default)
                            </option>
                            <option value="always" <?php echo $policyValue === 'always' ? 'selected' : ''; ?>>
                                Always notify
                            </option>
                            <option value="off" <?php echo $policyValue === 'off' ? 'selected' : ''; ?>>
                                Never notify
                            </option>
                        </select>
                        <p class="text-xs text-base-content/60 mt-1">
                            Whether an account is emailed when it is used from a device and
                            browser it has not signed in from before. Compared against the
                            activity log, so an account with history is not told its usual
                            device is new.
                        </p>
                    </div>
                    <div>
                        <?php
                        /**
                         * And what such a sign-in has to *satisfy*.
                         *
                         * Telling somebody afterwards is the weakest useful response —
                         * by the time the mail arrives, whoever had the password is
                         * already inside. These are the options that stop it instead.
                         *
                         * Each one falls back to something the account can actually do,
                         * so none of them is a way to lock a user base out: the details
                         * are in NewSignInAlert::requiredFor().
                         */
                        $actionKey   = \Pramnos\Auth\NewSignInAlert::ACTION_SETTING;
                        $actionValue = (string) ($s[$actionKey] ?? '') ?: 'notify';
                        /*
                         * The labels name the fallback, because the strict readings are
                         * meaningless without it — and worse, they read as a promise the
                         * deployment cannot keep. "Require a passkey" on a user base that
                         * has none is not a policy; what actually happens is that the
                         * account is asked for whatever it does have, and for a mailed code
                         * if it has nothing. A dropdown that hides that is a dropdown an
                         * operator chooses wrongly.
                         */
                        $actionLabels = [
                            'notify'          => 'Only notify (default)',
                            'authlink'        => 'Require a link sent by email — works for every account',
                            'require_2fa'     => 'Require a second factor — a mailed code if the account has none',
                            'require_passkey' => 'Require a passkey — falls back to a factor, then a mailed code',
                        ];
                        ?>
                        <label class="<?php echo $label; ?>" for="<?php echo $actionKey; ?>">
                            On a sign-in from a new device
                        </label>
                        <select class="select select-sm w-full" id="<?php echo $actionKey; ?>"
                                name="<?php echo $actionKey; ?>">
                            <?php foreach ($actionLabels as $value => $text): ?>
                            <option value="<?php echo $value; ?>" <?php echo $actionValue === $value ? 'selected' : ''; ?>>
                                <?php echo $text; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-base-content/60 mt-1">
                            Beyond the alert. Nothing here can lock an account out: a demand
                            it cannot satisfy becomes the strongest factor it does have, and
                            a code emailed to it as a last resort — every account has a
                            mailbox. Accounts with no second factor set up are therefore
                            covered too, which is the point, since those are the ones a
                            stolen password reaches.
                        </p>
                    </div>
                </div>

                <?php
                /**
                 * What this application has enabled — read-only, and next to the settings
                 * that refer to it.
                 *
                 * An operator choosing "require a second factor" cannot otherwise tell
                 * whether the factors it refers to exist in this deployment, and "why is
                 * nobody asked for a code" has no answer on this screen. These are
                 * declared in `app/app.php`, versioned with the code, which is why they
                 * are shown rather than edited here.
                 */
                ?>
                <div class="mt-6 pt-4 border-t border-base-300">
                    <h4 class="font-semibold text-sm mb-2">What this application offers</h4>
                    <div class="flex flex-wrap gap-4 text-sm">
                        <div>
                            <span class="text-base-content/60">Second factors:</span>
                            <?php foreach ((array) ($this->twofactorMethods ?? []) as $method): ?>
                                <span class="badge badge-sm badge-outline ml-1"><?php echo htmlspecialchars((string) $method); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <div>
                            <span class="text-base-content/60">Features:</span>
                            <?php foreach ((array) ($this->enabledFeatures ?? []) as $feature): ?>
                                <span class="badge badge-sm badge-ghost ml-1"><?php echo htmlspecialchars((string) $feature); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <p class="text-xs text-base-content/60 mt-2">
                        Set in <code>app/app.php</code> —
                        <code>'auth' =&gt; ['twofactor_methods' =&gt; [...]]</code> and
                        <code>'features' =&gt; [...]</code>. Not editable here: they are part of
                        the deployment, not of the runtime configuration.
                    </p>
                </div>
            </div>
        </div>

        <?php if ($devpanelEnabled): ?>
        <!-- DevPanel -->
        <div id="settings-tab-devpanel" class="settings-pane hidden">
            <div class="<?php echo $card; ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label class="<?php echo $label; ?>">Debug Mode</label>
                        <?php
                        /*
                         * Here rather than under General, because this is the only thing it
                         * still decides.
                         *
                         * It used to open the debug toolbar for every visitor of the site —
                         * queries with their bindings, session keys, authentication state —
                         * from a row anybody reaching this screen can flip, and it wrote the
                         * view's file path into the source of every page. Both of those follow
                         * the environment now. What is left is this panel and the debug log,
                         * so this is where the switch belongs: a checkbox called "Debug Mode"
                         * sitting among the general settings is read as "show me the developer
                         * tools", and it no longer does that.
                         */
                        ?>
                        <?php if (defined('DEVELOPMENT') && DEVELOPMENT === true): ?>
                            <p class="text-xs text-warning mt-1">&#9888; Always ON — the DEVELOPMENT constant is defined in the application config, so this setting has no effect.</p>
                        <?php else: ?>
                        <?php /* Says "this field was on the form": an unchecked checkbox
                                 submits nothing, and without this the controller could not
                                 tell an installation that turned it off from one whose
                                 DevPanel tab was never rendered. */ ?>
                        <input type="hidden" name="debug_present" value="1">
                        <label class="flex items-center gap-2 mt-1 cursor-pointer">
                            <input type="checkbox" name="debug" value="yes" class="w-4 h-4"
                                <?php echo (($s['debug'] ?? '') === 'yes') ? 'checked' : ''; ?>>
                            <span class="text-sm text-base-content/80">Enabled</span>
                        </label>
                        <?php endif; ?>
                        <p class="text-xs text-base-content/60 mt-1">
                            Opens this panel, together with the usertype floor beside it, and
                            writes the debug log. It does <strong>not</strong> open the debug
                            toolbar and adds nothing to a page a visitor sees — those follow
                            <code>APP_DEBUG</code>, the <code>DEVELOPMENT</code> constant, or a
                            one-browser grant from <code>debug:token</code>.
                        </p>
                    </div>
                    <div><label class="<?php echo $label; ?>">Minimum Usertype for DevPanel</label>
                        <input type="number" name="devpanel.min_usertype" class="<?php echo $input; ?>" min="0" max="100"
                            value="<?php
                                $dpu = $s['devpanel.min_usertype'] ?? '';
                                echo htmlspecialchars($dpu !== '' ? $dpu : '90');
                            ?>">
                        <p class="text-xs text-base-content/60 mt-1">Users below this type cannot access the DevPanel.</p>
                    </div>
                    <div><label class="<?php echo $label; ?>">DevPanel Mount Point</label>
                        <input type="text" name="devpanel.mount" class="<?php echo $input; ?>"
                            value="<?php echo htmlspecialchars($s['devpanel.mount'] !== '' ? $s['devpanel.mount'] : 'devpanel'); ?>">
                        <p class="text-xs text-base-content/60 mt-1">URL segment where the DevPanel is mounted.</p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="flex gap-3 mt-2">
            <button type="submit" class="<?php echo $btnPri; ?>">Save Settings</button>
            <a href="<?php echo adminUrl('settings/list'); ?>" class="<?php echo $btnSec; ?>">Advanced / Raw Settings</a>
        </div>
    </form>
</div>

<style>
/* Theme tokens, not literals: `background:#fff` renders a white card in the dark theme
   with the theme's own light text on it, which is a card nobody can read. */
.lockout-rule-card { display:flex; align-items:center; gap:12px; background:var(--color-base-100); border:1px solid var(--color-base-300); border-radius:6px; padding:10px 12px; margin-bottom:6px; }
.lockout-rule-card label { font-size:12px; color:var(--color-base-content); opacity:.65; white-space:nowrap; }
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
                b.classList.toggle('text-primary', active);
                b.classList.toggle('bg-base-100', active);
                b.classList.toggle('border-base-300', active);
                b.classList.toggle('text-base-content/80', !active);
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
        rb.className = 'ml-auto text-xs px-2 py-1 border border-error text-error rounded-sm hover:bg-error/10';
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
