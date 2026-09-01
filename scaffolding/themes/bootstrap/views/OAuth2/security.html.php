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
    <h2 class="mb-4">Security Overview</h2>

    <?php if ($this->hasMessages()): ?>
        <div class="alert alert-success"><?php echo $this->_printMessages(); ?></div>
    <?php endif; ?>
    <?php if ($this->hasErrors()): ?>
        <div class="alert alert-danger"><?php echo $this->_printErrors(); ?></div>
    <?php endif; ?>

    <div class="row g-4">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="col-lg-9 col-md-8">
            <!-- 2FA status -->
            <div class="card mb-4">
                <div class="card-header fw-semibold">Two-Factor Authentication</div>
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <?php if ($this->twoFactorEnabled): ?>
                            <span class="badge bg-success me-2">Enabled</span>
                            Your account is protected with two-factor authentication.
                        <?php else: ?>
                            <span class="badge bg-warning text-dark me-2">Disabled</span>
                            Protect your account by enabling two-factor authentication.
                        <?php endif; ?>
                    </div>
                    <a href="<?php echo sURL; ?>TwoFactorAuth" class="btn btn-sm btn-outline-secondary">
                        <?php echo $this->twoFactorEnabled ? 'Manage' : 'Enable'; ?> 2FA
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
                <div class="card-header fw-semibold">Sign-in codes by email</div>
                <div class="card-body">
                    <p class="mb-3">
                        <?php if (!empty($this->emailFactorEnabled)): ?>
                            <span class="badge bg-success me-2">On</span>
                            We email you a code when you sign in.
                        <?php else: ?>
                            <span class="badge bg-secondary me-2">Off</span>
                            Weaker than an authenticator app, and better than a password alone.
                        <?php endif; ?>
                    </p>
                    <form method="POST" action="<?php echo sURL . $routeBase; ?>/emailfactor" class="row g-2">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <input type="hidden" name="enable" value="<?php echo empty($this->emailFactorEnabled) ? '1' : '0'; ?>">
                        <div class="col-sm-8">
                            <input id="password" type="password" name="password" required autocomplete="current-password" enterkeyhint="go"
                                   class="form-control form-control-sm" placeholder="Your password">
                            <?php echo \Pramnos\Html\PasswordToggle::render(
                                'password', '', ''
                            ); ?>
                        </div>
                        <div class="col-sm-4">
                            <button type="submit" class="btn btn-sm w-100 <?php echo empty($this->emailFactorEnabled) ? 'btn-primary' : 'btn-outline-secondary'; ?>">
                                <?php echo empty($this->emailFactorEnabled) ? 'Turn on' : 'Turn off'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Passkeys -->
            <div class="card mb-4">
                <div class="card-header fw-semibold">Passkeys</div>
                <div class="card-body d-flex justify-content-between align-items-center">
                    <span>Sign in without a password using your device's fingerprint, face or screen lock.</span>
                    <a href="<?php echo sURL; ?>Passkey" class="btn btn-sm btn-outline-primary">Manage Passkeys</a>
                </div>
            </div>

            <!-- Change password -->
            <div class="card mb-4">
                <div class="card-header fw-semibold">Password</div>
                <div class="card-body d-flex justify-content-between align-items-center">
                    <?php /* Not "change it regularly": routine rotation is advice that has been
                     withdrawn by the people who used to give it (NIST SP 800-63B), because
                     forced changes produce predictable variations of one password and get
                     written down. Change it when there is a reason to. */ ?>
                    <span>Change it if you think somebody else knows it, or if you have used it anywhere else.</span>
                    <a href="<?php echo sURL . $routeBase; ?>/changepassword" class="btn btn-sm btn-outline-primary">
                        Change Password
                    </a>
                </div>
            </div>

            <!-- Active sessions -->
            <div class="card mb-4">
                <div class="card-header fw-semibold">Active Sessions</div>
                <?php if (empty($this->activeSessions)): ?>
                    <div class="card-body text-muted">No active sessions.</div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($this->activeSessions as $s): ?>
                            <?php $isCurrent = (string) ($s['sid'] ?? '') === (string) ($this->currentSid ?? ''); ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <div><?php echo htmlspecialchars(substr((string) ($s['agent'] ?? 'Unknown device'), 0, 80)); ?></div>
                                    <small class="text-muted">
                                        <?php echo htmlspecialchars((string) ($s['host_addr'] ?? '')); ?>
                                        &middot; <?php echo htmlspecialchars(localDateTime( (int) ($s['time'] ?? 0))); ?>
                                    </small>
                                </div>
                                <?php if ($isCurrent): ?>
                                    <span class="badge bg-success">This device</span>
                                <?php else: ?>
                                    <form method="post" action="<?php echo sURL . $routeBase; ?>/revokesession" class="m-0">
                                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                                        <input type="hidden" name="sid" value="<?php echo htmlspecialchars((string) ($s['sid'] ?? '')); ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Sign out</button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- Recent activity -->
            <div class="card">
                <div class="card-header fw-semibold">Recent Account Activity</div>
                <?php if (empty($this->recentActivity)): ?>
                    <div class="card-body text-muted">No activity recorded yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Event</th>
                                    <th>Date</th>
                                    <th>IP Address</th>
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
