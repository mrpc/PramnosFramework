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
    <h2>Security Overview</h2>

    <?php if ($this->hasMessages()): ?>
        <div class="alert alert-success"><?php echo $this->_printMessages(); ?></div>
    <?php endif; ?>
    <?php if ($this->hasErrors()): ?>
        <div class="alert alert-error"><?php echo $this->_printErrors(); ?></div>
    <?php endif; ?>

    <div class="account-grid">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div>
            <!-- 2FA status -->
            <div class="card" style="margin-bottom:16px">
                <div class="card-header"><strong>Two-Factor Authentication</strong></div>
                <div class="card-body" style="display:flex;justify-content:space-between;align-items:center">
                    <p style="margin:0">
                        <?php if ($this->twoFactorEnabled): ?>
                            <span style="color:#28a745;font-weight:bold">&#10003; Enabled</span>
                            — Your account is protected with two-factor authentication.
                        <?php else: ?>
                            <span style="color:#dc3545;font-weight:bold">&#10007; Disabled</span>
                            — Enable 2FA to protect your account.
                        <?php endif; ?>
                    </p>
                    <a href="<?php echo sURL; ?>TwoFactorAuth" class="btn">
                        <?php echo $this->twoFactorEnabled ? 'Manage 2FA' : 'Enable 2FA'; ?>
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
                <div class="card-header"><strong>Sign-in codes by email</strong></div>
                <div class="card-body" style="padding:16px">
                    <p style="margin:0 0 12px">
                        <?php if (!empty($this->emailFactorEnabled)): ?>
                            <span class="badge badge-success">On</span>
                            We email you a code when you sign in.
                        <?php else: ?>
                            <span class="badge">Off</span>
                            Weaker than an authenticator app, and better than a password alone.
                        <?php endif; ?>
                    </p>
                    <form method="POST" action="<?php echo sURL . $routeBase; ?>/emailfactor"
                          style="display:flex;gap:8px;flex-wrap:wrap">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <input type="hidden" name="enable" value="<?php echo empty($this->emailFactorEnabled) ? '1' : '0'; ?>">
                        <input type="password" name="password" required autocomplete="current-password"
                               style="flex:1;min-width:180px;padding:8px;border:1px solid #ccc;border-radius:4px"
                               placeholder="Your password">
                        <button type="submit" class="btn btn-sm">
                            <?php echo empty($this->emailFactorEnabled) ? 'Turn on' : 'Turn off'; ?>
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Passkeys -->
            <div class="card" style="margin-bottom:16px">
                <div class="card-header"><strong>Passkeys</strong></div>
                <div class="card-body" style="display:flex;justify-content:space-between;align-items:center">
                    <p style="margin:0;font-size:.9em;color:#666">
                        Sign in without a password using your device's fingerprint, face or screen lock.
                    </p>
                    <a href="<?php echo sURL; ?>Passkey" class="btn">Manage Passkeys</a>
                </div>
            </div>

            <!-- Change password -->
            <div class="card" style="margin-bottom:16px">
                <div class="card-body" style="display:flex;justify-content:space-between;align-items:center">
                    <p style="margin:0;font-size:.9em;color:#666">
                        <?php /* Not "change it regularly": routine rotation is advice that has been
                     withdrawn by the people who used to give it (NIST SP 800-63B), because
                     forced changes produce predictable variations of one password and get
                     written down. Change it when there is a reason to. */ ?>
                        Change it if you think somebody else knows it, or if you have used it anywhere else.
                    </p>
                    <a href="<?php echo sURL . $routeBase; ?>/changepassword" class="btn">Change Password</a>
                </div>
            </div>

            <!-- Active sessions -->
            <div class="card" style="margin-bottom:16px">
                <div class="card-header"><strong>Active Sessions</strong></div>
                <div class="card-body" style="padding:0">
                    <?php if (empty($this->activeSessions)): ?>
                        <p style="padding:12px 16px;color:#666;margin:0">No active sessions.</p>
                    <?php else: ?>
                        <table style="width:100%;border-collapse:collapse;font-size:.9em">
                            <tbody>
                            <?php foreach ($this->activeSessions as $s): ?>
                                <?php $isCurrent = (string) ($s['sid'] ?? '') === (string) ($this->currentSid ?? ''); ?>
                                <tr style="border-bottom:1px solid #f5f5f5">
                                    <td style="padding:10px 16px">
                                        <div><?php echo htmlspecialchars(substr((string) ($s['agent'] ?? 'Unknown device'), 0, 80)); ?></div>
                                        <small style="color:#888">
                                            <?php echo htmlspecialchars((string) ($s['host_addr'] ?? '')); ?>
                                            · <?php echo htmlspecialchars(date('Y-m-d H:i', (int) ($s['time'] ?? 0))); ?>
                                        </small>
                                    </td>
                                    <td style="padding:10px 16px;text-align:right">
                                        <?php if ($isCurrent): ?>
                                            <span style="color:#28a745;font-size:.85em">This device</span>
                                        <?php else: ?>
                                            <form method="post" action="<?php echo sURL . $routeBase; ?>/revokesession" style="margin:0">
                                                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                                                <input type="hidden" name="sid" value="<?php echo htmlspecialchars((string) ($s['sid'] ?? '')); ?>">
                                                <button type="submit" class="btn btn-sm">Sign out</button>
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
                <div class="card-header"><strong>Recent Account Activity</strong></div>
                <div class="card-body" style="padding:0">
                    <?php if (empty($this->recentActivity)): ?>
                        <p style="padding:12px 16px;color:#666;margin:0">No activity recorded yet.</p>
                    <?php else: ?>
                        <table style="width:100%;border-collapse:collapse;font-size:.9em">
                            <thead style="background:#f8f8f8">
                                <tr>
                                    <th style="text-align:left;padding:10px 16px;border-bottom:1px solid #eee">Event</th>
                                    <th style="text-align:left;padding:10px 16px;border-bottom:1px solid #eee">Date</th>
                                    <th style="text-align:left;padding:10px 16px;border-bottom:1px solid #eee">IP Address</th>
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
