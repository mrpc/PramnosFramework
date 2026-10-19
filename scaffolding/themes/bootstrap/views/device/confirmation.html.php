<?php
/**
 * Device authorization confirmation — confirm the scopes before granting (Bootstrap theme).
 *
 * Variables:
 *   $this->userCode — Device user code
 */
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body p-4 text-center">
                    <?php $this->insert('../partials/auth_brand'); ?>
                    <div class="fs-1 mb-2">&#128241;</div>
                    <h1 class="h4 mb-3"><?php echo htmlspecialchars(t('Confirm Device Authorization'), ENT_QUOTES, 'UTF-8'); ?></h1>

                    <form method="POST" action="<?php echo sURL; ?>Device">
                        <input type="hidden" name="action" value="verify">
                        <div class="mb-3 text-start">
                            <label for="user_code" class="form-label"><?php echo htmlspecialchars(t('Device Code'), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="text" id="user_code" name="user_code"
                                   class="form-control text-center font-monospace fw-bold fs-5 text-uppercase"
                                   value="<?php echo htmlspecialchars($this->userCode ?? ''); ?>"
                                   placeholder="XXXX-XXXX" maxlength="9"
                                   pattern="[A-Z0-9]{4}-[A-Z0-9]{4}" required>
                        </div>
                        <button type="submit" class="btn btn-success w-100"><?php echo htmlspecialchars(t('Confirm Authorization'), ENT_QUOTES, 'UTF-8'); ?></button>
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
