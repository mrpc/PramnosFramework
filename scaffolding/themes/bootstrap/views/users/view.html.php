<?php
/**
 * User detail (read-only) view (Bootstrap theme).
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
    if ($t >= 90) return ['danger',  'Admin'];
    if ($t >= 80) return ['warning', 'Manager'];
    if ($t >= 50) return ['info',    'Editor'];
    if ($t >= 10) return ['primary', 'Member'];
    return ['secondary', 'Guest'];
};

[$typeBadge, $typeLabel] = $typeInfo((int) ($user['usertype'] ?? 0));
$isActive    = (bool) ($user['active']    ?? 1);
$isValidated = (bool) ($user['validated'] ?? 1);

$initials = strtoupper(substr(
    trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')) ?: ($user['username'] ?? '?'),
    0, 1
));
$fullName = trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''));
?>
<div class="container-fluid py-4">
    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="<?php echo sURL; ?>users" class="btn btn-sm btn-outline-secondary">&larr; Users</a>
        <h2 class="mb-0"><?php echo htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></h2>
        <?php if (!$isActive): ?>
            <span class="badge bg-danger">Inactive</span>
        <?php endif; ?>
        <?php if (!$isValidated): ?>
            <span class="badge bg-warning text-dark">Unvalidated</span>
        <?php endif; ?>
    </div>

    <div class="row g-4">
        <!-- Left: profile card + stats + actions -->
        <div class="col-xl-3 col-lg-4">

            <div class="card mb-3">
                <div class="card-body text-center py-4">
                    <div class="rounded-circle bg-secondary d-inline-flex align-items-center justify-content-center mb-3"
                         style="width:80px;height:80px;font-size:2rem;color:#fff">
                        <?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <h5 class="mb-1 fw-semibold">
                        <?php echo htmlspecialchars($fullName ?: ($user['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                    </h5>
                    <p class="text-muted small mb-2">@<?php echo htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                    <span class="badge bg-<?php echo $typeBadge; ?> me-1"><?php echo $typeLabel; ?> (<?php echo (int) ($user['usertype'] ?? 0); ?>)</span>
                    <span class="badge bg-<?php echo $isActive ? 'success' : 'danger'; ?>"><?php echo $isActive ? 'Active' : 'Inactive'; ?></span>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header fw-semibold small text-uppercase text-muted">Statistics</div>
                <div class="list-group list-group-flush small">
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="text-muted">Tokens</span>
                        <a href="<?php echo sURL; ?>users/tokens/<?php echo $uid; ?>" class="fw-semibold text-decoration-none">
                            <?php echo (int) ($usageStats['total_tokens'] ?? 0); ?>
                        </a>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="text-muted">Unique Apps</span>
                        <strong><?php echo (int) ($usageStats['unique_apps'] ?? 0); ?></strong>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="text-muted">Sessions</span>
                        <a href="<?php echo sURL; ?>users/sessions/<?php echo $uid; ?>" class="fw-semibold text-decoration-none">
                            <?php echo $sessionCount; ?>
                        </a>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="text-muted">Registered</span>
                        <span><?php echo ($user['regdate'] ?? 0) > 0 ? date('Y-m-d', (int) $user['regdate']) : '—'; ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="text-muted">Last Login</span>
                        <span><?php echo ($user['lastlogin'] ?? 0) > 0 ? date('Y-m-d H:i', (int) $user['lastlogin']) : '—'; ?></span>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header fw-semibold small text-uppercase text-muted">Actions</div>
                <div class="card-body d-grid gap-2">
                    <a href="<?php echo sURL; ?>users/edit/<?php echo $uid; ?>" class="btn btn-primary btn-sm">Edit User</a>
                    <?php if ($isActive): ?>
                        <a href="<?php echo sURL; ?>users/lock/<?php echo $uid; ?>" class="btn btn-outline-warning btn-sm"
                           data-confirm="Lock this account?">Lock Account</a>
                    <?php else: ?>
                        <a href="<?php echo sURL; ?>users/unlock/<?php echo $uid; ?>" class="btn btn-outline-success btn-sm">Unlock Account</a>
                    <?php endif; ?>
                    <a href="<?php echo sURL; ?>users/tokens/<?php echo $uid; ?>" class="btn btn-outline-secondary btn-sm">All Tokens</a>
                    <a href="<?php echo sURL; ?>users/sessions/<?php echo $uid; ?>" class="btn btn-outline-secondary btn-sm">Sessions</a>
                </div>
            </div>

        </div>

        <!-- Right: account details + recent tokens -->
        <div class="col-xl-9 col-lg-8">

            <div class="card mb-4">
                <div class="card-header fw-semibold">Account Details</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-muted small mb-1">User ID</div>
                            <code><?php echo $uid; ?></code>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-muted small mb-1">Username</div>
                            <div><?php echo htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-muted small mb-1">First Name</div>
                            <div><?php echo ($user['firstname'] ?? '') !== '' ? htmlspecialchars($user['firstname'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>'; ?></div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-muted small mb-1">Last Name</div>
                            <div><?php echo ($user['lastname'] ?? '') !== '' ? htmlspecialchars($user['lastname'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>'; ?></div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-muted small mb-1">Email</div>
                            <div><?php echo ($user['email'] ?? '') !== '' ? htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>'; ?></div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-muted small mb-1">Phone</div>
                            <div><?php echo ($user['phone'] ?? '') !== '' ? htmlspecialchars($user['phone'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>'; ?></div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-muted small mb-1">Mobile</div>
                            <div><?php echo ($user['mobile'] ?? '') !== '' ? htmlspecialchars($user['mobile'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>'; ?></div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-muted small mb-1">Language</div>
                            <div><?php echo ($user['language'] ?? '') !== '' ? htmlspecialchars($user['language'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">default</span>'; ?></div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-muted small mb-1">Timezone</div>
                            <div><?php echo ($user['timezone'] ?? '') !== '' ? htmlspecialchars($user['timezone'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>'; ?></div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-muted small mb-1">User Type</div>
                            <span class="badge bg-<?php echo $typeBadge; ?>"><?php echo $typeLabel; ?></span>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-muted small mb-1">Status</div>
                            <span class="badge bg-<?php echo $isActive ? 'success' : 'danger'; ?>"><?php echo $isActive ? 'Active' : 'Inactive'; ?></span>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-muted small mb-1">Validation</div>
                            <span class="badge bg-<?php echo $isValidated ? 'success' : 'warning'; ?> <?php echo $isValidated ? '' : 'text-dark'; ?>">
                                <?php echo $isValidated ? 'Validated' : 'Pending'; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($recentTokens)): ?>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Recent Tokens</span>
                    <a href="<?php echo sURL; ?>users/tokens/<?php echo $uid; ?>" class="btn btn-sm btn-outline-primary">All Tokens</a>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th><th>Type</th><th>Status</th>
                                <th>IP</th><th>Last Used</th><th>Expires</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentTokens as $tok):
                            $s = (int) ($tok['status'] ?? 0);
                            $sMap = [0 => ['secondary','Inactive'], 1 => ['success','Active'], 2 => ['dark','Deleted'], 3 => ['danger','Revoked']];
                            [$sBadge, $sLabel] = $sMap[$s] ?? ['secondary','Unknown'];
                            $exp = (int) ($tok['expires'] ?? 0);
                        ?>
                            <tr>
                                <td><code class="small"><?php echo (int) $tok['tokenid']; ?></code></td>
                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($tok['tokentype'] ?? 'auth', ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><span class="badge bg-<?php echo $sBadge; ?>"><?php echo $sLabel; ?></span></td>
                                <td class="small text-muted"><?php echo htmlspecialchars($tok['ipaddress'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="small"><?php echo ($tok['lastused'] ?? 0) > 0 ? date('Y-m-d H:i', (int) $tok['lastused']) : '—'; ?></td>
                                <td class="small"><?php echo $exp > 0 ? date('Y-m-d H:i', $exp) : 'Never'; ?></td>
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
