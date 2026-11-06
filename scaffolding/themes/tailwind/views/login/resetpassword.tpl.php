@php /**
 * Reset-password form (Tailwind theme) — Account/LoginFlow flow.
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::renderReset):
 *   $this->routeBase, $this->brand, $this->token, $this->error
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
<div class="flex flex-col items-center justify-center min-h-screen bg-base-200 px-4">
    <div class="card bg-base-100 shadow-md p-8 w-full max-w-sm"{!! $primary !== '' ? ' style="--color-primary:' . $primary . '"' : '' !!}>
        @include('../partials/auth_brand')
        <h1 class="text-2xl font-semibold mb-4">{{ t('Choose a new password') }}</h1>

        @if ($errorText !== '')
            <div role="alert" id="form-error" class="alert alert-error mb-4">{{ $errorText }}</div>
        @endif

        @if ($expired)
            <p class="text-center text-sm">
                <a href="{!! $base !!}/forgotpassword" class="text-primary hover:underline">{{ t('Request a new reset link') }}</a>
            </p>
        @else
            <div data-pf-password-error role="alert" class="alert alert-error mb-4 hidden"></div>
            <form data-pf-progress method="POST" action="{!! $base !!}/resetpassword" data-pf-password-policy class="space-y-4">
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                <input type="hidden" name="token" value="{{ $token }}">
                <div>
                    <label for="password" class="block text-sm font-medium text-base-content mb-1">{{ t('New password') }}</label>
                    <input type="password" name="password" id="password" class="input w-full" required autofocus autocomplete="new-password"{!! $errorFieldAttributes !!}>
                    {!! \Pramnos\Html\PasswordToggle::render('password', '', '') !!}
                    <p class="text-xs text-base-content/70 mt-1">{{ t('At least 8 characters, with a number and a symbol.') }}</p>
                </div>
                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-base-content mb-1">{{ t('Confirm new password') }}</label>
                    <input type="password" name="confirm_password" enterkeyhint="go" id="confirm_password" class="input w-full" required autocomplete="new-password">
                    {!! \Pramnos\Html\PasswordToggle::render('confirm_password', '', '') !!}
                </div>
                <button type="submit" class="btn btn-primary w-full">{{ t('Reset password') }}</button>
            </form>
        @endif
        <p class="text-center text-sm mt-4">
            <a href="{!! $base !!}/login" class="text-primary hover:underline">&larr; {{ t('Back to login') }}</a>
        </p>
    </div>
    @include('../partials/auth_home_link')
</div>
<script src="{!! assetUrl('assets/js/pf-auth.js') !!}"></script>
