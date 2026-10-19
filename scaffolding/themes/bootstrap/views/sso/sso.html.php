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
            <?php $this->insert('../partials/auth_brand'); ?>
            <h2 class="mb-4"><?php echo htmlspecialchars($this->header ?? t('Single Sign-On')); ?></h2>

            <?php if ($this->isLoggedIn ?? false): ?>
                <div role="status" class="alert alert-success">
                    <strong>&#10003; <?php echo htmlspecialchars(t('Signed In'), ENT_QUOTES, 'UTF-8'); ?></strong> — <?php echo sprintf(htmlspecialchars(t('Signed in as %s'), ENT_QUOTES, 'UTF-8'), '<strong>' . htmlspecialchars($this->user->username ?? '', ENT_QUOTES, 'UTF-8') . '</strong>'); ?>
                    (<?php echo htmlspecialchars($this->user->email ?? ''); ?>)
                </div>

                <?php if (!empty($this->activeApps)): ?>
                    <h5 class="mb-2"><?php echo htmlspecialchars(t('Active Applications'), ENT_QUOTES, 'UTF-8'); ?></h5>
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

                <a href="<?php echo adminUrl('Dashboard'); ?>" class="btn btn-primary me-2"><?php echo htmlspecialchars(t('Dashboard'), ENT_QUOTES, 'UTF-8'); ?></a>
                <a href="<?php echo sURL; ?>login/logout" class="btn btn-outline-danger"><?php echo htmlspecialchars(t('Sign Out'), ENT_QUOTES, 'UTF-8'); ?></a>
            <?php else: ?>
                <div class="alert alert-secondary">
                    <strong>&#10007; <?php echo htmlspecialchars(t('Not Signed In'), ENT_QUOTES, 'UTF-8'); ?></strong> — <?php echo htmlspecialchars(t('You are not currently signed in to any application.'), ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <a href="<?php echo sURL; ?>login" class="btn btn-primary me-2"><?php echo htmlspecialchars(t('Sign In'), ENT_QUOTES, 'UTF-8'); ?></a>
                <a href="<?php echo sURL; ?>register" class="btn btn-outline-secondary"><?php echo htmlspecialchars(t('Create Account'), ENT_QUOTES, 'UTF-8'); ?></a>
            <?php endif; ?>
        </div>
    </div>
</div>
