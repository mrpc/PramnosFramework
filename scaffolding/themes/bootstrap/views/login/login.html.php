<?php
/**
 * Built-in login form (Bootstrap theme) — Account/LoginFlow flow.
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::renderLogin):
 *   $this->routeBase, $this->brand, $this->error, $this->returnUrl, $this->lockoutSeconds
 *
 * The password is submitted once to <routeBase>/login; any second factor is
 * handled server-side (see login_2fa) — no password round-trip.
 */
$brand   = $this->brand ?? [];
$primary = htmlspecialchars((string) ($brand['primary_color'] ?? '#2563eb'), ENT_QUOTES);
$base    = sURL . rawurlencode((string) ($this->routeBase ?? 'Account'));

$errorMessages = [
    'invalid_token'       => 'Your session expired. Please try again.',
    'missing_credentials' => 'Please enter your username and password.',
    'invalid_credentials' => 'Invalid username or password.',
    'locked'              => 'Too many attempts. Please wait a moment and try again.',
    'session_expired'     => 'Your login session expired. Please sign in again.',
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

?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <div class="text-center mb-3">
                        <?php if (!empty($brand['logo'])): ?>
                            <img src="<?php echo htmlspecialchars((string) $brand['logo']); ?>" alt="<?php echo htmlspecialchars((string) ($brand['name'] ?? '')); ?>" style="max-width:220px;max-height:64px">
                        <?php else: ?>
                            <h1 class="h4 mb-0"><?php echo htmlspecialchars((string) ($brand['name'] ?? 'Sign in')); ?></h1>
                        <?php endif; ?>
                    </div>

                    <?php if ($errorText !== ''): ?>
                        <div role="alert" id="form-error" class="alert alert-danger"><?php echo htmlspecialchars($errorText); ?></div>
                    <?php endif; ?>
                    <?php if ($this->hasMessages()): ?>
                        <div role="status" class="alert alert-info"><?php echo $this->_printMessages(); ?></div>
                    <?php endif; ?>

                    <?php if (($this->lockoutSeconds ?? 0) > 0): ?>
                    <script>
                    (function() {
                        var until = Date.now() + <?php echo (int) $this->lockoutSeconds; ?> * 1000;
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
                    <?php endif; ?>

                    <form data-pf-progress method="POST" action="<?php echo $base; ?>/login">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <?php if (!empty($this->returnUrl)): ?>
                            <input type="hidden" name="return" value="<?php echo htmlspecialchars((string) $this->returnUrl); ?>">
                        <?php endif; ?>
                        <div class="mb-3">
                            <label for="username" class="form-label">Username or Email</label>
                            <input type="text" name="username" id="username" class="form-control" value="<?php echo htmlspecialchars((string) ($this->username ?? '')); ?>" required autocomplete="username webauthn"<?php echo $errorFieldAttributes; ?> autocapitalize="none" autocorrect="off" spellcheck="false" autofocus>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" name="password" id="password" class="form-control" required autocomplete="current-password" enterkeyhint="go">
                            <?php echo \Pramnos\Html\PasswordToggle::render(
                                'password', '', ''
                            ); ?>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1" checked>
                            <label class="form-check-label" for="remember">Remember me</label>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 login-submit" style="background-color:<?php echo $primary; ?>;border-color:<?php echo $primary; ?>">Sign In</button>
                    </form>

                    <div id="passkey-login-wrap" class="d-none">
                        <div class="text-center text-muted small my-3">or</div>
                        <button type="button" class="btn btn-dark w-100"
                                data-pf-passkey-login
                                data-options-url="<?php echo sURL; ?>Passkey/loginOptions"
                                data-verify-url="<?php echo sURL; ?>Passkey/login"
                                data-redirect="<?php echo sURL; ?>"
                                data-wrap="#passkey-login-wrap"
                                data-error="#passkey-login-error">Sign in with a passkey</button>
                        <p id="passkey-login-error" class="text-danger small mt-2 d-none"></p>
                    </div>

                    <div class="text-center mt-3">
                        <a href="<?php echo $base; ?>/forgotpassword" class="small">Forgot your password?</a>
                    </div>
                </div>
            </div>
            <?php if (!empty($brand['footer'])): ?>
                <p class="text-center text-muted small mt-3"><?php echo htmlspecialchars((string) $brand['footer']); ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="<?php echo sURL; ?>assets/js/pf-webauthn.js"></script>
<script src="<?php echo sURL; ?>assets/js/pf-auth.js"></script>
