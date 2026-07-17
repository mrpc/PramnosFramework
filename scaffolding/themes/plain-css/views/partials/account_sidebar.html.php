<?php
/**
 * Shared account-area navigation sidebar (plain-CSS theme).
 *
 * Rendered on every account/dashboard page (via $this->insert()) so the
 * "Account Settings" navigation is identical everywhere. Only TOP-LEVEL
 * destinations appear here; deeper options are reached from their parent page
 * and are not duplicated in the sidebar:
 *   - Security → change password, two-factor, passkeys
 *   - Privacy  → export my data, delete account
 * When a child page is active, its parent entry is highlighted.
 *
 * Context (inherited from the including View):
 *   $this->routeBase — Account controller route base (defaults to 'Account')
 *   $this->activeNav — key of the current page
 */
// Account nav always points at the Account controller. Pages rendered by other
// controllers (TwoFactorAuth, Passkey) set $this->accountBase = 'Account' so the
// links stay correct even though their own routeBase differs.
$routeBase = $this->accountBase ?? $this->routeBase ?? 'Account';
$active    = $this->activeNav ?? '';
// Child pages highlight their parent entry.
$parents = [
    'changepassword' => 'security', 'twofactor' => 'security', 'passkey' => 'security', 'twofactor_setup' => 'security', 'twofactor_backup' => 'security',
    'exportdata'     => 'privacy',  'deleteaccount' => 'privacy',
];
$active = $parents[$active] ?? $active;
$navItems = [
    ['key' => 'dashboard',    'href' => $routeBase,                   'label' => 'Dashboard'],
    ['key' => 'profile',      'href' => $routeBase . '/profile',      'label' => 'Profile'],
    ['key' => 'applications', 'href' => $routeBase . '/applications', 'label' => 'Authorized Applications'],
    ['key' => 'security',     'href' => $routeBase . '/security',     'label' => 'Security'],
    ['key' => 'privacy',      'href' => $routeBase . '/privacy',      'label' => 'Privacy'],
];
?>
<div class="card account-sidebar" style="align-self:start">
    <div class="card-header"><strong>Account Settings</strong></div>
    <ul style="list-style:none;margin:0;padding:0">
        <?php foreach ($navItems as $item): ?>
            <li style="border-bottom:1px solid #eee">
                <a href="<?php echo sURL . $item['href']; ?>"
                   style="display:block;padding:10px 16px;text-decoration:none;<?php echo $item['key'] === $active ? 'background:#f0f9ff;color:#0e7490;font-weight:600' : 'color:#333'; ?>">
                    <?php echo htmlspecialchars($item['label']); ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
