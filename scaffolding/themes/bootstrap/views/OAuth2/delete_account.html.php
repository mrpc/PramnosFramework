<?php
/**
 * Delete Account confirmation page (Bootstrap theme).
 * GDPR Article 17 — right to erasure.
 *
 * No view variables set by the controller.
 * Error state communicated via $_GET['error'].
 */
$errorMessages = [
    'invalid_password'      => 'The password you entered is incorrect.',
    'confirmation_required' => 'You must type DELETE in the confirmation field.',
    'deletion_failed'       => 'An error occurred while deleting your account. Please try again.',
];
?>
<div class="container py-4" style="max-width:540px">

    <p><a href="<?php echo sURL; ?>Dashboard">← Back to Dashboard</a></p>

    <div class="card border-danger">
        <div class="card-header bg-danger text-white fw-semibold">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> Delete Account
        </div>
        <div class="card-body">

            <?php if (!empty($_GET['error']) && isset($errorMessages[$_GET['error']])): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($errorMessages[$_GET['error']]); ?>
                </div>
            <?php endif; ?>

            <div class="alert alert-warning">
                <strong>Warning — this action is permanent.</strong><br>
                All your personal data, authorized applications, activity history, and account
                information will be permanently deleted. This cannot be undone.
            </div>

            <form method="post" action="<?php echo sURL; ?>Dashboard/deleteaccount">
                <div class="mb-3">
                    <label for="del_password" class="form-label">Current Password</label>
                    <input type="password" id="del_password" name="password"
                           class="form-control" required autocomplete="current-password">
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
                <a href="<?php echo sURL; ?>Dashboard" class="btn btn-outline-secondary w-100 mt-2">
                    Cancel
                </a>
            </form>
        </div>
    </div>

</div>
