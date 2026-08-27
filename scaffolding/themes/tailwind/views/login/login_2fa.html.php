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

$errorMessages = [
    'invalid_token' => 'Your session expired. Please try again.',
    'missing_code'  => 'Please enter your verification code.',
    'invalid_code'  => 'Invalid or expired code. Please try again.',
    'email_code_failed' => 'We could not send a code to your email address.',
    'email_code_wait'   => 'We have already sent you a code. You can ask for another one in %d seconds.',
    'auth_link_failed'  => 'We could not email you a sign-in link.',
    'authlink_invalid'  => 'That sign-in link has been used or has expired. Please sign in again.',
];
$errorKey  = (string) ($this->error ?? '');
$errorText = $errorMessages[$errorKey] ?? $errorKey;
// The wait message carries a number, so it is the one error that is formatted.
if ($errorKey === 'email_code_wait') {
    $errorText = sprintf($errorText, max(1, $resendIn));
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
$emailFirst   = $hasEmail && !$hasTotp;

$noticeMessages = [
    'email_code_sent' => 'We have sent a code to your email address.',
    'auth_link_sent'  => 'We have emailed you a link to finish signing in.',
];
$resendIn   = (int) ($this->resendIn ?? 0);
$noticeKey  = (string) ($this->notice ?? '');
$noticeText = $noticeMessages[$noticeKey] ?? $noticeKey;

$intro = $authLink
    ? 'This browser has not been used with your account before, so we have emailed you a link to finish signing in.'
    : ($emailFirst
        ? 'Enter the 6-digit code we sent to your email address.'
        : 'Enter the 6-digit code from your authenticator app.');
?>
<div class="flex items-center justify-center min-h-screen bg-base-200 px-4">
    <div class="card bg-base-100 shadow-md p-8 w-full max-w-sm" style="--color-primary:<?php echo $primary; ?>">
        <h1 class="text-2xl font-semibold mb-1">Two-step verification</h1>
        <p class="text-sm text-base-content/70 mb-6"><?php echo $intro; ?></p>

        <?php if ($errorText !== ''): ?>
            <div class="alert alert-error mb-4"><?php echo htmlspecialchars($errorText); ?></div>
        <?php endif; ?>
        <?php if ($noticeText !== ''): ?>
            <div class="alert alert-success mb-4"><?php echo htmlspecialchars($noticeText); ?></div>
        <?php endif; ?>

        <?php /* The link case: nothing to type, so no code box — a field with no source
                 is how somebody concludes the mail never arrived. */ ?>
        <?php if ($authLink): ?>
        <p class="text-sm text-base-content/70 mb-4">
            Open the link within 15 minutes. It works once, and only for this sign-in.
        </p>
        <form method="POST" action="<?php echo $base; ?>/verify">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <input type="hidden" name="send_auth_link" value="1">
            <button type="submit" class="btn btn-neutral w-full">Email the link again</button>
        </form>
        <?php else: ?>

        <form method="POST" action="<?php echo $base; ?>/verify" class="space-y-4">
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
                       autocomplete="one-time-code" required autofocus>
            </div>
            <button type="submit" class="btn btn-primary w-full">Verify &amp; Sign In</button>
        </form>

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
            <summary class="text-sm text-primary cursor-pointer">Try another way</summary>
            <div class="mt-3 space-y-2">
                <?php if ($codePending): ?>
                <form method="POST" action="<?php echo $base; ?>/verify" class="space-y-2">
                    <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                    <input type="hidden" name="method" value="email">
                    <label for="email-code" class="block text-sm font-medium mb-1">Code sent to your email</label>
                    <input type="text" id="email-code" name="code"
                           class="input w-full text-center text-xl font-mono tracking-widest"
                           maxlength="6" pattern="[0-9]{6}" placeholder="000000"
                           autocomplete="one-time-code">
                    <button type="submit" class="btn btn-neutral btn-sm w-full">Use the emailed code</button>
                </form>
                <?php endif; ?>
                <form method="POST" action="<?php echo $base; ?>/verify">
                    <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                    <input type="hidden" name="send_email_code" value="1">
                    <button type="submit" class="btn btn-ghost btn-sm w-full"
                            <?php echo $resendIn > 0 ? 'disabled' : ''; ?>>
                        <?php
                        // Disabled rather than hidden while the limit applies, with the wait
                        // in the label: a control that disappears leaves somebody wondering
                        // whether they imagined it.
                        echo $resendIn > 0
                            ? 'Another code in ' . $resendIn . 's'
                            : ($codePending ? 'Send another code' : 'Email me a code instead');
                        ?>
                    </button>
                </form>
            </div>
        </details>
        <?php endif; ?>

        <?php if ($emailFirst): ?>
        <form method="POST" action="<?php echo $base; ?>/verify" class="mt-2">
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
            <form method="POST" action="<?php echo $base; ?>/verify" class="mt-3 space-y-2">
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

        <?php endif; ?>

        <p class="text-center text-sm mt-4">
            <a href="<?php echo $base; ?>/login" class="text-primary hover:underline">&larr; Back to login</a>
        </p>
    </div>
</div>
<script src="<?php echo sURL; ?>assets/js/pf-webauthn.js"></script>
<script src="<?php echo sURL; ?>assets/js/pf-auth.js"></script>
