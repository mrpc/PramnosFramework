<?php
/**
 * OAuth2 Application create/edit form (Bootstrap theme).
 *
 * Variables:
 *   $this->application — app row array (null when creating)
 *   $this->message     — success flash (string)
 *   $this->error       — error flash (string)
 */
$app   = $this->application ?? [];
$isNew = empty($app['appid']);

$apptypes   = [0 => 'Web Application', 1 => 'Mobile App', 2 => 'Service / Daemon', 3 => 'Desktop App', 4 => 'IoT Device', 5 => 'Other'];
$accessTypes = [0 => 'REST (API Key)', 1 => 'OAuth2', 2 => 'Legacy API Only'];
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0"><?php echo $isNew ? 'New Application' : 'Edit Application'; ?></h2>
        <a href="<?php echo sURL; ?>applications" class="btn btn-outline-secondary btn-sm">Back to list</a>
    </div>

    <?php if (!empty($this->message)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo htmlspecialchars($this->message === 'secret_rotated' ? 'Client secret rotated successfully.' : 'Application saved.'); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($this->error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($this->error === 'name_required' ? 'Application name is required.' : $this->error); ?></div>
    <?php endif; ?>

    <?php if (!$isNew && !empty($app['apikey'])): ?>
        <div class="alert alert-info d-flex align-items-center gap-3 small">
            <div>
                <strong>Client ID (API Key):</strong>
                <code class="ms-1"><?php echo htmlspecialchars($app['apikey'] ?? ''); ?></code>
            </div>
            <div class="ms-auto">
                <a href="<?php echo sURL; ?>applications/rotate/<?php echo (int)$app['appid']; ?>"
                   class="btn btn-sm btn-outline-warning"
                   onclick="return confirm('Rotate the client secret? All new token requests will use the new secret.')">
                   Rotate Secret
                </a>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo sURL; ?>applications/save">
        <?php if (!$isNew): ?>
            <input type="hidden" name="appid" value="<?php echo (int)$app['appid']; ?>">
        <?php endif; ?>

        <ul class="nav nav-tabs mb-3" id="appEditTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#app-tab-basic" type="button" role="tab">Basic</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#app-tab-org" type="button" role="tab">Organisation</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#app-tab-oauth" type="button" role="tab">OAuth2 / API</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#app-tab-legal" type="button" role="tab">Legal</button>
            </li>
        </ul>

        <div class="tab-content">

            <!-- Basic -->
            <div class="tab-pane fade show active" id="app-tab-basic" role="tabpanel">
                <div class="card mb-3"><div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Application Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required value="<?php echo htmlspecialchars($app['name'] ?? ''); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea name="description" class="form-control" rows="2"><?php echo htmlspecialchars($app['description'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Application Type</label>
                            <select name="apptype" class="form-select">
                                <?php foreach ($apptypes as $v => $label): ?>
                                    <option value="<?php echo $v; ?>"<?php echo ((int)($app['apptype'] ?? 0) === $v) ? ' selected' : ''; ?>><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Access Type</label>
                            <select name="accesstype" class="form-select">
                                <?php foreach ($accessTypes as $v => $label): ?>
                                    <option value="<?php echo $v; ?>"<?php echo ((int)($app['accesstype'] ?? 0) === $v) ? ' selected' : ''; ?>><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">API Version</label>
                            <input type="text" name="apiversion" class="form-control" maxlength="20" value="<?php echo htmlspecialchars($app['apiversion'] ?? 'v1'); ?>" placeholder="v1">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">App Version</label>
                            <input type="text" name="appversion" class="form-control" maxlength="50" value="<?php echo htmlspecialchars($app['appversion'] ?? ''); ?>" placeholder="1.0.0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="status" class="form-select">
                                <option value="1"<?php echo ((int)($app['status'] ?? 1) === 1) ? ' selected' : ''; ?>>Active</option>
                                <option value="0"<?php echo ((int)($app['status'] ?? 1) === 0) ? ' selected' : ''; ?>>Disabled</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold d-block">Public Directory</label>
                            <div class="form-check form-switch mt-1">
                                <input class="form-check-input" type="checkbox" name="public" value="1" id="chk_public"
                                    <?php echo ((int)($app['public'] ?? 0) === 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="chk_public">Listed publicly</label>
                            </div>
                        </div>
                    </div>
                </div></div>
            </div>

            <!-- Organisation -->
            <div class="tab-pane fade" id="app-tab-org" role="tabpanel">
                <div class="card mb-3"><div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Organisation Name</label>
                            <input type="text" name="organization" class="form-control" value="<?php echo htmlspecialchars($app['organization'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Organisation URL</label>
                            <input type="url" name="organizationurl" class="form-control" value="<?php echo htmlspecialchars($app['organizationurl'] ?? ''); ?>" placeholder="https://example.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Application Homepage URL</label>
                            <input type="url" name="url" class="form-control" value="<?php echo htmlspecialchars($app['url'] ?? ''); ?>" placeholder="https://app.example.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Support Email</label>
                            <input type="email" name="supportemail" class="form-control" value="<?php echo htmlspecialchars($app['supportemail'] ?? ''); ?>" placeholder="support@example.com">
                        </div>
                    </div>
                </div></div>
            </div>

            <!-- OAuth2 / API -->
            <div class="tab-pane fade" id="app-tab-oauth" role="tabpanel">
                <div class="card mb-3"><div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">OAuth2 Redirect URI(s) / Callback</label>
                            <textarea name="callback" class="form-control font-monospace" rows="2" placeholder="https://app.example.com/callback&#10;One URI per line or comma-separated"><?php echo htmlspecialchars($app['callback'] ?? ''); ?></textarea>
                            <div class="form-text">Allowed redirect URIs for authorization code and implicit flows.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Allowed Scopes</label>
                            <input type="text" name="scope" class="form-control" value="<?php echo htmlspecialchars($app['scope'] ?? ''); ?>" placeholder="openid profile email">
                            <div class="form-text">Space-separated list of OAuth2 scopes this client may request.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Public Key (PEM — for JWT client assertion)</label>
                            <textarea name="public_key" class="form-control font-monospace" rows="4" placeholder="-----BEGIN PUBLIC KEY-----"><?php echo htmlspecialchars($app['public_key'] ?? ''); ?></textarea>
                            <div class="form-text">RSA/EC public key for <code>private_key_jwt</code> client authentication (RFC 7523).</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">JWKS URI (dynamic key rotation)</label>
                            <input type="url" name="jwks_uri" class="form-control font-monospace" value="<?php echo htmlspecialchars($app['jwks_uri'] ?? ''); ?>" placeholder="https://app.example.com/.well-known/jwks.json">
                            <div class="form-text">If set, public keys are fetched dynamically from this endpoint.</div>
                        </div>
                    </div>
                </div></div>
            </div>

            <!-- Legal -->
            <div class="tab-pane fade" id="app-tab-legal" role="tabpanel">
                <div class="card mb-3"><div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Terms of Service URL</label>
                            <input type="url" name="termsurl" class="form-control" value="<?php echo htmlspecialchars($app['termsurl'] ?? ''); ?>" placeholder="https://example.com/terms">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Privacy Policy URL</label>
                            <input type="url" name="privacyurl" class="form-control" value="<?php echo htmlspecialchars($app['privacyurl'] ?? ''); ?>" placeholder="https://example.com/privacy">
                        </div>
                    </div>
                </div></div>
            </div>

        </div><!-- /.tab-content -->

        <div class="mt-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary">Save Application</button>
            <a href="<?php echo sURL; ?>applications" class="btn btn-outline-secondary">Cancel</a>
            <?php if (!$isNew): ?>
                <a href="<?php echo sURL; ?>applications/tokens/<?php echo (int)$app['appid']; ?>" class="btn btn-outline-info ms-auto">View Tokens</a>
            <?php endif; ?>
        </div>
    </form>
</div>
