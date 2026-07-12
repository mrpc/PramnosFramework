<?php
/**
 * User registration form (Bootstrap theme).
 *
 * Variables:
 *   $this->header       — Page heading
 *   $this->error        — Optional error string
 *   $this->formData     — array of previously submitted field values (username, email)
 */
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-7 col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3"><?php echo htmlspecialchars($this->header ?? 'Create Account'); ?></h1>

                    <?php if (!empty($this->error)): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($this->error); ?></div>
                    <?php endif; ?>
                    <?php if ($this->hasErrors()): ?>
                        <div class="alert alert-danger"><?php echo $this->_printErrors(); ?></div>
                    <?php endif; ?>

                    <form method="POST" action="<?php echo sURL; ?>Home/register">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" name="username" id="username" class="form-control" required autocomplete="username"
                                   value="<?php echo htmlspecialchars($this->formData['username'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" name="email" id="email" class="form-control" required autocomplete="email"
                                   value="<?php echo htmlspecialchars($this->formData['email'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" name="password" id="password" class="form-control" required minlength="6" autocomplete="new-password">
                            <div class="form-text">At least 6 characters.</div>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm Password</label>
                            <input type="password" name="confirm_password" id="confirm_password" class="form-control" required autocomplete="new-password">
                        </div>
                        <button type="submit" class="btn btn-success w-100">Create Account</button>
                    </form>
                    <div class="text-center mt-3">
                        <a href="<?php echo sURL; ?>Home/login" class="small">Already have an account? Sign in</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
