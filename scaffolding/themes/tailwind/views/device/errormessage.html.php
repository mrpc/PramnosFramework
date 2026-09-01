<?php
/**
 * Device authorization error page (Tailwind theme).
 *
 * Variables:
 *   $this->error    — Error message string
 *   $this->userCode — User code for retry link (optional)
 */
?>
<div class="flex items-center justify-center min-h-screen bg-base-200 px-4">
    <div class="card bg-base-100 shadow-md p-8 w-full max-w-sm text-center">
        <div class="text-5xl mb-4">&#9888;&#65039;</div>
        <h1 class="text-xl font-semibold mb-4">Authorization Error</h1>
        <div role="alert" class="alert alert-error mb-5"><?php echo htmlspecialchars($this->error ?? 'An error occurred.'); ?></div>
        <a href="?user_code=<?php echo urlencode($this->userCode ?? ''); ?>" class="btn btn-outline btn-primary inline-block">&larr; Try Again</a>
    </div>
</div>
