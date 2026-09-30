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
    'push_sent'       => 'We have sent the notification again.',
];
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
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-1">Two-step verification</h1>
                    <p class="text-muted small mb-3"><?php echo $intro; ?></p>

                    <?php if ($errorText !== ''): ?>
                        <div role="alert" id="form-error" class="alert alert-danger"><?php echo htmlspecialchars($errorText); ?></div>
                    <?php endif; ?>
                    <?php if ($noticeText !== ''): ?>
                        <div role="status" class="alert alert-success"><?php echo htmlspecialchars($noticeText); ?></div>
                    <?php endif; ?>

                    <?php /* The link case: nothing to type, so no code box — a field with no
                             source is how somebody concludes the mail never arrived. */ ?>
                    <?php if ($authLink): ?>
                    <p class="text-muted small">Open the link within 15 minutes. It works once, and only for this sign-in.</p>
                    <form data-pf-progress method="POST" action="<?php echo $base; ?>/verify">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <input type="hidden" name="send_auth_link" value="1">
                        <button type="submit" class="btn btn-secondary w-100">Email the link again</button>
                    </form>
                    <?php else: ?>

                    <?php if ($pushFirst): ?>
                    <div data-pf-push-approval
                         data-status-url="<?php echo $base; ?>/pushstatus"
                         data-other-ways="#pf-other-ways" data-patience="20">
                        <p class="small">
                            We sent a notification to <strong><?php echo htmlspecialchars($pushTo !== '' ? $pushTo : 'your phone'); ?></strong>.
                            Open it and tap <strong>Yes</strong>.
                        </p>
                        <?php if ($pushNumber !== null): ?>
                        <p class="small">This sign-in looks new, so your phone will ask you to pick this number:</p>
                        <p class="text-center display-4 fw-bold" aria-label="The number to pick on your phone"><?php echo $pushNumber; ?></p>
                        <?php endif; ?>
                        <p class="small text-muted" role="status" aria-live="polite" data-pf-push-state>
                            <?php echo $pushStuck
                                ? 'The notification could not be sent or has expired. Send it again, or use another way.'
                                : 'Sent. Waiting for your phone…'; ?>
                        </p>
                        <form data-pf-progress method="POST" action="<?php echo $base; ?>/verify" data-pf-push-finish>
                            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                            <?php if (!empty($this->returnUrl)): ?>
                                <input type="hidden" name="return" value="<?php echo htmlspecialchars((string) $this->returnUrl); ?>">
                            <?php endif; ?>
                            <input type="hidden" name="method" value="push">
                            <noscript><button type="submit" class="btn btn-primary w-100">I approved it on my phone</button></noscript>
                        </form>
                        <form data-pf-progress method="POST" action="<?php echo $base; ?>/verify" data-pf-push-resend
                              class="<?php echo $pushStuck ? '' : 'd-none'; ?>">
                            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                            <input type="hidden" name="send_factor" value="push">
                            <button type="submit" class="btn btn-secondary btn-sm w-100">Send it again</button>
                        </form>
                    </div>

                    <?php /* Every other way through, one click away. Opened by itself when the
                             phone has not received the prompt in twenty seconds, or it failed. */ ?>
                    <details id="pf-other-ways" class="mt-4"<?php echo $pushStuck ? ' open' : ''; ?>>
                        <summary class="small" style="cursor:pointer">Try another way</summary>
                        <div class="mt-3">
                    <?php endif; ?>

                    <?php if (!$pushFirst || $hasTotp || $emailFirst): ?>
                    <form data-pf-progress method="POST" action="<?php echo $base; ?>/verify">
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
                                   autocomplete="one-time-code"<?php echo $errorFieldAttributes; ?> inputmode="numeric" enterkeyhint="go" required autofocus>
                        </div>
                        <button type="submit" class="btn btn-primary w-100" style="background-color:<?php echo $primary; ?>;border-color:<?php echo $primary; ?>">Verify &amp; Sign In</button>
                    </form>
                    <?php endif; ?>

                    <?php if ($hasEmail && $hasTotp): ?>
                    <div class="text-center text-muted small my-3">or</div>
                    <?php if ($codePending): ?>
                    <form data-pf-progress method="POST" action="<?php echo $base; ?>/verify" class="mb-2">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <input type="hidden" name="method" value="email">
                        <label for="email-code" class="form-label">Code sent to your email</label>
                        <input type="text" id="email-code" name="code" class="form-control text-center font-monospace mb-2"
                               maxlength="6" pattern="[0-9]{6}" placeholder="000000" autocomplete="one-time-code" inputmode="numeric" enterkeyhint="go">
                        <button type="submit" class="btn btn-secondary w-100">Use the emailed code</button>
                    </form>
                    <?php endif; ?>
                    <form data-pf-progress method="POST" action="<?php echo $base; ?>/verify">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <input type="hidden" name="send_email_code" value="1">
                        <button type="submit" class="btn btn-link btn-sm w-100">
                            <?php echo $codePending ? 'Send another code' : 'Email me a code instead'; ?>
                        </button>
                    </form>
                    <?php endif; ?>

                    <?php if ($emailFirst): ?>
                    <form data-pf-progress method="POST" action="<?php echo $base; ?>/verify">
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
                        <form data-pf-progress method="POST" action="<?php echo $base; ?>/verify" class="mt-2">
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

                    <?php if ($pushFirst): ?>
                        </div>
                    </details>
                    <?php endif; ?>

                    <?php endif; ?>

                    <?php /* Google's "Don't ask again on this device", ticked as Google ticks it. */ ?>
                    <?php if (!empty($this->canTrust)): ?>
                    <div class="form-check mt-4 small">
                        <input type="checkbox" class="form-check-input" id="pf-trust-device" data-pf-trust-device
                               <?php echo ($this->trustChosen ?? true) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="pf-trust-device">
                            Don't ask again on this device
                            <span class="d-block text-muted">
                                For <?php echo (int) ($this->trustDays ?? 30); ?> days.<?php if (!empty($this->pushOffered)): ?> A phone you trust can then also approve your sign-ins elsewhere.<?php endif; ?>
                            </span>
                        </label>
                    </div>
                    <?php endif; ?>

                    <p class="text-center small mt-3"><a href="<?php echo $base; ?>/login">&larr; Back to login</a></p>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="<?php echo assetUrl('assets/js/pf-webauthn.js'); ?>"></script>
<script src="<?php echo assetUrl('assets/js/pf-auth.js'); ?>"></script>
