<?php
/**
 * User detail (read-only) view (Tailwind theme).
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
    if ($t >= 90) return ['bg-error/10 text-error',     'Admin'];
    if ($t >= 80) return ['bg-warning/10 text-warning', 'Manager'];
    if ($t >= 50) return ['bg-primary/10 text-primary',   'Editor'];
    if ($t >= 10) return ['bg-primary/10 text-primary','Member'];
    return ['bg-base-200 text-base-content/80', 'Guest'];
};

[$typeCls, $typeLabel] = $typeInfo((int) ($user['usertype'] ?? 0));
$isActive    = (bool) ($user['active']    ?? 1);
$isValidated = (bool) ($user['validated'] ?? 1);

$initials = strtoupper(substr(
    trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')) ?: ($user['username'] ?? '?'),
    0, 1
));
$fullName = trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''));
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'users_view'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div class="flex items-center gap-3 mb-6">
        <h2 class="text-2xl font-semibold"><?php echo htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></h2>
        <?php if (!$isActive): ?>
            <span class="inline-block px-2 py-0.5 rounded-sm text-xs font-medium bg-error/10 text-error">Inactive</span>
        <?php endif; ?>
        <?php if (!$isValidated): ?>
            <span class="inline-block px-2 py-0.5 rounded-sm text-xs font-medium bg-warning/10 text-warning">Unvalidated</span>
        <?php endif; ?>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        <!-- Left: profile card + stats + actions -->
        <div class="lg:col-span-1 space-y-4">

            <div class="card bg-base-100 border border-base-300 shadow-xs p-6 text-center">
                <div class="w-20 h-20 bg-neutral text-neutral-content rounded-full flex items-center justify-center mx-auto mb-3 text-3xl font-bold">
                    <?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <h3 class="font-semibold text-base-content">
                    <?php echo htmlspecialchars($fullName ?: ($user['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                </h3>
                <p class="text-sm text-base-content/60 mt-0.5">@<?php echo htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                <div class="mt-2 flex justify-center gap-1 flex-wrap">
                    <span class="inline-block px-2 py-0.5 rounded-sm text-xs font-medium <?php echo $typeCls; ?>">
                        <?php echo $typeLabel; ?> (<?php echo (int) ($user['usertype'] ?? 0); ?>)
                    </span>
                    <span class="inline-block px-2 py-0.5 rounded-sm text-xs font-medium <?php echo $isActive ? 'bg-success/10 text-success' : 'bg-error/10 text-error'; ?>">
                        <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                    </span>
                </div>
            </div>

            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                <div class="px-4 py-2 bg-base-200 text-xs font-semibold text-base-content/70 uppercase tracking-wide">Statistics</div>
                <div class="divide-y divide-base-300 text-sm">
                    <div class="px-4 py-2.5 flex justify-between">
                        <span class="text-base-content/70">Tokens</span>
                        <a href="<?php echo adminUrl('Tokens' . '/userid/' . ($uid)); ?>" class="font-semibold text-primary hover:underline">
                            <?php echo (int) ($usageStats['total_tokens'] ?? 0); ?>
                        </a>
                    </div>
                    <div class="px-4 py-2.5 flex justify-between">
                        <span class="text-base-content/70">Unique Apps</span>
                        <strong><?php echo (int) ($usageStats['unique_apps'] ?? 0); ?></strong>
                    </div>
                    <div class="px-4 py-2.5 flex justify-between">
                        <span class="text-base-content/70">Sessions</span>
                        <a href="<?php echo adminUrl('users' . '/sessions/' . ($uid)); ?>" class="font-semibold text-primary hover:underline">
                            <?php echo $sessionCount; ?>
                        </a>
                    </div>
                    <div class="px-4 py-2.5 flex justify-between">
                        <span class="text-base-content/70">Registered</span>
                        <span class="text-xs"><?php echo ($user['regdate'] ?? 0) > 0 ? date('Y-m-d', (int) $user['regdate']) : '—'; ?></span>
                    </div>
                    <div class="px-4 py-2.5 flex justify-between">
                        <span class="text-base-content/70">Last Login</span>
                        <span class="text-xs"><?php echo ($user['lastlogin'] ?? 0) > 0 ? date('Y-m-d H:i', (int) $user['lastlogin']) : '—'; ?></span>
                    </div>
                </div>
            </div>

            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                <div class="px-4 py-2 bg-base-200 text-xs font-semibold text-base-content/70 uppercase tracking-wide">Actions</div>
                <div class="p-4 grid gap-2">
                    <a href="<?php echo adminUrl('users' . '/edit/' . ($uid)); ?>"
                       class="btn btn-primary btn-sm block text-center">Edit User</a>
                    <?php if ($isActive): ?>
                        <a href="<?php echo adminUrl('users' . '/lock/' . ($uid)); ?>"
                           class="btn btn-outline btn-warning btn-sm block text-center"
                           data-confirm="Lock this account?">Lock Account</a>
                    <?php else: ?>
                        <a href="<?php echo adminUrl('users' . '/unlock/' . ($uid)); ?>"
                           class="btn btn-outline btn-success btn-sm block text-center">Unlock Account</a>
                    <?php endif; ?>
                    <a href="<?php echo adminUrl('Tokens' . '/userid/' . ($uid)); ?>"
                       class="btn btn-outline btn-sm block text-center">All Tokens</a>
                    <a href="<?php echo adminUrl('users' . '/sessions/' . ($uid)); ?>"
                       class="btn btn-outline btn-sm block text-center">Sessions</a>
                </div>
            </div>

        </div>

        <!-- Right: details + recent tokens -->
        <div class="lg:col-span-3 space-y-4">

            <div class="card bg-base-100 border border-base-300 shadow-xs">
                <div class="px-6 py-3 border-b border-base-300 font-semibold text-base-content">Account Details</div>
                <div class="p-6 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 text-sm">
                    <?php $field = function(string $label, string $value) { ?>
                        <div>
                            <div class="text-xs text-base-content/60 mb-0.5"><?php echo $label; ?></div>
                            <div class="text-base-content"><?php echo $value; ?></div>
                        </div>
                    <?php }; ?>
                    <?php $field('User ID', '<code class="text-xs">' . (int)($user['userid'] ?? 0) . '</code>'); ?>
                    <?php $field('Username', htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8')); ?>
                    <?php $field('First Name', ($user['firstname'] ?? '') !== '' ? htmlspecialchars($user['firstname'], ENT_QUOTES, 'UTF-8') : '<span class="text-base-content/50">—</span>'); ?>
                    <?php $field('Last Name', ($user['lastname'] ?? '') !== '' ? htmlspecialchars($user['lastname'], ENT_QUOTES, 'UTF-8') : '<span class="text-base-content/50">—</span>'); ?>
                    <?php $field('Email', ($user['email'] ?? '') !== '' ? htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') : '<span class="text-base-content/50">—</span>'); ?>
                    <?php $field('Phone', ($user['phone'] ?? '') !== '' ? htmlspecialchars($user['phone'], ENT_QUOTES, 'UTF-8') : '<span class="text-base-content/50">—</span>'); ?>
                    <?php $field('Mobile', ($user['mobile'] ?? '') !== '' ? htmlspecialchars($user['mobile'], ENT_QUOTES, 'UTF-8') : '<span class="text-base-content/50">—</span>'); ?>
                    <?php $field('Language', ($user['language'] ?? '') !== '' ? htmlspecialchars($user['language'], ENT_QUOTES, 'UTF-8') : '<span class="text-base-content/50">default</span>'); ?>
                    <?php $field('Timezone', ($user['timezone'] ?? '') !== '' ? htmlspecialchars($user['timezone'], ENT_QUOTES, 'UTF-8') : '<span class="text-base-content/50">—</span>'); ?>
                    <div>
                        <div class="text-xs text-base-content/60 mb-0.5">User Type</div>
                        <span class="inline-block px-2 py-0.5 rounded-sm text-xs font-medium <?php echo $typeCls; ?>"><?php echo $typeLabel; ?></span>
                    </div>
                    <div>
                        <div class="text-xs text-base-content/60 mb-0.5">Status</div>
                        <span class="inline-block px-2 py-0.5 rounded-sm text-xs font-medium <?php echo $isActive ? 'bg-success/10 text-success' : 'bg-error/10 text-error'; ?>">
                            <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>
                    <div>
                        <div class="text-xs text-base-content/60 mb-0.5">Validation</div>
                        <span class="inline-block px-2 py-0.5 rounded-sm text-xs font-medium <?php echo $isValidated ? 'bg-success/10 text-success' : 'bg-warning/10 text-warning'; ?>">
                            <?php echo $isValidated ? 'Validated' : 'Pending'; ?>
                        </span>
                    </div>
                </div>
            </div>

            <?php if (!empty($recentTokens)): ?>
            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                <div class="px-6 py-3 border-b border-base-300 flex justify-between items-center">
                    <span class="font-semibold text-base-content">Recent Tokens</span>
                    <a href="<?php echo adminUrl('Tokens' . '/userid/' . ($uid)); ?>"
                       class="text-sm text-primary hover:underline">All Tokens</a>
                </div>
                <table class="table table-sm text-sm">
                    <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                        <tr>
                            <th class="px-4 py-2 text-left">ID</th>
                            <th class="px-4 py-2 text-left">Type</th>
                            <th class="px-4 py-2 text-left">Status</th>
                            <th class="px-4 py-2 text-left">IP</th>
                            <th class="px-4 py-2 text-left">Last Used</th>
                            <th class="px-4 py-2 text-left">Expires</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-base-300">
                    <?php foreach ($recentTokens as $tok):
                        $s    = (int) ($tok['status'] ?? 0);
                        $sMap = [0 => ['bg-base-200 text-base-content/80','Inactive'], 1 => ['bg-success/10 text-success','Active'], 2 => ['bg-neutral text-white','Deleted'], 3 => ['bg-error/10 text-error','Revoked']];
                        [$sCls, $sLabel] = $sMap[$s] ?? ['bg-base-200 text-base-content/80','Unknown'];
                        $exp = (int) ($tok['expires'] ?? 0);
                        $tokActionsUrl = sURL . 'TokenActions?token_id=' . (int) $tok['tokenid'] . '&from=user&uid=' . $uid;
                    ?>
                        <tr class="hover:bg-base-200 cursor-pointer" data-href="<?php echo $tokActionsUrl; ?>" title="View token actions">
                            <td class="px-4 py-2 font-mono text-xs text-base-content/70"><?php echo (int) $tok['tokenid']; ?></td>
                            <td class="px-4 py-2"><span class="inline-block px-2 py-0.5 bg-base-200 text-base-content/80 text-xs rounded-sm"><?php echo htmlspecialchars($tok['tokentype'] ?? 'auth', ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td class="px-4 py-2"><span class="inline-block px-2 py-0.5 rounded-sm text-xs font-medium <?php echo $sCls; ?>"><?php echo $sLabel; ?></span></td>
                            <td class="px-4 py-2 text-xs text-base-content/60"><?php echo htmlspecialchars($tok['ipaddress'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="px-4 py-2 text-xs"><?php echo ($tok['lastused'] ?? 0) > 0 ? date('Y-m-d H:i', (int) $tok['lastused']) : '—'; ?></td>
                            <td class="px-4 py-2 text-xs"><?php echo $exp > 0 ? date('Y-m-d H:i', $exp) : 'Never'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>
