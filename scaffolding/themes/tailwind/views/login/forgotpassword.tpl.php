@php /**
 * Forgot-password form (Tailwind theme) — Account/LoginFlow flow.
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::renderForgot):
 *   $this->routeBase, $this->brand, $this->error, $this->message
 */
$brand   = $this->brand ?? \Pramnos\Auth\Brand::current();
// The brand's colour only where one was set: otherwise the theme's own primary, light or dark.
$primary = ($brand['primary_color_set'] ?? true) ? htmlspecialchars((string) ($brand['primary_color'] ?? ''), ENT_QUOTES) : '';
$base    = sURL . rawurlencode((string) ($this->routeBase ?? 'Account'));

$errorMessages = [
    'human_check' => t('The security check did not complete. Reload the page and try again — it needs a modern browser with JavaScript enabled.'),
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
<div class="flex flex-col items-center justify-center min-h-screen bg-base-200 px-4">
    <div class="card bg-base-100 shadow-md p-8 w-full max-w-sm"{!! $primary !== '' ? ' style="--color-primary:' . $primary . '"' : '' !!}>
        @include('../partials/auth_brand')
        <h1 class="text-2xl font-semibold mb-1">{{ t('Forgot your password?') }}</h1>
        <p class="text-sm text-base-content/70 mb-6">{{ t("Enter your email and we'll send you a reset link.") }}</p>

        @if ($sent)
            <div role="status" class="alert alert-info mb-4">{{ t('If an account exists for that email, a password-reset link is on its way. Check your inbox.') }}</div>
        @else
            @if ($errorText !== '')
                <div role="alert" id="form-error" class="alert alert-error mb-4">{{ $errorText }}</div>
            @endif
            <form data-pf-progress method="POST" action="{!! $base !!}/forgotpassword" class="space-y-4">
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
            {{-- The human check's fields, when the application asks for one. Renders
                     nothing otherwise, so the insert is unconditional. --}}
            {!! humanCheckField($this->humanCheck ?? null) !!}
                <div>
                    <label for="email" class="block text-sm font-medium text-base-content mb-1">{{ t('Email') }}</label>
                    <input type="email" name="email" id="email" class="input w-full" value="{{ (string) ($this->email ?? '') }}" required autofocus autocomplete="email"{!! $errorFieldAttributes !!} enterkeyhint="go">
                </div>
                <button type="submit" class="btn btn-primary w-full">{{ t('Send reset link') }}</button>
            </form>
        @endif
        <p class="text-center text-sm mt-4">
            <a href="{!! $base !!}/login" class="text-primary hover:underline">&larr; {{ t('Back to login') }}</a>
        </p>
    </div>
    @include('../partials/auth_home_link')
</div>
<script src="{!! assetUrl('assets/js/pf-auth.js') !!}"></script>
