<?php
/**
 * Built-in login form (plain-CSS theme) — Account/LoginFlow flow.
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::renderLogin):
 *   $this->routeBase      — controller route base (form action prefix)
 *   $this->brand          — [name, logo, primary_color, footer]
 *   $this->error          — optional error key (mapped to a message below)
 *   $this->returnUrl      — post-login redirect (hidden field)
 *   $this->lockoutSeconds — remaining lockout seconds (disables submit)
 *
 * Unlike the legacy Home flow, the password is submitted once to
 * <routeBase>/login; any second factor is handled server-side (see login_2fa).
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
    : '';

?>
<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:60vh;padding:20px">
    <div class="card" style="width:100%;max-width:400px">
        <?php $this->insert('../partials/auth_brand'); ?>
        <div class="card-body" style="padding:24px">

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

            <form data-pf-progress method="POST" action="<?php echo $base; ?>/login<?php echo $returnQuery; ?>">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <?php if (!empty($this->returnUrl)): ?>
                    <input type="hidden" name="return" value="<?php echo htmlspecialchars((string) $this->returnUrl); ?>">
                <?php endif; ?>
                <div style="margin-bottom:16px">
                    <label for="username" style="display:block;margin-bottom:4px;font-weight:500"><?php echo htmlspecialchars(t('Username or Email'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input type="text" name="username" id="username" style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:15px" value="<?php echo htmlspecialchars((string) ($this->username ?? '')); ?>" required autocomplete="username webauthn"<?php echo $errorFieldAttributes; ?> autocapitalize="none" autocorrect="off" spellcheck="false" autofocus>
                </div>
                <div style="margin-bottom:16px">
                    <label for="password" style="display:block;margin-bottom:4px;font-weight:500"><?php echo htmlspecialchars(t('Password'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input type="password" name="password" id="password" style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:15px" required autocomplete="current-password" enterkeyhint="go">
                    <?php echo \Pramnos\Html\PasswordToggle::render(
                        'password', '', ''
                    ); ?>
                </div>
                <label style="display:flex;align-items:center;gap:8px;margin-bottom:20px;font-size:14px">
                    <input type="checkbox" name="remember" value="1" checked> <?php echo htmlspecialchars(t('Remember me'), ENT_QUOTES, 'UTF-8'); ?>
                </label>
                <button type="submit" class="btn login-submit" style="width:100%;<?php echo $primary !== '' ? 'background-color:' . $primary . ';border-color:' . $primary : ''; ?>"><?php echo htmlspecialchars(t('Sign In'), ENT_QUOTES, 'UTF-8'); ?></button>
            </form>

            <div id="passkey-login-wrap" style="display:none">
                <div style="text-align:center;margin:16px 0;color:#888;font-size:13px"><?php echo htmlspecialchars(t('or'), ENT_QUOTES, 'UTF-8'); ?></div>
                <button type="button" class="btn" style="width:100%;background-color:#374151;border-color:#374151"
                        data-pf-passkey-login
                        data-options-url="<?php echo sURL; ?>Passkey/loginOptions"
                        data-verify-url="<?php echo sURL; ?>Passkey/login"
                        data-redirect="<?php echo sURL; ?>"
                        data-wrap="#passkey-login-wrap"
                        data-error="#passkey-login-error"><?php echo htmlspecialchars(t('Sign in with a passkey'), ENT_QUOTES, 'UTF-8'); ?></button>
                <p id="passkey-login-error" style="color:#b91c1c;font-size:13px;margin-top:8px;display:none"></p>
            </div>

            <div style="text-align:center;margin-top:12px">
                <a href="<?php echo $base; ?>/forgotpassword" style="font-size:13px"><?php echo htmlspecialchars(t('Forgot your password?'), ENT_QUOTES, 'UTF-8'); ?></a>
            </div>
        </div>
    </div>
    <?php $this->insert('../partials/auth_home_link'); ?>
    <?php if (!empty($brand['footer'])): ?>
        <p style="text-align:center;color:#888;font-size:12px;margin-top:16px"><?php echo htmlspecialchars((string) $brand['footer']); ?></p>
    <?php endif; ?>
</div>
<script src="<?php echo assetUrl('assets/js/pf-webauthn.js'); ?>"></script>
<script src="<?php echo assetUrl('assets/js/pf-auth.js'); ?>"></script>
