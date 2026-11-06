@php /**
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
    'organization_view' => 'organization',
];
$active = $parents[$active] ?? $active;
$navItems = [
    ['key' => 'dashboard',    'href' => $routeBase,                   'label' => t('Overview')],
    ['key' => 'profile',      'href' => $routeBase . '/profile',      'label' => t('Profile')],
    ['key' => 'applications', 'href' => $routeBase . '/applications', 'label' => t('Authorized Applications')],
    ['key' => 'security',     'href' => $routeBase . '/security',     'label' => t('Security')],
    ['key' => 'privacy',      'href' => $routeBase . '/privacy',      'label' => t('Privacy')],
];
// For somebody who manages an organisation, the way to it — and for nobody else.
$_signedIn = \Pramnos\User\User::getCurrentUser();
if (is_object($_signedIn) && (int) ($_signedIn->userid ?? 0) >= 2) {
    try {
        if (\Pramnos\Auth\OrganizationAdmin::managedBy($_signedIn) !== []) {
            $navItems[] = ['key' => 'organization', 'href' => 'organization', 'label' => t('Organisations')];
        }
    } catch (\Throwable) {
        // No organisation tables: nothing to manage.
    }
} @endphp
{{-- On a phone the menu is one row that scrolls sideways, so the page starts at its
   content instead of a screen below it; from `md` up it is the vertical list again. --}}
<div class="col-lg-3 col-md-4">
    <div class="card">
        <div class="card-header fw-semibold d-none d-md-block">{{ t('Account Settings') }}</div>
        <nav aria-label="{{ t('Account settings') }}" class="list-group list-group-flush pf-account-nav flex-row flex-md-column flex-nowrap overflow-auto">
            @foreach ($navItems as $item)
                <a href="{!! sURL . $item['href'] !!}"{!! $item['key'] === $active ? ' aria-current="page"' : '' !!}
                   class="list-group-item list-group-item-action flex-shrink-0 text-nowrap w-auto{!! $item['key'] === $active ? ' active' : '' !!}">
                    {{ $item['label'] }}
                </a>
            @endforeach
        </nav>
    </div>
</div>
