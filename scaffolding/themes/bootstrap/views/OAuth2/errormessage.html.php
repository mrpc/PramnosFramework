<?php
/**
 * OAuth2 error message page (Bootstrap theme).
 *
 * Variables:
 *   $this->error — Error description string
 */
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm border-danger">
                <div class="card-body p-4 text-center">
                    <h1 class="h4 text-danger mb-3">Authorization Error</h1>
                    <p class="mb-4"><?php echo htmlspecialchars($this->error ?? 'An unknown error occurred.'); ?></p>
                    <a href="javascript:history.back()" class="btn btn-outline-secondary">&larr; Go Back</a>
                </div>
            </div>
        </div>
    </div>
</div>
