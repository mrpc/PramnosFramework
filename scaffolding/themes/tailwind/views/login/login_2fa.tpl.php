@php /**
 * Built-in second-factor step-up (Tailwind theme) — Account/LoginFlow flow.
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
    'email_code_sent' => t('We have sent a code to your email address.'),
    'auth_link_sent'  => t('We have emailed you a link to finish signing in.'),
    'push_sent'       => t('We have sent the notification again.'),
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
    ? t('Confirm it is you, from your phone.')
    : ($authLink
    ? t('This browser has not been used with your account before, so we have emailed you a link to finish signing in.')
    : ($emailFirst
        ? t('Enter the 6-digit code we sent to your email address.')
        : t('Enter the 6-digit code from your authenticator app.'))); @endphp
<div class="flex flex-col items-center justify-center min-h-screen bg-base-200 px-4">
    <div class="card bg-base-100 shadow-md p-8 w-full max-w-sm"{!! $primary !== '' ? ' style="--color-primary:' . $primary . '"' : '' !!}>
        @include('../partials/auth_brand')
        <h1 class="text-2xl font-semibold mb-1">{{ t('Two-step verification') }}</h1>
        <p class="text-sm text-base-content/70 mb-6">{{ $intro }}</p>

        @if ($errorText !== '')
            <div role="alert" id="form-error" class="alert alert-error mb-4">{{ $errorText }}</div>
        @endif
        @if ($noticeText !== '')
            <div role="status" class="alert alert-success mb-4">{{ $noticeText }}</div>
        @endif

        {{-- The link case: nothing to type, so no code box — a field with no source
                 is how somebody concludes the mail never arrived. --}}
        @if ($authLink)
        <p class="text-sm text-base-content/70 mb-4">
            {{ t('Open the link within 15 minutes. It works once, and only for this sign-in.') }}
        </p>
        <form data-pf-progress method="POST" action="{!! $base !!}/verify{!! $returnQuery !!}">
            {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
            <input type="hidden" name="send_auth_link" value="1">
            <button type="submit" class="btn btn-neutral w-full">{{ t('Email the link again') }}</button>
        </form>
        @else

        @if ($pushFirst)
        <div class="space-y-3" data-pf-push-approval
             data-status-url="{!! $base !!}/pushstatus"
             data-other-ways="#pf-other-ways" data-patience="20">
            <p class="text-sm">
                {!! sprintf(htmlspecialchars(t('We sent a notification to %s. Open it and tap %s.'), ENT_QUOTES, 'UTF-8'), '<strong>' . htmlspecialchars($pushTo !== '' ? $pushTo : t('your phone'), ENT_QUOTES, 'UTF-8') . '</strong>', '<strong>' . htmlspecialchars(t('Yes'), ENT_QUOTES, 'UTF-8') . '</strong>') !!}
            </p>
            @if ($pushNumber !== null)
            <p class="text-sm">{{ t('This sign-in looks new, so your phone will ask you to pick this number:') }}</p>
            <p class="text-center text-5xl font-bold tracking-wider" aria-label="{{ t('The number to pick on your phone') }}">{!! $pushNumber !!}</p>
            @endif
            <p class="text-sm text-base-content/70" role="status" aria-live="polite" data-pf-push-state>
                {{ $pushStuck ? t('The notification could not be sent or has expired. Send it again, or use another way.') : t('Sent. Waiting for your phone…') }}
            </p>
            <form data-pf-progress method="POST" action="{!! $base !!}/verify{!! $returnQuery !!}" data-pf-push-finish>
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                @if (!empty($this->returnUrl))
                    <input type="hidden" name="return" value="{{ (string) $this->returnUrl }}">
                @endif
                <input type="hidden" name="method" value="push">
                <noscript><button type="submit" class="btn btn-primary w-full">{{ t('I approved it on my phone') }}</button></noscript>
            </form>
            <form data-pf-progress method="POST" action="{!! $base !!}/verify{!! $returnQuery !!}" data-pf-push-resend
                  class="{!! $pushStuck ? '' : 'hidden' !!}">
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                <input type="hidden" name="send_factor" value="push">
                <button type="submit" class="btn btn-neutral btn-sm w-full">{{ t('Send it again') }}</button>
            </form>
        </div>

        {{-- Every other way through, one click away. Opened by itself when the phone
                 has not received the prompt in twenty seconds, or it failed. --}}
        <details id="pf-other-ways" class="mt-5"{!! $pushStuck ? ' open' : '' !!}>
            <summary class="text-sm text-primary cursor-pointer">{{ t('Try another way') }}</summary>
            <div class="mt-4">
        @endif

        @if (!$pushFirst || $hasTotp || $emailFirst)
        <form data-pf-progress method="POST" action="{!! $base !!}/verify{!! $returnQuery !!}" class="space-y-4">
            {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
            @if (!empty($this->returnUrl))
                <input type="hidden" name="return" value="{{ (string) $this->returnUrl }}">
            @endif
            {{-- Names the factor, because both codes are six digits: guessing would
                     spend an email attempt every time somebody typed an app code. --}}
            <input type="hidden" name="method" value="{!! $emailFirst ? 'email' : 'totp' !!}">
            <div>
                <label for="code" class="block text-sm font-medium text-base-content mb-1">{{ t('Verification Code') }}</label>
                <input type="text" id="code" name="code" data-pf-otp
                       class="input w-full text-center text-2xl font-mono tracking-widest"
                       maxlength="6" pattern="[0-9]{6}" placeholder="000000"
                       autocomplete="one-time-code"{!! $errorFieldAttributes !!} inputmode="numeric" enterkeyhint="go" required autofocus>
            </div>
            <button type="submit" class="btn btn-primary w-full">{{ t('Verify & Sign In') }}</button>
        </form>
        @endif

        {{-- When the account has both, the app is the flow and email is a way out.

It used to be a second block of equal weight under an "or", which reads as two
choices and invites the weaker one — every account that had enrolled an
authenticator would be offered the mailbox beside it, every time. Behind a small
link it is still one click away for the person whose phone is flat, and it is not
a suggestion. --}}
        @if ($hasEmail && $hasTotp)
        <details class="mt-4" {!! $codePending ? 'open' : '' !!}>
            <summary class="text-sm text-primary cursor-pointer">{{ $pushFirst ? t('A code by email') : t('Try another way') }}</summary>
            <div class="mt-3 space-y-2">
                @if ($codePending)
                <form data-pf-progress method="POST" action="{!! $base !!}/verify{!! $returnQuery !!}" class="space-y-2">
                    {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                    <input type="hidden" name="method" value="email">
                    <label for="email-code" class="block text-sm font-medium mb-1">{{ t('Code sent to your email') }}</label>
                    <input type="text" id="email-code" name="code"
                           class="input w-full text-center text-xl font-mono tracking-widest"
                           maxlength="6" pattern="[0-9]{6}" placeholder="000000"
                           autocomplete="one-time-code" inputmode="numeric" enterkeyhint="go">
                    <button type="submit" class="btn btn-neutral btn-sm w-full">{{ t('Use the emailed code') }}</button>
                </form>
                @endif
                <form data-pf-progress method="POST" action="{!! $base !!}/verify{!! $returnQuery !!}">
                    {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                    <input type="hidden" name="send_email_code" value="1">
                    @php /*
                     * Disabled rather than hidden while the limit applies, with the wait in
                     * the label — and the label counts down (`data-pf-countdown`, handled
                     * in pf-utils.js). A number that never changes is indistinguishable
                     * from a broken button, and the usual response to one is to reload the
                     * page, which tells the reader nothing.
                     */
                    $readyLabel = $codePending ? t('Send another code') : t('Email me a code instead'); @endphp
                    <button type="submit" class="btn btn-ghost btn-sm w-full"
                            @if ($resendIn > 0)
                            disabled
                            data-pf-countdown="{!! $resendIn !!}"
                            data-pf-countdown-label="{{ t('Another code in %ss') }}"
                            data-pf-countdown-ready="{{ $readyLabel }}"
                            @endif>
                        {{ $resendIn > 0 ? t('Another code in %ss', $resendIn) : $readyLabel }}
                    </button>
                </form>
            </div>
        </details>
        @endif

        @if ($emailFirst)
        <form data-pf-progress method="POST" action="{!! $base !!}/verify{!! $returnQuery !!}" class="mt-2">
            {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
            <input type="hidden" name="send_email_code" value="1">
            <button type="submit" class="btn btn-ghost btn-sm w-full">{{ t('Send another code') }}</button>
        </form>
        @endif

        @if ($offerPasskey)
        <div class="text-center text-sm text-base-content/60 my-4">{{ t('or') }}</div>
        <button type="button" class="btn btn-neutral w-full"
                data-pf-passkey-stepup
                data-options-url="{!! $base !!}/passkeyOptions"
                data-verify-url="{!! $base !!}/passkeyVerify"
                data-redirect="{!! sURL !!}"
                data-error="#passkey-error">{{ t('Use a passkey') }}</button>
        <p id="passkey-error" class="text-error text-sm mt-2 hidden"></p>
        @endif

        {{-- Backup codes belong to the authenticator enrolment; an account with only
                 the email factor has none, and offering them would be a dead end. --}}
        @if ($hasTotp)
        <details class="mt-4">
            <summary class="text-sm text-base-content/70 cursor-pointer">{{ t('Use a backup code instead') }}</summary>
            <form data-pf-progress method="POST" action="{!! $base !!}/verify{!! $returnQuery !!}" class="mt-3 space-y-2">
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                @if (!empty($this->returnUrl))
                    <input type="hidden" name="return" value="{{ (string) $this->returnUrl }}">
                @endif
                <input type="text" name="code"
                       class="input w-full uppercase tracking-widest"
                       maxlength="8" placeholder="XXXXXXXX">
                <button type="submit" class="btn btn-neutral btn-sm w-full">{{ t('Use Backup Code') }}</button>
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
        <label class="mt-5 flex items-start gap-2 text-sm cursor-pointer">
            <input type="checkbox" class="checkbox checkbox-sm mt-0.5" data-pf-trust-device
                   {!! ($this->trustChosen ?? true) ? 'checked' : '' !!}>
            <span>
                {{ t("Don't ask again on this device") }}
                <span class="block text-base-content/60">
                    {{ t('For %d days.', (int) ($this->trustDays ?? 30)) }}@if (!empty($this->pushOffered)) {{ t('A phone you trust can then also approve your sign-ins elsewhere.') }}@endif
                </span>
            </span>
        </label>
        @endif

        <p class="text-center text-sm mt-4">
            <a href="{!! $base !!}/login" class="text-primary hover:underline">&larr; {{ t('Back to login') }}</a>
        </p>
    </div>
    @include('../partials/auth_home_link')
</div>
<script src="{!! assetUrl('assets/js/pf-webauthn.js') !!}"></script>
<script src="{!! assetUrl('assets/js/pf-auth.js') !!}"></script>
