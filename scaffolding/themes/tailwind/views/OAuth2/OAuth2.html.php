<?php
/**
 * OAuth2 default/fallback page (Tailwind theme).
 *
 * Variables:
 *   $this->header — Page heading string
 */
?>
<div class="container mx-auto py-10 px-4">
    <h2 class="text-2xl font-semibold mb-4"><?php echo htmlspecialchars($this->header ?? 'OAuth2'); ?></h2>
    <div class="bg-blue-100 border border-blue-300 text-blue-800 rounded p-4 mb-6">
        <strong>OAuth2 Server</strong> — This is the default OAuth2 view. A specific template was not found for the requested action.
    </div>
    <a href="<?php echo sURL; ?>Dashboard" class="bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded-md mr-2 transition-colors">Dashboard</a>
    <a href="<?php echo sURL; ?>" class="bg-gray-600 hover:bg-gray-700 text-white py-2 px-4 rounded-md transition-colors">Home</a>
</div>
