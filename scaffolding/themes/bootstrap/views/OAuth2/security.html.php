<?php
/**
 * Security Overview page (Bootstrap theme).
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
<div class="container py-4">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2 class="mb-4"><?php echo htmlspecialchars(t('Security Overview'), ENT_QUOTES, 'UTF-8'); ?></h2>

    <?php if ($this->hasMessages()): ?>
        <div role="status" class="alert alert-success"><?php echo $this->_printMessages(); ?></div>
    <?php endif; ?>
    <?php if ($this->hasErrors()): ?>
        <div role="alert" class="alert alert-danger"><?php echo $this->_printErrors(); ?></div>
    <?php endif; ?>

    <div class="row g-4">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="col-lg-9 col-md-8">
            <!-- 2FA status -->
            <div class="card mb-4">
                <div class="card-header fw-semibold"><?php echo htmlspecialchars(t('Two-Factor Authentication'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="card-body d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-between gap-2">
                    <div>
                        <?php if ($this->twoFactorEnabled): ?>
                            <span class="badge bg-success me-2"><?php echo htmlspecialchars(t('Enabled'), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php echo htmlspecialchars(t('Your account is protected with two-factor authentication.'), ENT_QUOTES, 'UTF-8'); ?>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark me-2"><?php echo htmlspecialchars(t('Disabled'), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php echo htmlspecialchars(t('Protect your account by enabling two-factor authentication.'), ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </div>
                    <a href="<?php echo sURL; ?>TwoFactorAuth" class="btn btn-sm btn-outline-secondary">
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
            <div class="card mb-4">
                <div class="card-header fw-semibold"><?php echo htmlspecialchars(t('Sign-in codes by email'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="card-body">
                    <p class="mb-3">
                        <?php if (!empty($this->emailFactorEnabled)): ?>
                            <span class="badge bg-success me-2"><?php echo htmlspecialchars(t('On'), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php echo htmlspecialchars(t('We email you a code when you sign in.'), ENT_QUOTES, 'UTF-8'); ?>
                        <?php else: ?>
                            <span class="badge bg-secondary me-2"><?php echo htmlspecialchars(t('Off'), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php echo htmlspecialchars(t('Weaker than an authenticator app, and better than a password alone.'), ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </p>
                    <form method="POST" action="<?php echo sURL . $routeBase; ?>/emailfactor" class="row g-2">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <input type="hidden" name="enable" value="<?php echo empty($this->emailFactorEnabled) ? '1' : '0'; ?>">
                        <div class="col-sm-8">
                            <input id="password" type="password" name="password" required autocomplete="current-password" enterkeyhint="go"
                                   class="form-control form-control-sm" placeholder="<?php echo htmlspecialchars(t('Your password'), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo \Pramnos\Html\PasswordToggle::render(
                                'password', '', ''
                            ); ?>
                        </div>
                        <div class="col-sm-4">
                            <button type="submit" class="btn btn-sm w-100 <?php echo empty($this->emailFactorEnabled) ? 'btn-primary' : 'btn-outline-secondary'; ?>">
                                <?php echo htmlspecialchars(empty($this->emailFactorEnabled) ? t('Turn on') : t('Turn off'), ENT_QUOTES, 'UTF-8'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Passkeys -->
            <div class="card mb-4">
                <div class="card-header fw-semibold"><?php echo htmlspecialchars(t('Passkeys'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="card-body d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-between gap-2">
                    <span><?php echo htmlspecialchars(t('Sign in without a password using your device\'s fingerprint, face or screen lock.'), ENT_QUOTES, 'UTF-8'); ?></span>
                    <a href="<?php echo sURL; ?>Passkey" class="btn btn-sm btn-outline-primary"><?php echo htmlspecialchars(t('Manage Passkeys'), ENT_QUOTES, 'UTF-8'); ?></a>
                </div>
            </div>

            <!-- Change password -->
            <div class="card mb-4">
                <div class="card-header fw-semibold"><?php echo htmlspecialchars(t('Password'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="card-body d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-between gap-2">
                    <?php /* Not "change it regularly": routine rotation is advice that has been
                     withdrawn by the people who used to give it (NIST SP 800-63B), because
                     forced changes produce predictable variations of one password and get
                     written down. Change it when there is a reason to. */ ?>
                    <span><?php echo htmlspecialchars(t('Change it if you think somebody else knows it, or if you have used it anywhere else.'), ENT_QUOTES, 'UTF-8'); ?></span>
                    <a href="<?php echo sURL . $routeBase; ?>/changepassword" class="btn btn-sm btn-outline-primary">
                        <?php echo htmlspecialchars(t('Change Password'), ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                </div>
            </div>

            <!-- Trusted devices: "don't ask again on this device", each revocable -->
            <?php /* Shown whenever the site offers it, so a page that says "your trusted devices" has
                     them — or says how a device becomes one. */ ?>
            <?php if (!empty($this->trustedDevicesOffered)): ?>
            <div class="card mb-4">
                <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                    <span><?php echo htmlspecialchars(t('Trusted devices'), ENT_QUOTES, 'UTF-8'); ?></span>
<?php if (!empty($this->trustedDevices)): ?>
                    <form method="post" action="<?php echo sURL . $routeBase; ?>/revokedevice" class="m-0">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <input type="hidden" name="all" value="1">
                        <button type="submit" class="btn btn-sm btn-link text-danger p-0"><?php echo htmlspecialchars(t('Forget all'), ENT_QUOTES, 'UTF-8'); ?></button>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="card-body pb-0">
                    <p class="small text-muted mb-2"><?php echo htmlspecialchars(t('These skip the second step when you sign in, and can approve your sign-ins elsewhere.'), ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <?php if (empty($this->trustedDevices)): ?>
                <p class="card-body pt-0 small text-muted"><?php echo htmlspecialchars(t('No device is trusted yet. When you sign in with your second step, leave “Don\'t ask again on this device” ticked, and that device will appear here.'), ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($this->trustedDevices as $d): ?>
                        <li class="list-group-item d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-between gap-2">
                            <div>
                                <div><?php echo htmlspecialchars((string) ($d['name'] ?? t('A browser'))); ?></div>
                                <small class="text-muted">
                                    <?php echo htmlspecialchars(t('Trusted %s · until %s', localDateTime((int) ($d['created_at'] ?? 0)), localDateTime((int) ($d['expires_at'] ?? 0))), ENT_QUOTES, 'UTF-8'); ?>
                                </small>
                            </div>
                            <?php if (!empty($d['is_current'])): ?>
                                <span class="badge bg-success"><?php echo htmlspecialchars(t('This device'), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                            <form method="post" action="<?php echo sURL . $routeBase; ?>/revokedevice" class="m-0">
                                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                                <input type="hidden" name="device" value="<?php echo (int) ($d['device_id'] ?? 0); ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><?php echo htmlspecialchars(t('Forget'), ENT_QUOTES, 'UTF-8'); ?></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- Active sessions -->
            <div class="card mb-4">
                <div class="card-header fw-semibold"><?php echo htmlspecialchars(t('Active Sessions'), ENT_QUOTES, 'UTF-8'); ?></div>
                <?php if (empty($this->activeSessions)): ?>
                    <div class="card-body text-muted"><?php echo htmlspecialchars(t('No active sessions.'), ENT_QUOTES, 'UTF-8'); ?></div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($this->activeSessions as $s): ?>
                            <?php $isCurrent = (string) ($s['sid'] ?? '') === (string) ($this->currentSid ?? ''); ?>
                            <li class="list-group-item d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-between gap-2">
                                <div>
                                    <div><?php echo htmlspecialchars(substr((string) ($s['agent'] ?? t('Unknown device')), 0, 80)); ?></div>
                                    <small class="text-muted">
                                        <?php echo htmlspecialchars((string) ($s['host_addr'] ?? '')); ?>
                                        &middot; <?php echo htmlspecialchars(localDateTime( (int) ($s['time'] ?? 0))); ?>
                                    </small>
                                </div>
                                <?php if ($isCurrent): ?>
                                    <span class="badge bg-success"><?php echo htmlspecialchars(t('This device'), ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php else: ?>
                                    <form method="post" action="<?php echo sURL . $routeBase; ?>/revokesession" class="m-0">
                                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                                        <input type="hidden" name="sid" value="<?php echo htmlspecialchars((string) ($s['sid'] ?? '')); ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><?php echo htmlspecialchars(t('Sign out'), ENT_QUOTES, 'UTF-8'); ?></button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- Recent activity -->
            <div class="card">
                <div class="card-header fw-semibold"><?php echo htmlspecialchars(t('Recent Account Activity'), ENT_QUOTES, 'UTF-8'); ?></div>
                <?php if (empty($this->recentActivity)): ?>
                    <div class="card-body text-muted"><?php echo htmlspecialchars(t('No activity recorded yet.'), ENT_QUOTES, 'UTF-8'); ?></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th><?php echo htmlspecialchars(t('Event'), ENT_QUOTES, 'UTF-8'); ?></th>
                                    <th><?php echo htmlspecialchars(t('Date'), ENT_QUOTES, 'UTF-8'); ?></th>
                                    <th><?php echo htmlspecialchars(t('IP Address'), ENT_QUOTES, 'UTF-8'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($this->recentActivity as $entry): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($entry['action']); ?></td>
                                        <td class="text-muted small"><?php echo htmlspecialchars($entry['created_at']); ?></td>
                                        <td class="text-muted small"><?php echo htmlspecialchars($entry['ip_address'] ?? '—'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>
