<?php
/**
 * Change Password page (Bootstrap theme).
 *
 * Server-side rejections arrive as flash errors (Base::addError); client-side
 * policy hints come from pf-auth.js via [data-pf-password-error].
 * Password policy: ≥ 8 chars, at least one digit, at least one non-alphanumeric.
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'changepassword';
?>
<div class="container py-4">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2 class="mb-4"><?php echo htmlspecialchars(t('Change Password'), ENT_QUOTES, 'UTF-8'); ?></h2>

    <?php if ($this->hasErrors()): ?>
        <div role="alert" class="alert alert-danger"><?php echo $this->_printErrors(); ?></div>
    <?php endif; ?>

    <div class="row g-4">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="col-lg-9 col-md-8">
            <div class="card" style="max-width:520px">
                <div class="card-body">
                    <p class="text-muted mb-4">
                        <?php echo htmlspecialchars(t('Choose a strong password: at least 8 characters, one digit, and one special character.'), ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <div data-pf-password-error role="alert" class="alert alert-danger d-none"></div>
                    <form method="post" action="<?php echo sURL . $routeBase; ?>/changepassword" data-pf-password-policy>
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <div class="mb-3">
                            <label for="current_password" class="form-label"><?php echo htmlspecialchars(t('Current Password'), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="password" id="current_password" name="current_password"
                                   class="form-control" required autocomplete="current-password" enterkeyhint="go" autofocus>
                            <?php echo \Pramnos\Html\PasswordToggle::render(
                                'current_password', '', ''
                            ); ?>
                        </div>
                        <div class="mb-3">
                            <label for="new_password" class="form-label"><?php echo htmlspecialchars(t('New Password'), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="password" id="new_password" name="new_password"
                                   class="form-control" required autocomplete="new-password"
                                   minlength="8" pattern="(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}">
                            <?php echo \Pramnos\Html\PasswordToggle::render(
                                'new_password', '', ''
                            ); ?>
                        </div>
                        <div class="mb-4">
                            <label for="confirm_password" class="form-label"><?php echo htmlspecialchars(t('Confirm New Password'), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="password" id="confirm_password" name="confirm_password" enterkeyhint="go"
                                   class="form-control" required autocomplete="new-password">
                            <?php echo \Pramnos\Html\PasswordToggle::render(
                                'confirm_password', '', ''
                            ); ?>
                        </div>
                        <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('Update Password'), ENT_QUOTES, 'UTF-8'); ?></button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
<script src="<?php echo assetUrl('assets/js/pf-auth.js'); ?>"></script>
