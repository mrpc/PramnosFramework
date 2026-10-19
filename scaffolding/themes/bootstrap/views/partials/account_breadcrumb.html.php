<?php
/**
 * Account-area breadcrumb trail (bootstrap theme).
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
    'profile'        => [[t('Profile'), '']],
    'applications'   => [[t('Authorized Applications'), '']],
    'security'       => [[t('Security'), '']],
    'privacy'        => [[t('Privacy'), '']],
    'changepassword' => [[t('Security'), $securityUrl], [t('Change Password'), '']],
    'twofactor'      => [[t('Security'), $securityUrl], [t('Two-Factor Auth'), '']],
    'passkey'        => [[t('Security'), $securityUrl], [t('Passkeys'), '']],
        'twofactor_setup'  => [[t('Security'), $securityUrl], [t('Two-Factor Auth'), $twoUrl], [t('Set up'), '']],
        'twofactor_backup' => [[t('Security'), $securityUrl], [t('Two-Factor Auth'), $twoUrl], [t('Backup codes'), '']],
    'exportdata'     => [[t('Privacy'), $privacyUrl], [t('Export My Data'), '']],
    'deleteaccount'  => [[t('Privacy'), $privacyUrl], [t('Delete Account'), '']],
];

$bc = new \Pramnos\Html\Breadcrumb();
$bc->addItem(htmlspecialchars(t('Home'), ENT_QUOTES, 'UTF-8'), sURL, t('Home'));
$bc->addItem(htmlspecialchars(t('Dashboard'), ENT_QUOTES, 'UTF-8'), sURL . $routeBase, t('Dashboard'));
foreach ($trails[$active] ?? [] as $crumb) {
    // Labels are rendered as HTML by Breadcrumb, so a translation is escaped here.
    $bc->addItem(htmlspecialchars($crumb[0], ENT_QUOTES, 'UTF-8'), $crumb[1], $crumb[0]);
}
echo $bc->render();
