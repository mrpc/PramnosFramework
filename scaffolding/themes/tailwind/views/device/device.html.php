<?php
/**
 * Device authorization entry form (Tailwind theme).
 *
 * Variables:
 *   $this->userCode — Pre-filled user code from query param (optional)
 */
?>
<div class="flex items-center justify-center min-h-screen bg-gray-100 px-4">
    <div class="w-full max-w-sm bg-white rounded-xl shadow-md p-8">
        <div class="text-center mb-6">
            <div class="text-5xl mb-2">&#128241;</div>
            <h1 class="text-2xl font-semibold">Device Authorization</h1>
            <p class="text-sm text-gray-500 mt-1">Enter the code shown on your device and your login credentials.</p>
        </div>

        <form method="POST" action="<?php echo sURL; ?>Device" class="space-y-4">
            <input type="hidden" name="action" value="verify">
            <div>
                <label for="user_code" class="block text-sm font-medium text-gray-700 mb-1">Device Code</label>
                <input type="text" id="user_code" name="user_code"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-center font-mono text-xl font-bold uppercase tracking-widest focus:outline-none focus:ring-2 focus:ring-blue-500"
                       value="<?php echo htmlspecialchars($this->userCode ?? ''); ?>"
                       placeholder="XXXX-XXXX" maxlength="9"
                       pattern="[A-Z0-9]{4}-[A-Z0-9]{4}" required>
                <p class="text-xs text-gray-500 mt-1">Format: XXXX-XXXX (8 characters)</p>
            </div>
            <div>
                <label for="username" class="block text-sm font-medium text-gray-700 mb-1">Username or Email</label>
                <input type="text" id="username" name="username"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                       required autocomplete="username">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <input type="password" id="password" name="password"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                       required autocomplete="current-password">
            </div>
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition-colors">Authorize Device</button>
        </form>
    </div>
</div>
<script>
document.getElementById('user_code').addEventListener('input', function(e) {
    var v = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    e.target.value = v.length > 4 ? v.slice(0, 4) + '-' + v.slice(4, 8) : v;
});
</script>
