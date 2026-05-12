<?php
/**
 * Device authorization error page (Bootstrap theme).
 *
 * Variables:
 *   $this->error    — Error message string
 *   $this->userCode — User code for retry link (optional)
 */
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm border-danger text-center">
                <div class="card-body p-4">
                    <div class="fs-1 mb-2">&#9888;&#65039;</div>
                    <h1 class="h4 mb-3">Authorization Error</h1>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($this->error ?? 'An error occurred.'); ?></div>
                    <a href="?user_code=<?php echo urlencode($this->userCode ?? ''); ?>" class="btn btn-outline-primary">&larr; Try Again</a>
                </div>
            </div>
        </div>
    </div>
</div>
