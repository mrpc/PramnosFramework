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

$inp  = 'w-full border border-base-300 rounded-sm px-3 py-2 text-sm focus:outline-hidden focus:ring-2 focus:ring-primary';
$lbl  = 'block text-sm font-semibold text-base-content mb-1';
$card = 'bg-base-100 rounded-xl shadow-xs border border-base-300 p-5 mb-4';
?>
<div class="px-4 py-6">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-semibold"><?php echo $isNew ? 'New Application' : 'Edit Application'; ?></h2>
        <a href="<?php echo adminUrl('applications'); ?>" class="btn btn-outline btn-sm">Back to list</a>
    </div>

    <?php /* The message itself, not a lookup on a code. This used to map
             'secret_rotated' to a sentence and everything else to
             "Application saved." — but the controller flashes a sentence, so no
             code ever matched: rotating a client secret confirmed a save that
             had not happened, on the page an operator checks precisely because
             the rotation is invisible otherwise. */ ?>
    <?php if (!empty($this->message)): ?>
        <div role="status" class="alert alert-success mb-4">
            <?php echo htmlspecialchars((string) $this->message); ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($this->error)): ?>
        <div role="alert" class="alert alert-error mb-4">
            <?php echo htmlspecialchars((string) $this->error); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($app['appid']) && \Pramnos\Auth\Application::needsARegisteredCallback($app)): ?>
        <div role="status" class="alert alert-warning mb-4 block">
            <strong>This application cannot sign anyone in yet.</strong>
            It cannot keep a client secret &mdash; it is marked public, or it has no secret stored &mdash;
            and it has no registered redirect URI. For such a client the registered URI is the only
            thing tying a sign-in code to it, so the authorization endpoint refuses every sign-in
            until one is added. Add the exact callback URI(s) the application uses under
            <em>OAuth2 Redirect URI(s)</em>.
            <a href="<?php echo \Pramnos\Auth\Application::CALLBACK_GUIDE_URL; ?>" target="_blank" rel="noopener">Why this is required &rarr;</a>
        </div>
    <?php endif; ?>

    <?php if (!$isNew && !empty($app['apikey'])): ?>
        <div class="bg-primary/10 border border-primary rounded-sm px-4 py-3 mb-4 flex items-center gap-4 text-sm">
            <div><strong>Client ID:</strong> <code class="card bg-base-100 border border-base-300 px-1 py-0.5 font-mono"><?php echo htmlspecialchars($app['apikey'] ?? ''); ?></code></div>
            <form method="post" action="<?php echo adminUrl('applications' . '/rotate/' . ((int)$app['appid'])); ?>" style="display:inline;margin:0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><button type="submit"
               class="btn btn-outline btn-warning btn-xs ml-auto"
               data-confirm="Rotate the client secret?">Rotate Secret</button></form>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo adminUrl('applications/save'); ?>" id="appEditForm">
        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
        <?php if (!$isNew): ?>
            <input type="hidden" name="appid" value="<?php echo (int)$app['appid']; ?>">
        <?php endif; ?>

        <div class="flex border-b border-base-300 mb-4 gap-1">
            <button type="button" class="app-tab-btn px-4 py-2 text-sm font-medium rounded-t border border-b-0 border-base-300 bg-base-100 text-primary" data-tab="app-tab-basic">Basic</button>
            <button type="button" class="app-tab-btn px-4 py-2 text-sm font-medium rounded-t border border-b-0 border-transparent text-base-content/80 hover:text-primary" data-tab="app-tab-org">Organisation</button>
            <button type="button" class="app-tab-btn px-4 py-2 text-sm font-medium rounded-t border border-b-0 border-transparent text-base-content/80 hover:text-primary" data-tab="app-tab-oauth">OAuth2 / API</button>
            <button type="button" class="app-tab-btn px-4 py-2 text-sm font-medium rounded-t border border-b-0 border-transparent text-base-content/80 hover:text-primary" data-tab="app-tab-legal">Legal</button>
        </div>

        <div id="app-tab-basic" class="app-tab-pane">
            <div class="<?php echo $card; ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2"><label class="<?php echo $lbl; ?>">Application Name <span class="text-error">*</span></label>
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
                            <span class="text-sm text-base-content/80">Listed publicly</span>
                        </label></div>
                    <div><label class="<?php echo $lbl; ?>" title="Confidential: runs on a server you control and keeps its client secret there. Public: a browser app, mobile app or desktop app &mdash; anything you ship to users, because every user then has its secret. A public client must have a registered redirect URI, or no one can sign in with it.">Client Type</label>
                        <label class="flex items-center gap-2 mt-1 cursor-pointer">
                            <input type="checkbox" name="is_confidential" value="1" class="w-4 h-4"
                                <?php echo ((int)($app['is_confidential'] ?? 1) === 1) ? 'checked' : ''; ?>>
                            <span class="text-sm text-base-content/80">Confidential — can hold a client secret</span>
                        </label>
                        <p class="text-xs text-base-content/60 mt-1">
                            Untick for a single-page app or a mobile binary: whatever secret
                            it ships with, every user of it has. A public client uses PKCE
                            and cannot use the client-credentials grant. A public client <strong>must</strong> have a registered redirect URI (OAuth2 tab), or no one can sign in with it. <a href="<?php echo \Pramnos\Auth\Application::CALLBACK_GUIDE_URL; ?>" target="_blank" rel="noopener">What the difference means &rarr;</a>
                        </p></div>
                    <div><label class="<?php echo $lbl; ?>">Trusted</label>
                        <input type="hidden" name="trusted" value="0">
                        <label class="flex items-center gap-2 mt-1 cursor-pointer">
                            <input type="checkbox" name="trusted" value="1" class="w-4 h-4"
                                <?php echo ((int)($app['trusted'] ?? 0) === 1) ? 'checked' : ''; ?>>
                            <span class="text-sm text-base-content/80">First-party — no consent screen</span>
                        </label>
                        <p class="text-xs text-base-content/60 mt-1">Skips the consent screen: users signing in to it are not asked to approve its scopes. For your own first-party applications only — never for one a third party runs.</p></div>
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
                    <div><label class="<?php echo $lbl; ?>" title="The exact addresses this application may receive a sign-in code at. Optional for a confidential client that keeps its secret; required for a public one, or one with no secret, because for those it is the only thing tying the code to the application.">OAuth2 Redirect URI(s) / Callback</label>
                        <textarea name="callback" class="<?php echo $inp; ?> font-mono" rows="3" placeholder="https://app.example.com/callback&#10;One URI per line, or comma-separated"><?php echo htmlspecialchars($app['callback'] ?? ''); ?></textarea>
                        <p class="text-xs text-base-content/60 mt-1">Allowed redirect URIs. Exact match. Required when the client is public or has no secret. <a href="<?php echo \Pramnos\Auth\Application::CALLBACK_GUIDE_URL; ?>" target="_blank" rel="noopener">What the difference means &rarr;</a></p></div>
                    <div><label class="<?php echo $lbl; ?>">Allowed Scopes</label>
                        <input type="text" name="scope" class="<?php echo $inp; ?>" value="<?php echo htmlspecialchars($app['scope'] ?? ''); ?>" placeholder="openid profile email">
                        <p class="text-xs text-base-content/60 mt-1">Space-separated OAuth2 scopes.</p></div>
                    <div><label class="<?php echo $lbl; ?>">Public Key (PEM)</label>
                        <textarea name="public_key" class="<?php echo $inp; ?> font-mono" rows="4" placeholder="-----BEGIN PUBLIC KEY-----"><?php echo htmlspecialchars($app['public_key'] ?? ''); ?></textarea>
                        <p class="text-xs text-base-content/60 mt-1">For <code>private_key_jwt</code> client auth (RFC 7523).</p></div>
                    <div><label class="<?php echo $lbl; ?>">JWKS URI</label>
                        <input type="url" name="jwks_uri" class="<?php echo $inp; ?> font-mono" value="<?php echo htmlspecialchars($app['jwks_uri'] ?? ''); ?>" placeholder="https://app.example.com/.well-known/jwks.json">
                        <p class="text-xs text-base-content/60 mt-1">Dynamic key rotation endpoint.</p></div>
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
            <button type="submit" class="btn btn-primary btn-sm">Save Application</button>
            <a href="<?php echo adminUrl('applications'); ?>" class="btn btn-outline btn-sm">Cancel</a>
            <?php if (!$isNew): ?>
                <a href="<?php echo adminUrl('applications' . '/tokens/' . ((int)$app['appid'])); ?>" class="btn btn-outline btn-primary btn-sm ml-auto">View Tokens</a>
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
                b.classList.toggle('text-primary', a); b.classList.toggle('bg-base-100', a); b.classList.toggle('border-base-300', a);
                b.classList.toggle('text-base-content/80', !a); b.classList.toggle('border-transparent', !a);
            });
            panes.forEach(function (p) { p.classList.toggle('hidden', p.id !== id); });
        }
        btns.forEach(function (b) { b.addEventListener('click', function () { activate(b.getAttribute('data-tab')); }); });
        activate('app-tab-basic');
    }
    document.addEventListener('DOMContentLoaded', initTabs);
})();
</script>
