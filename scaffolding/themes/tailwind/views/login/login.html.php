<?php
/**
 * Login form (Tailwind theme).
 *
 * Variables:
 *   $this->header          — Page heading string
 *   $this->error           — Optional error message string
 *   $this->return          — URL to redirect after login (hidden field)
 *   $this->lockoutSeconds  — Remaining lockout seconds (disables submit button)
 */
?>
<div class="flex items-center justify-center min-h-screen bg-gray-100 px-4">
    <div class="w-full max-w-sm bg-white rounded-xl shadow-md p-8">
        <h1 class="text-2xl font-semibold mb-6"><?php echo htmlspecialchars($this->header ?? 'Sign In'); ?></h1>

        <?php if (!empty($this->error)): ?>
            <div class="bg-red-100 border border-red-300 text-red-800 rounded p-3 mb-4"><?php echo htmlspecialchars($this->error); ?></div>
        <?php endif; ?>
        <?php if ($this->hasErrors()): ?>
            <div class="bg-red-100 border border-red-300 text-red-800 rounded p-3 mb-4"><?php echo $this->_printErrors(); ?></div>
        <?php endif; ?>
        <?php if ($this->hasMessages()): ?>
            <div class="bg-blue-100 border border-blue-300 text-blue-800 rounded p-3 mb-4"><?php echo $this->_printMessages(); ?></div>
        <?php endif; ?>

        <?php if ($this->hasErrors() && ($this->lockoutSeconds ?? 0) > 0): ?>
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

        <form method="POST" action="<?php echo sURL; ?>Home/login" class="space-y-4">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <?php if (!empty($this->return)): ?>
                <input type="hidden" name="return" value="<?php echo htmlspecialchars($this->return); ?>">
            <?php endif; ?>
            <div>
                <label for="username" class="block text-sm font-medium text-gray-700 mb-1">Username or Email</label>
                <input type="text" name="username" id="username" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" required autocomplete="username">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <input type="password" name="password" id="password" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" required autocomplete="current-password">
            </div>
            <button type="submit" class="login-submit w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition-colors">Sign In</button>
        </form>
        <p class="text-center text-sm mt-4">
            <a href="<?php echo sURL; ?>Home/forgotpassword" class="text-blue-600 hover:underline">Forgot your password?</a>
        </p>
    </div>
</div>
