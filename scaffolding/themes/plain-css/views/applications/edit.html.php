<?php
/**
 * OAuth2 Application create/edit form (plain-CSS theme).
 *
 * Variables:
 *   $this->application — app row array (null when creating)
 *   $this->message     — success flash (string)
 *   $this->error       — error flash (string)
 */
$app   = $this->application ?? [];
$isNew = empty($app['appid']);

$apptypes    = [0 => 'Web Application', 1 => 'Mobile App', 2 => 'Service / Daemon', 3 => 'Desktop App', 4 => 'IoT Device', 5 => 'Other'];
$accessTypes = [0 => 'REST (API Key)', 1 => 'OAuth2', 2 => 'Legacy API Only'];

$inp = 'width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:13px';
?>
<div class="page-section">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <h2><?php echo $isNew ? 'New Application' : 'Edit Application'; ?></h2>
        <a href="<?php echo sURL; ?>applications" class="btn btn-outline-secondary">Back to list</a>
    </div>

    <?php if (!empty($this->message)): ?>
        <div style="background:#d4edda;border:1px solid #c3e6cb;padding:10px 16px;border-radius:4px;margin-bottom:12px;color:#155724">
            <?php echo htmlspecialchars($this->message === 'secret_rotated' ? 'Client secret rotated.' : 'Application saved.'); ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($this->error)): ?>
        <div style="background:#fde8e8;border:1px solid #f5c6cb;padding:10px 16px;border-radius:4px;margin-bottom:12px;color:#721c24">
            <?php echo htmlspecialchars($this->error === 'name_required' ? 'Application name is required.' : $this->error); ?>
        </div>
    <?php endif; ?>

    <?php if (!$isNew && !empty($app['apikey'])): ?>
        <div style="background:#d1ecf1;border:1px solid #bee5eb;padding:10px 16px;border-radius:4px;margin-bottom:12px;display:flex;align-items:center;gap:16px">
            <div style="font-size:13px"><strong>Client ID:</strong> <code style="background:#fff;padding:2px 4px;border-radius:3px"><?php echo htmlspecialchars($app['apikey'] ?? ''); ?></code></div>
            <a href="<?php echo sURL; ?>applications/rotate/<?php echo (int)$app['appid']; ?>"
               style="margin-left:auto" class="btn btn-sm btn-outline-warning"
               onclick="return confirm('Rotate the client secret?')">Rotate Secret</a>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo sURL; ?>applications/save">
        <?php if (!$isNew): ?>
            <input type="hidden" name="appid" value="<?php echo (int)$app['appid']; ?>">
        <?php endif; ?>

        <!-- Tab nav -->
        <div style="display:flex;border-bottom:2px solid #e0e0e0;margin-bottom:20px;gap:4px">
            <button type="button" class="app-plain-tab active" data-tab="app-plain-basic" style="padding:8px 16px;border:none;background:none;cursor:pointer;font-size:13px;font-weight:600;border-bottom:2px solid #0d6efd;margin-bottom:-2px;color:#0d6efd">Basic</button>
            <button type="button" class="app-plain-tab" data-tab="app-plain-org" style="padding:8px 16px;border:none;background:none;cursor:pointer;font-size:13px;border-bottom:2px solid transparent;margin-bottom:-2px;color:#666">Organisation</button>
            <button type="button" class="app-plain-tab" data-tab="app-plain-oauth" style="padding:8px 16px;border:none;background:none;cursor:pointer;font-size:13px;border-bottom:2px solid transparent;margin-bottom:-2px;color:#666">OAuth2 / API</button>
            <button type="button" class="app-plain-tab" data-tab="app-plain-legal" style="padding:8px 16px;border:none;background:none;cursor:pointer;font-size:13px;border-bottom:2px solid transparent;margin-bottom:-2px;color:#666">Legal</button>
        </div>

        <!-- Basic -->
        <div id="app-plain-basic" class="app-plain-pane">
            <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:16px">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div style="grid-column:1/-1">
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Application Name <span style="color:#dc3545">*</span></label>
                        <input type="text" name="name" required style="<?php echo $inp; ?>" value="<?php echo htmlspecialchars($app['name'] ?? ''); ?>">
                    </div>
                    <div style="grid-column:1/-1">
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Description</label>
                        <textarea name="description" style="<?php echo $inp; ?>" rows="2"><?php echo htmlspecialchars($app['description'] ?? ''); ?></textarea>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Application Type</label>
                        <select name="apptype" style="<?php echo $inp; ?>">
                            <?php foreach ($apptypes as $v => $label): ?>
                                <option value="<?php echo $v; ?>"<?php echo ((int)($app['apptype'] ?? 0) === $v) ? ' selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Access Type</label>
                        <select name="accesstype" style="<?php echo $inp; ?>">
                            <?php foreach ($accessTypes as $v => $label): ?>
                                <option value="<?php echo $v; ?>"<?php echo ((int)($app['accesstype'] ?? 0) === $v) ? ' selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">API Version</label>
                        <input type="text" name="apiversion" maxlength="20" style="<?php echo $inp; ?>" value="<?php echo htmlspecialchars($app['apiversion'] ?? 'v1'); ?>" placeholder="v1">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">App Version</label>
                        <input type="text" name="appversion" maxlength="50" style="<?php echo $inp; ?>" value="<?php echo htmlspecialchars($app['appversion'] ?? ''); ?>" placeholder="1.0.0">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Status</label>
                        <select name="status" style="<?php echo $inp; ?>">
                            <option value="1"<?php echo ((int)($app['status'] ?? 1) === 1) ? ' selected' : ''; ?>>Active</option>
                            <option value="0"<?php echo ((int)($app['status'] ?? 1) === 0) ? ' selected' : ''; ?>>Disabled</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Public Directory</label>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:6px">
                            <input type="checkbox" name="public" value="1"
                                <?php echo ((int)($app['public'] ?? 0) === 1) ? 'checked' : ''; ?>>
                            <span style="font-size:13px">Listed publicly</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Organisation -->
        <div id="app-plain-org" class="app-plain-pane" style="display:none">
            <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:16px">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Organisation Name</label>
                        <input type="text" name="organization" style="<?php echo $inp; ?>" value="<?php echo htmlspecialchars($app['organization'] ?? ''); ?>">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Organisation URL</label>
                        <input type="url" name="organizationurl" style="<?php echo $inp; ?>" value="<?php echo htmlspecialchars($app['organizationurl'] ?? ''); ?>" placeholder="https://example.com">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Application URL</label>
                        <input type="url" name="url" style="<?php echo $inp; ?>" value="<?php echo htmlspecialchars($app['url'] ?? ''); ?>" placeholder="https://app.example.com">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Support Email</label>
                        <input type="email" name="supportemail" style="<?php echo $inp; ?>" value="<?php echo htmlspecialchars($app['supportemail'] ?? ''); ?>" placeholder="support@example.com">
                    </div>
                </div>
            </div>
        </div>

        <!-- OAuth2 / API -->
        <div id="app-plain-oauth" class="app-plain-pane" style="display:none">
            <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:16px">
                <div style="display:grid;gap:12px">
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">OAuth2 Redirect URI(s) / Callback</label>
                        <textarea name="callback" style="<?php echo $inp; ?>;font-family:monospace" rows="2" placeholder="https://app.example.com/callback"><?php echo htmlspecialchars($app['callback'] ?? ''); ?></textarea>
                        <small style="color:#888;font-size:11px">Allowed redirect URIs for OAuth2 flows.</small>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Allowed Scopes</label>
                        <input type="text" name="scope" style="<?php echo $inp; ?>" value="<?php echo htmlspecialchars($app['scope'] ?? ''); ?>" placeholder="openid profile email">
                        <small style="color:#888;font-size:11px">Space-separated OAuth2 scopes.</small>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Public Key (PEM)</label>
                        <textarea name="public_key" style="<?php echo $inp; ?>;font-family:monospace" rows="4" placeholder="-----BEGIN PUBLIC KEY-----"><?php echo htmlspecialchars($app['public_key'] ?? ''); ?></textarea>
                        <small style="color:#888;font-size:11px">For private_key_jwt client auth (RFC 7523).</small>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">JWKS URI</label>
                        <input type="url" name="jwks_uri" style="<?php echo $inp; ?>;font-family:monospace" value="<?php echo htmlspecialchars($app['jwks_uri'] ?? ''); ?>" placeholder="https://app.example.com/.well-known/jwks.json">
                        <small style="color:#888;font-size:11px">Dynamic key rotation endpoint.</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Legal -->
        <div id="app-plain-legal" class="app-plain-pane" style="display:none">
            <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:16px">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Terms of Service URL</label>
                        <input type="url" name="termsurl" style="<?php echo $inp; ?>" value="<?php echo htmlspecialchars($app['termsurl'] ?? ''); ?>" placeholder="https://example.com/terms">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Privacy Policy URL</label>
                        <input type="url" name="privacyurl" style="<?php echo $inp; ?>" value="<?php echo htmlspecialchars($app['privacyurl'] ?? ''); ?>" placeholder="https://example.com/privacy">
                    </div>
                </div>
            </div>
        </div>

        <div style="display:flex;gap:8px;align-items:center">
            <button type="submit" class="btn btn-primary">Save Application</button>
            <a href="<?php echo sURL; ?>applications" class="btn btn-outline-secondary">Cancel</a>
            <?php if (!$isNew): ?>
                <a href="<?php echo sURL; ?>applications/tokens/<?php echo (int)$app['appid']; ?>" style="margin-left:auto" class="btn btn-outline-info">View Tokens</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<script>
(function () {
    var btns  = document.querySelectorAll('.app-plain-tab');
    var panes = document.querySelectorAll('.app-plain-pane');
    function activate(id) {
        btns.forEach(function (b) {
            var a = b.getAttribute('data-tab') === id;
            b.style.borderBottomColor = a ? '#0d6efd' : 'transparent';
            b.style.color = a ? '#0d6efd' : '#666';
            b.style.fontWeight = a ? '600' : 'normal';
        });
        panes.forEach(function (p) { p.style.display = p.id === id ? '' : 'none'; });
    }
    btns.forEach(function (b) { b.addEventListener('click', function () { activate(b.getAttribute('data-tab')); }); });
    activate('app-plain-basic');
})();
</script>
