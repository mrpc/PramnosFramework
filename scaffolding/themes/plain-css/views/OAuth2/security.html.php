<?php
/**
 * Security Overview page (plain-CSS theme).
 *
 * Variables:
 *   $this->recentActivity   — array[] {action, created_at, ip_address, user_agent}
 *   $this->twoFactorEnabled — bool
 *   $this->emailFactorOffered — bool: the application allows a code by email
 *   $this->emailFactorEnabled — bool: this account has asked for one
 *   $this->activeSessions   — array[] {sid, host_addr, agent, time, url}
 *   $this->currentSid       — sid of the session viewing this page
 *   $this->routeBase        — Account controller route base
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'security';
?>
<div class="page-section">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2><?php echo htmlspecialchars(t('Security Overview'), ENT_QUOTES, 'UTF-8'); ?></h2>

    <?php if ($this->hasMessages()): ?>
        <div role="status" class="alert alert-success"><?php echo $this->_printMessages(); ?></div>
    <?php endif; ?>
    <?php if ($this->hasErrors()): ?>
        <div role="alert" class="alert alert-error"><?php echo $this->_printErrors(); ?></div>
    <?php endif; ?>

    <div class="account-grid">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div>
            <!-- 2FA status -->
            <div class="card" style="margin-bottom:16px">
                <div class="card-header"><strong><?php echo htmlspecialchars(t('Two-Factor Authentication'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="card-body pf-row-split">
                    <p style="margin:0">
                        <?php if ($this->twoFactorEnabled): ?>
                            <span style="color:#28a745;font-weight:bold">&#10003; <?php echo htmlspecialchars(t('Enabled'), ENT_QUOTES, 'UTF-8'); ?></span>
                            — <?php echo htmlspecialchars(t('Your account is protected with two-factor authentication.'), ENT_QUOTES, 'UTF-8'); ?>
                        <?php else: ?>
                            <span style="color:#dc3545;font-weight:bold">&#10007; <?php echo htmlspecialchars(t('Disabled'), ENT_QUOTES, 'UTF-8'); ?></span>
                            — <?php echo htmlspecialchars(t('Enable 2FA to protect your account.'), ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </p>
                    <a href="<?php echo sURL; ?>TwoFactorAuth" class="btn">
                        <?php echo htmlspecialchars($this->twoFactorEnabled ? t('Manage 2FA') : t('Enable 2FA'), ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                </div>
            </div>

            <?php /*
             * A code by email — shown only where the application allows the method.
             *
             * Deliberately below the authenticator card and worded as the weaker option,
             * because it is one: mail is a channel somebody else may already be reading.
             * It is offered at all because it is the only second factor that can be turned
             * on by an account that has set nothing up in advance, which is most accounts.
             *
             * The password is asked for in the same form. Attaching a second factor is a
             * change to how the account authenticates, so a borrowed session must not be
             * able to make it — in either direction.
             */ ?>
            <?php if (!empty($this->emailFactorOffered)): ?>
            <div class="card" style="margin-bottom:20px">
                <div class="card-header"><strong><?php echo htmlspecialchars(t('Sign-in codes by email'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="card-body" style="padding:16px">
                    <p style="margin:0 0 12px">
                        <?php if (!empty($this->emailFactorEnabled)): ?>
                            <span class="badge badge-success"><?php echo htmlspecialchars(t('On'), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php echo htmlspecialchars(t('We email you a code when you sign in.'), ENT_QUOTES, 'UTF-8'); ?>
                        <?php else: ?>
                            <span class="badge"><?php echo htmlspecialchars(t('Off'), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php echo htmlspecialchars(t('Weaker than an authenticator app, and better than a password alone.'), ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </p>
                    <form method="POST" action="<?php echo sURL . $routeBase; ?>/emailfactor"
                          style="display:flex;gap:8px;flex-wrap:wrap">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <input type="hidden" name="enable" value="<?php echo empty($this->emailFactorEnabled) ? '1' : '0'; ?>">
                        <input id="password" type="password" name="password" required autocomplete="current-password" enterkeyhint="go"
                               style="flex:1;min-width:180px;padding:8px;border:1px solid #ccc;border-radius:4px"
                               placeholder="<?php echo htmlspecialchars(t('Your password'), ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo \Pramnos\Html\PasswordToggle::render(
                            'password', '', ''
                        ); ?>
                        <button type="submit" class="btn btn-sm">
                            <?php echo htmlspecialchars(empty($this->emailFactorEnabled) ? t('Turn on') : t('Turn off'), ENT_QUOTES, 'UTF-8'); ?>
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Passkeys -->
            <div class="card" style="margin-bottom:16px">
                <div class="card-header"><strong><?php echo htmlspecialchars(t('Passkeys'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="card-body pf-row-split">
                    <p style="margin:0;font-size:.9em;color:#666">
                        <?php echo htmlspecialchars(t('Sign in without a password using your device\'s fingerprint, face or screen lock.'), ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <a href="<?php echo sURL; ?>Passkey" class="btn"><?php echo htmlspecialchars(t('Manage Passkeys'), ENT_QUOTES, 'UTF-8'); ?></a>
                </div>
            </div>

            <!-- Change password -->
            <div class="card" style="margin-bottom:16px">
                <div class="card-body pf-row-split">
                    <p style="margin:0;font-size:.9em;color:#666">
                        <?php /* Not "change it regularly": routine rotation is advice that has been
                     withdrawn by the people who used to give it (NIST SP 800-63B), because
                     forced changes produce predictable variations of one password and get
                     written down. Change it when there is a reason to. */ ?>
                        <?php echo htmlspecialchars(t('Change it if you think somebody else knows it, or if you have used it anywhere else.'), ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <a href="<?php echo sURL . $routeBase; ?>/changepassword" class="btn"><?php echo htmlspecialchars(t('Change Password'), ENT_QUOTES, 'UTF-8'); ?></a>
                </div>
            </div>

            <!-- Trusted devices: "don't ask again on this device", each revocable -->
            <?php /* Shown whenever the site offers it, so a page that says "your trusted devices" has
                     them — or says how a device becomes one. */ ?>
            <?php if (!empty($this->trustedDevicesOffered)): ?>
            <div class="card" style="margin-bottom:16px">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
                    <strong><?php echo htmlspecialchars(t('Trusted devices'), ENT_QUOTES, 'UTF-8'); ?></strong>
<?php if (!empty($this->trustedDevices)): ?>
                    <form method="post" action="<?php echo sURL . $routeBase; ?>/revokedevice" style="margin:0">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <input type="hidden" name="all" value="1">
                        <button type="submit" class="btn btn-sm" style="background:transparent;color:#b91c1c;border-color:transparent"><?php echo htmlspecialchars(t('Forget all'), ENT_QUOTES, 'UTF-8'); ?></button>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="card-body" style="padding:0">
                    <p style="padding:12px 16px 0;margin:0;font-size:.85em;color:#666"><?php echo htmlspecialchars(t('These skip the second step when you sign in, and can approve your sign-ins elsewhere.'), ENT_QUOTES, 'UTF-8'); ?></p>
                <?php if (empty($this->trustedDevices)): ?>
                    <p style="padding:12px 16px;margin:0;font-size:.9em;color:#444"><?php echo htmlspecialchars(t('No device is trusted yet. When you sign in with your second step, leave “Don\'t ask again on this device” ticked, and that device will appear here.'), ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
                    <table style="width:100%;border-collapse:collapse;font-size:.9em">
                        <tbody>
                        <?php foreach ($this->trustedDevices as $d): ?>
                            <tr style="border-bottom:1px solid #f5f5f5">
                                <td style="padding:10px 16px">
                                    <div><?php echo htmlspecialchars((string) ($d['name'] ?? t('A browser'))); ?></div>
                                    <small style="color:#888">
                                        <?php echo htmlspecialchars(t('Trusted %s · until %s', localDateTime((int) ($d['created_at'] ?? 0)), localDateTime((int) ($d['expires_at'] ?? 0))), ENT_QUOTES, 'UTF-8'); ?>
                                    </small>
                                </td>
                                <td style="padding:10px 16px;text-align:right;white-space:nowrap">
                                    <?php if (!empty($d['is_current'])): ?>
                                        <span style="color:#28a745;font-size:.85em;margin-right:8px"><?php echo htmlspecialchars(t('This device'), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                    <form method="post" action="<?php echo sURL . $routeBase; ?>/revokedevice" style="margin:0;display:inline">
                                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                                        <input type="hidden" name="device" value="<?php echo (int) ($d['device_id'] ?? 0); ?>">
                                        <button type="submit" class="btn btn-sm"><?php echo htmlspecialchars(t('Forget'), ENT_QUOTES, 'UTF-8'); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Active sessions -->
            <div class="card" style="margin-bottom:16px">
                <div class="card-header"><strong><?php echo htmlspecialchars(t('Active Sessions'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="card-body" style="padding:0">
                    <?php if (empty($this->activeSessions)): ?>
                        <p style="padding:12px 16px;color:#666;margin:0"><?php echo htmlspecialchars(t('No active sessions.'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php else: ?>
                        <table style="width:100%;border-collapse:collapse;font-size:.9em">
                            <tbody>
                            <?php foreach ($this->activeSessions as $s): ?>
                                <?php $isCurrent = (string) ($s['sid'] ?? '') === (string) ($this->currentSid ?? ''); ?>
                                <tr style="border-bottom:1px solid #f5f5f5">
                                    <td style="padding:10px 16px">
                                        <div><?php echo htmlspecialchars(substr((string) ($s['agent'] ?? t('Unknown device')), 0, 80)); ?></div>
                                        <small style="color:#888">
                                            <?php echo htmlspecialchars((string) ($s['host_addr'] ?? '')); ?>
                                            · <?php echo htmlspecialchars(localDateTime( (int) ($s['time'] ?? 0))); ?>
                                        </small>
                                    </td>
                                    <td style="padding:10px 16px;text-align:right">
                                        <?php if ($isCurrent): ?>
                                            <span style="color:#28a745;font-size:.85em"><?php echo htmlspecialchars(t('This device'), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php else: ?>
                                            <form method="post" action="<?php echo sURL . $routeBase; ?>/revokesession" style="margin:0">
                                                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                                                <input type="hidden" name="sid" value="<?php echo htmlspecialchars((string) ($s['sid'] ?? '')); ?>">
                                                <button type="submit" class="btn btn-sm"><?php echo htmlspecialchars(t('Sign out'), ENT_QUOTES, 'UTF-8'); ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Activity log -->
            <div class="card">
                <div class="card-header"><strong><?php echo htmlspecialchars(t('Recent Account Activity'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="card-body" style="padding:0">
                    <?php if (empty($this->recentActivity)): ?>
                        <p style="padding:12px 16px;color:#666;margin:0"><?php echo htmlspecialchars(t('No activity recorded yet.'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php else: ?>
                        <table style="width:100%;border-collapse:collapse;font-size:.9em">
                            <thead style="background:#f8f8f8">
                                <tr>
                                    <th style="text-align:left;padding:10px 16px;border-bottom:1px solid #eee"><?php echo htmlspecialchars(t('Event'), ENT_QUOTES, 'UTF-8'); ?></th>
                                    <th style="text-align:left;padding:10px 16px;border-bottom:1px solid #eee"><?php echo htmlspecialchars(t('Date'), ENT_QUOTES, 'UTF-8'); ?></th>
                                    <th style="text-align:left;padding:10px 16px;border-bottom:1px solid #eee"><?php echo htmlspecialchars(t('IP Address'), ENT_QUOTES, 'UTF-8'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($this->recentActivity as $entry): ?>
                                    <tr style="border-bottom:1px solid #f5f5f5">
                                        <td style="padding:10px 16px"><?php echo htmlspecialchars($entry['action']); ?></td>
                                        <td style="padding:10px 16px;color:#888;font-size:.85em"><?php echo htmlspecialchars($entry['created_at']); ?></td>
                                        <td style="padding:10px 16px;color:#888;font-size:.85em"><?php echo htmlspecialchars($entry['ip_address'] ?? '—'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div>
