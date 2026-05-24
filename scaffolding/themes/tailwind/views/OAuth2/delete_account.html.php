<?php
/**
 * Delete Account page (Tailwind theme).
 * GDPR Article 17 — right to erasure.
 */
$errorMessages = [
    'invalid_password'      => 'The password you entered is incorrect.',
    'confirmation_required' => 'You must type DELETE in the confirmation field.',
    'deletion_failed'       => 'An error occurred while deleting your account. Please try again.',
];
?>
<div class="max-w-lg mx-auto px-4 py-8">

    <p class="text-sm mb-4"><a href="<?php echo sURL . ($this->routeBase ?? 'Dashboard'); ?>" class="text-blue-600 hover:underline">← Back to Dashboard</a></p>

    <div class="bg-white border border-red-200 rounded-lg shadow">
        <div class="px-4 py-3 bg-red-600 rounded-t-lg">
            <h2 class="text-white font-semibold">Delete Account</h2>
        </div>
        <div class="p-6">

            <?php if (!empty($_GET['error']) && isset($errorMessages[$_GET['error']])): ?>
                <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded">
                    <?php echo htmlspecialchars($errorMessages[$_GET['error']]); ?>
                </div>
            <?php endif; ?>

            <div class="mb-6 px-4 py-3 bg-yellow-50 border border-yellow-200 text-yellow-800 rounded text-sm">
                <strong>Warning — this action is permanent.</strong><br>
                All your personal data, authorized applications, activity history, and account
                information will be permanently deleted and cannot be recovered.
            </div>

            <form method="post" action="<?php echo sURL . ($this->routeBase ?? 'Dashboard'); ?>/deleteaccount">
                <div class="mb-4">
                    <label for="del_password" class="block text-sm font-medium text-gray-700 mb-1">
                        Current Password
                    </label>
                    <input type="password" id="del_password" name="password"
                           class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-400"
                           required autocomplete="current-password">
                </div>
                <div class="mb-6">
                    <label for="del_confirm" class="block text-sm font-medium text-gray-700 mb-1">
                        Type <strong>DELETE</strong> to confirm
                    </label>
                    <input type="text" id="del_confirm" name="confirmation"
                           class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-400"
                           placeholder="DELETE" required autocomplete="off">
                </div>
                <button type="submit"
                        class="w-full py-2 px-4 bg-red-600 hover:bg-red-700 text-white font-semibold rounded transition-colors">
                    Permanently Delete My Account
                </button>
                <a href="<?php echo sURL . ($this->routeBase ?? 'Dashboard'); ?>"
                   class="block mt-3 text-center py-2 px-4 border border-gray-300 text-gray-600 rounded hover:bg-gray-50 transition-colors">
                    Cancel
                </a>
            </form>
        </div>
    </div>

</div>
