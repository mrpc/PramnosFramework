<?php
/**
 * Reset-password form (Tailwind theme) — Account/LoginFlow flow.
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::renderReset):
 *   $this->routeBase, $this->brand, $this->token, $this->error
 */
$brand   = $this->brand ?? [];
$primary = htmlspecialchars((string) ($brand['primary_color'] ?? '#2563eb'), ENT_QUOTES);
$base    = sURL . rawurlencode((string) ($this->routeBase ?? 'Account'));
$token   = (string) ($this->token ?? '');

$errorMessages = [
    'invalid_token'          => 'Your session expired. Please try again.',
    'invalid_reset_link'     => 'This reset link is invalid or has expired. Please request a new one.',
    'password_required'      => 'Please enter a new password.',
    'password_too_short'     => 'Your password must be at least 8 characters long.',
    'password_needs_digit'   => 'Your password must contain at least one number.',
    'password_needs_symbol'  => 'Your password must contain at least one symbol.',
    'passwords_do_not_match' => 'The two passwords do not match.',
];
$errorKey  = (string) ($this->error ?? '');
$errorText = $errorMessages[$errorKey] ?? $errorKey;
$expired   = ($errorKey === 'invalid_reset_link' && $token === '');
?>
<div class="flex items-center justify-center min-h-screen bg-gray-100 px-4">
    <div class="w-full max-w-sm bg-white rounded-xl shadow-md p-8">
        <h1 class="text-2xl font-semibold mb-4">Choose a new password</h1>

        <?php if ($errorText !== ''): ?>
            <div class="bg-red-100 border border-red-300 text-red-800 rounded-sm p-3 mb-4"><?php echo htmlspecialchars($errorText); ?></div>
        <?php endif; ?>

        <?php if ($expired): ?>
            <p class="text-center text-sm">
                <a href="<?php echo $base; ?>/forgotpassword" class="text-blue-600 hover:underline">Request a new reset link</a>
            </p>
        <?php else: ?>
            <div data-pf-password-error class="bg-red-100 border border-red-300 text-red-800 rounded-sm p-3 mb-4 hidden"></div>
            <form method="POST" action="<?php echo $base; ?>/resetpassword" data-pf-password-policy class="space-y-4">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1">New password</label>
                    <input type="password" name="password" id="password" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-hidden focus:ring-2 focus:ring-blue-500" required autofocus autocomplete="new-password">
                    <p class="text-xs text-gray-500 mt-1">At least 8 characters, with a number and a symbol.</p>
                </div>
                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm new password</label>
                    <input type="password" name="confirm_password" id="confirm_password" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-hidden focus:ring-2 focus:ring-blue-500" required autocomplete="new-password">
                </div>
                <button type="submit" class="w-full text-white font-medium py-2 px-4 rounded-md transition-colors" style="background-color:<?php echo $primary; ?>">Reset password</button>
            </form>
        <?php endif; ?>
        <p class="text-center text-sm mt-4">
            <a href="<?php echo $base; ?>/login" class="text-blue-600 hover:underline">&larr; Back to login</a>
        </p>
    </div>
</div>
<script src="<?php echo sURL; ?>assets/js/pf-auth.js"></script>
