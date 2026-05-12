<?php
/**
 * OAuth2 error message page (Tailwind theme).
 *
 * Variables:
 *   $this->error — Error description string
 */
?>
<div class="flex items-center justify-center min-h-screen bg-gray-100 px-4">
    <div class="w-full max-w-sm bg-white rounded-xl shadow-md p-8 text-center">
        <h1 class="text-2xl font-semibold text-red-600 mb-4">Authorization Error</h1>
        <p class="text-gray-700 mb-6"><?php echo htmlspecialchars($this->error ?? 'An unknown error occurred.'); ?></p>
        <a href="javascript:history.back()" class="inline-block border border-gray-300 text-gray-700 py-2 px-6 rounded-md hover:bg-gray-50 transition-colors">&larr; Go Back</a>
    </div>
</div>
