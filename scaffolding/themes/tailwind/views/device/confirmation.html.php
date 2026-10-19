<?php
/**
 * Device authorization confirmation (Tailwind theme).
 *
 * Variables:
 *   $this->userCode — Device user code
 */
?>
<div class="flex items-center justify-center min-h-screen bg-base-200 px-4">
    <div class="card bg-base-100 shadow-md p-8 w-full max-w-sm text-center">
        <?php $this->insert('../partials/auth_brand'); ?>
        <div class="text-5xl mb-3">&#128241;</div>
        <h1 class="text-xl font-semibold mb-6"><?php echo htmlspecialchars(t('Confirm Device Authorization'), ENT_QUOTES, 'UTF-8'); ?></h1>

        <form method="POST" action="<?php echo sURL; ?>Device" class="text-left space-y-4">
            <input type="hidden" name="action" value="verify">
            <div>
                <label for="user_code" class="block text-sm font-medium text-base-content mb-1"><?php echo htmlspecialchars(t('Device Code'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="text" id="user_code" name="user_code"
                       class="input w-full text-center font-mono text-xl uppercase tracking-widest"
                       value="<?php echo htmlspecialchars($this->userCode ?? ''); ?>"
                       placeholder="XXXX-XXXX" maxlength="9"
                       pattern="[A-Z0-9]{4}-[A-Z0-9]{4}" required>
            </div>
            <button type="submit" class="btn btn-success w-full"><?php echo htmlspecialchars(t('Confirm Authorization'), ENT_QUOTES, 'UTF-8'); ?></button>
        </form>
    </div>
</div>
<script>
document.getElementById('user_code').addEventListener('input', function(e) {
    var v = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    e.target.value = v.length > 4 ? v.slice(0, 4) + '-' + v.slice(4, 8) : v;
});
</script>
