<?php
/**
 * Application detail (read-only) view (Tailwind theme).
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

$isActive = (bool) ($app['status'] ?? 1);

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
<div class="px-4 py-6">
    <div class="flex items-center gap-3 mb-6">
        <a href="<?php echo sURL; ?>applications" class="px-3 py-1.5 text-sm border border-gray-300 text-gray-600 rounded hover:bg-gray-50">&larr; Applications</a>
        <h2 class="text-2xl font-semibold"><?php echo htmlspecialchars($app['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></h2>
        <span class="inline-block px-2 py-0.5 rounded text-xs font-medium <?php echo $isActive ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
            <?php echo $isActive ? 'Active' : 'Disabled'; ?>
        </span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left: credentials + stats + actions -->
        <div class="space-y-4">

            <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                <div class="px-4 py-2 bg-gray-50 text-xs font-semibold text-gray-500 uppercase tracking-wide rounded-t-xl">Credentials</div>
                <div class="p-4 space-y-3">
                    <div>
                        <div class="text-xs text-gray-400 mb-1">Client ID (API Key)</div>
                        <div class="flex gap-1">
                            <input type="text" readonly
                                   class="flex-1 text-xs font-mono border border-gray-200 rounded px-2 py-1.5 bg-gray-50"
                                   value="<?php echo htmlspecialchars($app['apikey'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <button class="px-2 py-1 border border-gray-300 text-gray-500 rounded text-xs hover:bg-gray-50"
                                    onclick="navigator.clipboard.writeText(this.previousElementSibling.value)" title="Copy">&#128203;</button>
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400 mb-1">Client Secret</div>
                        <div class="flex gap-1">
                            <input type="password" readonly id="twAppSecret<?php echo $appId; ?>"
                                   class="flex-1 text-xs font-mono border border-gray-200 rounded px-2 py-1.5 bg-gray-50"
                                   value="<?php echo htmlspecialchars($app['apisecret'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <button class="px-2 py-1 border border-gray-300 text-gray-500 rounded text-xs hover:bg-gray-50"
                                    onclick="var f=document.getElementById('twAppSecret<?php echo $appId; ?>');f.type=f.type==='password'?'text':'password'"
                                    title="Toggle">&#128065;</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-4 py-2 bg-gray-50 text-xs font-semibold text-gray-500 uppercase tracking-wide">Token Statistics</div>
                <div class="divide-y divide-gray-100 text-sm">
                    <div class="px-4 py-2.5 flex justify-between">
                        <span class="text-gray-500">Total</span>
                        <a href="<?php echo sURL; ?>applications/tokens/<?php echo $appId; ?>" class="font-semibold text-indigo-600 hover:underline">
                            <?php echo (int) ($tokenStats['total'] ?? 0); ?>
                        </a>
                    </div>
                    <div class="px-4 py-2.5 flex justify-between">
                        <span class="text-gray-500">Active</span>
                        <span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700"><?php echo (int) ($tokenStats['active'] ?? 0); ?></span>
                    </div>
                    <div class="px-4 py-2.5 flex justify-between">
                        <span class="text-gray-500">Revoked</span>
                        <span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700"><?php echo (int) ($tokenStats['revoked'] ?? 0); ?></span>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-4 py-2 bg-gray-50 text-xs font-semibold text-gray-500 uppercase tracking-wide rounded-t-xl">Actions</div>
                <div class="p-4 grid gap-2">
                    <a href="<?php echo sURL; ?>applications/edit/<?php echo $appId; ?>"
                       class="block text-center px-3 py-2 text-sm bg-indigo-600 text-white rounded hover:bg-indigo-700">Edit Application</a>
                    <a href="<?php echo sURL; ?>applications/tokens/<?php echo $appId; ?>"
                       class="block text-center px-3 py-2 text-sm border border-gray-300 text-gray-600 rounded hover:bg-gray-50">View Tokens</a>
                    <a href="<?php echo sURL; ?>applications/rotate/<?php echo $appId; ?>"
                       class="block text-center px-3 py-2 text-sm border border-yellow-400 text-yellow-700 rounded hover:bg-yellow-50"
                       onclick="return confirm('Rotate the client secret? Existing tokens remain valid.')">Rotate Secret</a>
                    <a href="<?php echo sURL; ?>applications/delete/<?php echo $appId; ?>"
                       class="block text-center px-3 py-2 text-sm border border-red-300 text-red-700 rounded hover:bg-red-50"
                       onclick="return confirm('Disable this application and revoke all active tokens?')">Disable App</a>
                </div>
            </div>

        </div>

        <!-- Right: details + last users -->
        <div class="lg:col-span-2 space-y-4">

            <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                <div class="px-6 py-3 border-b border-gray-100 font-semibold text-gray-700">Application Details</div>
                <div class="p-6 grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
                    <?php $field = function(string $label, string $value) { ?>
                        <div>
                            <div class="text-xs text-gray-400 mb-0.5"><?php echo $label; ?></div>
                            <div class="text-gray-800"><?php echo $value; ?></div>
                        </div>
                    <?php }; ?>
                    <?php $field('App ID', '<code class="text-xs">' . $appId . '</code>'); ?>
                    <?php $field('Type', $appTypeLabel((int) ($app['apptype'] ?? 0))); ?>
                    <?php $field('Access Type', $accessTypeLabel((int) ($app['accesstype'] ?? 0))); ?>
                    <?php $field('API Version', htmlspecialchars($app['apiversion'] ?? 'v1', ENT_QUOTES, 'UTF-8')); ?>
                    <?php $field('App Version', ($app['appversion'] ?? '') !== '' ? htmlspecialchars($app['appversion'], ENT_QUOTES, 'UTF-8') : '<span class="text-gray-300">—</span>'); ?>
                    <div>
                        <div class="text-xs text-gray-400 mb-0.5">Public</div>
                        <span class="inline-block px-2 py-0.5 rounded text-xs font-medium <?php echo (int) ($app['public'] ?? 0) ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600'; ?>">
                            <?php echo (int) ($app['public'] ?? 0) ? 'Yes' : 'No'; ?>
                        </span>
                    </div>
                    <?php $field('Added', ($app['added'] ?? 0) > 0 ? date('Y-m-d H:i', (int) $app['added']) : '—'); ?>
                    <?php if (!empty($app['description'])): ?>
                    <div class="col-span-full">
                        <div class="text-xs text-gray-400 mb-0.5">Description</div>
                        <div class="text-gray-700"><?php echo htmlspecialchars($app['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($app['callback'])): ?>
                    <div class="col-span-full">
                        <div class="text-xs text-gray-400 mb-0.5">Callback URL</div>
                        <div class="font-mono text-xs text-gray-600 break-all"><?php echo htmlspecialchars($app['callback'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($app['scope'])): ?>
                    <div class="col-span-full">
                        <div class="text-xs text-gray-400 mb-0.5">Scope</div>
                        <div class="font-mono text-xs text-gray-600"><?php echo htmlspecialchars($app['scope'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($app['organization'])): ?>
                    <?php $field('Organization', htmlspecialchars($app['organization'], ENT_QUOTES, 'UTF-8')); ?>
                    <?php endif; ?>
                    <?php if (!empty($app['url'])): ?>
                    <?php $field('URL', '<span class="text-xs break-all">' . htmlspecialchars($app['url'], ENT_QUOTES, 'UTF-8') . '</span>'); ?>
                    <?php endif; ?>
                    <?php if (!empty($app['public_key'])): ?>
                    <div class="col-span-full">
                        <div class="text-xs text-gray-400 mb-0.5">Public Key</div>
                        <pre class="bg-gray-50 rounded p-2 text-xs overflow-x-auto max-h-28"><?php echo htmlspecialchars($app['public_key'], ENT_QUOTES, 'UTF-8'); ?></pre>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($lastUsers)): ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-6 py-3 border-b border-gray-100 flex justify-between items-center">
                    <span class="font-semibold text-gray-700">Recent Users</span>
                    <a href="<?php echo sURL; ?>applications/tokens/<?php echo $appId; ?>"
                       class="text-sm text-indigo-600 hover:underline">All Tokens</a>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-2 text-left">User</th>
                            <th class="px-4 py-2 text-left">Scope</th>
                            <th class="px-4 py-2 text-left">IP</th>
                            <th class="px-4 py-2 text-left">Last Used</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                    <?php foreach ($lastUsers as $u): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2">
                                <a href="<?php echo sURL; ?>users/view/<?php echo (int) ($u['userid'] ?? 0); ?>"
                                   class="text-indigo-600 hover:underline">
                                    <?php echo htmlspecialchars($u['username'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                            </td>
                            <td class="px-4 py-2 text-xs text-gray-400"><?php echo htmlspecialchars($u['scope'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="px-4 py-2 text-xs text-gray-400"><?php echo htmlspecialchars($u['ipaddress'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="px-4 py-2 text-xs"><?php echo ($u['lastused'] ?? 0) > 0 ? date('Y-m-d H:i', (int) $u['lastused']) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>
