<?php
/**
 * Security Overview page (Tailwind theme).
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
<div class="container mx-auto px-4 py-8">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2 class="text-2xl font-bold text-base-content mb-6"><?php echo htmlspecialchars(t('Security Overview'), ENT_QUOTES, 'UTF-8'); ?></h2>

    <?php if ($this->hasMessages()): ?>
        <div role="status" class="alert alert-success mb-4">
            <?php echo $this->_printMessages(); ?>
        </div>
    <?php endif; ?>
    <?php if ($this->hasErrors()): ?>
        <div role="alert" class="alert alert-error mb-4">
            <?php echo $this->_printErrors(); ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="md:col-span-3 space-y-4">
            <!-- 2FA -->
            <div class="card bg-base-100 shadow-sm">
                <div class="px-4 py-3 border-b border-base-300 font-semibold text-base-content">
                    <?php echo htmlspecialchars(t('Two-Factor Authentication'), ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="flex flex-col items-start gap-2 sm:flex-row sm:items-center sm:justify-between px-4 py-4">
                    <div>
                        <?php if ($this->twoFactorEnabled): ?>
                            <span class="badge badge-success badge-sm mr-2">
                                <?php echo htmlspecialchars(t('Enabled'), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span class="text-sm text-base-content/80"><?php echo htmlspecialchars(t('Your account is protected with two-factor authentication.'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php else: ?>
                            <span class="badge badge-warning badge-sm mr-2">
                                <?php echo htmlspecialchars(t('Disabled'), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span class="text-sm text-base-content/80"><?php echo htmlspecialchars(t('Enable 2FA to protect your account.'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>
                    <a href="<?php echo sURL; ?>TwoFactorAuth"
                       class="text-sm text-primary hover:underline whitespace-nowrap">
                        <?php echo htmlspecialchars($this->twoFactorEnabled ? t('Manage 2FA') : t('Enable 2FA'), ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                </div>
            </div>

            <?php /*
             * A code by email — shown only where the application allows the method.
             *
             * The first version of this card said "Off — weaker than an authenticator app"
             * over a bare password box, and a reader could not tell what the switch *did*:
             * a badge, a comparison to a thing they may not have, and a password field with
             * no stated reason. So it now says what happens (a code arrives by email at
             * sign-in), what it costs (one extra step), and why the password is being asked
             * for (it changes how the account authenticates, so a borrowed session must not
             * be able to do it — in either direction).
             *
             * Still below the authenticator card and still described as the weaker option,
             * because it is one: mail is a channel somebody else may already be reading.
             */ ?>
            <?php if (!empty($this->emailFactorOffered)): ?>
            <?php
            $emailOn      = !empty($this->emailFactorEnabled);
            $emailPending = !empty($this->emailFactorPending);
            $emailAction  = sURL . $routeBase . '/emailfactor';
            $emailWait    = (int) ($this->emailFactorResendIn ?? 0);
            ?>
            <div class="card bg-base-100 shadow-sm">
                <div class="flex items-center gap-2 px-4 py-3 border-b border-base-300">
                    <span class="font-semibold text-base-content"><?php echo htmlspecialchars(t('Sign-in code by email'), ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="badge badge-sm <?php echo $emailOn ? 'badge-success' : ($emailPending ? 'badge-warning' : 'badge-ghost'); ?>">
                        <?php echo htmlspecialchars($emailOn ? t('On') : ($emailPending ? t('Waiting for the code') : t('Off')), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
                <div class="px-4 py-4 space-y-3">
                    <p class="text-sm text-base-content/80">
                        <?php if ($emailOn): ?>
                            <?php echo htmlspecialchars(t('When you sign in, we email you a six-digit code and ask for it before letting you in. Somebody who learns your password still cannot sign in without your mailbox.'), ENT_QUOTES, 'UTF-8'); ?>
                        <?php else: ?>
                            <?php echo htmlspecialchars(t('Turn this on and we will email you a six-digit code when you sign in, and ask for it before letting you in — so a password on its own is not enough to reach your account.'), ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </p>
                    <p class="text-xs text-base-content/60">
                        <?php echo htmlspecialchars(t('An authenticator app is stronger, because email is a channel somebody else may be able to read. This needs nothing installed.'), ENT_QUOTES, 'UTF-8'); ?>
                    </p>

                    <?php /*
                     * Three states, and the middle one is why this is not one button.
                     *
                     * Turning it *on* is verified by email rather than by password: a password
                     * proves who is asking, not that the address on the account is one they can
                     * still read. Attaching the factor to a stale address would build a lockout
                     * on purpose — every later sign-in from a new device would wait for a code
                     * arriving somewhere nobody reads. So the card mails a code and asks for it
                     * back, exactly as enrolling an authenticator app asks for a code from the
                     * app.
                     *
                     * Turning it *off* asks for the password instead: removing a factor is the
                     * direction an attacker wants, and demanding a mailed code to switch it off
                     * would strand the one person who most needs to — somebody whose mailbox has
                     * become unreachable.
                     */ ?>
                    <?php if ($emailOn): ?>
                    <form method="POST" action="<?php echo $emailAction; ?>" class="pt-1 border-t border-base-300">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <input type="hidden" name="enable" value="0">
                        <label for="emailfactor-password" class="block text-sm mt-3 mb-1">
                            <?php echo htmlspecialchars(t('Confirm with your password to turn this off'), ENT_QUOTES, 'UTF-8'); ?>
                        </label>
                        <div class="flex flex-col sm:flex-row gap-2">
                            <input type="password" id="emailfactor-password" name="password" required
                                   autocomplete="current-password" enterkeyhint="go" class="input input-sm flex-1"
                                   placeholder="<?php echo htmlspecialchars(t('Your account password'), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo \Pramnos\Html\PasswordToggle::render(
                                'emailfactor-password', '', ''
                            ); ?>
                            <button type="submit" class="btn btn-sm btn-neutral"><?php echo htmlspecialchars(t('Turn off'), ENT_QUOTES, 'UTF-8'); ?></button>
                        </div>
                    </form>

                    <?php elseif ($emailPending): ?>
                    <form method="POST" action="<?php echo $emailAction; ?>" class="pt-1 border-t border-base-300">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <label for="emailfactor-code" class="block text-sm mt-3 mb-1">
                            <?php echo htmlspecialchars(t('Enter the code we emailed you'), ENT_QUOTES, 'UTF-8'); ?>
                        </label>
                        <div class="flex flex-col sm:flex-row gap-2">
                            <input type="text" id="emailfactor-code" name="code" required
                                   inputmode="numeric" maxlength="6" pattern="[0-9]{6}"
                                   autocomplete="one-time-code" enterkeyhint="go" class="input input-sm flex-1 font-mono tracking-widest"
                                   placeholder="000000">
                            <button type="submit" class="btn btn-sm btn-primary"><?php echo htmlspecialchars(t('Finish'), ENT_QUOTES, 'UTF-8'); ?></button>
                        </div>
                    </form>
                    <form method="POST" action="<?php echo $emailAction; ?>">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <button type="submit" class="btn btn-ghost btn-xs"
                                <?php if ($emailWait > 0): ?>
                                disabled
                                data-pf-countdown="<?php echo $emailWait; ?>"
                                data-pf-countdown-label="<?php echo htmlspecialchars(t('Another code in %ss'), ENT_QUOTES, 'UTF-8'); ?>"
                                data-pf-countdown-ready="<?php echo htmlspecialchars(t('Send another code'), ENT_QUOTES, 'UTF-8'); ?>"
                                <?php endif; ?>>
                            <?php echo htmlspecialchars($emailWait > 0
                                ? t('Another code in %ss', $emailWait)
                                : t('Send another code'), ENT_QUOTES, 'UTF-8'); ?>
                        </button>
                    </form>

                    <?php else: ?>
                    <form method="POST" action="<?php echo $emailAction; ?>" class="pt-1 border-t border-base-300">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <p class="text-sm mt-3 mb-2">
                            <?php echo htmlspecialchars(t('We will email a code to the address on your profile to check it reaches you, then turn this on.'), ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                        <button type="submit" class="btn btn-sm btn-primary"
                                <?php if ($emailWait > 0): ?>
                                disabled
                                data-pf-countdown="<?php echo $emailWait; ?>"
                                data-pf-countdown-label="<?php echo htmlspecialchars(t('Try again in %ss'), ENT_QUOTES, 'UTF-8'); ?>"
                                data-pf-countdown-ready="<?php echo htmlspecialchars(t('Email me a code'), ENT_QUOTES, 'UTF-8'); ?>"
                                <?php endif; ?>>
                            <?php echo htmlspecialchars($emailWait > 0
                                ? t('Try again in %ss', $emailWait)
                                : t('Email me a code'), ENT_QUOTES, 'UTF-8'); ?>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Passkeys -->
            <div class="card bg-base-100 shadow-sm">
                <div class="flex flex-col items-start gap-2 sm:flex-row sm:items-center sm:justify-between px-4 py-4">
                    <span class="text-sm text-base-content/80"><?php echo htmlspecialchars(t("Sign in without a password using your device's fingerprint, face or screen lock."), ENT_QUOTES, 'UTF-8'); ?></span>
                    <a href="<?php echo sURL; ?>Passkey"
                       class="text-sm text-primary hover:underline whitespace-nowrap">
                        <?php echo htmlspecialchars(t('Manage Passkeys'), ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                </div>
            </div>

            <!-- Change password -->
            <div class="card bg-base-100 shadow-sm">
                <div class="flex flex-col items-start gap-2 sm:flex-row sm:items-center sm:justify-between px-4 py-4">
                    <?php /* Not "change it regularly": routine rotation is advice that has been
                     withdrawn by the people who used to give it (NIST SP 800-63B), because
                     forced changes produce predictable variations of one password and get
                     written down. Change it when there is a reason to. */ ?>
                    <span class="text-sm text-base-content/80"><?php echo htmlspecialchars(t('Change it if you think somebody else knows it, or if you have used it anywhere else.'), ENT_QUOTES, 'UTF-8'); ?></span>
                    <a href="<?php echo sURL . $routeBase; ?>/changepassword"
                       class="text-sm text-primary hover:underline whitespace-nowrap">
                        <?php echo htmlspecialchars(t('Change Password'), ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                </div>
            </div>

            <!-- Trusted devices: "don't ask again on this device", each revocable -->
            <?php /* Shown whenever the site offers it, so a page that says "your trusted devices" has
                     them — or says how a device becomes one. */ ?>
            <?php if (!empty($this->trustedDevicesOffered)): ?>
            <div class="card bg-base-100 shadow-sm">
                <div class="px-4 py-3 border-b border-base-300 flex items-center justify-between">
                    <span class="font-semibold text-base-content"><?php echo htmlspecialchars(t('Trusted devices'), ENT_QUOTES, 'UTF-8'); ?></span>
<?php if (!empty($this->trustedDevices)): ?>
                    <form method="post" action="<?php echo sURL . $routeBase; ?>/revokedevice" class="m-0">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <input type="hidden" name="all" value="1">
                        <button type="submit" class="text-sm text-error hover:underline"><?php echo htmlspecialchars(t('Forget all'), ENT_QUOTES, 'UTF-8'); ?></button>
                    </form>
                    <?php endif; ?>
                </div>
                <p class="px-4 pt-3 text-xs text-base-content/60">
                    <?php echo htmlspecialchars(t('These skip the second step when you sign in, and can approve your sign-ins elsewhere.'), ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <?php if (empty($this->trustedDevices)): ?>
                <p class="px-4 py-3 text-sm text-base-content/70"><?php echo htmlspecialchars(t("No device is trusted yet. When you sign in with your second step, leave “Don't ask again on this device” ticked, and that device will appear here."), ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
                <ul class="divide-y divide-base-200">
                    <?php foreach ($this->trustedDevices as $d): ?>
                        <li class="flex flex-col items-start gap-2 sm:flex-row sm:items-center sm:justify-between px-4 py-3">
                            <div>
                                <div class="text-sm text-base-content"><?php echo htmlspecialchars((string) ($d['name'] ?? t('A browser'))); ?></div>
                                <div class="text-xs text-base-content/60">
                                    <?php echo htmlspecialchars(t('Trusted %s · until %s', localDateTime((int) ($d['created_at'] ?? 0)), localDateTime((int) ($d['expires_at'] ?? 0))), ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </div>
                            <?php if (!empty($d['is_current'])): ?>
                                <span class="badge badge-success badge-sm"><?php echo htmlspecialchars(t('This device'), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                            <form method="post" action="<?php echo sURL . $routeBase; ?>/revokedevice" class="m-0">
                                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                                <input type="hidden" name="device" value="<?php echo (int) ($d['device_id'] ?? 0); ?>">
                                <button type="submit" class="text-sm text-error hover:underline"><?php echo htmlspecialchars(t('Forget'), ENT_QUOTES, 'UTF-8'); ?></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- Active sessions -->
            <div class="card bg-base-100 shadow-sm">
                <div class="px-4 py-3 border-b border-base-300 font-semibold text-base-content">
                    <?php echo htmlspecialchars(t('Active Sessions'), ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <?php if (empty($this->activeSessions)): ?>
                    <p class="px-4 py-4 text-sm text-base-content/60"><?php echo htmlspecialchars(t('No active sessions.'), ENT_QUOTES, 'UTF-8'); ?></p>
                <?php else: ?>
                    <ul class="divide-y divide-base-200">
                        <?php foreach ($this->activeSessions as $s): ?>
                            <?php $isCurrent = (string) ($s['sid'] ?? '') === (string) ($this->currentSid ?? ''); ?>
                            <li class="flex flex-col items-start gap-2 sm:flex-row sm:items-center sm:justify-between px-4 py-3">
                                <div>
                                    <div class="text-sm text-base-content"><?php echo htmlspecialchars(substr((string) ($s['agent'] ?? t('Unknown device')), 0, 80)); ?></div>
                                    <div class="text-xs text-base-content/60">
                                        <?php echo htmlspecialchars((string) ($s['host_addr'] ?? '')); ?>
                                        &middot; <?php echo htmlspecialchars(localDateTime( (int) ($s['time'] ?? 0))); ?>
                                    </div>
                                </div>
                                <?php if ($isCurrent): ?>
                                    <span class="badge badge-success badge-sm"><?php echo htmlspecialchars(t('This device'), ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php else: ?>
                                    <form method="post" action="<?php echo sURL . $routeBase; ?>/revokesession" class="m-0">
                                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                                        <input type="hidden" name="sid" value="<?php echo htmlspecialchars((string) ($s['sid'] ?? '')); ?>">
                                        <button type="submit" class="text-sm text-error hover:underline"><?php echo htmlspecialchars(t('Sign out'), ENT_QUOTES, 'UTF-8'); ?></button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- Activity log -->
            <div class="card bg-base-100 shadow-sm">
                <div class="px-4 py-3 border-b border-base-300 font-semibold text-base-content">
                    <?php echo htmlspecialchars(t('Recent Account Activity'), ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <?php if (empty($this->recentActivity)): ?>
                    <p class="px-4 py-4 text-sm text-base-content/60"><?php echo htmlspecialchars(t('No activity recorded yet.'), ENT_QUOTES, 'UTF-8'); ?></p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="table table-sm text-sm">
                            <thead class="bg-base-200 text-xs font-semibold text-base-content/70 uppercase tracking-wide">
                                <tr>
                                    <th class="px-4 py-3 text-left"><?php echo htmlspecialchars(t('Event'), ENT_QUOTES, 'UTF-8'); ?></th>
                                    <th class="px-4 py-3 text-left"><?php echo htmlspecialchars(t('Date'), ENT_QUOTES, 'UTF-8'); ?></th>
                                    <th class="px-4 py-3 text-left"><?php echo htmlspecialchars(t('IP Address'), ENT_QUOTES, 'UTF-8'); ?></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-base-200">
                                <?php foreach ($this->recentActivity as $entry): ?>
                                    <tr class="hover:bg-base-200">
                                        <td class="px-4 py-3 text-base-content"><?php echo htmlspecialchars($entry['action']); ?></td>
                                        <td class="px-4 py-3 text-base-content/60"><?php echo htmlspecialchars($entry['created_at']); ?></td>
                                        <td class="px-4 py-3 text-base-content/60"><?php echo htmlspecialchars($entry['ip_address'] ?? '—'); ?></td>
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
