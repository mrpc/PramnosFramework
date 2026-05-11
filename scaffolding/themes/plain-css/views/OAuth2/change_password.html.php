<?php
/**
 * Change Password page (plain-CSS theme).
 *
 * Password policy: ≥ 8 chars, at least one digit, at least one non-alphanumeric.
 */
$errorMessages = [
    'wrong_password'         => 'The current password you entered is incorrect.',
    'password_required'      => 'New password is required.',
    'password_too_short'     => 'New password must be at least 8 characters.',
    'password_needs_digit'   => 'New password must contain at least one digit.',
    'password_needs_symbol'  => 'New password must contain at least one special character.',
    'passwords_do_not_match' => 'New passwords do not match.',
];
?>
<div class="page-section" style="max-width:460px;margin:0 auto">

    <p><a href="<?php echo sURL; ?>Dashboard/security">← Back to Security</a></p>
    <h2>Change Password</h2>

    <?php if (!empty($_GET['error']) && isset($errorMessages[$_GET['error']])): ?>
        <div class="alert alert-error">
            <?php echo htmlspecialchars($errorMessages[$_GET['error']]); ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <p style="font-size:.9em;color:#666;margin-bottom:16px">
                Choose a strong password: at least 8 characters, one digit, and one special character.
            </p>
            <form method="post" action="<?php echo sURL; ?>Dashboard/changepassword">
                <?php
                $session = \Pramnos\Http\Session::getInstance();
                echo $session->getTokenField('post');
                ?>
                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password"
                           class="form-control" required autocomplete="current-password" autofocus>
                </div>
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password"
                           class="form-control" required autocomplete="new-password" minlength="8">
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password"
                           class="form-control" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary">Update Password</button>
            </form>
        </div>
    </div>

</div>
