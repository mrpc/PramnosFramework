@php /**
 * Built-in login form (Tailwind theme) — Account/LoginFlow flow.
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::renderLogin):
 *   $this->routeBase, $this->brand, $this->error, $this->returnUrl, $this->lockoutSeconds
 *
 * The password is submitted once to <routeBase>/login; any second factor is
 * handled server-side (see login_2fa) — no password round-trip.
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
    'human_check'       => t('The security check did not complete. Reload the page and try again — it needs a modern browser with JavaScript enabled.'),
    'invalid_token'       => t('Your session expired. Please try again.'),
    'missing_credentials' => t('Please enter your username and password.'),
    'invalid_credentials' => t('Invalid username or password.'),
    'email_unverified'    => t('Confirm your email address first: open the link we emailed you. Signing in again after ten minutes sends a new one.'),
    'locked'              => t('Too many attempts. Please wait a moment and try again.'),
    'session_expired'     => t('Your login session expired. Please sign in again.'),
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
    : ''; @endphp
<div class="flex flex-col items-center justify-center min-h-screen bg-base-200 px-4">
    <div class="card bg-base-100 shadow-md p-8 w-full max-w-sm"{!! $primary !== '' ? ' style="--color-primary:' . $primary . '"' : '' !!}>
        @include('../partials/auth_brand')

        @if ($errorText !== '')
            <div role="alert" id="form-error" class="alert alert-error mb-4">{{ $errorText }}</div>
        @endif
        @if ($this->hasMessages())
            <div role="status" class="alert alert-info mb-4">{!! $this->_printMessages() !!}</div>
        @endif

        @if (($this->lockoutSeconds ?? 0) > 0)
        <script>
        (function() {
            var until = Date.now() + {!! (int) $this->lockoutSeconds !!} * 1000;
            document.addEventListener('DOMContentLoaded', function() {
                var btn = document.querySelector('.login-submit');
                if (!btn) return;
                var orig = btn.textContent;
                btn.disabled = true;
                (function tick() {
                    var s = Math.ceil((until - Date.now()) / 1000);
                    if (s <= 0) { btn.disabled = false; btn.textContent = orig; return; }
                    btn.textContent = orig + ' (' + s + 's)';
                    setTimeout(tick, 500);
                })();
            });
        })();
        </script>
        @endif

        <form data-pf-progress method="POST" action="{!! $base !!}/login{!! $returnQuery !!}" class="space-y-4">
            {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
            {{-- The human check's fields, when the application asks for one. Renders
                     nothing otherwise, so the insert is unconditional. --}}
            {!! humanCheckField($this->humanCheck ?? null) !!}
            @if (!empty($this->returnUrl))
                <input type="hidden" name="return" value="{{ (string) $this->returnUrl }}">
            @endif
            <div>
                <label for="username" class="block text-sm font-medium text-base-content mb-1">{{ t('Username or Email') }}</label>
                <input type="text" name="username" id="username" class="input w-full" value="{{ (string) ($this->username ?? '') }}" required autocomplete="username webauthn"{!! $errorFieldAttributes !!} autocapitalize="none" autocorrect="off" spellcheck="false" autofocus>
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-base-content mb-1">{{ t('Password') }}</label>
                <input type="password" name="password" id="password" class="input w-full" required autocomplete="current-password" enterkeyhint="go">
                {!! \Pramnos\Html\PasswordToggle::render('password', '', '') !!}
            </div>
            <label class="flex items-center gap-2 text-sm text-base-content">
                <input type="checkbox" name="remember" value="1" checked> {{ t('Remember me') }}
            </label>
            <button type="submit" class="btn btn-primary login-submit w-full">{{ t('Sign In') }}</button>
        </form>

        <div id="passkey-login-wrap" class="hidden">
            <div class="text-center text-sm text-base-content/60 my-4">{{ t('or') }}</div>
            <button type="button" class="btn btn-neutral w-full"
                    data-pf-passkey-login
                    data-options-url="{!! sURL !!}Passkey/loginOptions"
                    data-verify-url="{!! sURL !!}Passkey/login"
                    data-redirect="{!! sURL !!}"
                    data-wrap="#passkey-login-wrap"
                    data-error="#passkey-login-error">{{ t('Sign in with a passkey') }}</button>
            <p id="passkey-login-error" class="text-error text-sm mt-2 hidden"></p>
        </div>

        <p class="text-center text-sm mt-4">
            <a href="{!! $base !!}/forgotpassword" class="text-primary hover:underline">{{ t('Forgot your password?') }}</a>
        </p>
        @if (!empty($brand['footer']))
            <p class="text-center text-base-content/60 text-xs mt-4">{{ (string) $brand['footer'] }}</p>
        @endif
    </div>
    @include('../partials/auth_home_link')
</div>
<script src="{!! assetUrl('assets/js/pf-webauthn.js') !!}"></script>
<script src="{!! assetUrl('assets/js/pf-auth.js') !!}"></script>
