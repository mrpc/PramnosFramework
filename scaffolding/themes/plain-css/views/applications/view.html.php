<?php
/**
 * Application detail (read-only) view (plain-CSS theme).
 *
 * Variables:
 *   $this->app        — application row array
 *   $this->tokenStats — array: total, active, revoked
 *   $this->lastUsers  — array of recent token rows with userid, username, lastused, ipaddress, scope
 */
$app        = $this->app ?? [];
$tokenStats = $this->tokenStats ?? ['total' => 0, 'active' => 0, 'revoked' => 0];
$lastUsers  = $this->lastUsers ?? [];
$appId      = (int) ($app['appid'] ?? 0);
$isActive   = (bool) ($app['status'] ?? 1);

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
<div class="page-section">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
        <a href="<?php echo sURL; ?>applications" class="btn btn-outline-secondary">&larr; Applications</a>
        <h2 style="margin:0"><?php echo htmlspecialchars($app['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></h2>
        <span style="background:<?php echo $isActive ? '#198754' : '#dc3545'; ?>;color:#fff;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600">
            <?php echo $isActive ? 'Active' : 'Disabled'; ?>
        </span>
    </div>

    <div style="display:grid;grid-template-columns:300px 1fr;gap:20px;align-items:start">
        <!-- Left: credentials + stats + actions -->
        <div>

            <div class="card" style="margin-bottom:16px">
                <div style="padding:8px 14px;background:#f5f5f5;font-size:11px;font-weight:700;text-transform:uppercase;color:#666;letter-spacing:.05em">Credentials</div>
                <div style="padding:14px">
                    <div style="margin-bottom:12px">
                        <div style="font-size:11px;color:#888;margin-bottom:4px">Client ID (API Key)</div>
                        <div style="display:flex;gap:6px">
                            <input type="text" readonly style="flex:1;font-family:monospace;font-size:11px;border:1px solid #ddd;border-radius:3px;padding:5px 8px;background:#f9f9f9"
                                   value="<?php echo htmlspecialchars($app['apikey'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <button style="border:1px solid #ddd;background:#f9f9f9;border-radius:3px;padding:5px 8px;cursor:pointer"
                                    onclick="navigator.clipboard.writeText(this.previousElementSibling.value)" title="Copy">&#128203;</button>
                        </div>
                    </div>
                    <div>
                        <div style="font-size:11px;color:#888;margin-bottom:4px">Client Secret</div>
                        <div style="display:flex;gap:6px">
                            <input type="password" readonly id="pcAppSecret<?php echo $appId; ?>"
                                   style="flex:1;font-family:monospace;font-size:11px;border:1px solid #ddd;border-radius:3px;padding:5px 8px;background:#f9f9f9"
                                   value="<?php echo htmlspecialchars($app['apisecret'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <button style="border:1px solid #ddd;background:#f9f9f9;border-radius:3px;padding:5px 8px;cursor:pointer"
                                    onclick="var f=document.getElementById('pcAppSecret<?php echo $appId; ?>');f.type=f.type==='password'?'text':'password'"
                                    title="Toggle">&#128065;</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card" style="margin-bottom:16px">
                <div style="padding:8px 14px;background:#f5f5f5;font-size:11px;font-weight:700;text-transform:uppercase;color:#666;letter-spacing:.05em">Token Statistics</div>
                <?php $row = function(string $label, string $val) { ?>
                    <div style="display:flex;justify-content:space-between;padding:8px 14px;border-top:1px solid #f0f0f0;font-size:13px">
                        <span style="color:#666"><?php echo $label; ?></span>
                        <span><?php echo $val; ?></span>
                    </div>
                <?php }; ?>
                <?php $row('Total', '<a href="' . sURL . 'applications/tokens/' . $appId . '" style="font-weight:600">' . (int)($tokenStats['total'] ?? 0) . '</a>'); ?>
                <?php $row('Active', '<span style="color:#198754;font-weight:600">' . (int)($tokenStats['active'] ?? 0) . '</span>'); ?>
                <?php $row('Revoked', '<span style="color:#dc3545;font-weight:600">' . (int)($tokenStats['revoked'] ?? 0) . '</span>'); ?>
            </div>

            <div class="card">
                <div style="padding:8px 14px;background:#f5f5f5;font-size:11px;font-weight:700;text-transform:uppercase;color:#666;letter-spacing:.05em">Actions</div>
                <div style="padding:12px;display:flex;flex-direction:column;gap:8px">
                    <a href="<?php echo sURL; ?>applications/edit/<?php echo $appId; ?>" class="btn btn-primary" style="text-align:center">Edit Application</a>
                    <a href="<?php echo sURL; ?>applications/tokens/<?php echo $appId; ?>" class="btn btn-outline-secondary" style="text-align:center">View Tokens</a>
                    <a href="<?php echo sURL; ?>applications/rotate/<?php echo $appId; ?>" class="btn btn-outline-warning" style="text-align:center"
                       onclick="return confirm('Rotate the client secret? Existing tokens remain valid.')">Rotate Secret</a>
                    <a href="<?php echo sURL; ?>applications/delete/<?php echo $appId; ?>" class="btn btn-outline-danger" style="text-align:center"
                       onclick="return confirm('Disable this application and revoke all active tokens?')">Disable App</a>
                </div>
            </div>

        </div>

        <!-- Right: details + last users -->
        <div>

            <div class="card" style="margin-bottom:16px">
                <div style="padding:10px 16px;border-bottom:1px solid #eee;font-weight:600">Application Details</div>
                <div style="padding:16px;display:grid;grid-template-columns:repeat(3,1fr);gap:14px;font-size:13px">
                    <?php $field = function(string $label, string $val) { ?>
                        <div>
                            <div style="font-size:11px;color:#888;margin-bottom:2px"><?php echo $label; ?></div>
                            <div><?php echo $val; ?></div>
                        </div>
                    <?php }; ?>
                    <?php $field('App ID', '<code style="font-size:12px">' . $appId . '</code>'); ?>
                    <?php $field('Type', $appTypeLabel((int)($app['apptype'] ?? 0))); ?>
                    <?php $field('Access Type', $accessTypeLabel((int)($app['accesstype'] ?? 0))); ?>
                    <?php $field('API Version', htmlspecialchars($app['apiversion'] ?? 'v1', ENT_QUOTES, 'UTF-8')); ?>
                    <?php $field('App Version', ($app['appversion'] ?? '') !== '' ? htmlspecialchars($app['appversion'], ENT_QUOTES, 'UTF-8') : '<span style="color:#ccc">—</span>'); ?>
                    <div>
                        <div style="font-size:11px;color:#888;margin-bottom:2px">Public</div>
                        <span style="background:<?php echo (int)($app['public'] ?? 0) ? '#0dcaf0' : '#6c757d'; ?>;color:#fff;padding:2px 7px;border-radius:3px;font-size:11px;font-weight:600">
                            <?php echo (int)($app['public'] ?? 0) ? 'Yes' : 'No'; ?>
                        </span>
                    </div>
                    <?php $field('Added', ($app['added'] ?? 0) > 0 ? date('Y-m-d H:i', (int)$app['added']) : '—'); ?>
                    <?php if (!empty($app['description'])): ?>
                    <div style="grid-column:1/-1">
                        <div style="font-size:11px;color:#888;margin-bottom:2px">Description</div>
                        <div><?php echo htmlspecialchars($app['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($app['callback'])): ?>
                    <div style="grid-column:1/-1">
                        <div style="font-size:11px;color:#888;margin-bottom:2px">Callback URL</div>
                        <div style="font-family:monospace;font-size:12px;word-break:break-all"><?php echo htmlspecialchars($app['callback'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($app['scope'])): ?>
                    <div style="grid-column:1/-1">
                        <div style="font-size:11px;color:#888;margin-bottom:2px">Scope</div>
                        <div style="font-family:monospace;font-size:12px"><?php echo htmlspecialchars($app['scope'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($app['organization'])): ?>
                    <?php $field('Organization', htmlspecialchars($app['organization'], ENT_QUOTES, 'UTF-8')); ?>
                    <?php endif; ?>
                    <?php if (!empty($app['public_key'])): ?>
                    <div style="grid-column:1/-1">
                        <div style="font-size:11px;color:#888;margin-bottom:2px">Public Key</div>
                        <pre style="background:#f5f5f5;border-radius:4px;padding:8px;font-size:11px;max-height:120px;overflow-y:auto;margin:0"><?php echo htmlspecialchars($app['public_key'], ENT_QUOTES, 'UTF-8'); ?></pre>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($lastUsers)): ?>
            <div class="card">
                <div style="padding:10px 16px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center">
                    <span style="font-weight:600">Recent Users</span>
                    <a href="<?php echo sURL; ?>applications/tokens/<?php echo $appId; ?>" class="btn btn-sm btn-outline-primary">All Tokens</a>
                </div>
                <div style="overflow-x:auto">
                    <table style="width:100%;border-collapse:collapse;font-size:13px">
                        <thead style="background:#f5f5f5">
                            <tr>
                                <th style="padding:7px 12px;text-align:left">User</th>
                                <th style="padding:7px 12px;text-align:left">Scope</th>
                                <th style="padding:7px 12px;text-align:left">IP</th>
                                <th style="padding:7px 12px;text-align:left">Last Used</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($lastUsers as $u): ?>
                            <tr style="border-top:1px solid #f0f0f0">
                                <td style="padding:6px 12px">
                                    <a href="<?php echo sURL; ?>users/view/<?php echo (int)($u['userid'] ?? 0); ?>">
                                        <?php echo htmlspecialchars($u['username'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </td>
                                <td style="padding:6px 12px;color:#888;font-size:12px"><?php echo htmlspecialchars($u['scope'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="padding:6px 12px;color:#888;font-size:12px"><?php echo htmlspecialchars($u['ipaddress'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="padding:6px 12px;font-size:12px"><?php echo ($u['lastused'] ?? 0) > 0 ? date('Y-m-d H:i', (int)$u['lastused']) : '—'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>
