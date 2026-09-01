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
<div class="flex items-center justify-center min-h-screen bg-base-200 px-4">
    <div class="card bg-base-100 shadow-md p-8 w-full max-w-sm" style="--color-primary:<?php echo $primary; ?>">
        <h1 class="text-2xl font-semibold mb-4">Choose a new password</h1>

        <?php if ($errorText !== ''): ?>
            <div class="alert alert-error mb-4"><?php echo htmlspecialchars($errorText); ?></div>
        <?php endif; ?>

        <?php if ($expired): ?>
            <p class="text-center text-sm">
                <a href="<?php echo $base; ?>/forgotpassword" class="text-primary hover:underline">Request a new reset link</a>
            </p>
        <?php else: ?>
            <div data-pf-password-error class="alert alert-error mb-4 hidden"></div>
            <form method="POST" action="<?php echo $base; ?>/resetpassword" data-pf-password-policy class="space-y-4">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <div>
                    <label for="password" class="block text-sm font-medium text-base-content mb-1">New password</label>
                    <input type="password" name="password" id="password" class="input w-full" required autofocus autocomplete="new-password">
                    <?php echo \Pramnos\Html\PasswordToggle::render(
                        'password', '', ''
                    ); ?>
                    <p class="text-xs text-base-content/70 mt-1">At least 8 characters, with a number and a symbol.</p>
                </div>
                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-base-content mb-1">Confirm new password</label>
                    <input type="password" name="confirm_password" enterkeyhint="go" id="confirm_password" class="input w-full" required autocomplete="new-password">
                    <?php echo \Pramnos\Html\PasswordToggle::render(
                        'confirm_password', '', ''
                    ); ?>
                </div>
                <button type="submit" class="btn btn-primary w-full">Reset password</button>
            </form>
        <?php endif; ?>
        <p class="text-center text-sm mt-4">
            <a href="<?php echo $base; ?>/login" class="text-primary hover:underline">&larr; Back to login</a>
        </p>
    </div>
</div>
<script src="<?php echo sURL; ?>assets/js/pf-auth.js"></script>
