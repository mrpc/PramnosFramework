<?php
/**
 * Application detail (read-only) view (Bootstrap theme).
 *
 * Variables:
 *   $this->app        — application row array (all fields from `applications` table)
 *   $this->tokenStats — array: total, active, revoked
 *   $this->lastUsers  — array of recent token rows with userid, username, lastused, ipaddress, scope
 */
$app        = $this->app ?? [];
$tokenStats = $this->tokenStats ?? ['total' => 0, 'active' => 0, 'revoked' => 0];
$lastUsers  = $this->lastUsers ?? [];
$appId      = (int) ($app['appid'] ?? 0);

$statusBadge = (int) ($app['status'] ?? 1) ? 'success' : 'danger';
$statusLabel = (int) ($app['status'] ?? 1) ? 'Active'  : 'Disabled';

$appTypeLabel = function (int $t): string {
    return match($t) {
        1 => 'Web', 2 => 'Mobile', 3 => 'Desktop', 4 => 'Service', 5 => 'IoT', default => 'General'
    };
};

$accessTypeLabel = function (int $t): string {
    return match($t) {
        1 => 'User Data', 2 => 'Read-Only', default => 'Full Access'
    };
};
?>
<div class="container-fluid py-4">
    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="<?php echo adminUrl('applications'); ?>" class="btn btn-sm btn-outline-secondary">&larr; Applications</a>
        <h2 class="mb-0"><?php echo htmlspecialchars($app['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></h2>
        <span class="badge bg-<?php echo $statusBadge; ?>"><?php echo $statusLabel; ?></span>
    </div>

    <div class="row g-4">
        <!-- Left: key info + actions -->
        <div class="col-xl-4 col-lg-5">

            <div class="card mb-3">
                <div class="card-header fw-semibold small text-uppercase text-muted">Credentials</div>
                <div class="card-body">
                    <label class="form-label text-muted small">Client ID (API Key)</label>
                    <div class="input-group input-group-sm mb-3">
                        <input type="text" class="form-control font-monospace" readonly
                               value="<?php echo htmlspecialchars($app['apikey'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        <button class="btn btn-outline-secondary" type="button"
                                data-copy-prev
                                title="Copy">&#128203;</button>
                    </div>
                    <label class="form-label text-muted small">Client Secret</label>
                    <div class="input-group input-group-sm">
                        <input type="password" class="form-control font-monospace" readonly
                               value="<?php echo htmlspecialchars($app['apisecret'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                               id="appSecret<?php echo $appId; ?>">
                        <button class="btn btn-outline-secondary" type="button"
                                data-toggle-type="appSecret<?php echo $appId; ?>"
                                title="Toggle">&#128065;</button>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header fw-semibold small text-uppercase text-muted">Token Statistics</div>
                <div class="list-group list-group-flush small">
                    <div class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Total Tokens</span>
                        <a href="<?php echo adminUrl('applications' . '/tokens/' . ($appId)); ?>" class="fw-semibold text-decoration-none">
                            <?php echo (int) ($tokenStats['total'] ?? 0); ?>
                        </a>
                    </div>
                    <div class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Active</span>
                        <span class="badge bg-success"><?php echo (int) ($tokenStats['active'] ?? 0); ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Revoked</span>
                        <span class="badge bg-danger"><?php echo (int) ($tokenStats['revoked'] ?? 0); ?></span>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header fw-semibold small text-uppercase text-muted">Actions</div>
                <div class="card-body d-grid gap-2">
                    <a href="<?php echo adminUrl('applications' . '/edit/' . ($appId)); ?>" class="btn btn-primary btn-sm">Edit Application</a>
                    <a href="<?php echo adminUrl('applications' . '/tokens/' . ($appId)); ?>" class="btn btn-outline-secondary btn-sm">View Tokens</a>
                    <a href="<?php echo adminUrl('applications' . '/rotate/' . ($appId)); ?>"
                       class="btn btn-outline-warning btn-sm"
                       data-confirm="Rotate the client secret? Existing tokens remain valid.">Rotate Secret</a>
                    <a href="<?php echo adminUrl('applications' . '/delete/' . ($appId)); ?>"
                       class="btn btn-outline-danger btn-sm"
                       data-confirm="Disable this application and revoke all active tokens?">Disable App</a>
                </div>
            </div>

        </div>

        <!-- Right: details + last users -->
        <div class="col-xl-8 col-lg-7">

            <div class="card mb-4">
                <div class="card-header fw-semibold">Application Details</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-6 col-lg-4">
                            <div class="text-muted small mb-1">App ID</div>
                            <code><?php echo $appId; ?></code>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <div class="text-muted small mb-1">Type</div>
                            <div><?php echo $appTypeLabel((int) ($app['apptype'] ?? 0)); ?></div>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <div class="text-muted small mb-1">Access Type</div>
                            <div><?php echo $accessTypeLabel((int) ($app['accesstype'] ?? 0)); ?></div>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <div class="text-muted small mb-1">API Version</div>
                            <div><?php echo htmlspecialchars($app['apiversion'] ?? 'v1', ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <div class="text-muted small mb-1">App Version</div>
                            <div><?php echo ($app['appversion'] ?? '') !== '' ? htmlspecialchars($app['appversion'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>'; ?></div>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <div class="text-muted small mb-1">Public</div>
                            <span class="badge bg-<?php echo (int) ($app['public'] ?? 0) ? 'info' : 'secondary'; ?>">
                                <?php echo (int) ($app['public'] ?? 0) ? 'Yes' : 'No'; ?>
                            </span>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <div class="text-muted small mb-1">Added</div>
                            <div><?php echo ($app['added'] ?? 0) > 0 ? localDateTime( (int) $app['added']) : '—'; ?></div>
                        </div>
                        <?php if (!empty($app['description'])): ?>
                        <div class="col-12">
                            <div class="text-muted small mb-1">Description</div>
                            <div><?php echo htmlspecialchars($app['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($app['callback'])): ?>
                        <div class="col-12">
                            <div class="text-muted small mb-1">Callback URL</div>
                            <div class="font-monospace small text-break"><?php echo htmlspecialchars($app['callback'], ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($app['scope'])): ?>
                        <div class="col-12">
                            <div class="text-muted small mb-1">Scope</div>
                            <div class="font-monospace small"><?php echo htmlspecialchars($app['scope'], ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($app['organization'])): ?>
                        <div class="col-sm-6">
                            <div class="text-muted small mb-1">Organization</div>
                            <div><?php echo htmlspecialchars($app['organization'], ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($app['url'])): ?>
                        <div class="col-sm-6">
                            <div class="text-muted small mb-1">URL</div>
                            <div class="small text-break"><?php echo htmlspecialchars($app['url'], ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($app['public_key'])): ?>
                        <div class="col-12">
                            <div class="text-muted small mb-1">Public Key</div>
                            <pre class="bg-light rounded p-2 small mb-0" style="max-height:120px;overflow-y:auto"><?php echo htmlspecialchars($app['public_key'], ENT_QUOTES, 'UTF-8'); ?></pre>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($lastUsers)): ?>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Recent Users</span>
                    <a href="<?php echo adminUrl('applications' . '/tokens/' . ($appId)); ?>" class="btn btn-sm btn-outline-primary">All Tokens</a>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr><th>User</th><th>Scope</th><th>IP</th><th>Last Used</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($lastUsers as $u): ?>
                            <tr>
                                <td>
                                    <a href="<?php echo adminUrl('users/view/'); ?><?php echo (int) ($u['userid'] ?? 0); ?>" class="text-decoration-none">
                                        <?php echo htmlspecialchars($u['username'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </td>
                                <td class="small text-muted"><?php echo htmlspecialchars($u['scope'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="small text-muted"><?php echo htmlspecialchars($u['ipaddress'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="small"><?php echo ($u['lastused'] ?? 0) > 0 ? localDateTime( (int) $u['lastused']) : '—'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <?php
            /**
             * What this client has declared it understands.
             *
             * Pushed by the application itself through the capabilities endpoint,
             * usually from CI — so this is the application's own account of its
             * resources, scopes and ABAC condition keys, not something an
             * administrator typed. A permission grant names one of these, which is
             * why the list belongs next to the client rather than on a screen of
             * its own.
             *
             * A row an application has stopped declaring is shown struck through
             * rather than hidden: that is exactly what somebody is looking for when
             * a grant referring to it has stopped working.
             */
            $caps = $this->capabilities ?? [];
            ?>
            <div class="mt-4">
                <div class="d-flex justify-content-between align-items-baseline mb-2">
                    <h3 class="font-semibold">Declared capabilities</h3>
                    <?php if (!empty($caps['synced_at'])): ?>
                        <span class="small text-muted">
                            last pushed <?php echo htmlspecialchars((string) $caps['synced_at'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if (empty($caps['resources']) && empty($caps['conditions'])): ?>
                    <p class="text-muted">
                        This client has not pushed a capabilities manifest. Until it does,
                        the server knows no resource or scope names for it.
                    </p>
                <?php else: ?>
                    <?php if (!empty($caps['resources'])): ?>
                        <table class="table table-sm mb-4">
                            <thead class="table-light small text-uppercase">
                                <tr><th >Resource</th><th >Scopes</th></tr>
                            </thead>
                            <tbody >
                            <?php foreach ($caps['resources'] as $resource): ?>
                                <tr>
                                    <td class="align-top">
                                        <span class="font-mono<?php echo $resource['is_active'] ? '' : ' text-decoration-line-through text-muted'; ?>">
                                            <?php echo htmlspecialchars($resource['name'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                        <?php if (!empty($resource['description'])): ?>
                                            <div class="small text-muted"><?php echo htmlspecialchars((string) $resource['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="align-top">
                                        <?php if (empty($resource['scopes'])): ?>
                                            <span class="small text-muted">no scopes declared</span>
                                        <?php else: ?>
                                            <?php foreach ($resource['scopes'] as $scope): ?>
                                                <span class="badge me-1 mb-1 text-dark<?php echo $scope['is_active'] ? ' bg-light' : ' bg-light text-decoration-line-through text-muted'; ?>"
                                                      title="<?php echo htmlspecialchars((string) ($scope['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <?php echo htmlspecialchars($scope['name'], ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <?php if (!empty($caps['conditions'])): ?>
                        <h4 class="h6">Condition keys</h4>
                        <ul class="list-unstyled">
                        <?php foreach ($caps['conditions'] as $condition): ?>
                            <li class="<?php echo $condition['is_active'] ? '' : ' text-decoration-line-through text-muted'; ?>">
                                <span class="font-mono"><?php echo htmlspecialchars($condition['key'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="small text-muted">
                                    <?php echo htmlspecialchars($condition['value_type'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if (!empty($condition['description'])): ?>
                                        — <?php echo htmlspecialchars((string) $condition['description'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php endif; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>
