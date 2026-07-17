<?php
/**
 * Built-in login form (Tailwind theme) — Account/LoginFlow flow.
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
?>
<div class="flex items-center justify-center min-h-screen bg-gray-100 px-4">
    <div class="w-full max-w-sm bg-white rounded-xl shadow-md p-8">
        <div class="text-center mb-6">
            <?php if (!empty($brand['logo'])): ?>
                <img src="<?php echo htmlspecialchars((string) $brand['logo']); ?>" alt="<?php echo htmlspecialchars((string) ($brand['name'] ?? '')); ?>" class="mx-auto" style="max-width:220px;max-height:64px">
            <?php else: ?>
                <h1 class="text-2xl font-semibold"><?php echo htmlspecialchars((string) ($brand['name'] ?? 'Sign in')); ?></h1>
            <?php endif; ?>
        </div>

        <?php if ($errorText !== ''): ?>
            <div class="bg-red-100 border border-red-300 text-red-800 rounded-sm p-3 mb-4"><?php echo htmlspecialchars($errorText); ?></div>
        <?php endif; ?>
        <?php if ($this->hasMessages()): ?>
            <div class="bg-blue-100 border border-blue-300 text-blue-800 rounded-sm p-3 mb-4"><?php echo $this->_printMessages(); ?></div>
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

        <form method="POST" action="<?php echo $base; ?>/login" class="space-y-4">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <?php if (!empty($this->returnUrl)): ?>
                <input type="hidden" name="return" value="<?php echo htmlspecialchars((string) $this->returnUrl); ?>">
            <?php endif; ?>
            <div>
                <label for="username" class="block text-sm font-medium text-gray-700 mb-1">Username or Email</label>
                <input type="text" name="username" id="username" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-hidden focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars((string) ($this->username ?? '')); ?>" required autocomplete="username" autofocus>
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <input type="password" name="password" id="password" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-hidden focus:ring-2 focus:ring-blue-500" required autocomplete="current-password">
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="remember" value="1" checked> Remember me
            </label>
            <button type="submit" class="login-submit w-full text-white font-medium py-2 px-4 rounded-md transition-colors" style="background-color:<?php echo $primary; ?>">Sign In</button>
        </form>

        <div id="passkey-login-wrap" class="hidden">
            <div class="text-center text-sm text-gray-400 my-4">or</div>
            <button type="button" class="w-full bg-gray-800 hover:bg-gray-900 text-white font-medium py-2 px-4 rounded-md transition-colors"
                    data-pf-passkey-login
                    data-options-url="<?php echo sURL; ?>Passkey/loginOptions"
                    data-verify-url="<?php echo sURL; ?>Passkey/login"
                    data-redirect="<?php echo sURL; ?>"
                    data-wrap="#passkey-login-wrap"
                    data-error="#passkey-login-error">Sign in with a passkey</button>
            <p id="passkey-login-error" class="text-red-700 text-sm mt-2 hidden"></p>
        </div>

        <p class="text-center text-sm mt-4">
            <a href="<?php echo $base; ?>/forgotpassword" class="text-blue-600 hover:underline">Forgot your password?</a>
        </p>
        <?php if (!empty($brand['footer'])): ?>
            <p class="text-center text-gray-400 text-xs mt-4"><?php echo htmlspecialchars((string) $brand['footer']); ?></p>
        <?php endif; ?>
    </div>
</div>
<script src="<?php echo sURL; ?>assets/js/pf-webauthn.js"></script>
<script src="<?php echo sURL; ?>assets/js/pf-auth.js"></script>
