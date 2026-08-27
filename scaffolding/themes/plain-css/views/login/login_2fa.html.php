<?php
/**
 * Built-in second-factor step-up (plain-CSS theme) — Account/LoginFlow flow.
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::renderStepUp):
 *   $this->routeBase     — controller route base (form action prefix)
 *   $this->brand         — [name, logo, primary_color, footer]
 *   $this->error         — optional error key (mapped below)
 *   $this->returnUrl     — post-login redirect (hidden field)
 *   $this->pendingUserId — user awaiting the second factor (informational)
 *   $this->methods       — offered step-up methods (e.g. ['twofactor','passkey'])
 *
 * The password is NOT present here — LoginFlow holds the pending login
 * server-side. This form submits only the code to <routeBase>/verify.
 */
$brand   = $this->brand ?? [];
$primary = htmlspecialchars((string) ($brand['primary_color'] ?? '#2563eb'), ENT_QUOTES);
$base    = sURL . rawurlencode((string) ($this->routeBase ?? 'Account'));

$errorMessages = [
    'invalid_token' => 'Your session expired. Please try again.',
    'missing_code'  => 'Please enter your verification code.',
    'invalid_code'  => 'Invalid or expired code. Please try again.',
    'email_code_failed' => 'We could not send a code to your email address.',
    'auth_link_failed'  => 'We could not email you a sign-in link.',
    'authlink_invalid'  => 'That sign-in link has been used or has expired. Please sign in again.',
];
$errorKey  = (string) ($this->error ?? '');
$errorText = $errorMessages[$errorKey] ?? $errorKey;
$offerPasskey = in_array('passkey', (array) ($this->methods ?? []), true);

/*
 * Which factors this account actually has, from the controller.
 *
 * The screen used to assume an authenticator app: one heading, one box, one hint about
 * an app. An account whose only second factor is a mailed code was shown a box it had
 * no way to fill, and no way to ask for the code either.
 */
$hasTotp     = (bool) ($this->totpFactor ?? true);
$hasEmail    = (bool) ($this->emailFactor ?? false);
$codePending = (bool) ($this->emailCodePending ?? false);
$emailFirst  = $hasEmail && !$hasTotp;
$authLink    = (bool) ($this->authLink ?? false);

$noticeMessages = [
    'email_code_sent' => 'We have sent a code to your email address.',
    'auth_link_sent'  => 'We have emailed you a link to finish signing in.',
];
$noticeKey  = (string) ($this->notice ?? '');
$noticeText = $noticeMessages[$noticeKey] ?? $noticeKey;

$intro = $authLink
    ? 'This browser has not been used with your account before, so we have emailed you a link to finish signing in.'
    : ($emailFirst
        ? 'Enter the 6-digit code we sent to your email address.'
        : 'Enter the 6-digit code from your authenticator app.');
?>
<div style="display:flex;align-items:center;justify-content:center;min-height:60vh;padding:20px">
    <div class="card" style="width:100%;max-width:400px">
        <div class="card-header">
            <h2 style="margin:0;font-size:1.25rem">Two-step verification</h2>
            <p style="margin:4px 0 0;font-size:13px;color:#666"><?php echo $intro; ?></p>
        </div>
        <div class="card-body" style="padding:24px">

            <?php if ($errorText !== ''): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($errorText); ?></div>
            <?php endif; ?>
            <?php if ($noticeText !== ''): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($noticeText); ?></div>
            <?php endif; ?>

            <?php /* The link case: nothing to type, so no code box — a field with no source
                     is how somebody concludes the mail never arrived. */ ?>
            <?php if ($authLink): ?>
            <p style="font-size:13px;color:#666">Open the link within 15 minutes. It works once, and only for this sign-in.</p>
            <form method="POST" action="<?php echo $base; ?>/verify">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <input type="hidden" name="send_auth_link" value="1">
                <button type="submit" class="btn" style="width:100%;background-color:#374151;border-color:#374151">Email the link again</button>
            </form>
            <?php else: ?>

            <form method="POST" action="<?php echo $base; ?>/verify">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <?php if (!empty($this->returnUrl)): ?>
                    <input type="hidden" name="return" value="<?php echo htmlspecialchars((string) $this->returnUrl); ?>">
                <?php endif; ?>
                <?php /* Names the factor, because both codes are six digits: guessing would
                         spend an email attempt every time somebody typed an app code. */ ?>
                <input type="hidden" name="method" value="<?php echo $emailFirst ? 'email' : 'totp'; ?>">
                <div style="margin-bottom:20px">
                    <label for="code" style="display:block;margin-bottom:4px;font-weight:500">Verification Code</label>
                    <input type="text" id="code" name="code" data-pf-otp
                           style="width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-family:monospace;font-size:22px;text-align:center;letter-spacing:.15em"
                           maxlength="6" pattern="[0-9]{6}" placeholder="000000"
                           autocomplete="one-time-code" required autofocus>
                </div>
                <button type="submit" class="btn" style="width:100%;background-color:<?php echo $primary; ?>;border-color:<?php echo $primary; ?>">Verify &amp; Sign In</button>
            </form>

            <?php if ($hasEmail && $hasTotp): ?>
            <div style="text-align:center;margin:16px 0;color:#888;font-size:13px">or</div>
            <?php if ($codePending): ?>
            <form method="POST" action="<?php echo $base; ?>/verify" style="margin-bottom:8px">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <input type="hidden" name="method" value="email">
                <label for="email-code" style="display:block;margin-bottom:4px;font-weight:500">Code sent to your email</label>
                <input type="text" id="email-code" name="code"
                       style="width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-family:monospace;font-size:20px;text-align:center;letter-spacing:.15em;margin-bottom:8px"
                       maxlength="6" pattern="[0-9]{6}" placeholder="000000" autocomplete="one-time-code">
                <button type="submit" class="btn" style="width:100%;background-color:#374151;border-color:#374151">Use the emailed code</button>
            </form>
            <?php endif; ?>
            <form method="POST" action="<?php echo $base; ?>/verify">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <input type="hidden" name="send_email_code" value="1">
                <button type="submit" class="btn btn-sm" style="width:100%">
                    <?php echo $codePending ? 'Send another code' : 'Email me a code instead'; ?>
                </button>
            </form>
            <?php endif; ?>

            <?php if ($emailFirst): ?>
            <form method="POST" action="<?php echo $base; ?>/verify">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <input type="hidden" name="send_email_code" value="1">
                <button type="submit" class="btn btn-sm" style="width:100%">Send another code</button>
            </form>
            <?php endif; ?>

            <?php if ($offerPasskey): ?>
            <div style="text-align:center;margin:16px 0;color:#888;font-size:13px">or</div>
            <button type="button" class="btn" style="width:100%;background-color:#374151;border-color:#374151"
                    data-pf-passkey-stepup
                    data-options-url="<?php echo $base; ?>/passkeyOptions"
                    data-verify-url="<?php echo $base; ?>/passkeyVerify"
                    data-redirect="<?php echo sURL; ?>"
                    data-error="#passkey-error">Use a passkey</button>
            <p id="passkey-error" style="color:#b91c1c;font-size:13px;margin-top:8px;display:none"></p>
            <?php endif; ?>

            <?php /* Backup codes belong to the authenticator enrolment; an account with only
                     the email factor has none, and offering them would be a dead end. */ ?>
            <?php if ($hasTotp): ?>
            <details style="margin-top:16px">
                <summary style="font-size:13px;color:#666;cursor:pointer">Use a backup code instead</summary>
                <form method="POST" action="<?php echo $base; ?>/verify" style="margin-top:8px">
                    <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                    <?php if (!empty($this->returnUrl)): ?>
                        <input type="hidden" name="return" value="<?php echo htmlspecialchars((string) $this->returnUrl); ?>">
                    <?php endif; ?>
                    <input type="text" name="code"
                           style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;text-transform:uppercase;letter-spacing:.1em;font-size:15px;margin-bottom:8px"
                           maxlength="8" placeholder="XXXXXXXX">
                    <button type="submit" class="btn btn-sm" style="width:100%">Use Backup Code</button>
                </form>
            </details>
            <?php endif; ?>

            <?php endif; ?>

            <div style="text-align:center;margin-top:12px">
                <a href="<?php echo $base; ?>/login" style="font-size:13px">&larr; Back to login</a>
            </div>
        </div>
    </div>
</div>
<script src="<?php echo sURL; ?>assets/js/pf-webauthn.js"></script>
<script src="<?php echo sURL; ?>assets/js/pf-auth.js"></script>
