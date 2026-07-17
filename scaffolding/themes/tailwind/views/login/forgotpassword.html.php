<?php
/**
 * Forgot-password form (Tailwind theme) — Account/LoginFlow flow.
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::renderForgot):
 *   $this->routeBase, $this->brand, $this->error, $this->message
 */
$brand   = $this->brand ?? [];
$primary = htmlspecialchars((string) ($brand['primary_color'] ?? '#2563eb'), ENT_QUOTES);
$base    = sURL . rawurlencode((string) ($this->routeBase ?? 'Account'));

$errorMessages = [
    'invalid_token' => 'Your session expired. Please try again.',
    'invalid_email' => 'Please enter a valid email address.',
];
$errorKey  = (string) ($this->error ?? '');
$errorText = $errorMessages[$errorKey] ?? $errorKey;
$sent      = (($this->message ?? '') === 'sent');
?>
<div class="flex items-center justify-center min-h-screen bg-gray-100 px-4">
    <div class="w-full max-w-sm bg-white rounded-xl shadow-md p-8">
        <h1 class="text-2xl font-semibold mb-1">Forgot your password?</h1>
        <p class="text-sm text-gray-500 mb-6">Enter your email and we'll send you a reset link.</p>

        <?php if ($sent): ?>
            <div class="bg-blue-100 border border-blue-300 text-blue-800 rounded-sm p-3 mb-4">If an account exists for that email, a password-reset link is on its way. Check your inbox.</div>
        <?php else: ?>
            <?php if ($errorText !== ''): ?>
                <div class="bg-red-100 border border-red-300 text-red-800 rounded-sm p-3 mb-4"><?php echo htmlspecialchars($errorText); ?></div>
            <?php endif; ?>
            <form method="POST" action="<?php echo $base; ?>/forgotpassword" class="space-y-4">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" id="email" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-hidden focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars((string) ($this->email ?? '')); ?>" required autofocus autocomplete="email">
                </div>
                <button type="submit" class="w-full text-white font-medium py-2 px-4 rounded-md transition-colors" style="background-color:<?php echo $primary; ?>">Send reset link</button>
            </form>
        <?php endif; ?>
        <p class="text-center text-sm mt-4">
            <a href="<?php echo $base; ?>/login" class="text-blue-600 hover:underline">&larr; Back to login</a>
        </p>
    </div>
</div>
