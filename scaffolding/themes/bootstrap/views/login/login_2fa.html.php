<?php
/**
 * Built-in second-factor step-up (Bootstrap theme) — Account/LoginFlow flow.
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
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-1">Two-step verification</h1>
                    <p class="text-muted small mb-3"><?php echo $intro; ?></p>

                    <?php if ($errorText !== ''): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($errorText); ?></div>
                    <?php endif; ?>
                    <?php if ($noticeText !== ''): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($noticeText); ?></div>
                    <?php endif; ?>

                    <?php /* The link case: nothing to type, so no code box — a field with no
                             source is how somebody concludes the mail never arrived. */ ?>
                    <?php if ($authLink): ?>
                    <p class="text-muted small">Open the link within 15 minutes. It works once, and only for this sign-in.</p>
                    <form method="POST" action="<?php echo $base; ?>/verify">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <input type="hidden" name="send_auth_link" value="1">
                        <button type="submit" class="btn btn-secondary w-100">Email the link again</button>
                    </form>
                    <?php else: ?>

                    <form method="POST" action="<?php echo $base; ?>/verify">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <?php if (!empty($this->returnUrl)): ?>
                            <input type="hidden" name="return" value="<?php echo htmlspecialchars((string) $this->returnUrl); ?>">
                        <?php endif; ?>
                        <?php /* Names the factor, because both codes are six digits: guessing
                                 would spend an email attempt every time somebody typed an
                                 app code. */ ?>
                        <input type="hidden" name="method" value="<?php echo $emailFirst ? 'email' : 'totp'; ?>">
                        <div class="mb-3">
                            <label for="code" class="form-label">Verification Code</label>
                            <input type="text" id="code" name="code" data-pf-otp class="form-control text-center fs-4 font-monospace"
                                   maxlength="6" pattern="[0-9]{6}" placeholder="000000"
                                   autocomplete="one-time-code" required autofocus>
                        </div>
                        <button type="submit" class="btn btn-primary w-100" style="background-color:<?php echo $primary; ?>;border-color:<?php echo $primary; ?>">Verify &amp; Sign In</button>
                    </form>

                    <?php if ($hasEmail && $hasTotp): ?>
                    <div class="text-center text-muted small my-3">or</div>
                    <?php if ($codePending): ?>
                    <form method="POST" action="<?php echo $base; ?>/verify" class="mb-2">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <input type="hidden" name="method" value="email">
                        <label for="email-code" class="form-label">Code sent to your email</label>
                        <input type="text" id="email-code" name="code" class="form-control text-center font-monospace mb-2"
                               maxlength="6" pattern="[0-9]{6}" placeholder="000000" autocomplete="one-time-code">
                        <button type="submit" class="btn btn-secondary w-100">Use the emailed code</button>
                    </form>
                    <?php endif; ?>
                    <form method="POST" action="<?php echo $base; ?>/verify">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <input type="hidden" name="send_email_code" value="1">
                        <button type="submit" class="btn btn-link btn-sm w-100">
                            <?php echo $codePending ? 'Send another code' : 'Email me a code instead'; ?>
                        </button>
                    </form>
                    <?php endif; ?>

                    <?php if ($emailFirst): ?>
                    <form method="POST" action="<?php echo $base; ?>/verify">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <input type="hidden" name="send_email_code" value="1">
                        <button type="submit" class="btn btn-link btn-sm w-100">Send another code</button>
                    </form>
                    <?php endif; ?>

                    <?php if ($offerPasskey): ?>
                    <div class="text-center text-muted small my-3">or</div>
                    <button type="button" class="btn btn-dark w-100"
                            data-pf-passkey-stepup
                            data-options-url="<?php echo $base; ?>/passkeyOptions"
                            data-verify-url="<?php echo $base; ?>/passkeyVerify"
                            data-redirect="<?php echo sURL; ?>"
                            data-error="#passkey-error">Use a passkey</button>
                    <p id="passkey-error" class="text-danger small mt-2 d-none"></p>
                    <?php endif; ?>

                    <?php /* Backup codes belong to the authenticator enrolment; an account with
                             only the email factor has none, and offering them would be a dead
                             end. */ ?>
                    <?php if ($hasTotp): ?>
                    <details class="mt-3">
                        <summary class="small text-muted" style="cursor:pointer">Use a backup code instead</summary>
                        <form method="POST" action="<?php echo $base; ?>/verify" class="mt-2">
                            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                            <?php if (!empty($this->returnUrl)): ?>
                                <input type="hidden" name="return" value="<?php echo htmlspecialchars((string) $this->returnUrl); ?>">
                            <?php endif; ?>
                            <div class="mb-2">
                                <input type="text" name="code" class="form-control text-uppercase"
                                       maxlength="8" placeholder="XXXXXXXX" style="letter-spacing:.1em">
                            </div>
                            <button type="submit" class="btn btn-secondary w-100 btn-sm">Use Backup Code</button>
                        </form>
                    </details>
                    <?php endif; ?>

                    <?php endif; ?>

                    <p class="text-center small mt-3"><a href="<?php echo $base; ?>/login">&larr; Back to login</a></p>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="<?php echo sURL; ?>assets/js/pf-webauthn.js"></script>
<script src="<?php echo sURL; ?>assets/js/pf-auth.js"></script>
