<?php
/**
 * User registration form (Tailwind theme).
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::renderRegister):
 *   $this->header             — Page heading
 *   $this->brand              — name / logo / footer from the application settings
 *   $this->error              — Error key, mapped to a message below
 *   $this->formData           — Previously submitted username / email
 *   $this->registrationOpen   — false when the auth_allow_registration setting is off
 *
 * The error arrives as a key rather than a sentence so the controller does not
 * decide wording and this file does not decide policy.
 */
$brand    = $this->brand ?? [];
$messages = [
    'registration_closed'    => 'Registration is not open on this server.',
    'invalid_token'          => 'Your session expired. Please try again.',
    'username_required'      => 'Please choose a username.',
    'username_length'        => 'A username must be between 3 and 60 characters.',
    'username_invalid'       => 'A username may contain letters, digits, dots, dashes and underscores only.',
    'username_taken'         => 'That username is already taken.',
    'invalid_email'          => 'Please enter a valid email address.',
    'email_unavailable'      => 'That email address cannot be used to create an account.',
    'password_required'      => 'Please choose a password.',
    'password_too_short'     => 'Your password must be at least 8 characters long.',
    'password_needs_digit'   => 'Your password must contain at least one digit.',
    'password_needs_symbol'  => 'Your password must contain at least one symbol.',
    'passwords_do_not_match' => 'The two passwords do not match.',
    'registration_failed'    => 'The account could not be created. Please try again later.',
];
$errorKey  = (string) ($this->error ?? '');
$errorText = $messages[$errorKey] ?? $errorKey;
$closed    = ($this->registrationOpen ?? true) === false;
?>
<div class="flex items-center justify-center min-h-screen bg-gray-100 px-4 py-8">
    <div class="w-full max-w-md bg-white rounded-xl shadow-md p-8">
        <h1 class="text-2xl font-semibold mb-6"><?php echo htmlspecialchars($this->header ?? 'Create Account'); ?></h1>

        <?php if ($errorText !== ''): ?>
            <div class="bg-red-100 border border-red-300 text-red-800 rounded-sm p-3 mb-4"><?php echo htmlspecialchars($errorText); ?></div>
        <?php endif; ?>
        <?php if ($this->hasErrors()): ?>
            <div class="bg-red-100 border border-red-300 text-red-800 rounded-sm p-3 mb-4"><?php echo $this->_printErrors(); ?></div>
        <?php endif; ?>

        <?php if (!$closed): ?>
        <form method="POST" action="<?php echo sURL; ?>register" class="space-y-4">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <div>
                <label for="username" class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                <input type="text" name="username" id="username" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-hidden focus:ring-2 focus:ring-green-500"
                       required autocomplete="username" value="<?php echo htmlspecialchars($this->formData['username'] ?? ''); ?>">
            </div>
            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                <input type="email" name="email" id="email" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-hidden focus:ring-2 focus:ring-green-500"
                       required autocomplete="email" value="<?php echo htmlspecialchars($this->formData['email'] ?? ''); ?>">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <input type="password" name="password" id="password" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-hidden focus:ring-2 focus:ring-green-500"
                       required minlength="8" autocomplete="new-password">
                <p class="text-xs text-gray-500 mt-1">At least 8 characters, with a digit and a symbol.</p>
            </div>
            <div>
                <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
                <input type="password" name="confirm_password" id="confirm_password" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-hidden focus:ring-2 focus:ring-green-500"
                       required autocomplete="new-password">
            </div>
            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition-colors">Create Account</button>
        </form>
        <?php endif; ?>
        <p class="text-center text-sm mt-4">
            <a href="<?php echo sURL; ?>login" class="text-blue-600 hover:underline">Already have an account? Sign in</a>
        </p>
    </div>
</div>
