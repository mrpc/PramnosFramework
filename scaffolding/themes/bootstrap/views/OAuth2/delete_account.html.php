<?php
/**
 * Delete Account confirmation page (Bootstrap theme).
 * GDPR Article 17 — right to erasure. Reached from Privacy.
 *
 * Variables:
 *   $this->routeBase — Account controller route base
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'deleteaccount';
?>
<div class="container py-4">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2 class="mb-4">Delete Account</h2>

    <?php if ($this->hasErrors()): ?>
        <div class="alert alert-danger"><?php echo $this->_printErrors(); ?></div>
    <?php endif; ?>

    <div class="row g-4">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="col-lg-9 col-md-8">
            <div class="card border-danger" style="max-width:560px">
                <div class="card-header bg-danger text-white fw-semibold">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> Delete Account
                </div>
                <div class="card-body">

                    <div class="alert alert-warning">
                        <strong>Warning — this action is permanent.</strong><br>
                        All your personal data, authorized applications, activity history, and account
                        information will be permanently deleted. This cannot be undone.
                    </div>

                    <form method="post" action="<?php echo sURL . $routeBase; ?>/deleteaccount">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <div class="mb-3">
                            <label for="del_password" class="form-label">Current Password</label>
                            <input type="password" id="del_password" name="password"
                                   class="form-control" required autocomplete="current-password">
                            <?php echo \Pramnos\Html\PasswordToggle::render(
                                'del_password', '', '', 'btn btn-link btn-sm'
                            ); ?>
                        </div>
                        <div class="mb-3">
                            <label for="del_confirm" class="form-label">
                                Type <strong>DELETE</strong> to confirm
                            </label>
                            <input type="text" id="del_confirm" name="confirmation"
                                   class="form-control" placeholder="DELETE" required autocomplete="off">
                        </div>
                        <button type="submit" class="btn btn-danger w-100">
                            Permanently Delete My Account
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
