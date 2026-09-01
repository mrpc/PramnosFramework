<?php
/**
 * User registration form (Bootstrap theme).
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
/*
 * The error box's id, and the attributes that point the first field at it.
 *
 * `role="alert"` on its own is unreliable for an error that is already in the document when the
 * page loads: a screen reader announces a live region when it *changes*, and this one never
 * changed. What works with no JavaScript at all is the description — the field is marked invalid
 * and described by the box, so the message is read out as part of the field the moment focus
 * lands on it, and focus lands there on load because the first field carries `autofocus`.
 *
 * The *first* field only. These errors are form-level — «wrong username or password» is about the
 * pair — and marking four fields invalid to report one failure tells a screen reader four things
 * that are not true.
 */
$errorFieldAttributes = $errorText !== ''
    ? ' aria-invalid="true" aria-describedby="form-error"'
    : '';

$closed    = ($this->registrationOpen ?? true) === false;
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-7 col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3"><?php echo htmlspecialchars($this->header ?? 'Create Account'); ?></h1>

                    <?php if ($errorText !== ''): ?>
                        <div role="alert" id="form-error" class="alert alert-danger"><?php echo htmlspecialchars($errorText); ?></div>
                    <?php endif; ?>
                    <?php if ($this->hasErrors()): ?>
                        <div role="alert" class="alert alert-danger"><?php echo $this->_printErrors(); ?></div>
                    <?php endif; ?>

                    <?php if (!$closed): ?>
                    <form data-pf-progress method="POST" action="<?php echo sURL; ?>register">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" name="username" id="username" class="form-control" required autocomplete="username"<?php echo $errorFieldAttributes; ?> autocapitalize="none" autocorrect="off" spellcheck="false"
                                   value="<?php echo htmlspecialchars($this->formData['username'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" name="email" id="email" class="form-control" required autocomplete="email"
                                   value="<?php echo htmlspecialchars($this->formData['email'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" name="password" id="password" class="form-control" required minlength="8" autocomplete="new-password">
                            <?php echo \Pramnos\Html\PasswordToggle::render(
                                'password', '', ''
                            ); ?>
                            <div class="form-text">At least 8 characters, with a digit and a symbol.</div>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm Password</label>
                            <input type="password" name="confirm_password" enterkeyhint="go" id="confirm_password" class="form-control" required autocomplete="new-password">
                            <?php echo \Pramnos\Html\PasswordToggle::render(
                                'confirm_password', '', ''
                            ); ?>
                        </div>
                        <button type="submit" class="btn btn-success w-100">Create Account</button>
                    </form>
                    <?php endif; ?>
                    <div class="text-center mt-3">
                        <a href="<?php echo sURL; ?>login" class="small">Already have an account? Sign in</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="<?php echo sURL; ?>assets/js/pf-auth.js"></script>
