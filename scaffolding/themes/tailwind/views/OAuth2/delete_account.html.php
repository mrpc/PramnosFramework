<?php
/**
 * Delete Account page (Tailwind theme).
 * GDPR Article 17 — right to erasure. Reached from Privacy.
 *
 * Variables:
 *   $this->routeBase — Account controller route base
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'deleteaccount';
?>
<div class="container mx-auto px-4 py-8">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2 class="text-2xl font-bold text-gray-800 mb-6">Delete Account</h2>

    <?php if ($this->hasErrors()): ?>
        <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded-sm">
            <?php echo $this->_printErrors(); ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="md:col-span-3">
            <div class="bg-white border border-red-200 rounded-lg shadow-sm max-w-lg">
                <div class="px-4 py-3 bg-red-600 rounded-t-lg">
                    <h3 class="text-white font-semibold">Delete Account</h3>
                </div>
                <div class="p-6">

                    <div class="mb-6 px-4 py-3 bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-sm text-sm">
                        <strong>Warning — this action is permanent.</strong><br>
                        All your personal data, authorized applications, activity history, and account
                        information will be permanently deleted and cannot be recovered.
                    </div>

                    <form method="post" action="<?php echo sURL . $routeBase; ?>/deleteaccount">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <div class="mb-4">
                            <label for="del_password" class="block text-sm font-medium text-gray-700 mb-1">Current Password</label>
                            <input type="password" id="del_password" name="password"
                                   class="w-full border border-gray-300 rounded-sm px-3 py-2 focus:outline-hidden focus:ring-2 focus:ring-red-400"
                                   required autocomplete="current-password">
                        </div>
                        <div class="mb-6">
                            <label for="del_confirm" class="block text-sm font-medium text-gray-700 mb-1">
                                Type <strong>DELETE</strong> to confirm
                            </label>
                            <input type="text" id="del_confirm" name="confirmation"
                                   class="w-full border border-gray-300 rounded-sm px-3 py-2 focus:outline-hidden focus:ring-2 focus:ring-red-400"
                                   placeholder="DELETE" required autocomplete="off">
                        </div>
                        <button type="submit"
                                class="w-full py-2 px-4 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-sm transition-colors">
                            Permanently Delete My Account
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
