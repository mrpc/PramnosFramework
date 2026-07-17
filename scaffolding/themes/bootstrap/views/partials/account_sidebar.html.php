<?php
/**
 * Shared account-area navigation sidebar (Bootstrap theme).
 *
 * Rendered on every account/dashboard page (via $this->insert()) as the first
 * column of a Bootstrap row:
 *
 *   <div class="row g-4">
 *       <?php $this->insert('../partials/account_sidebar'); ?>
 *       <div class="col-lg-9 col-md-8"> … page content … </div>
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
<div class="col-lg-3 col-md-4">
    <div class="card">
        <div class="card-header fw-semibold">Account Settings</div>
        <div class="list-group list-group-flush">
            <?php foreach ($navItems as $item): ?>
                <a href="<?php echo sURL . $item['href']; ?>"
                   class="list-group-item list-group-item-action<?php echo $item['key'] === $active ? ' active' : ''; ?>">
                    <?php echo htmlspecialchars($item['label']); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
