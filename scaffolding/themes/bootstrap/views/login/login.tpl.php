@php /**
 * Built-in login form (Bootstrap theme) — Account/LoginFlow flow.
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
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    @include('../partials/auth_brand')

                    @if ($errorText !== '')
                        <div role="alert" id="form-error" class="alert alert-danger">{{ $errorText }}</div>
                    @endif
                    @if ($this->hasMessages())
                        <div role="status" class="alert alert-info">{!! $this->_printMessages() !!}</div>
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

                    <form data-pf-progress method="POST" action="{!! $base !!}/login{!! $returnQuery !!}">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                        @if (!empty($this->returnUrl))
                            <input type="hidden" name="return" value="{{ (string) $this->returnUrl }}">
                        @endif
                        <div class="mb-3">
                            <label for="username" class="form-label">{{ t('Username or Email') }}</label>
                            <input type="text" name="username" id="username" class="form-control" value="{{ (string) ($this->username ?? '') }}" required autocomplete="username webauthn"{!! $errorFieldAttributes !!} autocapitalize="none" autocorrect="off" spellcheck="false" autofocus>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">{{ t('Password') }}</label>
                            <input type="password" name="password" id="password" class="form-control" required autocomplete="current-password" enterkeyhint="go">
                            {!! \Pramnos\Html\PasswordToggle::render('password', '', '') !!}
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1" checked>
                            <label class="form-check-label" for="remember">{{ t('Remember me') }}</label>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 login-submit" style="{!! $primary !== '' ? 'background-color:' . $primary . ';border-color:' . $primary : '' !!}">{{ t('Sign In') }}</button>
                    </form>

                    <div id="passkey-login-wrap" class="d-none">
                        <div class="text-center text-muted small my-3">{{ t('or') }}</div>
                        <button type="button" class="btn btn-dark w-100"
                                data-pf-passkey-login
                                data-options-url="{!! sURL !!}Passkey/loginOptions"
                                data-verify-url="{!! sURL !!}Passkey/login"
                                data-redirect="{!! sURL !!}"
                                data-wrap="#passkey-login-wrap"
                                data-error="#passkey-login-error">{{ t('Sign in with a passkey') }}</button>
                        <p id="passkey-login-error" class="text-danger small mt-2 d-none"></p>
                    </div>

                    <div class="text-center mt-3">
                        <a href="{!! $base !!}/forgotpassword" class="small">{{ t('Forgot your password?') }}</a>
                    </div>
                </div>
            </div>
            @include('../partials/auth_home_link')
            @if (!empty($brand['footer']))
                <p class="text-center text-muted small mt-3">{{ (string) $brand['footer'] }}</p>
            @endif
        </div>
    </div>
</div>
<script src="{!! assetUrl('assets/js/pf-webauthn.js') !!}"></script>
<script src="{!! assetUrl('assets/js/pf-auth.js') !!}"></script>
