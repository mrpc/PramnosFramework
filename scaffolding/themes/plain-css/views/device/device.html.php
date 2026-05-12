<?php
/**
 * Device authorization entry form (plain-CSS theme).
 *
 * Variables:
 *   $this->userCode — Pre-filled user code from query param (optional)
 */
?>
<div style="display:flex;align-items:center;justify-content:center;min-height:70vh;padding:20px">
    <div class="card" style="width:100%;max-width:420px">
        <div class="card-body" style="padding:28px;text-align:center">
            <div style="font-size:3rem;margin-bottom:8px">&#128241;</div>
            <h2 style="margin:0 0 4px;font-size:1.3rem">Device Authorization</h2>
            <p style="color:#666;font-size:13px;margin-bottom:20px">Enter the code shown on your device and your login credentials.</p>

            <form method="POST" action="<?php echo sURL; ?>Device" style="text-align:left">
                <input type="hidden" name="action" value="verify">
                <div style="margin-bottom:14px">
                    <label for="user_code" style="display:block;margin-bottom:4px;font-weight:500">Device Code</label>
                    <input type="text" id="user_code" name="user_code"
                           style="width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-family:monospace;font-size:20px;text-align:center;text-transform:uppercase;letter-spacing:.1em;font-weight:bold"
                           value="<?php echo htmlspecialchars($this->userCode ?? ''); ?>"
                           placeholder="XXXX-XXXX" maxlength="9"
                           pattern="[A-Z0-9]{4}-[A-Z0-9]{4}" required>
                    <small style="color:#666">Format: XXXX-XXXX (8 characters)</small>
                </div>
                <div style="margin-bottom:14px">
                    <label for="username" style="display:block;margin-bottom:4px;font-weight:500">Username or Email</label>
                    <input type="text" id="username" name="username"
                           style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:15px"
                           required autocomplete="username">
                </div>
                <div style="margin-bottom:20px">
                    <label for="password" style="display:block;margin-bottom:4px;font-weight:500">Password</label>
                    <input type="password" id="password" name="password"
                           style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:15px"
                           required autocomplete="current-password">
                </div>
                <button type="submit" class="btn" style="width:100%">Authorize Device</button>
            </form>
        </div>
    </div>
</div>
<script>
document.getElementById('user_code').addEventListener('input', function(e) {
    var v = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    e.target.value = v.length > 4 ? v.slice(0, 4) + '-' + v.slice(4, 8) : v;
});
</script>
