@php /**
 * Forgot-password form (plain-CSS theme) — Account/LoginFlow flow.
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::renderForgot):
 *   $this->routeBase — controller route base (form action prefix)
 *   $this->brand     — [name, logo, primary_color, footer]
 *   $this->error     — optional error key
 *   $this->message   — optional message key ('sent')
 */
$brand   = $this->brand ?? \Pramnos\Auth\Brand::current();
// The brand's colour only where one was set: otherwise the theme's own primary, light or dark.
$primary = ($brand['primary_color_set'] ?? true) ? htmlspecialchars((string) ($brand['primary_color'] ?? ''), ENT_QUOTES) : '';
$base    = sURL . rawurlencode((string) ($this->routeBase ?? 'Account'));

$errorMessages = [
    'invalid_token' => t('Your session expired. Please try again.'),
    'invalid_email' => t('Please enter a valid email address.'),
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

$sent      = (($this->message ?? '') === 'sent'); @endphp
<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:60vh;padding:20px">
    <div class="card" style="width:100%;max-width:400px">
        @include('../partials/auth_brand')
        <div class="card-header">
            <h2 style="margin:0;font-size:1.25rem">{{ t('Forgot your password?') }}</h2>
            <p style="margin:4px 0 0;font-size:13px;color:#666">{{ t('Enter your email and we\'ll send you a reset link.') }}</p>
        </div>
        <div class="card-body" style="padding:24px">
            @if ($sent)
                <div role="status" class="alert alert-info">{{ t('If an account exists for that email, a password-reset link is on its way. Check your inbox.') }}</div>
                <div style="text-align:center;margin-top:12px">
                    <a href="{!! $base !!}/login" style="font-size:13px">&larr; {{ t('Back to login') }}</a>
                </div>
            @else
                @if ($errorText !== '')
                    <div role="alert" id="form-error" class="alert alert-danger">{{ $errorText }}</div>
                @endif
                <form data-pf-progress method="POST" action="{!! $base !!}/forgotpassword">
                    {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                    <div style="margin-bottom:20px">
                        <label for="email" style="display:block;margin-bottom:4px;font-weight:500">{{ t('Email') }}</label>
                        <input type="email" name="email" id="email" style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:15px" value="{{ (string) ($this->email ?? '') }}" required autofocus autocomplete="email"{!! $errorFieldAttributes !!} enterkeyhint="go">
                    </div>
                    <button type="submit" class="btn" style="width:100%;{!! $primary !== '' ? 'background-color:' . $primary . ';border-color:' . $primary : '' !!}">{{ t('Send reset link') }}</button>
                </form>
                <div style="text-align:center;margin-top:12px">
                    <a href="{!! $base !!}/login" style="font-size:13px">&larr; {{ t('Back to login') }}</a>
                </div>
            @endif
        </div>
    </div>
    @include('../partials/auth_home_link')
</div>
<script src="{!! assetUrl('assets/js/pf-auth.js') !!}"></script>
