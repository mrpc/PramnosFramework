<?php
/**
 * User registration form (plain-CSS theme).
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
<div style="display:flex;align-items:center;justify-content:center;min-height:70vh;padding:20px">
    <div class="card" style="width:100%;max-width:460px">
        <div class="card-header"><h2 style="margin:0;font-size:1.25rem"><?php echo htmlspecialchars($this->header ?? 'Create Account'); ?></h2></div>
        <div class="card-body" style="padding:24px">

            <?php if ($errorText !== ''): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($errorText); ?></div>
            <?php endif; ?>
            <?php if ($this->hasErrors()): ?>
                <div class="alert alert-danger"><?php echo $this->_printErrors(); ?></div>
            <?php endif; ?>

            <?php if (!$closed): ?>
            <form method="POST" action="<?php echo sURL; ?>register">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <?php
                $inputStyle = 'width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:15px';
                $labelStyle = 'display:block;margin-bottom:4px;font-weight:500';
                ?>
                <div style="margin-bottom:14px">
                    <label for="username" style="<?php echo $labelStyle; ?>">Username</label>
                    <input type="text" name="username" id="username" style="<?php echo $inputStyle; ?>" required autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false"
                           value="<?php echo htmlspecialchars($this->formData['username'] ?? ''); ?>">
                </div>
                <div style="margin-bottom:14px">
                    <label for="email" style="<?php echo $labelStyle; ?>">Email Address</label>
                    <input type="email" name="email" id="email" style="<?php echo $inputStyle; ?>" required autocomplete="email"
                           value="<?php echo htmlspecialchars($this->formData['email'] ?? ''); ?>">
                </div>
                <div style="margin-bottom:14px">
                    <label for="password" style="<?php echo $labelStyle; ?>">Password</label>
                    <input type="password" name="password" id="password" style="<?php echo $inputStyle; ?>
                    <?php echo \Pramnos\Html\PasswordToggle::render(
                        'password', '', ''
                    ); ?>" required minlength="8" autocomplete="new-password">
                    <small style="color:#666">At least 8 characters, with a digit and a symbol.</small>
                </div>
                <div style="margin-bottom:20px">
                    <label for="confirm_password" style="<?php echo $labelStyle; ?>">Confirm Password</label>
                    <input type="password" name="confirm_password" enterkeyhint="go" id="confirm_password" style="<?php echo $inputStyle; ?>
                    <?php echo \Pramnos\Html\PasswordToggle::render(
                        'confirm_password', '', ''
                    ); ?>" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn" style="width:100%;background:#27ae60">Create Account</button>
            </form>
            <?php endif; ?>
            <div style="text-align:center;margin-top:12px">
                <a href="<?php echo sURL; ?>login" style="font-size:13px">Already have an account? Sign in</a>
            </div>
        </div>
    </div>
</div>
