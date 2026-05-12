<?php
/**
 * Device authorization confirmation (Tailwind theme).
 *
 * Variables:
 *   $this->userCode — Device user code
 */
?>
<div class="flex items-center justify-center min-h-screen bg-gray-100 px-4">
    <div class="w-full max-w-sm bg-white rounded-xl shadow-md p-8 text-center">
        <div class="text-5xl mb-3">&#128241;</div>
        <h1 class="text-xl font-semibold mb-6">Confirm Device Authorization</h1>

        <form method="POST" action="<?php echo sURL; ?>Device" class="text-left space-y-4">
            <input type="hidden" name="action" value="verify">
            <div>
                <label for="user_code" class="block text-sm font-medium text-gray-700 mb-1">Device Code</label>
                <input type="text" id="user_code" name="user_code"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-center font-mono text-xl font-bold uppercase tracking-widest focus:outline-none focus:ring-2 focus:ring-green-500"
                       value="<?php echo htmlspecialchars($this->userCode ?? ''); ?>"
                       placeholder="XXXX-XXXX" maxlength="9"
                       pattern="[A-Z0-9]{4}-[A-Z0-9]{4}" required>
            </div>
            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition-colors">Confirm Authorization</button>
        </form>
    </div>
</div>
<script>
document.getElementById('user_code').addEventListener('input', function(e) {
    var v = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    e.target.value = v.length > 4 ? v.slice(0, 4) + '-' + v.slice(4, 8) : v;
});
</script>
