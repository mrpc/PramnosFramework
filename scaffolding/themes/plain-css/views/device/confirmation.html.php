<?php
/**
 * Device authorization confirmation (plain-CSS theme).
 *
 * Variables:
 *   $this->userCode — Device user code
 */
?>
<div style="display:flex;align-items:center;justify-content:center;min-height:70vh;padding:20px">
    <div class="card" style="width:100%;max-width:400px;text-align:center">
        <div class="card-body" style="padding:28px">
            <div style="font-size:3rem;margin-bottom:8px">&#128241;</div>
            <h2 style="margin:0 0 20px;font-size:1.3rem">Confirm Device Authorization</h2>

            <form method="POST" action="<?php echo sURL; ?>Device" style="text-align:left">
                <input type="hidden" name="action" value="verify">
                <div style="margin-bottom:20px">
                    <label for="user_code" style="display:block;margin-bottom:4px;font-weight:500">Device Code</label>
                    <input type="text" id="user_code" name="user_code"
                           style="width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-family:monospace;font-size:20px;text-align:center;text-transform:uppercase;letter-spacing:.1em;font-weight:bold"
                           value="<?php echo htmlspecialchars($this->userCode ?? ''); ?>"
                           placeholder="XXXX-XXXX" maxlength="9"
                           pattern="[A-Z0-9]{4}-[A-Z0-9]{4}" required>
                </div>
                <button type="submit" class="btn" style="width:100%">Confirm Authorization</button>
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
