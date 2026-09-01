<?php
/**
 * Change Password page (plain-CSS theme).
 *
 * Server-side rejections arrive as flash errors (Base::addError); client-side
 * policy hints come from pf-auth.js via [data-pf-password-error].
 * Password policy: ≥ 8 chars, at least one digit, at least one non-alphanumeric.
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'changepassword';
?>
<div class="page-section">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2>Change Password</h2>

    <?php if ($this->hasErrors()): ?>
        <div class="alert alert-error"><?php echo $this->_printErrors(); ?></div>
    <?php endif; ?>

    <div class="account-grid">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div>
            <div class="card" style="max-width:480px">
                <div class="card-body">
                    <p style="font-size:.9em;color:#666;margin-bottom:16px">
                        Choose a strong password: at least 8 characters, one digit, and one special character.
                    </p>
                    <div data-pf-password-error class="alert alert-error" style="display:none"></div>
                    <form method="post" action="<?php echo sURL . $routeBase; ?>/changepassword" data-pf-password-policy>
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password"
                                   class="form-control" required autocomplete="current-password" enterkeyhint="go" autofocus>
                            <?php echo \Pramnos\Html\PasswordToggle::render(
                                'current_password', '', '', 'btn btn-outline btn-sm'
                            ); ?>
                        </div>
                        <div class="form-group">
                            <label for="new_password">New Password</label>
                            <input type="password" id="new_password" name="new_password"
                                   class="form-control" required autocomplete="new-password" minlength="8">
                            <?php echo \Pramnos\Html\PasswordToggle::render(
                                'new_password', '', '', 'btn btn-outline btn-sm'
                            ); ?>
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" enterkeyhint="go"
                                   class="form-control" required autocomplete="new-password">
                            <?php echo \Pramnos\Html\PasswordToggle::render(
                                'confirm_password', '', '', 'btn btn-outline btn-sm'
                            ); ?>
                        </div>
                        <button type="submit" class="btn btn-primary">Update Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
<script src="<?php echo sURL; ?>assets/js/pf-auth.js"></script>
