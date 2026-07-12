<?php
/**
 * OAuth2 Application create/edit form (Tailwind theme).
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

$inp  = 'w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500';
$lbl  = 'block text-sm font-semibold text-gray-700 mb-1';
$card = 'bg-white rounded-xl shadow-sm border border-gray-200 p-5 mb-4';
?>
<div class="px-4 py-6">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-semibold"><?php echo $isNew ? 'New Application' : 'Edit Application'; ?></h2>
        <a href="<?php echo sURL; ?>applications" class="px-3 py-1.5 border border-gray-300 text-gray-700 text-sm rounded hover:bg-gray-50">Back to list</a>
    </div>

    <?php if (!empty($this->message)): ?>
        <div class="bg-green-50 border border-green-300 text-green-800 rounded px-4 py-3 mb-4 text-sm">
            <?php echo htmlspecialchars($this->message === 'secret_rotated' ? 'Client secret rotated.' : 'Application saved.'); ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($this->error)): ?>
        <div class="bg-red-50 border border-red-300 text-red-700 rounded px-4 py-3 mb-4 text-sm">
            <?php echo htmlspecialchars($this->error === 'name_required' ? 'Application name is required.' : $this->error); ?>
        </div>
    <?php endif; ?>

    <?php if (!$isNew && !empty($app['apikey'])): ?>
        <div class="bg-blue-50 border border-blue-200 rounded px-4 py-3 mb-4 flex items-center gap-4 text-sm">
            <div><strong>Client ID:</strong> <code class="font-mono text-xs bg-white px-1 py-0.5 border border-gray-200 rounded"><?php echo htmlspecialchars($app['apikey'] ?? ''); ?></code></div>
            <a href="<?php echo sURL; ?>applications/rotate/<?php echo (int)$app['appid']; ?>"
               class="ml-auto px-3 py-1 border border-yellow-400 text-yellow-700 rounded text-xs hover:bg-yellow-50"
               data-confirm="Rotate the client secret?">Rotate Secret</a>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo sURL; ?>applications/save" id="appEditForm">
        <?php if (!$isNew): ?>
            <input type="hidden" name="appid" value="<?php echo (int)$app['appid']; ?>">
        <?php endif; ?>

        <div class="flex border-b border-gray-200 mb-4 gap-1">
            <button type="button" class="app-tab-btn px-4 py-2 text-sm font-medium rounded-t border border-b-0 border-gray-200 bg-white text-blue-600" data-tab="app-tab-basic">Basic</button>
            <button type="button" class="app-tab-btn px-4 py-2 text-sm font-medium rounded-t border border-b-0 border-transparent text-gray-600 hover:text-blue-600" data-tab="app-tab-org">Organisation</button>
            <button type="button" class="app-tab-btn px-4 py-2 text-sm font-medium rounded-t border border-b-0 border-transparent text-gray-600 hover:text-blue-600" data-tab="app-tab-oauth">OAuth2 / API</button>
            <button type="button" class="app-tab-btn px-4 py-2 text-sm font-medium rounded-t border border-b-0 border-transparent text-gray-600 hover:text-blue-600" data-tab="app-tab-legal">Legal</button>
        </div>

        <div id="app-tab-basic" class="app-tab-pane">
            <div class="<?php echo $card; ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2"><label class="<?php echo $lbl; ?>">Application Name <span class="text-red-500">*</span></label>
                        <input type="text" name="name" class="<?php echo $inp; ?>" required value="<?php echo htmlspecialchars($app['name'] ?? ''); ?>"></div>
                    <div class="md:col-span-2"><label class="<?php echo $lbl; ?>">Description</label>
                        <textarea name="description" class="<?php echo $inp; ?>" rows="2"><?php echo htmlspecialchars($app['description'] ?? ''); ?></textarea></div>
                    <div><label class="<?php echo $lbl; ?>">Application Type</label>
                        <select name="apptype" class="<?php echo $inp; ?>">
                            <?php foreach ($apptypes as $v => $label): ?>
                                <option value="<?php echo $v; ?>"<?php echo ((int)($app['apptype'] ?? 0) === $v) ? ' selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div><label class="<?php echo $lbl; ?>">Access Type</label>
                        <select name="accesstype" class="<?php echo $inp; ?>">
                            <?php foreach ($accessTypes as $v => $label): ?>
                                <option value="<?php echo $v; ?>"<?php echo ((int)($app['accesstype'] ?? 0) === $v) ? ' selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div><label class="<?php echo $lbl; ?>">API Version</label>
                        <input type="text" name="apiversion" class="<?php echo $inp; ?>" maxlength="20" value="<?php echo htmlspecialchars($app['apiversion'] ?? 'v1'); ?>" placeholder="v1"></div>
                    <div><label class="<?php echo $lbl; ?>">App Version</label>
                        <input type="text" name="appversion" class="<?php echo $inp; ?>" maxlength="50" value="<?php echo htmlspecialchars($app['appversion'] ?? ''); ?>" placeholder="1.0.0"></div>
                    <div><label class="<?php echo $lbl; ?>">Status</label>
                        <select name="status" class="<?php echo $inp; ?>">
                            <option value="1"<?php echo ((int)($app['status'] ?? 1) === 1) ? ' selected' : ''; ?>>Active</option>
                            <option value="0"<?php echo ((int)($app['status'] ?? 1) === 0) ? ' selected' : ''; ?>>Disabled</option>
                        </select></div>
                    <div><label class="<?php echo $lbl; ?>">Public Directory</label>
                        <label class="flex items-center gap-2 mt-1 cursor-pointer">
                            <input type="checkbox" name="public" value="1" class="w-4 h-4"
                                <?php echo ((int)($app['public'] ?? 0) === 1) ? 'checked' : ''; ?>>
                            <span class="text-sm text-gray-600">Listed publicly</span>
                        </label></div>
                </div>
            </div>
        </div>

        <div id="app-tab-org" class="app-tab-pane hidden">
            <div class="<?php echo $card; ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label class="<?php echo $lbl; ?>">Organisation Name</label>
                        <input type="text" name="organization" class="<?php echo $inp; ?>" value="<?php echo htmlspecialchars($app['organization'] ?? ''); ?>"></div>
                    <div><label class="<?php echo $lbl; ?>">Organisation URL</label>
                        <input type="url" name="organizationurl" class="<?php echo $inp; ?>" value="<?php echo htmlspecialchars($app['organizationurl'] ?? ''); ?>" placeholder="https://example.com"></div>
                    <div><label class="<?php echo $lbl; ?>">Application URL</label>
                        <input type="url" name="url" class="<?php echo $inp; ?>" value="<?php echo htmlspecialchars($app['url'] ?? ''); ?>" placeholder="https://app.example.com"></div>
                    <div><label class="<?php echo $lbl; ?>">Support Email</label>
                        <input type="email" name="supportemail" class="<?php echo $inp; ?>" value="<?php echo htmlspecialchars($app['supportemail'] ?? ''); ?>" placeholder="support@example.com"></div>
                </div>
            </div>
        </div>

        <div id="app-tab-oauth" class="app-tab-pane hidden">
            <div class="<?php echo $card; ?>">
                <div class="grid grid-cols-1 gap-4">
                    <div><label class="<?php echo $lbl; ?>">OAuth2 Redirect URI(s) / Callback</label>
                        <textarea name="callback" class="<?php echo $inp; ?> font-mono" rows="2" placeholder="https://app.example.com/callback"><?php echo htmlspecialchars($app['callback'] ?? ''); ?></textarea>
                        <p class="text-xs text-gray-400 mt-1">Allowed redirect URIs.</p></div>
                    <div><label class="<?php echo $lbl; ?>">Allowed Scopes</label>
                        <input type="text" name="scope" class="<?php echo $inp; ?>" value="<?php echo htmlspecialchars($app['scope'] ?? ''); ?>" placeholder="openid profile email">
                        <p class="text-xs text-gray-400 mt-1">Space-separated OAuth2 scopes.</p></div>
                    <div><label class="<?php echo $lbl; ?>">Public Key (PEM)</label>
                        <textarea name="public_key" class="<?php echo $inp; ?> font-mono" rows="4" placeholder="-----BEGIN PUBLIC KEY-----"><?php echo htmlspecialchars($app['public_key'] ?? ''); ?></textarea>
                        <p class="text-xs text-gray-400 mt-1">For <code>private_key_jwt</code> client auth (RFC 7523).</p></div>
                    <div><label class="<?php echo $lbl; ?>">JWKS URI</label>
                        <input type="url" name="jwks_uri" class="<?php echo $inp; ?> font-mono" value="<?php echo htmlspecialchars($app['jwks_uri'] ?? ''); ?>" placeholder="https://app.example.com/.well-known/jwks.json">
                        <p class="text-xs text-gray-400 mt-1">Dynamic key rotation endpoint.</p></div>
                </div>
            </div>
        </div>

        <div id="app-tab-legal" class="app-tab-pane hidden">
            <div class="<?php echo $card; ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label class="<?php echo $lbl; ?>">Terms of Service URL</label>
                        <input type="url" name="termsurl" class="<?php echo $inp; ?>" value="<?php echo htmlspecialchars($app['termsurl'] ?? ''); ?>" placeholder="https://example.com/terms"></div>
                    <div><label class="<?php echo $lbl; ?>">Privacy Policy URL</label>
                        <input type="url" name="privacyurl" class="<?php echo $inp; ?>" value="<?php echo htmlspecialchars($app['privacyurl'] ?? ''); ?>" placeholder="https://example.com/privacy"></div>
                </div>
            </div>
        </div>

        <div class="flex gap-3 mt-2">
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded hover:bg-blue-700">Save Application</button>
            <a href="<?php echo sURL; ?>applications" class="px-4 py-2 border border-gray-300 text-gray-700 text-sm rounded hover:bg-gray-50">Cancel</a>
            <?php if (!$isNew): ?>
                <a href="<?php echo sURL; ?>applications/tokens/<?php echo (int)$app['appid']; ?>" class="ml-auto px-4 py-2 border border-blue-300 text-blue-700 text-sm rounded hover:bg-blue-50">View Tokens</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<script>
(function () {
    function initTabs() {
        var btns  = document.querySelectorAll('.app-tab-btn');
        var panes = document.querySelectorAll('.app-tab-pane');
        function activate(id) {
            btns.forEach(function (b) {
                var a = b.getAttribute('data-tab') === id;
                b.classList.toggle('text-blue-600', a); b.classList.toggle('bg-white', a); b.classList.toggle('border-gray-200', a);
                b.classList.toggle('text-gray-600', !a); b.classList.toggle('border-transparent', !a);
            });
            panes.forEach(function (p) { p.classList.toggle('hidden', p.id !== id); });
        }
        btns.forEach(function (b) { b.addEventListener('click', function () { activate(b.getAttribute('data-tab')); }); });
        activate('app-tab-basic');
    }
    document.addEventListener('DOMContentLoaded', initTabs);
})();
</script>
