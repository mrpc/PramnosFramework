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
    <?php $this->insert('../partials/auth_brand'); ?>
    <h2 class="text-2xl font-semibold mb-6"><?php echo htmlspecialchars($this->header ?? t('Single Sign-On'), ENT_QUOTES, 'UTF-8'); ?></h2>

    <?php if ($this->isLoggedIn ?? false): ?>
        <div role="status" class="alert alert-success mb-5 block">
            <strong>&#10003; <?php echo htmlspecialchars(t('Signed In'), ENT_QUOTES, 'UTF-8'); ?></strong> — <?php echo sprintf(
                htmlspecialchars(t('Signed in as %s (%s)'), ENT_QUOTES, 'UTF-8'),
                '<strong>' . htmlspecialchars($this->user->username ?? '', ENT_QUOTES, 'UTF-8') . '</strong>',
                htmlspecialchars($this->user->email ?? '', ENT_QUOTES, 'UTF-8')
            ); ?>
        </div>

        <?php if (!empty($this->activeApps)): ?>
            <h4 class="text-lg font-semibold mb-3"><?php echo htmlspecialchars(t('Active Applications'), ENT_QUOTES, 'UTF-8'); ?></h4>
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
            <a href="<?php echo sURL . rawurlencode((string) ($this->routeBase ?? 'Account')); ?>" class="btn btn-primary"><?php echo htmlspecialchars(t('Your account'), ENT_QUOTES, 'UTF-8'); ?></a>
            <a href="<?php echo sURL; ?>login/logout" class="btn btn-outline btn-error"><?php echo htmlspecialchars(t('Sign Out'), ENT_QUOTES, 'UTF-8'); ?></a>
        </div>
    <?php else: ?>
        <div class="alert mb-5 block">
            <strong>&#10007; <?php echo htmlspecialchars(t('Not Signed In'), ENT_QUOTES, 'UTF-8'); ?></strong> — <?php echo htmlspecialchars(t('You are not currently signed in to any application.'), ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <div class="flex gap-3">
            <a href="<?php echo sURL; ?>login" class="btn btn-primary"><?php echo htmlspecialchars(t('Sign In'), ENT_QUOTES, 'UTF-8'); ?></a>
            <?php if ($this->registrationOpen ?? true): ?>
                <a href="<?php echo sURL; ?>register" class="btn btn-outline"><?php echo htmlspecialchars(t('Create Account'), ENT_QUOTES, 'UTF-8'); ?></a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
