<?php
/**
 * Change Password page (Tailwind theme).
 */
$errorMessages = [
    'wrong_password'         => 'The current password you entered is incorrect.',
    'password_required'      => 'New password is required.',
    'password_too_short'     => 'New password must be at least 8 characters.',
    'password_needs_digit'   => 'New password must contain at least one digit.',
    'password_needs_symbol'  => 'New password must contain at least one special character.',
    'passwords_do_not_match' => 'New passwords do not match.',
];
?>
<div class="max-w-md mx-auto px-4 py-8">

    <p class="text-sm mb-4"><a href="<?php echo sURL; ?>Dashboard/security" class="text-blue-600 hover:underline">← Back to Security</a></p>
    <h2 class="text-2xl font-bold text-gray-800 mb-6">Change Password</h2>

    <?php if (!empty($_GET['error']) && isset($errorMessages[$_GET['error']])): ?>
        <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded">
            <?php echo htmlspecialchars($errorMessages[$_GET['error']]); ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow p-6">
        <p class="text-sm text-gray-500 mb-5">
            Choose a strong password: at least 8 characters, one digit, and one special character.
        </p>
        <form method="post" action="<?php echo sURL; ?>Dashboard/changepassword" class="space-y-4">
            <?php
            $session = \Pramnos\Http\Session::getInstance();
            echo $session->getTokenField('post');
            ?>
            <div>
                <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">
                    Current Password
                </label>
                <input type="password" id="current_password" name="current_password"
                       class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400"
                       required autocomplete="current-password" autofocus>
            </div>
            <div>
                <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">
                    New Password
                </label>
                <input type="password" id="new_password" name="new_password"
                       class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400"
                       required autocomplete="new-password" minlength="8">
            </div>
            <div>
                <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">
                    Confirm New Password
                </label>
                <input type="password" id="confirm_password" name="confirm_password"
                       class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400"
                       required autocomplete="new-password">
            </div>
            <button type="submit"
                    class="w-full py-2 px-4 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded transition-colors">
                Update Password
            </button>
        </form>
    </div>

</div>
