<?php
/**
 * Device authorization error page (Tailwind theme).
 *
 * Variables:
 *   $this->error    — Error message string
 *   $this->userCode — User code for retry link (optional)
 */
?>
<div class="flex items-center justify-center min-h-screen bg-gray-100 px-4">
    <div class="w-full max-w-sm bg-white rounded-xl shadow-md p-8 text-center">
        <div class="text-5xl mb-4">&#9888;&#65039;</div>
        <h1 class="text-xl font-semibold mb-4">Authorization Error</h1>
        <div class="bg-red-100 border border-red-300 text-red-800 rounded p-3 mb-5"><?php echo htmlspecialchars($this->error ?? 'An error occurred.'); ?></div>
        <a href="?user_code=<?php echo urlencode($this->userCode ?? ''); ?>" class="inline-block border border-blue-600 text-blue-600 hover:bg-blue-50 py-2 px-6 rounded-md transition-colors">&larr; Try Again</a>
    </div>
</div>
