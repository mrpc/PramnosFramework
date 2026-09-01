<?php
/**
 * Reset-password form (Bootstrap theme) — Account/LoginFlow flow.
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
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3">Choose a new password</h1>

                    <?php if ($errorText !== ''): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($errorText); ?></div>
                    <?php endif; ?>

                    <?php if ($expired): ?>
                        <div class="text-center">
                            <a href="<?php echo $base; ?>/forgotpassword" class="small">Request a new reset link</a>
                        </div>
                    <?php else: ?>
                        <div data-pf-password-error class="alert alert-danger d-none"></div>
                        <form method="POST" action="<?php echo $base; ?>/resetpassword" data-pf-password-policy>
                            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                            <div class="mb-3">
                                <label for="password" class="form-label">New password</label>
                                <input type="password" name="password" id="password" class="form-control" required autofocus autocomplete="new-password">
                                <?php echo \Pramnos\Html\PasswordToggle::render(
                                    'password', '', ''
                                ); ?>
                                <div class="form-text">At least 8 characters, with a number and a symbol.</div>
                            </div>
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Confirm new password</label>
                                <input type="password" name="confirm_password" enterkeyhint="go" id="confirm_password" class="form-control" required autocomplete="new-password">
                                <?php echo \Pramnos\Html\PasswordToggle::render(
                                    'confirm_password', '', ''
                                ); ?>
                            </div>
                            <button type="submit" class="btn btn-primary w-100" style="background-color:<?php echo $primary; ?>;border-color:<?php echo $primary; ?>">Reset password</button>
                        </form>
                    <?php endif; ?>
                    <div class="text-center mt-3">
                        <a href="<?php echo $base; ?>/login" class="small">&larr; Back to login</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="<?php echo sURL; ?>assets/js/pf-auth.js"></script>
