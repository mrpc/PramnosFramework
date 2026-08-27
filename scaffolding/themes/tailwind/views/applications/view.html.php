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
        <a href="<?php echo adminUrl('applications'); ?>" class="btn btn-outline btn-sm">&larr; Applications</a>
        <h2 class="text-2xl font-semibold"><?php echo htmlspecialchars($app['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></h2>
        <span class="inline-block px-2 py-0.5 rounded-sm text-xs font-medium <?php echo $isActive ? 'bg-success/10 text-success' : 'bg-error/10 text-error'; ?>">
            <?php echo $isActive ? 'Active' : 'Disabled'; ?>
        </span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left: credentials + stats + actions -->
        <div class="space-y-4">

            <div class="card bg-base-100 border border-base-300 shadow-xs">
                <div class="px-4 py-2 bg-base-200 text-xs font-semibold text-base-content/70 uppercase tracking-wide rounded-t-xl">Credentials</div>
                <div class="p-4 space-y-3">
                    <div>
                        <div class="text-xs text-base-content/60 mb-1">Client ID (API Key)</div>
                        <div class="flex gap-1">
                            <input type="text" readonly
                                   class="input input-xs flex-1 font-mono"
                                   value="<?php echo htmlspecialchars($app['apikey'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <button class="btn btn-outline btn-xs"
                                    data-copy-prev title="Copy">&#128203;</button>
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-base-content/60 mb-1">Client Secret</div>
                        <div class="flex gap-1">
                            <input type="password" readonly id="twAppSecret<?php echo $appId; ?>"
                                   class="input input-xs flex-1 font-mono"
                                   value="<?php echo htmlspecialchars($app['apisecret'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <button class="btn btn-outline btn-xs"
                                    data-toggle-type="twAppSecret<?php echo $appId; ?>"
                                    title="Toggle">&#128065;</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                <div class="px-4 py-2 bg-base-200 text-xs font-semibold text-base-content/70 uppercase tracking-wide">Token Statistics</div>
                <div class="divide-y divide-base-300 text-sm">
                    <div class="px-4 py-2.5 flex justify-between">
                        <span class="text-base-content/70">Total</span>
                        <a href="<?php echo adminUrl('applications' . '/tokens/' . ($appId)); ?>" class="font-semibold text-primary hover:underline">
                            <?php echo (int) ($tokenStats['total'] ?? 0); ?>
                        </a>
                    </div>
                    <div class="px-4 py-2.5 flex justify-between">
                        <span class="text-base-content/70">Active</span>
                        <span class="inline-block px-2 py-0.5 rounded-sm text-xs font-medium bg-success/10 text-success"><?php echo (int) ($tokenStats['active'] ?? 0); ?></span>
                    </div>
                    <div class="px-4 py-2.5 flex justify-between">
                        <span class="text-base-content/70">Revoked</span>
                        <span class="inline-block px-2 py-0.5 rounded-sm text-xs font-medium bg-error/10 text-error"><?php echo (int) ($tokenStats['revoked'] ?? 0); ?></span>
                    </div>
                </div>
            </div>

            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                <div class="px-4 py-2 bg-base-200 text-xs font-semibold text-base-content/70 uppercase tracking-wide rounded-t-xl">Actions</div>
                <?php
                /**
                 * Left-aligned, icon-first, `btn-block` — the same shape the user record
                 * uses, from the same `Html\Icon` set. Centred label-only buttons in a
                 * narrow column read as four identical pills: the icon is what makes the
                 * list scannable, and a daisyUI `btn` centres its content unless the
                 * content is told where to go.
                 */
                $action = static function (
                    string $url,
                    string $icon,
                    string $label,
                    string $classes = 'btn-outline',
                    string $confirm = ''
                ): void {
                    echo '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"'
                        . ' class="btn btn-sm btn-block justify-start gap-2 ' . $classes . '"'
                        . ($confirm !== ''
                            ? ' data-confirm="' . htmlspecialchars($confirm, ENT_QUOTES, 'UTF-8') . '"'
                            : '')
                        . '>' . \Pramnos\Html\Icon::svg($icon)
                        . '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span></a>';
                };
                ?>
                <div class="p-4 grid gap-2">
                    <?php
                    $action(adminUrl('applications/edit/' . $appId), 'edit', 'Edit application', 'btn-primary');
                    $action(adminUrl('applications/tokens/' . $appId), 'tokens', 'View tokens');
                    $action(adminUrl('applications/rotate/' . $appId), 'retry', 'Rotate secret', 'btn-outline btn-warning',
                        'Rotate the client secret? Existing tokens remain valid.');
                    $action(adminUrl('applications/delete/' . $appId), 'deactivate', 'Disable application', 'btn-outline btn-error',
                        'Disable this application and revoke all active tokens?');
                    ?>
                </div>
            </div>

        </div>

        <!-- Right: details + last users -->
        <div class="lg:col-span-2 space-y-4">

            <div class="card bg-base-100 border border-base-300 shadow-xs">
                <div class="px-6 py-3 border-b border-base-300 font-semibold text-base-content">Application Details</div>
                <div class="p-6 grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
                    <?php $field = function(string $label, string $value) { ?>
                        <div>
                            <div class="text-xs text-base-content/60 mb-0.5"><?php echo $label; ?></div>
                            <div class="text-base-content"><?php echo $value; ?></div>
                        </div>
                    <?php }; ?>
                    <?php $field('App ID', '<code class="text-xs">' . $appId . '</code>'); ?>
                    <?php $field('Type', $appTypeLabel((int) ($app['apptype'] ?? 0))); ?>
                    <?php $field('Access Type', $accessTypeLabel((int) ($app['accesstype'] ?? 0))); ?>
                    <?php $field('API Version', htmlspecialchars($app['apiversion'] ?? 'v1', ENT_QUOTES, 'UTF-8')); ?>
                    <?php $field('App Version', ($app['appversion'] ?? '') !== '' ? htmlspecialchars($app['appversion'], ENT_QUOTES, 'UTF-8') : '<span class="text-base-content/50">—</span>'); ?>
                    <div>
                        <div class="text-xs text-base-content/60 mb-0.5">Public</div>
                        <span class="inline-block px-2 py-0.5 rounded-sm text-xs font-medium <?php echo (int) ($app['public'] ?? 0) ? 'bg-primary/10 text-primary' : 'bg-base-200 text-base-content/80'; ?>">
                            <?php echo (int) ($app['public'] ?? 0) ? 'Yes' : 'No'; ?>
                        </span>
                    </div>
                    <?php $field('Added', ($app['added'] ?? 0) > 0 ? date('Y-m-d H:i', (int) $app['added']) : '—'); ?>
                    <?php if (!empty($app['description'])): ?>
                    <div class="col-span-full">
                        <div class="text-xs text-base-content/60 mb-0.5">Description</div>
                        <div class="text-base-content"><?php echo htmlspecialchars($app['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($app['callback'])): ?>
                    <div class="col-span-full">
                        <div class="text-xs text-base-content/60 mb-0.5">Callback URL</div>
                        <div class="font-mono text-xs text-base-content/80 break-all"><?php echo htmlspecialchars($app['callback'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($app['scope'])): ?>
                    <div class="col-span-full">
                        <div class="text-xs text-base-content/60 mb-0.5">Scope</div>
                        <div class="font-mono text-xs text-base-content/80"><?php echo htmlspecialchars($app['scope'], ENT_QUOTES, 'UTF-8'); ?></div>
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
                        <div class="text-xs text-base-content/60 mb-0.5">Public Key</div>
                        <pre class="bg-base-200 rounded-sm p-2 text-xs overflow-x-auto max-h-28"><?php echo htmlspecialchars($app['public_key'], ENT_QUOTES, 'UTF-8'); ?></pre>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($lastUsers)): ?>
            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                <div class="px-6 py-3 border-b border-base-300 flex justify-between items-center">
                    <span class="font-semibold text-base-content">Recent Users</span>
                    <a href="<?php echo adminUrl('applications' . '/tokens/' . ($appId)); ?>"
                       class="text-sm text-primary hover:underline">All Tokens</a>
                </div>
                <table class="table table-sm text-sm">
                    <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                        <tr>
                            <th class="px-4 py-2 text-left">User</th>
                            <th class="px-4 py-2 text-left">Scope</th>
                            <th class="px-4 py-2 text-left">IP</th>
                            <th class="px-4 py-2 text-left">Last Used</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-base-300">
                    <?php foreach ($lastUsers as $u): ?>
                        <tr class="hover:bg-base-200">
                            <td class="px-4 py-2">
                                <a href="<?php echo adminUrl('users/view/'); ?><?php echo (int) ($u['userid'] ?? 0); ?>"
                                   class="text-primary hover:underline">
                                    <?php echo htmlspecialchars($u['username'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                            </td>
                            <td class="px-4 py-2 text-xs text-base-content/60"><?php echo htmlspecialchars($u['scope'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="px-4 py-2 text-xs text-base-content/60"><?php echo htmlspecialchars($u['ipaddress'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="px-4 py-2 text-xs"><?php echo ($u['lastused'] ?? 0) > 0 ? date('Y-m-d H:i', (int) $u['lastused']) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
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
            <div class="mt-8">
                <div class="flex items-baseline justify-between mb-2">
                    <h3 class="font-semibold text-base-content">Declared capabilities</h3>
                    <?php if (!empty($caps['synced_at'])): ?>
                        <span class="text-xs text-base-content/60">
                            last pushed <?php echo htmlspecialchars((string) $caps['synced_at'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if (empty($caps['resources']) && empty($caps['conditions'])): ?>
                    <p class="text-sm text-base-content/70">
                        This client has not pushed a capabilities manifest. Until it does,
                        the server knows no resource or scope names for it.
                    </p>
                <?php else: ?>
                    <?php if (!empty($caps['resources'])): ?>
                        <table class="w-full text-sm mb-4">
                            <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                                <tr><th class="px-4 py-2 text-left">Resource</th><th class="px-4 py-2 text-left">Scopes</th></tr>
                            </thead>
                            <tbody class="divide-y divide-base-300">
                            <?php foreach ($caps['resources'] as $resource): ?>
                                <tr>
                                    <td class="px-4 py-2 align-top">
                                        <span class="font-mono<?php echo $resource['is_active'] ? '' : ' line-through text-base-content/40'; ?>">
                                            <?php echo htmlspecialchars($resource['name'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                        <?php if (!empty($resource['description'])): ?>
                                            <div class="text-xs text-base-content/70"><?php echo htmlspecialchars((string) $resource['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-2 align-top">
                                        <?php if (empty($resource['scopes'])): ?>
                                            <span class="text-xs text-base-content/60">no scopes declared</span>
                                        <?php else: ?>
                                            <?php foreach ($resource['scopes'] as $scope): ?>
                                                <span class="badge badge-sm mr-1 mb-1<?php echo $scope['is_active'] ? ' badge-neutral' : ' badge-ghost line-through'; ?>"
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
                        <h4 class="text-sm font-semibold mb-1 text-base-content">Condition keys</h4>
                        <ul class="text-sm">
                        <?php foreach ($caps['conditions'] as $condition): ?>
                            <li class="py-0.5<?php echo $condition['is_active'] ? '' : ' line-through text-base-content/40'; ?>">
                                <span class="font-mono"><?php echo htmlspecialchars($condition['key'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="text-xs text-base-content/70">
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
