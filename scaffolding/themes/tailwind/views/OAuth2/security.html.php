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
    <h2 class="text-2xl font-bold text-base-content mb-6">Security Overview</h2>

    <?php if ($this->hasMessages()): ?>
        <div class="alert alert-success mb-4">
            <?php echo $this->_printMessages(); ?>
        </div>
    <?php endif; ?>
    <?php if ($this->hasErrors()): ?>
        <div class="alert alert-error mb-4">
            <?php echo $this->_printErrors(); ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="md:col-span-3 space-y-4">
            <!-- 2FA -->
            <div class="card bg-base-100 shadow-sm">
                <div class="px-4 py-3 border-b border-base-300 font-semibold text-base-content">
                    Two-Factor Authentication
                </div>
                <div class="flex items-center justify-between px-4 py-4">
                    <div>
                        <?php if ($this->twoFactorEnabled): ?>
                            <span class="badge badge-success badge-sm mr-2">
                                Enabled
                            </span>
                            <span class="text-sm text-base-content/80">Your account is protected with two-factor authentication.</span>
                        <?php else: ?>
                            <span class="badge badge-warning badge-sm mr-2">
                                Disabled
                            </span>
                            <span class="text-sm text-base-content/80">Enable 2FA to protect your account.</span>
                        <?php endif; ?>
                    </div>
                    <a href="<?php echo sURL; ?>TwoFactorAuth"
                       class="text-sm text-primary hover:underline whitespace-nowrap">
                        <?php echo $this->twoFactorEnabled ? 'Manage 2FA' : 'Enable 2FA'; ?>
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
                    <span class="font-semibold text-base-content">Sign-in code by email</span>
                    <span class="badge badge-sm <?php echo $emailOn ? 'badge-success' : ($emailPending ? 'badge-warning' : 'badge-ghost'); ?>">
                        <?php echo $emailOn ? 'On' : ($emailPending ? 'Waiting for the code' : 'Off'); ?>
                    </span>
                </div>
                <div class="px-4 py-4 space-y-3">
                    <p class="text-sm text-base-content/80">
                        <?php if ($emailOn): ?>
                            When you sign in, we email you a six-digit code and ask for it before
                            letting you in. Somebody who learns your password still cannot sign in
                            without your mailbox.
                        <?php else: ?>
                            Turn this on and we will email you a six-digit code when you sign in,
                            and ask for it before letting you in — so a password on its own is not
                            enough to reach your account.
                        <?php endif; ?>
                    </p>
                    <p class="text-xs text-base-content/60">
                        An authenticator app is stronger, because email is a channel somebody else
                        may be able to read. This needs nothing installed.
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
                            Confirm with your password to turn this off
                        </label>
                        <div class="flex flex-col sm:flex-row gap-2">
                            <input type="password" id="emailfactor-password" name="password" required
                                   autocomplete="current-password" class="input input-sm flex-1"
                                   placeholder="Your account password">
                            <button type="submit" class="btn btn-sm btn-neutral">Turn off</button>
                        </div>
                    </form>

                    <?php elseif ($emailPending): ?>
                    <form method="POST" action="<?php echo $emailAction; ?>" class="pt-1 border-t border-base-300">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <label for="emailfactor-code" class="block text-sm mt-3 mb-1">
                            Enter the code we emailed you
                        </label>
                        <div class="flex flex-col sm:flex-row gap-2">
                            <input type="text" id="emailfactor-code" name="code" required
                                   inputmode="numeric" maxlength="6" pattern="[0-9]{6}"
                                   autocomplete="one-time-code" class="input input-sm flex-1 font-mono tracking-widest"
                                   placeholder="000000">
                            <button type="submit" class="btn btn-sm btn-primary">Finish</button>
                        </div>
                    </form>
                    <form method="POST" action="<?php echo $emailAction; ?>">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <button type="submit" class="btn btn-ghost btn-xs"
                                <?php if ($emailWait > 0): ?>
                                disabled
                                data-pf-countdown="<?php echo $emailWait; ?>"
                                data-pf-countdown-label="Another code in %ss"
                                data-pf-countdown-ready="Send another code"
                                <?php endif; ?>>
                            <?php echo $emailWait > 0
                                ? 'Another code in ' . $emailWait . 's'
                                : 'Send another code'; ?>
                        </button>
                    </form>

                    <?php else: ?>
                    <form method="POST" action="<?php echo $emailAction; ?>" class="pt-1 border-t border-base-300">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <p class="text-sm mt-3 mb-2">
                            We will email a code to the address on your profile to check it reaches
                            you, then turn this on.
                        </p>
                        <button type="submit" class="btn btn-sm btn-primary"
                                <?php if ($emailWait > 0): ?>
                                disabled
                                data-pf-countdown="<?php echo $emailWait; ?>"
                                data-pf-countdown-label="Try again in %ss"
                                data-pf-countdown-ready="Email me a code"
                                <?php endif; ?>>
                            <?php echo $emailWait > 0
                                ? 'Try again in ' . $emailWait . 's'
                                : 'Email me a code'; ?>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Passkeys -->
            <div class="card bg-base-100 shadow-sm">
                <div class="flex items-center justify-between px-4 py-4">
                    <span class="text-sm text-base-content/80">Sign in without a password using your device's fingerprint, face or screen lock.</span>
                    <a href="<?php echo sURL; ?>Passkey"
                       class="text-sm text-primary hover:underline whitespace-nowrap">
                        Manage Passkeys
                    </a>
                </div>
            </div>

            <!-- Change password -->
            <div class="card bg-base-100 shadow-sm">
                <div class="flex items-center justify-between px-4 py-4">
                    <?php /* Not "change it regularly": routine rotation is advice that has been
                     withdrawn by the people who used to give it (NIST SP 800-63B), because
                     forced changes produce predictable variations of one password and get
                     written down. Change it when there is a reason to. */ ?>
                    <span class="text-sm text-base-content/80">Change it if you think somebody else knows it, or if you have used it anywhere else.</span>
                    <a href="<?php echo sURL . $routeBase; ?>/changepassword"
                       class="text-sm text-primary hover:underline whitespace-nowrap">
                        Change Password
                    </a>
                </div>
            </div>

            <!-- Active sessions -->
            <div class="card bg-base-100 shadow-sm">
                <div class="px-4 py-3 border-b border-base-300 font-semibold text-base-content">
                    Active Sessions
                </div>
                <?php if (empty($this->activeSessions)): ?>
                    <p class="px-4 py-4 text-sm text-base-content/60">No active sessions.</p>
                <?php else: ?>
                    <ul class="divide-y divide-base-200">
                        <?php foreach ($this->activeSessions as $s): ?>
                            <?php $isCurrent = (string) ($s['sid'] ?? '') === (string) ($this->currentSid ?? ''); ?>
                            <li class="flex items-center justify-between px-4 py-3">
                                <div>
                                    <div class="text-sm text-base-content"><?php echo htmlspecialchars(substr((string) ($s['agent'] ?? 'Unknown device'), 0, 80)); ?></div>
                                    <div class="text-xs text-base-content/60">
                                        <?php echo htmlspecialchars((string) ($s['host_addr'] ?? '')); ?>
                                        &middot; <?php echo htmlspecialchars(localDateTime( (int) ($s['time'] ?? 0))); ?>
                                    </div>
                                </div>
                                <?php if ($isCurrent): ?>
                                    <span class="badge badge-success badge-sm">This device</span>
                                <?php else: ?>
                                    <form method="post" action="<?php echo sURL . $routeBase; ?>/revokesession" class="m-0">
                                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                                        <input type="hidden" name="sid" value="<?php echo htmlspecialchars((string) ($s['sid'] ?? '')); ?>">
                                        <button type="submit" class="text-sm text-error hover:underline">Sign out</button>
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
                    Recent Account Activity
                </div>
                <?php if (empty($this->recentActivity)): ?>
                    <p class="px-4 py-4 text-sm text-base-content/60">No activity recorded yet.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="table table-sm text-sm">
                            <thead class="bg-base-200 text-xs font-semibold text-base-content/70 uppercase tracking-wide">
                                <tr>
                                    <th class="px-4 py-3 text-left">Event</th>
                                    <th class="px-4 py-3 text-left">Date</th>
                                    <th class="px-4 py-3 text-left">IP Address</th>
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
