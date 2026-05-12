<?php
/**
 * Single Sign-On status page (Bootstrap theme).
 *
 * Variables:
 *   $this->header      — Page heading
 *   $this->isLoggedIn  — bool
 *   $this->user        — User object (username, email) — set when logged in
 *   $this->activeApps  — array[] {name, website_url} — authorized apps
 */
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-7 col-lg-5">
            <h2 class="mb-4"><?php echo htmlspecialchars($this->header ?? 'Single Sign-On'); ?></h2>

            <?php if ($this->isLoggedIn ?? false): ?>
                <div class="alert alert-success">
                    <strong>&#10003; Signed In</strong> — Signed in as <strong><?php echo htmlspecialchars($this->user->username ?? ''); ?></strong>
                    (<?php echo htmlspecialchars($this->user->email ?? ''); ?>)
                </div>

                <?php if (!empty($this->activeApps)): ?>
                    <h5 class="mb-2">Active Applications</h5>
                    <ul class="list-group mb-3">
                        <?php foreach ($this->activeApps as $app): ?>
                            <li class="list-group-item">
                                <strong><?php echo htmlspecialchars($app['name']); ?></strong>
                                <?php if (!empty($app['website_url'])): ?>
                                    <br><small><a href="<?php echo htmlspecialchars($app['website_url']); ?>" target="_blank"><?php echo htmlspecialchars($app['website_url']); ?></a></small>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <a href="<?php echo sURL; ?>Dashboard" class="btn btn-primary me-2">Dashboard</a>
                <a href="<?php echo sURL; ?>logout" class="btn btn-outline-danger">Sign Out</a>
            <?php else: ?>
                <div class="alert alert-secondary">
                    <strong>&#10007; Not Signed In</strong> — You are not currently signed in to any application.
                </div>
                <a href="<?php echo sURL; ?>Home/login" class="btn btn-primary me-2">Sign In</a>
                <a href="<?php echo sURL; ?>Home/register" class="btn btn-outline-secondary">Create Account</a>
            <?php endif; ?>
        </div>
    </div>
</div>
