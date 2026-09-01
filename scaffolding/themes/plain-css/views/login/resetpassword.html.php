<?php
/**
 * Reset-password form (plain-CSS theme) — Account/LoginFlow flow.
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::renderReset):
 *   $this->routeBase — controller route base (form action prefix)
 *   $this->brand     — [name, logo, primary_color, footer]
 *   $this->token     — the reset token (carried in a hidden field)
 *   $this->error     — optional error key
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
<div style="display:flex;align-items:center;justify-content:center;min-height:60vh;padding:20px">
    <div class="card" style="width:100%;max-width:400px">
        <div class="card-header"><h2 style="margin:0;font-size:1.25rem">Choose a new password</h2></div>
        <div class="card-body" style="padding:24px">
            <?php if ($errorText !== ''): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($errorText); ?></div>
            <?php endif; ?>

            <?php if ($expired): ?>
                <div style="text-align:center;margin-top:12px">
                    <a href="<?php echo $base; ?>/forgotpassword" style="font-size:13px">Request a new reset link</a>
                </div>
            <?php else: ?>
                <div data-pf-password-error class="alert alert-danger" style="display:none"></div>
                <form method="POST" action="<?php echo $base; ?>/resetpassword" data-pf-password-policy>
                    <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    <div style="margin-bottom:16px">
                        <label for="password" style="display:block;margin-bottom:4px;font-weight:500">New password</label>
                        <input type="password" name="password" id="password" style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:15px" required autofocus autocomplete="new-password">
                        <?php echo \Pramnos\Html\PasswordToggle::render(
                            'password', '', ''
                        ); ?>
                        <small style="color:#666">At least 8 characters, with a number and a symbol.</small>
                    </div>
                    <div style="margin-bottom:20px">
                        <label for="confirm_password" style="display:block;margin-bottom:4px;font-weight:500">Confirm new password</label>
                        <input type="password" name="confirm_password" enterkeyhint="go" id="confirm_password" style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:15px" required autocomplete="new-password">
                        <?php echo \Pramnos\Html\PasswordToggle::render(
                            'confirm_password', '', ''
                        ); ?>
                    </div>
                    <button type="submit" class="btn" style="width:100%;background-color:<?php echo $primary; ?>;border-color:<?php echo $primary; ?>">Reset password</button>
                </form>
            <?php endif; ?>
            <div style="text-align:center;margin-top:12px">
                <a href="<?php echo $base; ?>/login" style="font-size:13px">&larr; Back to login</a>
            </div>
        </div>
    </div>
</div>
<script src="<?php echo sURL; ?>assets/js/pf-auth.js"></script>
