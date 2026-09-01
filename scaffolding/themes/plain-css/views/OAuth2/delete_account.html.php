<?php
/**
 * Delete Account confirmation page (plain-CSS theme).
 * GDPR Article 17 — right to erasure. Reached from Privacy.
 *
 * Variables:
 *   $this->routeBase — Account controller route base
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'deleteaccount';
?>
<div class="page-section">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2>Delete Account</h2>

    <?php if ($this->hasErrors()): ?>
        <div role="alert" class="alert alert-error"><?php echo $this->_printErrors(); ?></div>
    <?php endif; ?>

    <div class="account-grid">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div>
            <div class="card" style="border-color:#c00;max-width:520px">
                <div class="card-header" style="background:#c00;color:#fff"><strong>Delete Account</strong></div>
                <div class="card-body">

                    <div role="status" class="alert alert-warning">
                        <strong>Warning — this action is permanent.</strong><br>
                        All your personal data, authorized applications, activity history, and account
                        information will be permanently deleted. This cannot be undone.
                    </div>

                    <form method="post" action="<?php echo sURL . $routeBase; ?>/deleteaccount">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <div class="form-group">
                            <label for="del_password">Current Password</label>
                            <input type="password" id="del_password" name="password"
                                   class="form-control" required autocomplete="current-password" enterkeyhint="go">
                            <?php echo \Pramnos\Html\PasswordToggle::render(
                                'del_password', '', ''
                            ); ?>
                        </div>
                        <div class="form-group">
                            <label for="del_confirm">Type <strong>DELETE</strong> to confirm</label>
                            <input type="text" id="del_confirm" name="confirmation"
                                   class="form-control" placeholder="DELETE" required autocomplete="off">
                        </div>
                        <button type="submit" class="btn" style="width:100%;background:#c00;color:#fff;border-color:#c00;margin-top:8px">
                            Permanently Delete My Account
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
