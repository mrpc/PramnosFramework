@php /**
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
<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:60vh;padding:20px">
    <div class="card" style="width:100%;max-width:400px">
        @include('../partials/auth_brand')
        <div class="card-header">
            <h2 style="margin:0;font-size:1.25rem">{{ t('Two-step verification') }}</h2>
            <p style="margin:4px 0 0;font-size:13px;color:#666">{{ $intro }}</p>
        </div>
        <div class="card-body" style="padding:24px">

            @if ($errorText !== '')
                <div role="alert" id="form-error" class="alert alert-danger">{{ $errorText }}</div>
            @endif
            @if ($noticeText !== '')
                <div role="status" class="alert alert-success">{{ $noticeText }}</div>
            @endif

            {{-- The link case: nothing to type, so no code box — a field with no source
                     is how somebody concludes the mail never arrived. --}}
            @if ($authLink)
            <p style="font-size:13px;color:#666">{{ t('Open the link within 15 minutes. It works once, and only for this sign-in.') }}</p>
            <form data-pf-progress method="POST" action="{!! $base !!}/verify{!! $returnQuery !!}">
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                <input type="hidden" name="send_auth_link" value="1">
                <button type="submit" class="btn" style="width:100%;background-color:#374151;border-color:#374151">{{ t('Email the link again') }}</button>
            </form>
            @else

            @if ($pushFirst)
            <div data-pf-push-approval
                 data-status-url="{!! $base !!}/pushstatus"
                 data-other-ways="#pf-other-ways" data-patience="20">
                <p style="font-size:14px">
                    {!! sprintf(htmlspecialchars(t('We sent a notification to %s. Open it and tap %s.'), ENT_QUOTES, 'UTF-8'), '<strong>' . htmlspecialchars($pushTo !== '' ? $pushTo : t('your phone'), ENT_QUOTES, 'UTF-8') . '</strong>', '<strong>' . htmlspecialchars(t('Yes'), ENT_QUOTES, 'UTF-8') . '</strong>') !!}
                </p>
                @if ($pushNumber !== null)
                <p style="font-size:14px">{{ t('This sign-in looks new, so your phone will ask you to pick this number:') }}</p>
                <p style="text-align:center;font-size:48px;font-weight:700;letter-spacing:.05em;margin:8px 0" aria-label="{{ t('The number to pick on your phone') }}">{!! $pushNumber !!}</p>
                @endif
                <p style="font-size:13px;color:#666" role="status" aria-live="polite" data-pf-push-state>
                    {{ $pushStuck ? t('The notification could not be sent or has expired. Send it again, or use another way.') : t('Sent. Waiting for your phone…') }}
                </p>
                <form data-pf-progress method="POST" action="{!! $base !!}/verify{!! $returnQuery !!}" data-pf-push-finish>
                    {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                    @if (!empty($this->returnUrl))
                        <input type="hidden" name="return" value="{{ (string) $this->returnUrl }}">
                    @endif
                    <input type="hidden" name="method" value="push">
                    <noscript><button type="submit" class="btn" style="width:100%;{!! $primary !== '' ? 'background-color:' . $primary . ';border-color:' . $primary : '' !!}">{{ t('I approved it on my phone') }}</button></noscript>
                </form>
                <form data-pf-progress method="POST" action="{!! $base !!}/verify{!! $returnQuery !!}" data-pf-push-resend
                      style="{!! $pushStuck ? '' : 'display:none' !!}">
                    {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                    <input type="hidden" name="send_factor" value="push">
                    <button type="submit" class="btn btn-sm" style="width:100%">{{ t('Send it again') }}</button>
                </form>
            </div>

            {{-- Every other way through, one click away. Opened by itself when the phone
                     has not received the prompt in twenty seconds, or it failed. --}}
            <details id="pf-other-ways" style="margin-top:20px"{!! $pushStuck ? ' open' : '' !!}>
                <summary style="font-size:13px;color:{!! $primary !== '' ? $primary : 'var(--primary-color)' !!};cursor:pointer">{{ t('Try another way') }}</summary>
                <div style="margin-top:16px">
            @endif

            @if (!$pushFirst || $hasTotp || $emailFirst)
            <form data-pf-progress method="POST" action="{!! $base !!}/verify{!! $returnQuery !!}">
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                @if (!empty($this->returnUrl))
                    <input type="hidden" name="return" value="{{ (string) $this->returnUrl }}">
                @endif
                {{-- Names the factor, because both codes are six digits: guessing would
                         spend an email attempt every time somebody typed an app code. --}}
                <input type="hidden" name="method" value="{!! $emailFirst ? 'email' : 'totp' !!}">
                <div style="margin-bottom:20px">
                    <label for="code" style="display:block;margin-bottom:4px;font-weight:500">{{ t('Verification Code') }}</label>
                    <input type="text" id="code" name="code" data-pf-otp
                           style="width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-family:monospace;font-size:22px;text-align:center;letter-spacing:.15em"
                           maxlength="6" pattern="[0-9]{6}" placeholder="000000"
                           autocomplete="one-time-code"{!! $errorFieldAttributes !!} inputmode="numeric" enterkeyhint="go" required autofocus>
                </div>
                <button type="submit" class="btn" style="width:100%;{!! $primary !== '' ? 'background-color:' . $primary . ';border-color:' . $primary : '' !!}">{{ t('Verify & Sign In') }}</button>
            </form>
            @endif

            @if ($hasEmail && $hasTotp)
            <div style="text-align:center;margin:16px 0;color:#888;font-size:13px">{{ t('or') }}</div>
            @if ($codePending)
            <form data-pf-progress method="POST" action="{!! $base !!}/verify{!! $returnQuery !!}" style="margin-bottom:8px">
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                <input type="hidden" name="method" value="email">
                <label for="email-code" style="display:block;margin-bottom:4px;font-weight:500">{{ t('Code sent to your email') }}</label>
                <input type="text" id="email-code" name="code"
                       style="width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-family:monospace;font-size:20px;text-align:center;letter-spacing:.15em;margin-bottom:8px"
                       maxlength="6" pattern="[0-9]{6}" placeholder="000000" autocomplete="one-time-code" inputmode="numeric" enterkeyhint="go">
                <button type="submit" class="btn" style="width:100%;background-color:#374151;border-color:#374151">{{ t('Use the emailed code') }}</button>
            </form>
            @endif
            <form data-pf-progress method="POST" action="{!! $base !!}/verify{!! $returnQuery !!}">
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                <input type="hidden" name="send_email_code" value="1">
                <button type="submit" class="btn btn-sm" style="width:100%">
                    {{ $codePending ? t('Send another code') : t('Email me a code instead') }}
                </button>
            </form>
            @endif

            @if ($emailFirst)
            <form data-pf-progress method="POST" action="{!! $base !!}/verify{!! $returnQuery !!}">
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                <input type="hidden" name="send_email_code" value="1">
                <button type="submit" class="btn btn-sm" style="width:100%">{{ t('Send another code') }}</button>
            </form>
            @endif

            @if ($offerPasskey)
            <div style="text-align:center;margin:16px 0;color:#888;font-size:13px">{{ t('or') }}</div>
            <button type="button" class="btn" style="width:100%;background-color:#374151;border-color:#374151"
                    data-pf-passkey-stepup
                    data-options-url="{!! $base !!}/passkeyOptions"
                    data-verify-url="{!! $base !!}/passkeyVerify"
                    data-redirect="{!! sURL !!}"
                    data-error="#passkey-error">{{ t('Use a passkey') }}</button>
            <p id="passkey-error" style="color:#b91c1c;font-size:13px;margin-top:8px;display:none"></p>
            @endif

            {{-- Backup codes belong to the authenticator enrolment; an account with only
                     the email factor has none, and offering them would be a dead end. --}}
            @if ($hasTotp)
            <details style="margin-top:16px">
                <summary style="font-size:13px;color:#666;cursor:pointer">{{ t('Use a backup code instead') }}</summary>
                <form data-pf-progress method="POST" action="{!! $base !!}/verify{!! $returnQuery !!}" style="margin-top:8px">
                    {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                    @if (!empty($this->returnUrl))
                        <input type="hidden" name="return" value="{{ (string) $this->returnUrl }}">
                    @endif
                    <input type="text" name="code"
                           style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;text-transform:uppercase;letter-spacing:.1em;font-size:15px;margin-bottom:8px"
                           maxlength="8" placeholder="XXXXXXXX">
                    <button type="submit" class="btn btn-sm" style="width:100%">{{ t('Use Backup Code') }}</button>
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
            <label style="display:flex;align-items:flex-start;gap:8px;margin-top:20px;font-size:14px;cursor:pointer">
                <input type="checkbox" style="margin-top:3px" data-pf-trust-device
                       {!! ($this->trustChosen ?? true) ? 'checked' : '' !!}>
                <span>
                    {{ t('Don\'t ask again on this device') }}
                    <span style="display:block;color:#666;font-size:13px">
                        {{ t('For %d days.', (int) ($this->trustDays ?? 30)) }}@if (!empty($this->pushOffered)) {{ t('A phone you trust can then also approve your sign-ins elsewhere.') }}@endif
                    </span>
                </span>
            </label>
            @endif

            <div style="text-align:center;margin-top:12px">
                <a href="{!! $base !!}/login" style="font-size:13px">&larr; {{ t('Back to login') }}</a>
            </div>
        </div>
    </div>
    @include('../partials/auth_home_link')
</div>
<script src="{!! assetUrl('assets/js/pf-webauthn.js') !!}"></script>
<script src="{!! assetUrl('assets/js/pf-auth.js') !!}"></script>
