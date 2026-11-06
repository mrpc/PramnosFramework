@php /**
 * Built-in second-factor step-up (Bootstrap theme) — Account/LoginFlow flow.
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::renderStepUp):
 *   $this->routeBase, $this->brand, $this->error, $this->returnUrl,
 *   $this->pendingUserId, $this->methods
 *
 * No password here — LoginFlow holds the pending login server-side. The form
 * submits only the code to <routeBase>/verify.
 */
$brand   = $this->brand ?? \Pramnos\Auth\Brand::current();
// The brand's colour only where one was set: otherwise the theme's own primary, light or dark.
$primary = ($brand['primary_color_set'] ?? true) ? htmlspecialchars((string) ($brand['primary_color'] ?? ''), ENT_QUOTES) : '';
$base    = sURL . rawurlencode((string) ($this->routeBase ?? 'Account'));
// The return address in the form's own URL too, so a refresh after a failed attempt keeps it.
$returnQuery = (string) ($this->returnUrl ?? '') !== ''
    ? '?return=' . htmlspecialchars(rawurlencode((string) $this->returnUrl))
    : '';

$errorMessages = [
    'invalid_token' => t('Your session expired. Please try again.'),
    'missing_code'  => t('Please enter your verification code.'),
    'invalid_code'  => t('Invalid or expired code. Please try again.'),
    'email_code_failed' => t('We could not send a code to your email address.'),
    'email_code_wait'   => t('We have already sent you a code. You can ask for another one in %d seconds.'),
    'auth_link_failed'  => t('We could not email you a sign-in link.'),
    'authlink_invalid'  => t('That sign-in link has been used or has expired. Please sign in again.'),
    'push_failed'       => t('We could not send a notification to your phone just now. Use another way below.'),
    'push_not_approved' => t('Your phone has not approved this sign-in.'),
];
$errorKey  = (string) ($this->error ?? '');
$errorText = $errorMessages[$errorKey] ?? $errorKey;
// The wait message carries a number, so it is the one error that is formatted.
if ($errorKey === 'email_code_wait') {
    $errorText = sprintf($errorText, max(1, (int) ($this->resendIn ?? 0)));
}
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
    'email_code_sent' => t('We have sent a code to your email address.'),
    'auth_link_sent'  => t('We have emailed you a link to finish signing in.'),
    'push_sent'       => t('We have sent the notification again.'),
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
    ? t('Confirm it is you, from your phone.')
    : ($authLink
    ? t('This browser has not been used with your account before, so we have emailed you a link to finish signing in.')
    : ($emailFirst
        ? t('Enter the 6-digit code we sent to your email address.')
        : t('Enter the 6-digit code from your authenticator app.'))); @endphp
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    @include('../partials/auth_brand')
                    <h1 class="h4 mb-1">{{ t('Two-step verification') }}</h1>
                    <p class="text-muted small mb-3">{{ $intro }}</p>

                    @if ($errorText !== '')
                        <div role="alert" id="form-error" class="alert alert-danger">{{ $errorText }}</div>
                    @endif
                    @if ($noticeText !== '')
                        <div role="status" class="alert alert-success">{{ $noticeText }}</div>
                    @endif

                    {{-- The link case: nothing to type, so no code box — a field with no
                             source is how somebody concludes the mail never arrived. --}}
                    @if ($authLink)
                    <p class="text-muted small">{{ t('Open the link within 15 minutes. It works once, and only for this sign-in.') }}</p>
                    <form data-pf-progress method="POST" action="{!! $base !!}/verify{!! $returnQuery !!}">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                        <input type="hidden" name="send_auth_link" value="1">
                        <button type="submit" class="btn btn-secondary w-100">{{ t('Email the link again') }}</button>
                    </form>
                    @else

                    @if ($pushFirst)
                    <div data-pf-push-approval
                         data-status-url="{!! $base !!}/pushstatus"
                         data-other-ways="#pf-other-ways" data-patience="20">
                        <p class="small">
                            {!! sprintf(htmlspecialchars(t('We sent a notification to %s. Open it and tap %s.'), ENT_QUOTES, 'UTF-8'), '<strong>' . htmlspecialchars($pushTo !== '' ? $pushTo : t('your phone'), ENT_QUOTES, 'UTF-8') . '</strong>', '<strong>' . htmlspecialchars(t('Yes'), ENT_QUOTES, 'UTF-8') . '</strong>') !!}
                        </p>
                        @if ($pushNumber !== null)
                        <p class="small">{{ t('This sign-in looks new, so your phone will ask you to pick this number:') }}</p>
                        <p class="text-center display-4 fw-bold" aria-label="{{ t('The number to pick on your phone') }}">{!! $pushNumber !!}</p>
                        @endif
                        <p class="small text-muted" role="status" aria-live="polite" data-pf-push-state>
                            {{ $pushStuck ? t('The notification could not be sent or has expired. Send it again, or use another way.') : t('Sent. Waiting for your phone…') }}
                        </p>
                        <form data-pf-progress method="POST" action="{!! $base !!}/verify{!! $returnQuery !!}" data-pf-push-finish>
                            {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                            @if (!empty($this->returnUrl))
                                <input type="hidden" name="return" value="{{ (string) $this->returnUrl }}">
                            @endif
                            <input type="hidden" name="method" value="push">
                            <noscript><button type="submit" class="btn btn-primary w-100">{{ t('I approved it on my phone') }}</button></noscript>
                        </form>
                        <form data-pf-progress method="POST" action="{!! $base !!}/verify{!! $returnQuery !!}" data-pf-push-resend
                              class="{!! $pushStuck ? '' : 'd-none' !!}">
                            {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                            <input type="hidden" name="send_factor" value="push">
                            <button type="submit" class="btn btn-secondary btn-sm w-100">{{ t('Send it again') }}</button>
                        </form>
                    </div>

                    {{-- Every other way through, one click away. Opened by itself when the
                             phone has not received the prompt in twenty seconds, or it failed. --}}
                    <details id="pf-other-ways" class="mt-4"{!! $pushStuck ? ' open' : '' !!}>
                        <summary class="small" style="cursor:pointer">{{ t('Try another way') }}</summary>
                        <div class="mt-3">
                    @endif

                    @if (!$pushFirst || $hasTotp || $emailFirst)
                    <form data-pf-progress method="POST" action="{!! $base !!}/verify{!! $returnQuery !!}">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                        @if (!empty($this->returnUrl))
                            <input type="hidden" name="return" value="{{ (string) $this->returnUrl }}">
                        @endif
                        {{-- Names the factor, because both codes are six digits: guessing
                                 would spend an email attempt every time somebody typed an
                                 app code. --}}
                        <input type="hidden" name="method" value="{!! $emailFirst ? 'email' : 'totp' !!}">
                        <div class="mb-3">
                            <label for="code" class="form-label">{{ t('Verification Code') }}</label>
                            <input type="text" id="code" name="code" data-pf-otp class="form-control text-center fs-4 font-monospace"
                                   maxlength="6" pattern="[0-9]{6}" placeholder="000000"
                                   autocomplete="one-time-code"{!! $errorFieldAttributes !!} inputmode="numeric" enterkeyhint="go" required autofocus>
                        </div>
                        <button type="submit" class="btn btn-primary w-100" style="{!! $primary !== '' ? 'background-color:' . $primary . ';border-color:' . $primary : '' !!}">{{ t('Verify & Sign In') }}</button>
                    </form>
                    @endif

                    @if ($hasEmail && $hasTotp)
                    <div class="text-center text-muted small my-3">{{ t('or') }}</div>
                    @if ($codePending)
                    <form data-pf-progress method="POST" action="{!! $base !!}/verify{!! $returnQuery !!}" class="mb-2">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                        <input type="hidden" name="method" value="email">
                        <label for="email-code" class="form-label">{{ t('Code sent to your email') }}</label>
                        <input type="text" id="email-code" name="code" class="form-control text-center font-monospace mb-2"
                               maxlength="6" pattern="[0-9]{6}" placeholder="000000" autocomplete="one-time-code" inputmode="numeric" enterkeyhint="go">
                        <button type="submit" class="btn btn-secondary w-100">{{ t('Use the emailed code') }}</button>
                    </form>
                    @endif
                    <form data-pf-progress method="POST" action="{!! $base !!}/verify{!! $returnQuery !!}">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                        <input type="hidden" name="send_email_code" value="1">
                        <button type="submit" class="btn btn-link btn-sm w-100">
                            {{ $codePending ? t('Send another code') : t('Email me a code instead') }}
                        </button>
                    </form>
                    @endif

                    @if ($emailFirst)
                    <form data-pf-progress method="POST" action="{!! $base !!}/verify{!! $returnQuery !!}">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                        <input type="hidden" name="send_email_code" value="1">
                        <button type="submit" class="btn btn-link btn-sm w-100">{{ t('Send another code') }}</button>
                    </form>
                    @endif

                    @if ($offerPasskey)
                    <div class="text-center text-muted small my-3">{{ t('or') }}</div>
                    <button type="button" class="btn btn-dark w-100"
                            data-pf-passkey-stepup
                            data-options-url="{!! $base !!}/passkeyOptions"
                            data-verify-url="{!! $base !!}/passkeyVerify"
                            data-redirect="{!! sURL !!}"
                            data-error="#passkey-error">{{ t('Use a passkey') }}</button>
                    <p id="passkey-error" class="text-danger small mt-2 d-none"></p>
                    @endif

                    {{-- Backup codes belong to the authenticator enrolment; an account with
                             only the email factor has none, and offering them would be a dead
                             end. --}}
                    @if ($hasTotp)
                    <details class="mt-3">
                        <summary class="small text-muted" style="cursor:pointer">{{ t('Use a backup code instead') }}</summary>
                        <form data-pf-progress method="POST" action="{!! $base !!}/verify{!! $returnQuery !!}" class="mt-2">
                            {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                            @if (!empty($this->returnUrl))
                                <input type="hidden" name="return" value="{{ (string) $this->returnUrl }}">
                            @endif
                            <div class="mb-2">
                                <input type="text" name="code" class="form-control text-uppercase"
                                       maxlength="8" placeholder="XXXXXXXX" style="letter-spacing:.1em">
                            </div>
                            <button type="submit" class="btn btn-secondary w-100 btn-sm">{{ t('Use Backup Code') }}</button>
                        </form>
                    </details>
                    @endif

                    @if ($pushFirst)
                        </div>
                    </details>
                    @endif

                    @endif

                    {{-- Google's "Don't ask again on this device", ticked as Google ticks it. --}}
                    @if (!empty($this->canTrust))
                    <div class="form-check mt-4 small">
                        <input type="checkbox" class="form-check-input" id="pf-trust-device" data-pf-trust-device
                               {!! ($this->trustChosen ?? true) ? 'checked' : '' !!}>
                        <label class="form-check-label" for="pf-trust-device">
                            {{ t('Don\'t ask again on this device') }}
                            <span class="d-block text-muted">
                                {{ t('For %d days.', (int) ($this->trustDays ?? 30)) }}@if (!empty($this->pushOffered)) {{ t('A phone you trust can then also approve your sign-ins elsewhere.') }}@endif
                            </span>
                        </label>
                    </div>
                    @endif

                    <p class="text-center small mt-3"><a href="{!! $base !!}/login">&larr; {{ t('Back to login') }}</a></p>
                </div>
            </div>
            @include('../partials/auth_home_link')
        </div>
    </div>
</div>
<script src="{!! assetUrl('assets/js/pf-webauthn.js') !!}"></script>
<script src="{!! assetUrl('assets/js/pf-auth.js') !!}"></script>
