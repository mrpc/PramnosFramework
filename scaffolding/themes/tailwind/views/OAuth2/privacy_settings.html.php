<?php
/**
 * Privacy page (Tailwind theme).
 *
 * Variables:
 *   $this->privacySettings — array {analytics: bool, marketing: bool, notifysignin: bool}
 *   $this->routeBase       — Account controller route base
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'privacy';
?>
<div class="container mx-auto px-4 py-8">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2 class="text-2xl font-bold text-gray-800 mb-6">Privacy</h2>

    <?php if ($this->hasMessages()): ?>
        <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-sm"><?php echo $this->_printMessages(); ?></div>
    <?php endif; ?>
    <?php if ($this->hasErrors()): ?>
        <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded-sm"><?php echo $this->_printErrors(); ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="md:col-span-3 space-y-6">
            <div class="bg-white rounded-lg shadow-sm p-6">
                <p class="text-sm text-gray-500 mb-6">
                    Control how your data is used. You can update these preferences at any time.
                </p>

                <form method="post" action="<?php echo sURL . $routeBase; ?>/privacy">
                    <div class="space-y-5 mb-6">
                        <label class="flex items-start gap-3 cursor-pointer">
                            <div class="mt-0.5">
                                <input type="checkbox" id="analytics" name="analytics"
                                       class="w-4 h-4 text-blue-600 border-gray-300 rounded-sm"
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
                                       class="w-4 h-4 text-blue-600 border-gray-300 rounded-sm"
                                       <?php echo !empty($this->privacySettings['marketing']) ? 'checked' : ''; ?>>
                            </div>
                            <div>
                                <span class="font-semibold text-gray-800">Marketing Communications</span>
                                <p class="text-sm text-gray-500 mt-0.5">
                                    Receive occasional emails about new features and offers.
                                </p>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 cursor-pointer mt-4">
                            <div class="mt-0.5">
                                <input type="checkbox" id="notifysignin" name="notifysignin"
                                       class="w-4 h-4 text-blue-600 border-gray-300 rounded-sm"
                                       <?php echo !empty($this->privacySettings['notifysignin']) ? 'checked' : ''; ?>>
                            </div>
                            <div>
                                <span class="font-semibold text-gray-800">New sign-in alerts</span>
                                <p class="text-sm text-gray-500 mt-0.5">
                                    Email me when my account is signed in to from a browser or device I have not used before. You will not be emailed when your browser updates, or when your network address changes.
                                </p>
                            </div>
                        </label>
                    </div>

                    <button type="submit"
                            class="py-2 px-4 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-sm transition-colors">
                        Save Preferences
                    </button>
                </form>
            </div>

            <div class="text-sm text-gray-500">
                <p>
                    Under GDPR you have the right to access, rectify, and erase your data.
                    <a href="<?php echo sURL . $routeBase; ?>/exportdata" class="text-blue-600 hover:underline">Download your data</a>
                    or <a href="<?php echo sURL . $routeBase; ?>/deleteaccount" class="text-red-600 hover:underline">delete your account</a>.
                </p>
            </div>
        </div>
    </div>

</div>
