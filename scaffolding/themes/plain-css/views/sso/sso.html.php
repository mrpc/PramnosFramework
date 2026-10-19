<?php
/**
 * Single Sign-On status page (plain-CSS theme).
 *
 * Variables:
 *   $this->header      — Page heading
 *   $this->isLoggedIn  — bool
 *   $this->user        — User object (username, email) — set when logged in
 *   $this->activeApps  — array[] {name, website_url} — authorized apps
 */
?>
<div class="page-section">
    <h2 style="margin-bottom:20px"><?php echo htmlspecialchars($this->header ?? t('Single Sign-On'), ENT_QUOTES, 'UTF-8'); ?></h2>

    <?php if ($this->isLoggedIn ?? false): ?>
        <div role="status" class="alert alert-info" style="margin-bottom:16px">
            <strong>&#10003; <?php echo htmlspecialchars(t('Signed In'), ENT_QUOTES, 'UTF-8'); ?></strong> — <?php echo sprintf(
                htmlspecialchars(t('Signed in as %s (%s)'), ENT_QUOTES, 'UTF-8'),
                '<strong>' . htmlspecialchars($this->user->username ?? '', ENT_QUOTES, 'UTF-8') . '</strong>',
                htmlspecialchars($this->user->email ?? '', ENT_QUOTES, 'UTF-8')
            ); ?>
        </div>

        <?php if (!empty($this->activeApps)): ?>
            <h4 style="margin-bottom:12px"><?php echo htmlspecialchars(t('Active Applications'), ENT_QUOTES, 'UTF-8'); ?></h4>
            <ul style="list-style:none;margin:0 0 16px;padding:0">
                <?php foreach ($this->activeApps as $app): ?>
                    <li style="border:1px solid #eee;border-radius:4px;padding:10px 14px;margin-bottom:8px">
                        <strong><?php echo htmlspecialchars($app['name']); ?></strong>
                        <?php if (!empty($app['website_url'])): ?>
                            <br><small><a href="<?php echo htmlspecialchars($app['website_url']); ?>" target="_blank"><?php echo htmlspecialchars($app['website_url']); ?></a></small>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <a href="<?php echo sURL . rawurlencode((string) ($this->routeBase ?? 'Account')); ?>" class="btn"><?php echo htmlspecialchars(t('Your account'), ENT_QUOTES, 'UTF-8'); ?></a>
        <a href="<?php echo sURL; ?>login/logout" class="btn" style="margin-left:8px;background:#e74c3c;color:white"><?php echo htmlspecialchars(t('Sign Out'), ENT_QUOTES, 'UTF-8'); ?></a>
    <?php else: ?>
        <div class="alert" style="background:#f5f5f5;border:1px solid #ddd;padding:12px 16px;border-radius:4px;margin-bottom:16px">
            <strong>&#10007; <?php echo htmlspecialchars(t('Not Signed In'), ENT_QUOTES, 'UTF-8'); ?></strong> — <?php echo htmlspecialchars(t('You are not currently signed in to any application.'), ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <a href="<?php echo sURL; ?>login" class="btn"><?php echo htmlspecialchars(t('Sign In'), ENT_QUOTES, 'UTF-8'); ?></a>
        <?php if ($this->registrationOpen ?? true): ?>
            <a href="<?php echo sURL; ?>register" class="btn" style="margin-left:8px;background:#f0f0f0;color:#333"><?php echo htmlspecialchars(t('Create Account'), ENT_QUOTES, 'UTF-8'); ?></a>
        <?php endif; ?>
    <?php endif; ?>
</div>
