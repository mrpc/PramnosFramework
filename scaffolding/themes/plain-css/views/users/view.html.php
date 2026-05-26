<?php
/**
 * User detail (read-only) view (plain-CSS theme).
 *
 * Variables:
 *   $this->user         — array: userid, username, email, firstname, lastname,
 *                          usertype, active, validated, regdate, lastlogin,
 *                          phone, mobile, language, timezone
 *   $this->usageStats   — array: total_tokens, unique_apps, active_days, account_created
 *   $this->sessionCount — int, active session count from sessions table
 *   $this->recentTokens — array, up to 5 most recent token rows
 */
$user         = $this->user ?? [];
$usageStats   = $this->usageStats ?? [];
$sessionCount = (int) ($this->sessionCount ?? 0);
$recentTokens = $this->recentTokens ?? [];
$uid          = (int) ($user['userid'] ?? 0);

$typeInfo = function (int $t): array {
    if ($t >= 90) return ['#dc3545', 'Admin'];
    if ($t >= 80) return ['#856404', 'Manager'];
    if ($t >= 50) return ['#0d6efd', 'Editor'];
    if ($t >= 10) return ['#6610f2', 'Member'];
    return ['#6c757d', 'Guest'];
};

[$typeColor, $typeLabel] = $typeInfo((int) ($user['usertype'] ?? 0));
$isActive    = (bool) ($user['active']    ?? 1);
$isValidated = (bool) ($user['validated'] ?? 1);

$initials = strtoupper(substr(
    trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')) ?: ($user['username'] ?? '?'),
    0, 1
));
$fullName = trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''));
?>
<div class="page-section">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
        <a href="<?php echo sURL; ?>users" class="btn btn-outline-secondary">&larr; Users</a>
        <h2 style="margin:0"><?php echo htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></h2>
        <?php if (!$isActive): ?>
            <span style="background:#f8d7da;color:#842029;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600">Inactive</span>
        <?php endif; ?>
        <?php if (!$isValidated): ?>
            <span style="background:#fff3cd;color:#664d03;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600">Unvalidated</span>
        <?php endif; ?>
    </div>

    <div style="display:grid;grid-template-columns:280px 1fr;gap:20px;align-items:start">
        <!-- Left: profile card + stats + actions -->
        <div>

            <div class="card" style="text-align:center;padding:24px;margin-bottom:16px">
                <div style="width:72px;height:72px;border-radius:50%;background:#6c757d;color:#fff;font-size:28px;font-weight:700;display:flex;align-items:center;justify-content:center;margin:0 auto 12px">
                    <?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <h3 style="margin:0 0 4px;font-size:16px">
                    <?php echo htmlspecialchars($fullName ?: ($user['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                </h3>
                <div style="color:#888;font-size:13px;margin-bottom:8px">@<?php echo htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                <span style="background:<?php echo $typeColor; ?>;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;margin-right:4px">
                    <?php echo $typeLabel; ?> (<?php echo (int) ($user['usertype'] ?? 0); ?>)
                </span>
                <span style="background:<?php echo $isActive ? '#198754' : '#dc3545'; ?>;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600">
                    <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                </span>
            </div>

            <div class="card" style="margin-bottom:16px">
                <div style="padding:8px 14px;background:#f5f5f5;font-size:11px;font-weight:700;text-transform:uppercase;color:#666;letter-spacing:.05em">Statistics</div>
                <?php $row = function(string $label, string $val) { ?>
                    <div style="display:flex;justify-content:space-between;padding:8px 14px;border-top:1px solid #f0f0f0;font-size:13px">
                        <span style="color:#666"><?php echo $label; ?></span>
                        <span><?php echo $val; ?></span>
                    </div>
                <?php }; ?>
                <?php $row('Tokens', '<a href="' . sURL . 'users/tokens/' . $uid . '" style="font-weight:600">' . (int)($usageStats['total_tokens'] ?? 0) . '</a>'); ?>
                <?php $row('Unique Apps', '<strong>' . (int)($usageStats['unique_apps'] ?? 0) . '</strong>'); ?>
                <?php $row('Sessions', '<a href="' . sURL . 'users/sessions/' . $uid . '" style="font-weight:600">' . $sessionCount . '</a>'); ?>
                <?php $row('Registered', ($user['regdate'] ?? 0) > 0 ? date('Y-m-d', (int)$user['regdate']) : '—'); ?>
                <?php $row('Last Login', ($user['lastlogin'] ?? 0) > 0 ? date('Y-m-d H:i', (int)$user['lastlogin']) : '—'); ?>
            </div>

            <div class="card">
                <div style="padding:8px 14px;background:#f5f5f5;font-size:11px;font-weight:700;text-transform:uppercase;color:#666;letter-spacing:.05em">Actions</div>
                <div style="padding:12px;display:flex;flex-direction:column;gap:8px">
                    <a href="<?php echo sURL; ?>users/edit/<?php echo $uid; ?>" class="btn btn-primary" style="text-align:center">Edit User</a>
                    <?php if ($isActive): ?>
                        <a href="<?php echo sURL; ?>users/lock/<?php echo $uid; ?>" class="btn btn-outline-warning" style="text-align:center"
                           data-confirm="Lock this account?">Lock Account</a>
                    <?php else: ?>
                        <a href="<?php echo sURL; ?>users/unlock/<?php echo $uid; ?>" class="btn btn-outline-success" style="text-align:center">Unlock Account</a>
                    <?php endif; ?>
                    <a href="<?php echo sURL; ?>users/tokens/<?php echo $uid; ?>" class="btn btn-outline-secondary" style="text-align:center">All Tokens</a>
                    <a href="<?php echo sURL; ?>users/sessions/<?php echo $uid; ?>" class="btn btn-outline-secondary" style="text-align:center">Sessions</a>
                </div>
            </div>

        </div>

        <!-- Right: details + recent tokens -->
        <div>

            <div class="card" style="margin-bottom:16px">
                <div style="padding:10px 16px;border-bottom:1px solid #eee;font-weight:600">Account Details</div>
                <div style="padding:16px;display:grid;grid-template-columns:repeat(3,1fr);gap:16px;font-size:13px">
                    <?php $field = function(string $label, string $val) { ?>
                        <div>
                            <div style="font-size:11px;color:#888;margin-bottom:2px"><?php echo $label; ?></div>
                            <div><?php echo $val; ?></div>
                        </div>
                    <?php }; ?>
                    <?php $field('User ID', '<code style="font-size:12px">' . $uid . '</code>'); ?>
                    <?php $field('Username', htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8')); ?>
                    <?php $field('First Name', ($user['firstname'] ?? '') !== '' ? htmlspecialchars($user['firstname'], ENT_QUOTES, 'UTF-8') : '<span style="color:#ccc">—</span>'); ?>
                    <?php $field('Last Name', ($user['lastname'] ?? '') !== '' ? htmlspecialchars($user['lastname'], ENT_QUOTES, 'UTF-8') : '<span style="color:#ccc">—</span>'); ?>
                    <?php $field('Email', ($user['email'] ?? '') !== '' ? htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') : '<span style="color:#ccc">—</span>'); ?>
                    <?php $field('Phone', ($user['phone'] ?? '') !== '' ? htmlspecialchars($user['phone'], ENT_QUOTES, 'UTF-8') : '<span style="color:#ccc">—</span>'); ?>
                    <?php $field('Mobile', ($user['mobile'] ?? '') !== '' ? htmlspecialchars($user['mobile'], ENT_QUOTES, 'UTF-8') : '<span style="color:#ccc">—</span>'); ?>
                    <?php $field('Language', ($user['language'] ?? '') !== '' ? htmlspecialchars($user['language'], ENT_QUOTES, 'UTF-8') : '<span style="color:#ccc">default</span>'); ?>
                    <?php $field('Timezone', ($user['timezone'] ?? '') !== '' ? htmlspecialchars($user['timezone'], ENT_QUOTES, 'UTF-8') : '<span style="color:#ccc">—</span>'); ?>
                    <div>
                        <div style="font-size:11px;color:#888;margin-bottom:2px">User Type</div>
                        <span style="background:<?php echo $typeColor; ?>;color:#fff;padding:2px 7px;border-radius:3px;font-size:11px;font-weight:600"><?php echo $typeLabel; ?></span>
                    </div>
                    <div>
                        <div style="font-size:11px;color:#888;margin-bottom:2px">Status</div>
                        <span style="background:<?php echo $isActive ? '#198754' : '#dc3545'; ?>;color:#fff;padding:2px 7px;border-radius:3px;font-size:11px;font-weight:600">
                            <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>
                    <div>
                        <div style="font-size:11px;color:#888;margin-bottom:2px">Validation</div>
                        <span style="background:<?php echo $isValidated ? '#198754' : '#856404'; ?>;color:<?php echo $isValidated ? '#fff' : '#fff'; ?>;padding:2px 7px;border-radius:3px;font-size:11px;font-weight:600">
                            <?php echo $isValidated ? 'Validated' : 'Pending'; ?>
                        </span>
                    </div>
                </div>
            </div>

            <?php if (!empty($recentTokens)): ?>
            <div class="card">
                <div style="padding:10px 16px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center">
                    <span style="font-weight:600">Recent Tokens</span>
                    <a href="<?php echo sURL; ?>users/tokens/<?php echo $uid; ?>" class="btn btn-sm btn-outline-primary">All Tokens</a>
                </div>
                <div style="overflow-x:auto">
                    <table style="width:100%;border-collapse:collapse;font-size:13px">
                        <thead style="background:#f5f5f5">
                            <tr>
                                <th style="padding:7px 12px;text-align:left">ID</th>
                                <th style="padding:7px 12px;text-align:left">Type</th>
                                <th style="padding:7px 12px;text-align:left">Status</th>
                                <th style="padding:7px 12px;text-align:left">IP</th>
                                <th style="padding:7px 12px;text-align:left">Last Used</th>
                                <th style="padding:7px 12px;text-align:left">Expires</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentTokens as $tok):
                            $s = (int) ($tok['status'] ?? 0);
                            $sColors = [0 => '#6c757d', 1 => '#198754', 2 => '#343a40', 3 => '#dc3545'];
                            $sLabels = [0 => 'Inactive', 1 => 'Active', 2 => 'Deleted', 3 => 'Revoked'];
                            $exp = (int) ($tok['expires'] ?? 0);
                        ?>
                            <tr style="border-top:1px solid #f0f0f0">
                                <td style="padding:6px 12px;font-family:monospace;color:#666"><?php echo (int) $tok['tokenid']; ?></td>
                                <td style="padding:6px 12px"><span style="background:#e9ecef;padding:2px 6px;border-radius:3px;font-size:11px"><?php echo htmlspecialchars($tok['tokentype'] ?? 'auth', ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td style="padding:6px 12px"><span style="color:<?php echo $sColors[$s] ?? '#666'; ?>;font-weight:600;font-size:12px"><?php echo $sLabels[$s] ?? '?'; ?></span></td>
                                <td style="padding:6px 12px;color:#888;font-size:12px"><?php echo htmlspecialchars($tok['ipaddress'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="padding:6px 12px;font-size:12px"><?php echo ($tok['lastused'] ?? 0) > 0 ? date('Y-m-d H:i', (int) $tok['lastused']) : '—'; ?></td>
                                <td style="padding:6px 12px;font-size:12px"><?php echo $exp > 0 ? date('Y-m-d H:i', $exp) : 'Never'; ?></td>
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
