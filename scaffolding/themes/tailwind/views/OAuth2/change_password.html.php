<?php
/**
 * Change Password page (Tailwind theme).
 *
 * Server-side rejections arrive as flash errors (Base::addError); client-side
 * policy hints come from pf-auth.js via [data-pf-password-error].
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'changepassword';
$inputCls = 'input w-full';
?>
<div class="container mx-auto px-4 py-8">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2 class="text-2xl font-bold text-base-content mb-6">Change Password</h2>

    <?php if ($this->hasErrors()): ?>
        <div class="alert alert-error mb-4">
            <?php echo $this->_printErrors(); ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="md:col-span-3">
            <div class="card bg-base-100 shadow-sm p-6 max-w-xl">
                <p class="text-sm text-base-content/70 mb-5">
                    Choose a strong password: at least 8 characters, one digit, and one special character.
                </p>
                <div data-pf-password-error class="alert alert-error hidden mb-4"></div>
                <form method="post" action="<?php echo sURL . $routeBase; ?>/changepassword" class="space-y-4" data-pf-password-policy>
                    <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                    <div>
                        <label for="current_password" class="block text-sm font-medium text-base-content mb-1">Current Password</label>
                        <input type="password" id="current_password" name="current_password"
                               class="<?php echo $inputCls; ?>
                        <?php echo \Pramnos\Html\PasswordToggle::render(
                            'current_password', '', ''
                        ); ?>" required autocomplete="current-password" enterkeyhint="go" autofocus>
                    </div>
                    <div>
                        <label for="new_password" class="block text-sm font-medium text-base-content mb-1">New Password</label>
                        <input type="password" id="new_password" name="new_password"
                               class="<?php echo $inputCls; ?>
                        <?php echo \Pramnos\Html\PasswordToggle::render(
                            'new_password', '', ''
                        ); ?>" required autocomplete="new-password" minlength="8">
                    </div>
                    <div>
                        <label for="confirm_password" class="block text-sm font-medium text-base-content mb-1">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" enterkeyhint="go"
                               class="<?php echo $inputCls; ?>
                        <?php echo \Pramnos\Html\PasswordToggle::render(
                            'confirm_password', '', ''
                        ); ?>" required autocomplete="new-password">
                    </div>
                    <button type="submit"
                            class="btn btn-primary">
                        Update Password
                    </button>
                </form>
            </div>
        </div>
    </div>

</div>
<script src="<?php echo sURL; ?>assets/js/pf-auth.js"></script>
