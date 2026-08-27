<?php
/**
 * Shared account-area navigation sidebar (Tailwind theme).
 *
 * Rendered on every account/dashboard page (via $this->insert()) as the first
 * cell of a grid:
 *
 *   <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
 *       <?php $this->insert('../partials/account_sidebar'); ?>
 *       <div class="md:col-span-3"> … page content … </div>
 *   </div>
 *
 * Only TOP-LEVEL destinations appear; deeper options are reached from their
 * parent page (Security → change password / 2FA / passkeys; Privacy → export /
 * delete) and highlight the parent entry when active.
 *
 * Context (inherited from the including View):
 *   $this->routeBase   — current controller route base
 *   $this->accountBase — account controller base (set by non-Account controllers)
 *   $this->activeNav   — key of the current page
 */
$routeBase = $this->accountBase ?? $this->routeBase ?? 'Account';
$active    = $this->activeNav ?? '';
$parents   = [
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
<div class="md:col-span-1">
    <div class="card bg-base-100 shadow-sm overflow-hidden">
        <div class="px-4 py-3 font-semibold text-base-content bg-base-200">Account Settings</div>
        <nav class="divide-y divide-base-300">
            <?php foreach ($navItems as $item): ?>
                <a href="<?php echo sURL . $item['href']; ?>"
                   class="block px-4 py-2 text-sm <?php echo $item['key'] === $active ? 'bg-primary/10 text-primary font-semibold' : 'text-base-content hover:bg-base-200'; ?>">
                    <?php echo htmlspecialchars($item['label']); ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>
</div>
