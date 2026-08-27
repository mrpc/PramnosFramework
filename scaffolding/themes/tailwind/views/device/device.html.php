<?php
/**
 * Device authorization entry form (Tailwind theme).
 *
 * Variables:
 *   $this->userCode — Pre-filled user code from query param (optional)
 */
?>
<div class="flex items-center justify-center min-h-screen bg-base-200 px-4">
    <div class="card bg-base-100 shadow-md p-8 w-full max-w-sm">
        <div class="text-center mb-6">
            <div class="text-5xl mb-2">&#128241;</div>
            <h1 class="text-2xl font-semibold">Device Authorization</h1>
            <p class="text-sm text-base-content/70 mt-1">Enter the code shown on your device and your login credentials.</p>
        </div>

        <form method="POST" action="<?php echo sURL; ?>Device" class="space-y-4">
            <input type="hidden" name="action" value="verify">
            <div>
                <label for="user_code" class="block text-sm font-medium text-base-content mb-1">Device Code</label>
                <input type="text" id="user_code" name="user_code"
                       class="input w-full text-center font-mono text-xl uppercase tracking-widest"
                       value="<?php echo htmlspecialchars($this->userCode ?? ''); ?>"
                       placeholder="XXXX-XXXX" maxlength="9"
                       pattern="[A-Z0-9]{4}-[A-Z0-9]{4}" required>
                <p class="text-xs text-base-content/70 mt-1">Format: XXXX-XXXX (8 characters)</p>
            </div>
            <div>
                <label for="username" class="block text-sm font-medium text-base-content mb-1">Username or Email</label>
                <input type="text" id="username" name="username"
                       class="input w-full"
                       required autocomplete="username">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-base-content mb-1">Password</label>
                <input type="password" id="password" name="password"
                       class="input w-full"
                       required autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary w-full">Authorize Device</button>
        </form>
    </div>
</div>
<script>
document.getElementById('user_code').addEventListener('input', function(e) {
    var v = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    e.target.value = v.length > 4 ? v.slice(0, 4) + '-' + v.slice(4, 8) : v;
});
</script>
