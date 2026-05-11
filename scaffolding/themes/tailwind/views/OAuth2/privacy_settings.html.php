<?php
/**
 * Privacy Settings page (Tailwind theme).
 *
 * Variables:
 *   $this->privacySettings — array {analytics: bool, marketing: bool}
 */
?>
<div class="max-w-xl mx-auto px-4 py-8">

    <p class="text-sm mb-4"><a href="<?php echo sURL; ?>Dashboard" class="text-blue-600 hover:underline">← Back to Dashboard</a></p>
    <h2 class="text-2xl font-bold text-gray-800 mb-6">Privacy Settings</h2>

    <?php if (!empty($_GET['message']) && $_GET['message'] === 'saved'): ?>
        <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded">
            Your privacy settings have been saved.
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow p-6">
        <p class="text-sm text-gray-500 mb-6">
            Control how your data is used. You can update these preferences at any time.
        </p>

        <form method="post" action="<?php echo sURL; ?>Dashboard/privacy">
            <div class="space-y-5 mb-6">
                <label class="flex items-start gap-3 cursor-pointer">
                    <div class="mt-0.5">
                        <input type="checkbox" id="analytics" name="analytics"
                               class="w-4 h-4 text-blue-600 border-gray-300 rounded"
                               <?php echo !empty($this->privacySettings['analytics']) ? 'checked' : ''; ?>>
                    </div>
                    <div>
                        <span class="font-semibold text-gray-800">Analytics &amp; Usage Data</span>
                        <p class="text-sm text-gray-500 mt-0.5">
                            Allow anonymous usage analytics to help us improve the service.
                        </p>
                    </div>
                </label>

                <label class="flex items-start gap-3 cursor-pointer">
                    <div class="mt-0.5">
                        <input type="checkbox" id="marketing" name="marketing"
                               class="w-4 h-4 text-blue-600 border-gray-300 rounded"
                               <?php echo !empty($this->privacySettings['marketing']) ? 'checked' : ''; ?>>
                    </div>
                    <div>
                        <span class="font-semibold text-gray-800">Marketing Communications</span>
                        <p class="text-sm text-gray-500 mt-0.5">
                            Receive occasional emails about new features and offers.
                        </p>
                    </div>
                </label>
            </div>

            <button type="submit"
                    class="w-full py-2 px-4 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded transition-colors">
                Save Preferences
            </button>
        </form>
    </div>

    <div class="mt-6 text-sm text-gray-500">
        <p>
            Under GDPR you have the right to access, rectify, and erase your data.
            <a href="<?php echo sURL; ?>Dashboard/exportdata" class="text-blue-600 hover:underline">Download your data</a>
            or <a href="<?php echo sURL; ?>Dashboard/deleteaccount" class="text-red-600 hover:underline">delete your account</a>.
        </p>
    </div>

</div>
