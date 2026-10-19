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
    'registration_closed'    => t('Registration is not open on this server.'),
    'invalid_token'          => t('Your session expired. Please try again.'),
    'username_required'      => t('Please choose a username.'),
    'username_length'        => t('A username must be between 3 and 60 characters.'),
    'username_invalid'       => t('A username may contain letters, digits, dots, dashes and underscores only.'),
    'username_taken'         => t('That username is already taken.'),
    'invalid_email'          => t('Please enter a valid email address.'),
    'email_unavailable'      => t('That email address cannot be used to create an account.'),
    'invite_email_mismatch'  => t('This invitation is for another email address.'),
    'email_domain_not_allowed' => t('Accounts can only be created with an address at an allowed domain.'),
    'password_required'      => t('Please choose a password.'),
    'password_too_short'     => t('Your password must be at least 8 characters long.'),
    'password_needs_digit'   => t('Your password must contain at least one digit.'),
    'password_needs_symbol'  => t('Your password must contain at least one symbol.'),
    'passwords_do_not_match' => t('The two passwords do not match.'),
    'registration_failed'    => t('The account could not be created. Please try again later.'),
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
<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:70vh;padding:20px">
    <div class="card" style="width:100%;max-width:460px">
        <?php $this->insert('../partials/auth_brand'); ?>
        <div class="card-header"><h2 style="margin:0;font-size:1.25rem"><?php echo htmlspecialchars($this->header ?? t('Create Account'), ENT_QUOTES, 'UTF-8'); ?></h2></div>
        <div class="card-body" style="padding:24px">

            <?php if ($errorText !== ''): ?>
                <div role="alert" id="form-error" class="alert alert-danger"><?php echo htmlspecialchars($errorText); ?></div>
            <?php endif; ?>
            <?php if ($this->hasErrors()): ?>
                <div role="alert" class="alert alert-danger"><?php echo $this->_printErrors(); ?></div>
            <?php endif; ?>

            <?php if (!$closed): ?>
            <form data-pf-progress method="POST" action="<?php echo sURL; ?>register">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <?php
                $inputStyle = 'width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:15px';
                $labelStyle = 'display:block;margin-bottom:4px;font-weight:500';
                ?>
                <div style="margin-bottom:14px">
                    <label for="username" style="<?php echo $labelStyle; ?>"><?php echo htmlspecialchars(t('Username'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input type="text" name="username" id="username" style="<?php echo $inputStyle; ?>" required autocomplete="username"<?php echo $errorFieldAttributes; ?> autocapitalize="none" autocorrect="off" spellcheck="false"
                           value="<?php echo htmlspecialchars($this->formData['username'] ?? ''); ?>">
                </div>
                <div style="margin-bottom:14px">
                    <label for="email" style="<?php echo $labelStyle; ?>"><?php echo htmlspecialchars(t('Email Address'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input type="email" name="email" id="email" style="<?php echo $inputStyle; ?>" required autocomplete="email"
                           value="<?php echo htmlspecialchars(($this->invitedEmail ?? '') !== '' ? $this->invitedEmail : ($this->formData['email'] ?? '')); ?>"<?php echo ($this->invitedEmail ?? '') !== '' ? ' readonly' : ''; ?>>
                </div>
                <div style="margin-bottom:14px">
                    <label for="password" style="<?php echo $labelStyle; ?>"><?php echo htmlspecialchars(t('Password'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input type="password" name="password" id="password" style="<?php echo $inputStyle; ?>" required minlength="8" autocomplete="new-password">
                    <?php echo \Pramnos\Html\PasswordToggle::render(
                        'password', '', ''
                    ); ?>
                    <small style="color:#666"><?php echo htmlspecialchars(t('At least 8 characters, with a digit and a symbol.'), ENT_QUOTES, 'UTF-8'); ?></small>
                </div>
                <div style="margin-bottom:20px">
                    <label for="confirm_password" style="<?php echo $labelStyle; ?>"><?php echo htmlspecialchars(t('Confirm Password'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input type="password" name="confirm_password" enterkeyhint="go" id="confirm_password" style="<?php echo $inputStyle; ?>" required autocomplete="new-password">
                    <?php echo \Pramnos\Html\PasswordToggle::render(
                        'confirm_password', '', ''
                    ); ?>
                </div>
                <button type="submit" class="btn" style="width:100%;background:#27ae60"><?php echo htmlspecialchars(t('Create Account'), ENT_QUOTES, 'UTF-8'); ?></button>
            </form>
            <?php endif; ?>
            <div style="text-align:center;margin-top:12px">
                <a href="<?php echo sURL; ?>login" style="font-size:13px"><?php echo htmlspecialchars(t('Already have an account? Sign in'), ENT_QUOTES, 'UTF-8'); ?></a>
            </div>
        </div>
    </div>
    <?php $this->insert('../partials/auth_home_link'); ?>
</div>
<script src="<?php echo assetUrl('assets/js/pf-auth.js'); ?>"></script>
