<?php
/**
 * Account-area breadcrumb trail (tailwind theme).
 *
 * Renders Home / Dashboard / …/ <current page> using the framework's
 * Pramnos\Html\Breadcrumb, driven by $this->activeNav. Include at the top of
 * every account page so the trail always reflects the real hierarchy —
 * Security and Privacy are parents of their sub-pages — instead of ad-hoc
 * "Back" buttons.
 *
 * Context (inherited from the including View):
 *   $this->routeBase — Account controller route base (defaults to 'Account')
 *   $this->activeNav — key of the current page (see the sidebar partial)
 */
$routeBase   = $this->accountBase ?? $this->routeBase ?? 'Account';
$active      = $this->activeNav ?? '';
$securityUrl = sURL . $routeBase . '/security';
$privacyUrl  = sURL . $routeBase . '/privacy';
$twoUrl      = sURL . 'TwoFactorAuth';

// Trail beyond Home / Dashboard, per page. Each entry: [label, url] — the last
// item's url is '' so it renders as the current (non-link) crumb.
$trails = [
    'profile'        => [['Profile', '']],
    'applications'   => [['Authorized Applications', '']],
    'security'       => [['Security', '']],
    'privacy'        => [['Privacy', '']],
    'changepassword' => [['Security', $securityUrl], ['Change Password', '']],
    'twofactor'      => [['Security', $securityUrl], ['Two-Factor Auth', '']],
    'passkey'        => [['Security', $securityUrl], ['Passkeys', '']],
        'twofactor_setup'  => [['Security', $securityUrl], ['Two-Factor Auth', $twoUrl], ['Set up', '']],
        'twofactor_backup' => [['Security', $securityUrl], ['Two-Factor Auth', $twoUrl], ['Backup codes', '']],
    'exportdata'     => [['Privacy', $privacyUrl], ['Export My Data', '']],
    'deleteaccount'  => [['Privacy', $privacyUrl], ['Delete Account', '']],
];

$bc = new \Pramnos\Html\Breadcrumb();
$bc->addItem('Home', sURL);
$bc->addItem('Dashboard', sURL . $routeBase);
foreach ($trails[$active] ?? [] as $crumb) {
    $bc->addItem($crumb[0], $crumb[1]);
}
echo $bc->render();
