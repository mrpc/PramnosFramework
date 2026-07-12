<?php
/**
 * Delete Account confirmation page (plain-CSS theme).
 * GDPR Article 17 — right to erasure.
 */
$errorMessages = [
    'invalid_password'      => 'The password you entered is incorrect.',
    'confirmation_required' => 'You must type DELETE in the confirmation field.',
    'deletion_failed'       => 'An error occurred while deleting your account. Please try again.',
];
?>
<div class="page-section" style="max-width:500px;margin:0 auto">

    <p><a href="<?php echo sURL . ($this->routeBase ?? 'Dashboard'); ?>">← Back to Dashboard</a></p>

    <div class="card" style="border-color:#c00">
        <div class="card-header" style="background:#c00;color:#fff"><strong>Delete Account</strong></div>
        <div class="card-body">

            <?php if (!empty($_GET['error']) && isset($errorMessages[$_GET['error']])): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($errorMessages[$_GET['error']]); ?>
                </div>
            <?php endif; ?>

            <div class="alert alert-warning">
                <strong>Warning — this action is permanent.</strong><br>
                All your personal data, authorized applications, activity history, and account
                information will be permanently deleted. This cannot be undone.
            </div>

            <form method="post" action="<?php echo sURL . ($this->routeBase ?? 'Dashboard'); ?>/deleteaccount">
                <div class="form-group">
                    <label for="del_password">Current Password</label>
                    <input type="password" id="del_password" name="password"
                           class="form-control" required autocomplete="current-password">
                </div>
                <div class="form-group">
                    <label for="del_confirm">Type <strong>DELETE</strong> to confirm</label>
                    <input type="text" id="del_confirm" name="confirmation"
                           class="form-control" placeholder="DELETE" required autocomplete="off">
                </div>
                <button type="submit" class="btn" style="width:100%;background:#c00;color:#fff;border-color:#c00;margin-top:8px">
                    Permanently Delete My Account
                </button>
                <a href="<?php echo sURL . ($this->routeBase ?? 'Dashboard'); ?>" class="btn" style="width:100%;margin-top:8px;box-sizing:border-box;text-align:center;display:block">
                    Cancel
                </a>
            </form>
        </div>
    </div>

</div>
