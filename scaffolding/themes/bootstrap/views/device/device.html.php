<?php
/**
 * Device authorization entry form (Bootstrap theme).
 *
 * Variables:
 *   $this->userCode — Pre-filled user code from query param (optional)
 */
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body p-4 text-center">
                    <?php $this->insert('../partials/auth_brand'); ?>
                    <div class="fs-1 mb-2">&#128241;</div>
                    <h1 class="h4 mb-1"><?php echo htmlspecialchars(t('Device Authorization'), ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p class="text-muted small mb-3"><?php echo htmlspecialchars(t('Enter the code shown on your device and your login credentials.'), ENT_QUOTES, 'UTF-8'); ?></p>

                    <form method="POST" action="<?php echo sURL; ?>Device" class="text-start">
                        <input type="hidden" name="action" value="verify">
                        <div class="mb-3">
                            <label for="user_code" class="form-label"><?php echo htmlspecialchars(t('Device Code'), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="text" id="user_code" name="user_code"
                                   class="form-control text-center font-monospace fw-bold fs-5 text-uppercase"
                                   value="<?php echo htmlspecialchars($this->userCode ?? ''); ?>"
                                   placeholder="XXXX-XXXX" maxlength="9"
                                   pattern="[A-Z0-9]{4}-[A-Z0-9]{4}" required>
                            <div class="form-text"><?php echo htmlspecialchars(t('Format: XXXX-XXXX (8 characters)'), ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="mb-3">
                            <label for="username" class="form-label"><?php echo htmlspecialchars(t('Username or Email'), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="text" id="username" name="username" class="form-control" required autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false">
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label"><?php echo htmlspecialchars(t('Password'), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="password" id="password" name="password" class="form-control" required autocomplete="current-password" enterkeyhint="go">
                            <?php echo \Pramnos\Html\PasswordToggle::render(
                                'password', '', ''
                            ); ?>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><?php echo htmlspecialchars(t('Authorize Device'), ENT_QUOTES, 'UTF-8'); ?></button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
document.getElementById('user_code').addEventListener('input', function(e) {
    var v = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    e.target.value = v.length > 4 ? v.slice(0, 4) + '-' + v.slice(4, 8) : v;
});
</script>
