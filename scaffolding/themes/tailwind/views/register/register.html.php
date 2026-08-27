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
    'human_check'            => 'The security check did not complete. Reload the page and try again — it needs a modern browser with JavaScript enabled.',
];
$errorKey  = (string) ($this->error ?? '');
$errorText = $messages[$errorKey] ?? $errorKey;
$closed    = ($this->registrationOpen ?? true) === false;
?>
<div class="flex items-center justify-center min-h-screen bg-base-200 px-4 py-8">
    <div class="card bg-base-100 shadow-md p-8 w-full max-w-md">
        <h1 class="text-2xl font-semibold mb-6"><?php echo htmlspecialchars($this->header ?? 'Create Account'); ?></h1>

        <?php if ($errorText !== ''): ?>
            <div class="alert alert-error mb-4"><?php echo htmlspecialchars($errorText); ?></div>
        <?php endif; ?>
        <?php if ($this->hasErrors()): ?>
            <div class="alert alert-error mb-4"><?php echo $this->_printErrors(); ?></div>
        <?php endif; ?>

        <?php if (!$closed): ?>
        <form method="POST" action="<?php echo sURL; ?>register" class="space-y-4">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <?php /* The human check's fields, when the application asks for one. Renders
                     nothing otherwise, so the insert is unconditional. */ ?>
            <?php echo humanCheckField($this->humanCheck ?? null); ?>
            <div>
                <label for="username" class="block text-sm font-medium text-base-content mb-1">Username</label>
                <input type="text" name="username" id="username" class="input w-full"
                       required autocomplete="username" value="<?php echo htmlspecialchars($this->formData['username'] ?? ''); ?>">
            </div>
            <div>
                <label for="email" class="block text-sm font-medium text-base-content mb-1">Email Address</label>
                <input type="email" name="email" id="email" class="input w-full"
                       required autocomplete="email" value="<?php echo htmlspecialchars($this->formData['email'] ?? ''); ?>">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-base-content mb-1">Password</label>
                <input type="password" name="password" id="password" class="input w-full"
                       required minlength="8" autocomplete="new-password">
                <p class="text-xs text-base-content/70 mt-1">At least 8 characters, with a digit and a symbol.</p>
            </div>
            <div>
                <label for="confirm_password" class="block text-sm font-medium text-base-content mb-1">Confirm Password</label>
                <input type="password" name="confirm_password" id="confirm_password" class="input w-full"
                       required autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn-success w-full">Create Account</button>
        </form>
        <?php endif; ?>
        <p class="text-center text-sm mt-4">
            <a href="<?php echo sURL; ?>login" class="text-primary hover:underline">Already have an account? Sign in</a>
        </p>
    </div>
</div>
