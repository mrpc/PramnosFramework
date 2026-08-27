<?php
/**
 * Single Sign-On status page (Tailwind theme).
 *
 * Variables:
 *   $this->header      — Page heading
 *   $this->isLoggedIn  — bool
 *   $this->user        — User object (username, email) — set when logged in
 *   $this->activeApps  — array[] {name, website_url} — authorized apps
 */
?>
<div class="container mx-auto py-8 px-4 max-w-lg">
    <h2 class="text-2xl font-semibold mb-6"><?php echo htmlspecialchars($this->header ?? 'Single Sign-On'); ?></h2>

    <?php if ($this->isLoggedIn ?? false): ?>
        <div class="alert alert-success mb-5">
            <strong>&#10003; Signed In</strong> — Signed in as <strong><?php echo htmlspecialchars($this->user->username ?? ''); ?></strong>
            (<?php echo htmlspecialchars($this->user->email ?? ''); ?>)
        </div>

        <?php if (!empty($this->activeApps)): ?>
            <h4 class="text-lg font-semibold mb-3">Active Applications</h4>
            <ul class="space-y-2 mb-5">
                <?php foreach ($this->activeApps as $app): ?>
                    <li class="border border-base-300 rounded-lg p-3">
                        <strong class="text-sm"><?php echo htmlspecialchars($app['name']); ?></strong>
                        <?php if (!empty($app['website_url'])): ?>
                            <br><a href="<?php echo htmlspecialchars($app['website_url']); ?>" class="text-xs text-primary hover:underline" target="_blank"><?php echo htmlspecialchars($app['website_url']); ?></a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <div class="flex gap-3">
            <a href="<?php echo adminUrl('Dashboard'); ?>" class="btn btn-primary">Dashboard</a>
            <a href="<?php echo sURL; ?>login/logout" class="btn btn-outline btn-error">Sign Out</a>
        </div>
    <?php else: ?>
        <div class="alert mb-5">
            <strong>&#10007; Not Signed In</strong> — You are not currently signed in to any application.
        </div>
        <div class="flex gap-3">
            <a href="<?php echo sURL; ?>login" class="btn btn-primary">Sign In</a>
            <a href="<?php echo sURL; ?>register" class="btn btn-outline">Create Account</a>
        </div>
    <?php endif; ?>
</div>
