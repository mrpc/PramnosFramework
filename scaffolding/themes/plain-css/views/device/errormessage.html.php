<?php
/**
 * Device authorization error page (plain-CSS theme).
 *
 * Variables:
 *   $this->error    — Error message string
 *   $this->userCode — User code for retry link (optional)
 */
?>
<div style="display:flex;align-items:center;justify-content:center;min-height:60vh;padding:20px">
    <div class="card" style="width:100%;max-width:400px;text-align:center">
        <div class="card-body" style="padding:28px">
            <div style="font-size:3rem;margin-bottom:12px">&#9888;&#65039;</div>
            <h2 style="margin:0 0 16px;font-size:1.3rem">Authorization Error</h2>
            <div class="alert alert-danger"><?php echo htmlspecialchars($this->error ?? 'An error occurred.'); ?></div>
            <a href="?user_code=<?php echo urlencode($this->userCode ?? ''); ?>" class="btn" style="margin-top:8px">&larr; Try Again</a>
        </div>
    </div>
</div>
