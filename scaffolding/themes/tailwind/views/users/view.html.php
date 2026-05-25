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
    if ($t >= 90) return ['bg-red-100 text-red-700',     'Admin'];
    if ($t >= 80) return ['bg-yellow-100 text-yellow-700', 'Manager'];
    if ($t >= 50) return ['bg-blue-100 text-blue-700',   'Editor'];
    if ($t >= 10) return ['bg-indigo-100 text-indigo-700','Member'];
    return ['bg-gray-100 text-gray-600', 'Guest'];
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
    <div class="flex items-center gap-3 mb-6">
        <a href="<?php echo sURL; ?>users" class="px-3 py-1.5 text-sm border border-gray-300 text-gray-600 rounded hover:bg-gray-50">&larr; Users</a>
        <h2 class="text-2xl font-semibold"><?php echo htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></h2>
        <?php if (!$isActive): ?>
            <span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700">Inactive</span>
        <?php endif; ?>
        <?php if (!$isValidated): ?>
            <span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-700">Unvalidated</span>
        <?php endif; ?>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        <!-- Left: profile card + stats + actions -->
        <div class="lg:col-span-1 space-y-4">

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 text-center">
                <div class="w-20 h-20 bg-gray-400 rounded-full flex items-center justify-center mx-auto mb-3 text-3xl font-bold text-white">
                    <?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <h3 class="font-semibold text-gray-900">
                    <?php echo htmlspecialchars($fullName ?: ($user['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                </h3>
                <p class="text-sm text-gray-400 mt-0.5">@<?php echo htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                <div class="mt-2 flex justify-center gap-1 flex-wrap">
                    <span class="inline-block px-2 py-0.5 rounded text-xs font-medium <?php echo $typeCls; ?>">
                        <?php echo $typeLabel; ?> (<?php echo (int) ($user['usertype'] ?? 0); ?>)
                    </span>
                    <span class="inline-block px-2 py-0.5 rounded text-xs font-medium <?php echo $isActive ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                        <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                    </span>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-4 py-2 bg-gray-50 text-xs font-semibold text-gray-500 uppercase tracking-wide">Statistics</div>
                <div class="divide-y divide-gray-100 text-sm">
                    <div class="px-4 py-2.5 flex justify-between">
                        <span class="text-gray-500">Tokens</span>
                        <a href="<?php echo sURL; ?>users/tokens/<?php echo $uid; ?>" class="font-semibold text-indigo-600 hover:underline">
                            <?php echo (int) ($usageStats['total_tokens'] ?? 0); ?>
                        </a>
                    </div>
                    <div class="px-4 py-2.5 flex justify-between">
                        <span class="text-gray-500">Unique Apps</span>
                        <strong><?php echo (int) ($usageStats['unique_apps'] ?? 0); ?></strong>
                    </div>
                    <div class="px-4 py-2.5 flex justify-between">
                        <span class="text-gray-500">Sessions</span>
                        <a href="<?php echo sURL; ?>users/sessions/<?php echo $uid; ?>" class="font-semibold text-indigo-600 hover:underline">
                            <?php echo $sessionCount; ?>
                        </a>
                    </div>
                    <div class="px-4 py-2.5 flex justify-between">
                        <span class="text-gray-500">Registered</span>
                        <span class="text-xs"><?php echo ($user['regdate'] ?? 0) > 0 ? date('Y-m-d', (int) $user['regdate']) : '—'; ?></span>
                    </div>
                    <div class="px-4 py-2.5 flex justify-between">
                        <span class="text-gray-500">Last Login</span>
                        <span class="text-xs"><?php echo ($user['lastlogin'] ?? 0) > 0 ? date('Y-m-d H:i', (int) $user['lastlogin']) : '—'; ?></span>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-4 py-2 bg-gray-50 text-xs font-semibold text-gray-500 uppercase tracking-wide">Actions</div>
                <div class="p-4 grid gap-2">
                    <a href="<?php echo sURL; ?>users/edit/<?php echo $uid; ?>"
                       class="block text-center px-3 py-2 text-sm bg-indigo-600 text-white rounded hover:bg-indigo-700">Edit User</a>
                    <?php if ($isActive): ?>
                        <a href="<?php echo sURL; ?>users/lock/<?php echo $uid; ?>"
                           class="block text-center px-3 py-2 text-sm border border-yellow-400 text-yellow-700 rounded hover:bg-yellow-50"
                           onclick="return confirm('Lock this account?')">Lock Account</a>
                    <?php else: ?>
                        <a href="<?php echo sURL; ?>users/unlock/<?php echo $uid; ?>"
                           class="block text-center px-3 py-2 text-sm border border-green-400 text-green-700 rounded hover:bg-green-50">Unlock Account</a>
                    <?php endif; ?>
                    <a href="<?php echo sURL; ?>users/tokens/<?php echo $uid; ?>"
                       class="block text-center px-3 py-2 text-sm border border-gray-300 text-gray-600 rounded hover:bg-gray-50">All Tokens</a>
                    <a href="<?php echo sURL; ?>users/sessions/<?php echo $uid; ?>"
                       class="block text-center px-3 py-2 text-sm border border-gray-300 text-gray-600 rounded hover:bg-gray-50">Sessions</a>
                </div>
            </div>

        </div>

        <!-- Right: details + recent tokens -->
        <div class="lg:col-span-3 space-y-4">

            <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                <div class="px-6 py-3 border-b border-gray-100 font-semibold text-gray-700">Account Details</div>
                <div class="p-6 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 text-sm">
                    <?php $field = function(string $label, string $value) { ?>
                        <div>
                            <div class="text-xs text-gray-400 mb-0.5"><?php echo $label; ?></div>
                            <div class="text-gray-800"><?php echo $value; ?></div>
                        </div>
                    <?php }; ?>
                    <?php $field('User ID', '<code class="text-xs">' . (int)($user['userid'] ?? 0) . '</code>'); ?>
                    <?php $field('Username', htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8')); ?>
                    <?php $field('First Name', ($user['firstname'] ?? '') !== '' ? htmlspecialchars($user['firstname'], ENT_QUOTES, 'UTF-8') : '<span class="text-gray-300">—</span>'); ?>
                    <?php $field('Last Name', ($user['lastname'] ?? '') !== '' ? htmlspecialchars($user['lastname'], ENT_QUOTES, 'UTF-8') : '<span class="text-gray-300">—</span>'); ?>
                    <?php $field('Email', ($user['email'] ?? '') !== '' ? htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') : '<span class="text-gray-300">—</span>'); ?>
                    <?php $field('Phone', ($user['phone'] ?? '') !== '' ? htmlspecialchars($user['phone'], ENT_QUOTES, 'UTF-8') : '<span class="text-gray-300">—</span>'); ?>
                    <?php $field('Mobile', ($user['mobile'] ?? '') !== '' ? htmlspecialchars($user['mobile'], ENT_QUOTES, 'UTF-8') : '<span class="text-gray-300">—</span>'); ?>
                    <?php $field('Language', ($user['language'] ?? '') !== '' ? htmlspecialchars($user['language'], ENT_QUOTES, 'UTF-8') : '<span class="text-gray-300">default</span>'); ?>
                    <?php $field('Timezone', ($user['timezone'] ?? '') !== '' ? htmlspecialchars($user['timezone'], ENT_QUOTES, 'UTF-8') : '<span class="text-gray-300">—</span>'); ?>
                    <div>
                        <div class="text-xs text-gray-400 mb-0.5">User Type</div>
                        <span class="inline-block px-2 py-0.5 rounded text-xs font-medium <?php echo $typeCls; ?>"><?php echo $typeLabel; ?></span>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400 mb-0.5">Status</div>
                        <span class="inline-block px-2 py-0.5 rounded text-xs font-medium <?php echo $isActive ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                            <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400 mb-0.5">Validation</div>
                        <span class="inline-block px-2 py-0.5 rounded text-xs font-medium <?php echo $isValidated ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'; ?>">
                            <?php echo $isValidated ? 'Validated' : 'Pending'; ?>
                        </span>
                    </div>
                </div>
            </div>

            <?php if (!empty($recentTokens)): ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-6 py-3 border-b border-gray-100 flex justify-between items-center">
                    <span class="font-semibold text-gray-700">Recent Tokens</span>
                    <a href="<?php echo sURL; ?>users/tokens/<?php echo $uid; ?>"
                       class="text-sm text-indigo-600 hover:underline">All Tokens</a>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-2 text-left">ID</th>
                            <th class="px-4 py-2 text-left">Type</th>
                            <th class="px-4 py-2 text-left">Status</th>
                            <th class="px-4 py-2 text-left">IP</th>
                            <th class="px-4 py-2 text-left">Last Used</th>
                            <th class="px-4 py-2 text-left">Expires</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                    <?php foreach ($recentTokens as $tok):
                        $s    = (int) ($tok['status'] ?? 0);
                        $sMap = [0 => ['bg-gray-100 text-gray-600','Inactive'], 1 => ['bg-green-100 text-green-700','Active'], 2 => ['bg-gray-800 text-white','Deleted'], 3 => ['bg-red-100 text-red-700','Revoked']];
                        [$sCls, $sLabel] = $sMap[$s] ?? ['bg-gray-100 text-gray-600','Unknown'];
                        $exp = (int) ($tok['expires'] ?? 0);
                    ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs text-gray-500"><?php echo (int) $tok['tokenid']; ?></td>
                            <td class="px-4 py-2"><span class="inline-block px-2 py-0.5 bg-gray-100 text-gray-600 text-xs rounded"><?php echo htmlspecialchars($tok['tokentype'] ?? 'auth', ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td class="px-4 py-2"><span class="inline-block px-2 py-0.5 rounded text-xs font-medium <?php echo $sCls; ?>"><?php echo $sLabel; ?></span></td>
                            <td class="px-4 py-2 text-xs text-gray-400"><?php echo htmlspecialchars($tok['ipaddress'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
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
