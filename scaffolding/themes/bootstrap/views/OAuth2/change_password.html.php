<?php
/**
 * Change Password page (Bootstrap theme).
 *
 * No view variables set by the controller.
 * Error/success state communicated via $_GET['error'].
 *
 * Password policy: ≥ 8 chars, at least one digit, at least one non-alphanumeric.
 */
$errorMessages = [
    'wrong_password'        => 'The current password you entered is incorrect.',
    'password_required'     => 'New password is required.',
    'password_too_short'    => 'New password must be at least 8 characters.',
    'password_needs_digit'  => 'New password must contain at least one digit.',
    'password_needs_symbol' => 'New password must contain at least one special character.',
    'passwords_do_not_match' => 'New passwords do not match.',
];
?>
<div class="container py-4" style="max-width:500px">

    <p><a href="<?php echo sURL; ?>Dashboard/security">← Back to Security</a></p>
    <h2>Change Password</h2>

    <?php if (!empty($_GET['error']) && isset($errorMessages[$_GET['error']])): ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($errorMessages[$_GET['error']]); ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <p class="text-muted mb-4">
                Choose a strong password: at least 8 characters, one digit, and one special character.
            </p>
            <form method="post" action="<?php echo sURL; ?>Dashboard/changepassword">
                <?php
                $session = \Pramnos\Http\Session::getInstance();
                echo $session->getTokenField('post');
                ?>
                <div class="mb-3">
                    <label for="current_password" class="form-label">Current Password</label>
                    <input type="password" id="current_password" name="current_password"
                           class="form-control" required autocomplete="current-password" autofocus>
                </div>
                <div class="mb-3">
                    <label for="new_password" class="form-label">New Password</label>
                    <input type="password" id="new_password" name="new_password"
                           class="form-control" required autocomplete="new-password"
                           minlength="8" pattern="(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}">
                </div>
                <div class="mb-4">
                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password"
                           class="form-control" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary w-100">Update Password</button>
            </form>
        </div>
    </div>

</div>
