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
                <?php
                /**
                 * `btn-block`, not `block`.
                 *
                 * A daisyUI `btn` is an `inline-flex` box that centres its own content;
                 * `block` overrode that display, so the label stopped being centred by
                 * the button and the height came from the text instead of the component.
                 * `btn-block` is the width modifier the component ships for this.
                 *
                 * Each one carries the same icon the row actions use, from
                 * `Html\Icon` — one visual language for "edit", whether it is a 28px
                 * cell in a table or a full-width button here.
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
                    $action(adminUrl('users/edit/' . $uid), 'edit', 'Edit user', 'btn-primary');
                    $action(adminUrl('users/resetpassword/' . $uid), 'password', 'Send password reset', 'btn-outline',
                        'Send a password reset link to this user?');
                    if ($isActive) {
                        $action(adminUrl('users/lock/' . $uid), 'lock', 'Lock account', 'btn-outline btn-warning',
                            'Lock this account?');
                    } else {
                        $action(adminUrl('users/unlock/' . $uid), 'unlock', 'Unlock account', 'btn-outline btn-success');
                    }
                    $action(adminUrl('Tokens/userid/' . $uid), 'tokens', 'All tokens');
                    $action(adminUrl('users/sessions/' . $uid), 'sessions', 'Sessions');
                    $action(adminUrl('Logs/search?q=' . urlencode((string) ($user['username'] ?? ''))), 'log', 'Find in logs');
                    ?>
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
                        $tokActionsUrl = adminUrl('TokenActions') . '?token_id=' . (int) $tok['tokenid'] . '&from=user&uid=' . $uid;
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

            <?php
            /**
             * Everything else the framework records about this account.
             *
             * Nine stores, collected by `UsersController::userRecords()` and each one
             * guarded on its own: an application without the `authserver` feature has
             * none of the `authserver.*` tables, so a panel with nothing behind it says
             * so instead of taking the page down.
             *
             * A panel is rendered even when it is empty, on purpose. "No GDPR requests"
             * is an answer; a page that silently omits the section leaves the reader
             * unable to tell it from a screen that never had one.
             */
            $records = is_array($this->records ?? null) ? $this->records : [];
            $r = static fn (string $key, $default = []) => $records[$key] ?? $default;
            $when = static function ($value): string {
                if ($value === null || $value === '' || $value === 0 || $value === '0') {
                    return '—';
                }
                $time = is_numeric($value) ? (int) $value : strtotime((string) $value);

                return $time > 0 ? date('Y-m-d H:i', $time) : (string) $value;
            };
            $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

            /** A panel header with an optional count and an optional "see all" link. */
            $panel = static function (string $title, int $count = -1, string $moreUrl = '', string $moreLabel = 'See all') use ($esc): void {
                echo '<div class="px-4 py-2 bg-base-200 border-b border-base-300 flex items-center gap-2">'
                    . '<span class="text-xs font-semibold uppercase tracking-wide text-base-content/70">'
                    . $esc($title) . '</span>';
                if ($count >= 0) {
                    echo '<span class="badge badge-neutral badge-sm">' . $count . '</span>';
                }
                if ($moreUrl !== '') {
                    echo '<a class="ms-auto link text-xs" href="' . $esc($moreUrl) . '">' . $esc($moreLabel) . '</a>';
                }
                echo '</div>';
            };
            ?>

            <!-- Login security: the lockout that answers "I cannot sign in" -->
            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                <?php $panel('Login security'); ?>
                <div class="p-4 text-sm">
                    <?php $lockouts = $r('lockouts'); ?>
                    <?php
                    $activeLock = null;
                    foreach ($lockouts as $lock) {
                        $until = strtotime((string) ($lock['lockoutuntil'] ?? ''));
                        if ($until && $until > time()) {
                            $activeLock = $lock;
                            break;
                        }
                    }
                    ?>
                    <?php if ($activeLock !== null): ?>
                    <div class="flex items-center gap-2 mb-3">
                        <span class="badge badge-error badge-sm">Locked out</span>
                        <span class="text-xs">until <?php echo $esc($when($activeLock['lockoutuntil'] ?? null)); ?></span>
                    </div>
                    <?php else: ?>
                    <div class="mb-3"><span class="badge badge-success badge-sm">No active lockout</span></div>
                    <?php endif; ?>

                    <?php if ($lockouts !== []): ?>
                    <table class="table table-xs">
                        <thead><tr><th>Scope</th><th>Value</th><th>Failures</th><th>Last failure</th><th>Locked until</th></tr></thead>
                        <tbody>
                        <?php foreach ($lockouts as $lock): ?>
                            <tr>
                                <td><?php echo $esc($lock['locktype'] ?? $lock['scope'] ?? ''); ?></td>
                                <td class="font-mono text-xs"><?php echo $esc($lock['displayvalue'] ?? ''); ?></td>
                                <td><?php echo (int) ($lock['failedattempts'] ?? 0); ?></td>
                                <td class="text-xs"><?php echo $esc($when($lock['lastfailedat'] ?? null)); ?></td>
                                <td class="text-xs"><?php echo $esc($when($lock['lockoutuntil'] ?? null)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <a href="<?php echo adminUrl('users/unlocklogin/' . $uid); ?>"
                       class="btn btn-sm btn-outline btn-error mt-3"
                       data-confirm="Clear the login lockout for this user?">Clear lockout</a>
                    <?php else: ?>
                    <p class="text-base-content/60 text-sm mb-0">No failed sign-in attempts recorded.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Second factor and passkeys: credentials an operator may have to remove -->
            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                <?php $panel('Second factor & passkeys'); ?>
                <div class="p-4 text-sm space-y-3">
                    <?php $twofactor = $r('twofactor', null); ?>
                    <div class="flex items-center gap-2">
                        <?php if (is_array($twofactor) && (int) ($twofactor['is_enabled'] ?? $twofactor['enabled'] ?? 0) === 1): ?>
                        <span class="badge badge-success badge-sm">2FA on</span>
                        <span class="text-xs text-base-content/60">
                            since <?php echo $esc($when($twofactor['enabled_at'] ?? $twofactor['created_at'] ?? null)); ?>
                        </span>
                        <a href="<?php echo adminUrl('users/disabletwofactor/' . $uid); ?>"
                           class="btn btn-xs btn-outline btn-warning ms-auto"
                           data-confirm="Disable two-factor authentication for this user?">Disable</a>
                        <?php else: ?>
                        <span class="badge badge-ghost badge-sm">2FA off</span>
                        <?php endif; ?>
                    </div>

                    <?php $passkeys = $r('passkeys'); ?>
                    <?php if ($passkeys !== []): ?>
                    <table class="table table-xs">
                        <thead><tr><th>Passkey</th><th>Added</th><th>Last used</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($passkeys as $key): ?>
                            <tr>
                                <td><?php echo $esc($key['name'] ?? $key['label'] ?? ('#' . (int) ($key['id'] ?? 0))); ?></td>
                                <td class="text-xs"><?php echo $esc($when($key['created_at'] ?? null)); ?></td>
                                <td class="text-xs"><?php echo $esc($when($key['last_used_at'] ?? null)); ?></td>
                                <td class="text-end">
                                    <?php echo \Pramnos\Html\Icon::link(
                                        adminUrl('users/revokepasskey/' . $uid) . '?credential=' . (int) ($key['id'] ?? 0),
                                        'delete',
                                        'Revoke this passkey',
                                        ['data-confirm' => 'Revoke this passkey?', 'class' => 'pf-action-danger']
                                    ); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <p class="text-base-content/60 text-sm mb-0">No passkeys registered.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Activity: what this account has done -->
            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                <?php $panel('Activity', (int) $r('activityCount', 0), adminUrl('users/activity/' . $uid)); ?>
                <?php $activity = $r('activity'); ?>
                <?php if ($activity === []): ?>
                <div class="p-4 text-sm text-base-content/60">Nothing recorded for this account.</div>
                <?php else: ?>
                <table class="table table-sm text-sm">
                    <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                        <tr><th>When</th><th>Action</th><th>IP</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($activity as $entry): ?>
                        <tr>
                            <td class="text-xs whitespace-nowrap"><?php echo $esc($when($entry['created_at'] ?? null)); ?></td>
                            <td><?php echo $esc($entry['action'] ?? ''); ?></td>
                            <td class="font-mono text-xs"><?php echo $esc($entry['ip_address'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Token actions: what was done with this account's tokens -->
            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                <?php $panel('Token actions', (int) $r('tokenActionCount', 0), adminUrl('TokenActions?uid=' . $uid . '&from=user')); ?>
                <?php $tokenActions = $r('tokenActions'); ?>
                <?php if ($tokenActions === []): ?>
                <div class="p-4 text-sm text-base-content/60">No token activity.</div>
                <?php else: ?>
                <table class="table table-sm text-sm">
                    <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                        <tr><th>When</th><th>Action</th><th>Token</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tokenActions as $entry): ?>
                        <tr>
                            <td class="text-xs whitespace-nowrap"><?php echo $esc($when($entry['actiondate'] ?? null)); ?></td>
                            <td><?php echo $esc($entry['action'] ?? ''); ?></td>
                            <td class="text-xs">
                                <a class="link" href="<?php echo adminUrl('TokenActions?token_id=' . (int) ($entry['tokenid'] ?? 0) . '&from=user&uid=' . $uid); ?>">
                                    #<?php echo (int) ($entry['tokenid'] ?? 0); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- GDPR: requests an operator has to answer -->
            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                <?php $panel('Data requests (GDPR)', (int) $r('gdprCount', 0)); ?>
                <?php $gdpr = $r('gdpr'); ?>
                <?php if ($gdpr === []): ?>
                <div class="p-4 text-sm text-base-content/60">This user has made no export or erasure requests.</div>
                <?php else: ?>
                <table class="table table-sm text-sm">
                    <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                        <tr><th>Requested</th><th>Type</th><th>Status</th><th>IP</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($gdpr as $request): ?>
                        <tr>
                            <td class="text-xs whitespace-nowrap"><?php echo $esc($when($request['requested_at'] ?? null)); ?></td>
                            <td><?php echo $esc($request['request_type'] ?? ''); ?></td>
                            <td>
                                <span class="pf-state <?php echo ($request['status'] ?? '') === 'completed' ? 'pf-state-on' : 'pf-state-off'; ?>">
                                    <?php echo $esc($request['status'] ?? ''); ?>
                                </span>
                            </td>
                            <td class="font-mono text-xs"><?php echo $esc($request['ip_address'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Privacy choices and memberships: small, and nowhere else on the site -->
            <div class="grid gap-4 md:grid-cols-2">
                <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                    <?php $panel('Privacy choices'); ?>
                    <?php $privacy = $r('privacy', null); ?>
                    <?php if (!is_array($privacy) || $privacy === []): ?>
                    <div class="p-4 text-sm text-base-content/60">Nothing recorded — the defaults apply.</div>
                    <?php else: ?>
                    <table class="table table-xs">
                        <tbody>
                        <?php foreach ($privacy as $setting => $value): ?>
                            <?php if ($setting === 'userid' || is_array($value) || is_object($value)) { continue; } ?>
                            <tr>
                                <th class="font-normal text-xs text-base-content/60"><?php echo $esc(ucwords(str_replace('_', ' ', (string) $setting))); ?></th>
                                <td class="text-xs"><?php echo $esc(is_bool($value) ? ($value ? 'Yes' : 'No') : $value); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>

                <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                    <?php $panel('Organizations', count($r('organizations'))); ?>
                    <?php $organizations = $r('organizations'); ?>
                    <?php if ($organizations === []): ?>
                    <div class="p-4 text-sm text-base-content/60">Not a member of any organization.</div>
                    <?php else: ?>
                    <ul class="divide-y divide-base-200">
                        <?php foreach ($organizations as $organization): ?>
                        <li class="px-4 py-2 text-sm flex items-center gap-2">
                            <a class="link truncate" href="<?php echo adminUrl('Organizations/view/' . (int) ($organization['organization_id'] ?? 0)); ?>">
                                <?php echo $esc($organization['name'] ?? ''); ?>
                            </a>
                            <span class="ms-auto text-xs text-base-content/50"><?php echo $esc($when($organization['granted_at'] ?? null)); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>
