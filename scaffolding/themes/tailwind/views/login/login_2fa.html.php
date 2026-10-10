<?php
/**
 * Built-in second-factor step-up (Tailwind theme) — Account/LoginFlow flow.
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::renderStepUp):
 *   $this->routeBase, $this->brand, $this->error, $this->returnUrl,
 *   $this->pendingUserId, $this->methods
 *
 * No password here — LoginFlow holds the pending login server-side. The form
 * submits only the code to <routeBase>/verify.
 */
$brand   = $this->brand ?? [];
$primary = htmlspecialchars((string) ($brand['primary_color'] ?? '#2563eb'), ENT_QUOTES);
$base    = sURL . rawurlencode((string) ($this->routeBase ?? 'Account'));
// The return address in the form's own URL too, so a refresh after a failed attempt keeps it.
$returnQuery = (string) ($this->returnUrl ?? '') !== ''
    ? '?return=' . htmlspecialchars(rawurlencode((string) $this->returnUrl))
    : '';

$errorMessages = [
    'invalid_token' => 'Your session expired. Please try again.',
    'missing_code'  => 'Please enter your verification code.',
    'invalid_code'  => 'Invalid or expired code. Please try again.',
    'email_code_failed' => 'We could not send a code to your email address.',
    'email_code_wait'   => 'We have already sent you a code. You can ask for another one in %d seconds.',
    'auth_link_failed'  => 'We could not email you a sign-in link.',
    'authlink_invalid'  => 'That sign-in link has been used or has expired. Please sign in again.',
    'push_failed'       => 'We could not send a notification to your phone just now. Use another way below.',
    'push_not_approved' => 'Your phone has not approved this sign-in.',
];
$errorKey  = (string) ($this->error ?? '');
$errorText = $errorMessages[$errorKey] ?? $errorKey;
/*
 * The error box's id, and the attributes that point the first field at it.
 *
 * `role="alert"` on its own is unreliable for an error that is already in the document when the
 * page loads: a screen reader announces a live region when it *changes*, and this one never
 * changed. What works with no JavaScript at all is the description — the field is marked invalid
 * and described by the box, so the message is read out as part of the field the moment focus
 * lands on it, and focus lands there on load because the first field carries `autofocus`.
 *
 * The *first* field only. These errors are form-level — «wrong username or password» is about the
 * pair — and marking four fields invalid to report one failure tells a screen reader four things
 * that are not true.
 */
$errorFieldAttributes = $errorText !== ''
    ? ' aria-invalid="true" aria-describedby="form-error"'
    : '';

// The wait message carries a number, so it is the one error that is formatted.
if ($errorKey === 'email_code_wait') {
    $errorText = sprintf($errorText, max(1, (int) ($this->resendIn ?? 0)));
}
$offerPasskey = in_array('passkey', (array) ($this->methods ?? []), true);

/*
 * Which factors this account actually has, from the controller.
 *
 * The screen used to assume an authenticator app: one heading, one box, one hint about
 * an app. An account whose only second factor is a mailed code was shown a box it had
 * no way to fill, and no way to ask for the code either.
 */
$hasTotp      = (bool) ($this->totpFactor ?? true);
$hasEmail     = (bool) ($this->emailFactor ?? false);
$codePending  = (bool) ($this->emailCodePending ?? false);
$authLink     = (bool) ($this->authLink ?? false);
$emailFirst   = $hasEmail && !$hasTotp;

$noticeMessages = [
    'email_code_sent' => 'We have sent a code to your email address.',
    'auth_link_sent'  => 'We have emailed you a link to finish signing in.',
    'push_sent'       => 'We have sent the notification again.',
];
$resendIn   = (int) ($this->resendIn ?? 0);
$noticeKey  = (string) ($this->notice ?? '');
$noticeText = $noticeMessages[$noticeKey] ?? $noticeKey;

/*
 * The phone prompt, when this account has a trusted phone: it leads, as Google's does, and
 * everything else moves under "Try another way" — one click away, never gone.
 */
$pushFirst  = !$authLink && (bool) ($this->pushFactor ?? false);
$pushStatus = (array) ($this->pushStatus ?? []);
$pushState  = (string) ($pushStatus['state'] ?? 'none');
$pushNumber = isset($pushStatus['number']) && $pushStatus['number'] !== null ? (int) $pushStatus['number'] : null;
$pushTo     = implode(', ', array_map('strval', (array) ($pushStatus['devices'] ?? [])));
$pushStuck  = in_array($pushState, ['none', 'expired', 'denied'], true);

$intro = $pushFirst
    ? 'Confirm it is you, from your phone.'
    : ($authLink
    ? 'This browser has not been used with your account before, so we have emailed you a link to finish signing in.'
    : ($emailFirst
        ? 'Enter the 6-digit code we sent to your email address.'
        : 'Enter the 6-digit code from your authenticator app.'));
?>
<div class="flex items-center justify-center min-h-screen bg-base-200 px-4">
    <div class="card bg-base-100 shadow-md p-8 w-full max-w-sm" style="--color-primary:<?php echo $primary; ?>">
        <h1 class="text-2xl font-semibold mb-1">Two-step verification</h1>
        <p class="text-sm text-base-content/70 mb-6"><?php echo $intro; ?></p>

        <?php if ($errorText !== ''): ?>
            <div role="alert" id="form-error" class="alert alert-error mb-4"><?php echo htmlspecialchars($errorText); ?></div>
        <?php endif; ?>
        <?php if ($noticeText !== ''): ?>
            <div role="status" class="alert alert-success mb-4"><?php echo htmlspecialchars($noticeText); ?></div>
        <?php endif; ?>

        <?php /* The link case: nothing to type, so no code box — a field with no source
                 is how somebody concludes the mail never arrived. */ ?>
        <?php if ($authLink): ?>
        <p class="text-sm text-base-content/70 mb-4">
            Open the link within 15 minutes. It works once, and only for this sign-in.
        </p>
        <form data-pf-progress method="POST" action="<?php echo $base; ?>/verify<?php echo $returnQuery; ?>">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <input type="hidden" name="send_auth_link" value="1">
            <button type="submit" class="btn btn-neutral w-full">Email the link again</button>
        </form>
        <?php else: ?>

        <?php if ($pushFirst): ?>
        <div class="space-y-3" data-pf-push-approval
             data-status-url="<?php echo $base; ?>/pushstatus"
             data-other-ways="#pf-other-ways" data-patience="20">
            <p class="text-sm">
                We sent a notification to <strong><?php echo htmlspecialchars($pushTo !== '' ? $pushTo : 'your phone'); ?></strong>.
                Open it and tap <strong>Yes</strong>.
            </p>
            <?php if ($pushNumber !== null): ?>
            <p class="text-sm">This sign-in looks new, so your phone will ask you to pick this number:</p>
            <p class="text-center text-5xl font-bold tracking-wider" aria-label="The number to pick on your phone"><?php echo $pushNumber; ?></p>
            <?php endif; ?>
            <p class="text-sm text-base-content/70" role="status" aria-live="polite" data-pf-push-state>
                <?php echo $pushStuck
                    ? 'The notification could not be sent or has expired. Send it again, or use another way.'
                    : 'Sent. Waiting for your phone…'; ?>
            </p>
            <form data-pf-progress method="POST" action="<?php echo $base; ?>/verify<?php echo $returnQuery; ?>" data-pf-push-finish>
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <?php if (!empty($this->returnUrl)): ?>
                    <input type="hidden" name="return" value="<?php echo htmlspecialchars((string) $this->returnUrl); ?>">
                <?php endif; ?>
                <input type="hidden" name="method" value="push">
                <noscript><button type="submit" class="btn btn-primary w-full">I approved it on my phone</button></noscript>
            </form>
            <form data-pf-progress method="POST" action="<?php echo $base; ?>/verify<?php echo $returnQuery; ?>" data-pf-push-resend
                  class="<?php echo $pushStuck ? '' : 'hidden'; ?>">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <input type="hidden" name="send_factor" value="push">
                <button type="submit" class="btn btn-neutral btn-sm w-full">Send it again</button>
            </form>
        </div>

        <?php /* Every other way through, one click away. Opened by itself when the phone
                 has not received the prompt in twenty seconds, or it failed. */ ?>
        <details id="pf-other-ways" class="mt-5"<?php echo $pushStuck ? ' open' : ''; ?>>
            <summary class="text-sm text-primary cursor-pointer">Try another way</summary>
            <div class="mt-4">
        <?php endif; ?>

        <?php if (!$pushFirst || $hasTotp || $emailFirst): ?>
        <form data-pf-progress method="POST" action="<?php echo $base; ?>/verify<?php echo $returnQuery; ?>" class="space-y-4">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <?php if (!empty($this->returnUrl)): ?>
                <input type="hidden" name="return" value="<?php echo htmlspecialchars((string) $this->returnUrl); ?>">
            <?php endif; ?>
            <?php /* Names the factor, because both codes are six digits: guessing would
                     spend an email attempt every time somebody typed an app code. */ ?>
            <input type="hidden" name="method" value="<?php echo $emailFirst ? 'email' : 'totp'; ?>">
            <div>
                <label for="code" class="block text-sm font-medium text-base-content mb-1">Verification Code</label>
                <input type="text" id="code" name="code" data-pf-otp
                       class="input w-full text-center text-2xl font-mono tracking-widest"
                       maxlength="6" pattern="[0-9]{6}" placeholder="000000"
                       autocomplete="one-time-code"<?php echo $errorFieldAttributes; ?> inputmode="numeric" enterkeyhint="go" required autofocus>
            </div>
            <button type="submit" class="btn btn-primary w-full">Verify &amp; Sign In</button>
        </form>
        <?php endif; ?>

        <?php /*
         * When the account has both, the app is the flow and email is a way out.
         *
         * It used to be a second block of equal weight under an "or", which reads as two
         * choices and invites the weaker one — every account that had enrolled an
         * authenticator would be offered the mailbox beside it, every time. Behind a small
         * link it is still one click away for the person whose phone is flat, and it is not
         * a suggestion.
         */ ?>
        <?php if ($hasEmail && $hasTotp): ?>
        <details class="mt-4" <?php echo $codePending ? 'open' : ''; ?>>
            <summary class="text-sm text-primary cursor-pointer"><?php echo $pushFirst ? 'A code by email' : 'Try another way'; ?></summary>
            <div class="mt-3 space-y-2">
                <?php if ($codePending): ?>
                <form data-pf-progress method="POST" action="<?php echo $base; ?>/verify<?php echo $returnQuery; ?>" class="space-y-2">
                    <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                    <input type="hidden" name="method" value="email">
                    <label for="email-code" class="block text-sm font-medium mb-1">Code sent to your email</label>
                    <input type="text" id="email-code" name="code"
                           class="input w-full text-center text-xl font-mono tracking-widest"
                           maxlength="6" pattern="[0-9]{6}" placeholder="000000"
                           autocomplete="one-time-code" inputmode="numeric" enterkeyhint="go">
                    <button type="submit" class="btn btn-neutral btn-sm w-full">Use the emailed code</button>
                </form>
                <?php endif; ?>
                <form data-pf-progress method="POST" action="<?php echo $base; ?>/verify<?php echo $returnQuery; ?>">
                    <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                    <input type="hidden" name="send_email_code" value="1">
                    <?php
                    /*
                     * Disabled rather than hidden while the limit applies, with the wait in
                     * the label — and the label counts down (`data-pf-countdown`, handled
                     * in pf-utils.js). A number that never changes is indistinguishable
                     * from a broken button, and the usual response to one is to reload the
                     * page, which tells the reader nothing.
                     */
                    $readyLabel = $codePending ? 'Send another code' : 'Email me a code instead';
                    ?>
                    <button type="submit" class="btn btn-ghost btn-sm w-full"
                            <?php if ($resendIn > 0): ?>
                            disabled
                            data-pf-countdown="<?php echo $resendIn; ?>"
                            data-pf-countdown-label="Another code in %ss"
                            data-pf-countdown-ready="<?php echo htmlspecialchars($readyLabel, ENT_QUOTES); ?>"
                            <?php endif; ?>>
                        <?php echo $resendIn > 0
                            ? 'Another code in ' . $resendIn . 's'
                            : $readyLabel; ?>
                    </button>
                </form>
            </div>
        </details>
        <?php endif; ?>

        <?php if ($emailFirst): ?>
        <form data-pf-progress method="POST" action="<?php echo $base; ?>/verify<?php echo $returnQuery; ?>" class="mt-2">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <input type="hidden" name="send_email_code" value="1">
            <button type="submit" class="btn btn-ghost btn-sm w-full">Send another code</button>
        </form>
        <?php endif; ?>

        <?php if ($offerPasskey): ?>
        <div class="text-center text-sm text-base-content/60 my-4">or</div>
        <button type="button" class="btn btn-neutral w-full"
                data-pf-passkey-stepup
                data-options-url="<?php echo $base; ?>/passkeyOptions"
                data-verify-url="<?php echo $base; ?>/passkeyVerify"
                data-redirect="<?php echo sURL; ?>"
                data-error="#passkey-error">Use a passkey</button>
        <p id="passkey-error" class="text-error text-sm mt-2 hidden"></p>
        <?php endif; ?>

        <?php /* Backup codes belong to the authenticator enrolment; an account with only
                 the email factor has none, and offering them would be a dead end. */ ?>
        <?php if ($hasTotp): ?>
        <details class="mt-4">
            <summary class="text-sm text-base-content/70 cursor-pointer">Use a backup code instead</summary>
            <form data-pf-progress method="POST" action="<?php echo $base; ?>/verify<?php echo $returnQuery; ?>" class="mt-3 space-y-2">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <?php if (!empty($this->returnUrl)): ?>
                    <input type="hidden" name="return" value="<?php echo htmlspecialchars((string) $this->returnUrl); ?>">
                <?php endif; ?>
                <input type="text" name="code"
                       class="input w-full uppercase tracking-widest"
                       maxlength="8" placeholder="XXXXXXXX">
                <button type="submit" class="btn btn-neutral btn-sm w-full">Use Backup Code</button>
            </form>
        </details>
        <?php endif; ?>

        <?php if ($pushFirst): ?>
            </div>
        </details>
        <?php endif; ?>

        <?php endif; ?>

        <?php /* Google's "Don't ask again on this device", ticked as Google ticks it. */ ?>
        <?php if (!empty($this->canTrust)): ?>
        <label class="mt-5 flex items-start gap-2 text-sm cursor-pointer">
            <input type="checkbox" class="checkbox checkbox-sm mt-0.5" data-pf-trust-device
                   <?php echo ($this->trustChosen ?? true) ? 'checked' : ''; ?>>
            <span>
                Don't ask again on this device
                <span class="block text-base-content/60">
                    For <?php echo (int) ($this->trustDays ?? 30); ?> days.<?php if (!empty($this->pushOffered)): ?> A phone you trust can then also approve your sign-ins elsewhere.<?php endif; ?>
                </span>
            </span>
        </label>
        <?php endif; ?>

        <p class="text-center text-sm mt-4">
            <a href="<?php echo $base; ?>/login" class="text-primary hover:underline">&larr; Back to login</a>
        </p>
    </div>
</div>
<script src="<?php echo assetUrl('assets/js/pf-webauthn.js'); ?>"></script>
<script src="<?php echo assetUrl('assets/js/pf-auth.js'); ?>"></script>
