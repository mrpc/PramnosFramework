<?php
/**
 * Delete Account page (Tailwind theme).
 * GDPR Article 17 — right to erasure. Reached from Privacy.
 *
 * Variables:
 *   $this->routeBase    — Account controller route base
 *   $this->taskHandover — the scheduled AI tasks to hand over or delete (delete_account_tasks)
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'deleteaccount';
?>
<div class="container mx-auto px-4 py-8">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2 class="text-2xl font-bold text-base-content mb-6"><?php echo htmlspecialchars(t('Delete Account'), ENT_QUOTES, 'UTF-8'); ?></h2>

    <?php if ($this->hasErrors()): ?>
        <div role="alert" class="alert alert-error mb-4">
            <?php echo $this->_printErrors(); ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="md:col-span-3">
            <div class="card bg-base-100 shadow-sm max-w-lg">
                <div class="px-4 py-3 bg-error rounded-t-lg">
                    <h3 class="text-error-content font-semibold"><?php echo htmlspecialchars(t('Delete Account'), ENT_QUOTES, 'UTF-8'); ?></h3>
                </div>
                <div class="p-6">

                    <div role="status" class="alert alert-warning mb-6 text-sm block">
                        <strong><?php echo htmlspecialchars(t('Warning — this action is permanent.'), ENT_QUOTES, 'UTF-8'); ?></strong><br>
                        <?php echo htmlspecialchars(t('All your personal data, authorized applications, activity history, and account information will be permanently deleted and cannot be recovered.'), ENT_QUOTES, 'UTF-8'); ?>
                    </div>

                    <form method="post" action="<?php echo sURL . $routeBase; ?>/deleteaccount">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <?php $this->insert('delete_account_tasks'); ?>
                        <div class="mb-4">
                            <label for="del_password" class="block text-sm font-medium text-base-content mb-1"><?php echo htmlspecialchars(t('Current Password'), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="password" id="del_password" name="password"
                                   class="input w-full"
                                   required autocomplete="current-password" enterkeyhint="go">
                            <?php echo \Pramnos\Html\PasswordToggle::render(
                                'del_password', '', ''
                            ); ?>
                        </div>
                        <div class="mb-6">
                            <label for="del_confirm" class="block text-sm font-medium text-base-content mb-1">
                                <?php echo sprintf(htmlspecialchars(t('Type %s to confirm'), ENT_QUOTES, 'UTF-8'), '<strong>DELETE</strong>'); ?>
                            </label>
                            <input type="text" id="del_confirm" name="confirmation"
                                   class="input w-full"
                                   placeholder="DELETE" required autocomplete="off">
                        </div>
                        <button type="submit"
                                class="btn btn-error w-full">
                            <?php echo htmlspecialchars(t('Permanently Delete My Account'), ENT_QUOTES, 'UTF-8'); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
