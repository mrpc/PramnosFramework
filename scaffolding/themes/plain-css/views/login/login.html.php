<?php
/**
 * Login form (plain-CSS theme).
 *
 * Variables:
 *   $this->header          — Page heading string
 *   $this->error           — Optional error message string
 *   $this->return          — URL to redirect after login (hidden field)
 *   $this->lockoutSeconds  — Remaining lockout seconds (disables submit button)
 */
?>
<div style="display:flex;align-items:center;justify-content:center;min-height:60vh;padding:20px">
    <div class="card" style="width:100%;max-width:400px">
        <div class="card-header"><h2 style="margin:0;font-size:1.25rem"><?php echo htmlspecialchars($this->header ?? 'Sign In'); ?></h2></div>
        <div class="card-body" style="padding:24px">

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
                <div style="margin-bottom:16px">
                    <label for="username" style="display:block;margin-bottom:4px;font-weight:500">Username or Email</label>
                    <input type="text" name="username" id="username" style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:15px" required autocomplete="username">
                </div>
                <div style="margin-bottom:20px">
                    <label for="password" style="display:block;margin-bottom:4px;font-weight:500">Password</label>
                    <input type="password" name="password" id="password" style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:15px" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn login-submit" style="width:100%">Sign In</button>
            </form>
            <div style="text-align:center;margin-top:12px">
                <a href="<?php echo sURL; ?>Home/forgotpassword" style="font-size:13px">Forgot your password?</a>
            </div>
        </div>
    </div>
</div>
