@php /**
 * Reset-password form (plain-CSS theme) — Account/LoginFlow flow.
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::renderReset):
 *   $this->routeBase — controller route base (form action prefix)
 *   $this->brand     — [name, logo, primary_color, footer]
 *   $this->token     — the reset token (carried in a hidden field)
 *   $this->error     — optional error key
 */
$brand   = $this->brand ?? \Pramnos\Auth\Brand::current();
// The brand's colour only where one was set: otherwise the theme's own primary, light or dark.
$primary = ($brand['primary_color_set'] ?? true) ? htmlspecialchars((string) ($brand['primary_color'] ?? ''), ENT_QUOTES) : '';
$base    = sURL . rawurlencode((string) ($this->routeBase ?? 'Account'));
$token   = (string) ($this->token ?? '');

$errorMessages = [
    'invalid_token'          => t('Your session expired. Please try again.'),
    'invalid_reset_link'     => t('This reset link is invalid or has expired. Please request a new one.'),
    'password_required'      => t('Please enter a new password.'),
    'password_too_short'     => t('Your password must be at least 8 characters long.'),
    'password_needs_digit'   => t('Your password must contain at least one number.'),
    'password_needs_symbol'  => t('Your password must contain at least one symbol.'),
    'passwords_do_not_match' => t('The two passwords do not match.'),
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

$expired   = ($errorKey === 'invalid_reset_link' && $token === ''); @endphp
<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:60vh;padding:20px">
    <div class="card" style="width:100%;max-width:400px">
        @include('../partials/auth_brand')
        <div class="card-header"><h2 style="margin:0;font-size:1.25rem">{{ t('Choose a new password') }}</h2></div>
        <div class="card-body" style="padding:24px">
            @if ($errorText !== '')
                <div role="alert" id="form-error" class="alert alert-danger">{{ $errorText }}</div>
            @endif

            @if ($expired)
                <div style="text-align:center;margin-top:12px">
                    <a href="{!! $base !!}/forgotpassword" style="font-size:13px">{{ t('Request a new reset link') }}</a>
                </div>
            @else
                <div data-pf-password-error role="alert" class="alert alert-danger" style="display:none"></div>
                <form data-pf-progress method="POST" action="{!! $base !!}/resetpassword" data-pf-password-policy>
                    {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                    <input type="hidden" name="token" value="{{ $token }}">
                    <div style="margin-bottom:16px">
                        <label for="password" style="display:block;margin-bottom:4px;font-weight:500">{{ t('New password') }}</label>
                        <input type="password" name="password" id="password" style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:15px" required autofocus autocomplete="new-password"{!! $errorFieldAttributes !!}>
                        {!! \Pramnos\Html\PasswordToggle::render('password', '', '') !!}
                        <small style="color:#666">{{ t('At least 8 characters, with a number and a symbol.') }}</small>
                    </div>
                    <div style="margin-bottom:20px">
                        <label for="confirm_password" style="display:block;margin-bottom:4px;font-weight:500">{{ t('Confirm new password') }}</label>
                        <input type="password" name="confirm_password" enterkeyhint="go" id="confirm_password" style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:15px" required autocomplete="new-password">
                        {!! \Pramnos\Html\PasswordToggle::render('confirm_password', '', '') !!}
                    </div>
                    <button type="submit" class="btn" style="width:100%;{!! $primary !== '' ? 'background-color:' . $primary . ';border-color:' . $primary : '' !!}">{{ t('Reset password') }}</button>
                </form>
            @endif
            <div style="text-align:center;margin-top:12px">
                <a href="{!! $base !!}/login" style="font-size:13px">&larr; {{ t('Back to login') }}</a>
            </div>
        </div>
    </div>
    @include('../partials/auth_home_link')
</div>
<script src="{!! assetUrl('assets/js/pf-auth.js') !!}"></script>
