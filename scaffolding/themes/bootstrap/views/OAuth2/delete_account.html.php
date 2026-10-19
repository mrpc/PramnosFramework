<?php
/**
 * Delete Account confirmation page (Bootstrap theme).
 * GDPR Article 17 — right to erasure. Reached from Privacy.
 *
 * Variables:
 *   $this->routeBase    — Account controller route base
 *   $this->taskHandover — the scheduled AI tasks to hand over or delete (delete_account_tasks)
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'deleteaccount';
?>
<div class="container py-4">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2 class="mb-4"><?php echo htmlspecialchars(t('Delete Account'), ENT_QUOTES, 'UTF-8'); ?></h2>

    <?php if ($this->hasErrors()): ?>
        <div role="alert" class="alert alert-danger"><?php echo $this->_printErrors(); ?></div>
    <?php endif; ?>

    <div class="row g-4">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="col-lg-9 col-md-8">
            <div class="card border-danger" style="max-width:560px">
                <div class="card-header bg-danger text-white fw-semibold">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars(t('Delete Account'), ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="card-body">

                    <div role="status" class="alert alert-warning">
                        <strong><?php echo htmlspecialchars(t('Warning — this action is permanent.'), ENT_QUOTES, 'UTF-8'); ?></strong><br>
                        <?php echo htmlspecialchars(t('All your personal data, authorized applications, activity history, and account information will be permanently deleted. This cannot be undone.'), ENT_QUOTES, 'UTF-8'); ?>
                    </div>

                    <form method="post" action="<?php echo sURL . $routeBase; ?>/deleteaccount">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <?php $this->insert('delete_account_tasks'); ?>
                        <div class="mb-3">
                            <label for="del_password" class="form-label"><?php echo htmlspecialchars(t('Current Password'), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="password" id="del_password" name="password"
                                   class="form-control" required autocomplete="current-password" enterkeyhint="go">
                            <?php echo \Pramnos\Html\PasswordToggle::render(
                                'del_password', '', ''
                            ); ?>
                        </div>
                        <div class="mb-3">
                            <label for="del_confirm" class="form-label">
                                <?php echo sprintf(htmlspecialchars(t('Type %s to confirm'), ENT_QUOTES, 'UTF-8'), '<strong>DELETE</strong>'); ?>
                            </label>
                            <input type="text" id="del_confirm" name="confirmation"
                                   class="form-control" placeholder="DELETE" required autocomplete="off">
                        </div>
                        <button type="submit" class="btn btn-danger w-100">
                            <?php echo htmlspecialchars(t('Permanently Delete My Account'), ENT_QUOTES, 'UTF-8'); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
