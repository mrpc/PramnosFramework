<?php
/**
 * User profile page (Bootstrap theme).
 *
 * Variables:
 *   $this->title — Page title
 *   $this->user  — User object (username, email, regdate, last_login)
 */
?>
<div class="container py-4">
    <h2 class="mb-4"><?php echo htmlspecialchars($this->title ?? 'My Profile'); ?></h2>
    <div class="card">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Username</dt>
                <dd class="col-sm-9"><?php echo htmlspecialchars($this->user->username ?? ''); ?></dd>

                <dt class="col-sm-3">Email</dt>
                <dd class="col-sm-9"><?php echo htmlspecialchars($this->user->email ?? ''); ?></dd>

                <?php if (!empty($this->user->regdate)): ?>
                <dt class="col-sm-3">Member Since</dt>
                <dd class="col-sm-9"><?php echo htmlspecialchars(date('Y-m-d', is_numeric($this->user->regdate) ? $this->user->regdate : strtotime($this->user->regdate))); ?></dd>
                <?php endif; ?>

                <?php if (!empty($this->user->last_login)): ?>
                <dt class="col-sm-3">Last Login</dt>
                <dd class="col-sm-9"><?php echo htmlspecialchars($this->user->last_login); ?></dd>
                <?php endif; ?>
            </dl>
        </div>
    </div>
    <div class="mt-3">
        <a href="<?php echo sURL; ?>Dashboard" class="btn btn-outline-secondary">&larr; Back to Dashboard</a>
    </div>
</div>
