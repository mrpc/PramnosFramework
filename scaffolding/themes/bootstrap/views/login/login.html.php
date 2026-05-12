<?php
/**
 * Login form (Bootstrap theme).
 *
 * Variables:
 *   $this->header          — Page heading string
 *   $this->error           — Optional error message string
 *   $this->return          — URL to redirect after login (hidden field)
 *   $this->lockoutSeconds  — Remaining lockout seconds (disables submit button)
 */
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3"><?php echo htmlspecialchars($this->header ?? 'Sign In'); ?></h1>

                    <?php if (!empty($this->error)): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($this->error); ?></div>
                    <?php endif; ?>
                    <?php if ($this->hasErrors()): ?>
                        <div class="alert alert-danger"><?php echo $this->_printErrors(); ?></div>
                    <?php endif; ?>
                    <?php if ($this->hasMessages()): ?>
                        <div class="alert alert-info"><?php echo $this->_printMessages(); ?></div>
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

                    <form method="POST" action="<?php echo sURL; ?>Home/login">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <?php if (!empty($this->return)): ?>
                            <input type="hidden" name="return" value="<?php echo htmlspecialchars($this->return); ?>">
                        <?php endif; ?>
                        <div class="mb-3">
                            <label for="username" class="form-label">Username or Email</label>
                            <input type="text" name="username" id="username" class="form-control" required autocomplete="username">
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" name="password" id="password" class="form-control" required autocomplete="current-password">
                        </div>
                        <button type="submit" class="btn btn-primary w-100 login-submit">Sign In</button>
                    </form>
                    <div class="text-center mt-3">
                        <a href="<?php echo sURL; ?>Home/forgotpassword" class="small">Forgot your password?</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
