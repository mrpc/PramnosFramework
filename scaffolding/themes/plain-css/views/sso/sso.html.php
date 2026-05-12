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
    <h2 style="margin-bottom:20px"><?php echo htmlspecialchars($this->header ?? 'Single Sign-On'); ?></h2>

    <?php if ($this->isLoggedIn ?? false): ?>
        <div class="alert alert-info" style="margin-bottom:16px">
            <strong>&#10003; Signed In</strong> — Signed in as <strong><?php echo htmlspecialchars($this->user->username ?? ''); ?></strong>
            (<?php echo htmlspecialchars($this->user->email ?? ''); ?>)
        </div>

        <?php if (!empty($this->activeApps)): ?>
            <h4 style="margin-bottom:12px">Active Applications</h4>
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

        <a href="<?php echo sURL; ?>Dashboard" class="btn">Dashboard</a>
        <a href="<?php echo sURL; ?>logout" class="btn" style="margin-left:8px;background:#e74c3c;color:white">Sign Out</a>
    <?php else: ?>
        <div class="alert" style="background:#f5f5f5;border:1px solid #ddd;padding:12px 16px;border-radius:4px;margin-bottom:16px">
            <strong>&#10007; Not Signed In</strong> — You are not currently signed in to any application.
        </div>
        <a href="<?php echo sURL; ?>Home/login" class="btn">Sign In</a>
        <a href="<?php echo sURL; ?>Home/register" class="btn" style="margin-left:8px;background:#f0f0f0;color:#333">Create Account</a>
    <?php endif; ?>
</div>
