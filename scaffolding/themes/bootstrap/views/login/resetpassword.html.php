<?php
/**
 * Reset-password form (Bootstrap theme) — Account/LoginFlow flow.
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::renderReset):
 *   $this->routeBase, $this->brand, $this->token, $this->error
 */
$brand   = $this->brand ?? \Pramnos\Auth\Brand::current();
// The brand's colour only where one was set: otherwise the theme's own primary, light or dark.
$primary = ($brand['primary_color_set'] ?? true) ? htmlspecialchars((string) ($brand['primary_color'] ?? ''), ENT_QUOTES) : '';
$base    = sURL . rawurlencode((string) ($this->routeBase ?? 'Account'));
$token   = (string) ($this->token ?? '');

$errorMessages = [
    'invalid_token'          => t('Your session expired. Please try again.'),
    'invalid_reset_link'     => t('This reset link is invalid or has expired. Please request a new one.'),
    'password_required'      => t('Please enter a new password.'),
    'password_too_short'     => t('Your password must be at least 8 characters long.'),
    'password_needs_digit'   => t('Your password must contain at least one number.'),
    'password_needs_symbol'  => t('Your password must contain at least one symbol.'),
    'passwords_do_not_match' => t('The two passwords do not match.'),
];
$errorKey  = (string) ($this->error ?? '');
$errorText = $errorMessages[$errorKey] ?? $errorKey;
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

$expired   = ($errorKey === 'invalid_reset_link' && $token === '');
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <?php $this->insert('../partials/auth_brand'); ?>
                    <h1 class="h4 mb-3"><?php echo htmlspecialchars(t('Choose a new password'), ENT_QUOTES, 'UTF-8'); ?></h1>

                    <?php if ($errorText !== ''): ?>
                        <div role="alert" id="form-error" class="alert alert-danger"><?php echo htmlspecialchars($errorText); ?></div>
                    <?php endif; ?>

                    <?php if ($expired): ?>
                        <div class="text-center">
                            <a href="<?php echo $base; ?>/forgotpassword" class="small"><?php echo htmlspecialchars(t('Request a new reset link'), ENT_QUOTES, 'UTF-8'); ?></a>
                        </div>
                    <?php else: ?>
                        <div data-pf-password-error role="alert" class="alert alert-danger d-none"></div>
                        <form data-pf-progress method="POST" action="<?php echo $base; ?>/resetpassword" data-pf-password-policy>
                            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                            <div class="mb-3">
                                <label for="password" class="form-label"><?php echo htmlspecialchars(t('New password'), ENT_QUOTES, 'UTF-8'); ?></label>
                                <input type="password" name="password" id="password" class="form-control" required autofocus autocomplete="new-password"<?php echo $errorFieldAttributes; ?>>
                                <?php echo \Pramnos\Html\PasswordToggle::render(
                                    'password', '', ''
                                ); ?>
                                <div class="form-text"><?php echo htmlspecialchars(t('At least 8 characters, with a number and a symbol.'), ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label"><?php echo htmlspecialchars(t('Confirm new password'), ENT_QUOTES, 'UTF-8'); ?></label>
                                <input type="password" name="confirm_password" enterkeyhint="go" id="confirm_password" class="form-control" required autocomplete="new-password">
                                <?php echo \Pramnos\Html\PasswordToggle::render(
                                    'confirm_password', '', ''
                                ); ?>
                            </div>
                            <button type="submit" class="btn btn-primary w-100" style="<?php echo $primary !== '' ? 'background-color:' . $primary . ';border-color:' . $primary : ''; ?>"><?php echo htmlspecialchars(t('Reset password'), ENT_QUOTES, 'UTF-8'); ?></button>
                        </form>
                    <?php endif; ?>
                    <div class="text-center mt-3">
                        <a href="<?php echo $base; ?>/login" class="small">&larr; <?php echo htmlspecialchars(t('Back to login'), ENT_QUOTES, 'UTF-8'); ?></a>
                    </div>
                </div>
            </div>
            <?php $this->insert('../partials/auth_home_link'); ?>
        </div>
    </div>
</div>
<script src="<?php echo assetUrl('assets/js/pf-auth.js'); ?>"></script>
