<?php
/**
 * Authorized Applications page (Tailwind theme).
 *
 * Variables:
 *   $this->authorizedApps — array[] {appid, name, apikey, description, last_used, token_count}
 *   $this->routeBase      — Account controller route base
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'applications';
?>
<div class="container mx-auto px-4 py-8">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2 class="text-2xl font-bold text-base-content mb-6">Authorized Applications</h2>

    <?php if ($this->hasMessages()): ?>
        <div class="alert alert-success mb-4"><?php echo $this->_printMessages(); ?></div>
    <?php endif; ?>
    <?php if ($this->hasErrors()): ?>
        <div class="alert alert-error mb-4"><?php echo $this->_printErrors(); ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="md:col-span-3">
            <?php if (empty($this->authorizedApps)): ?>
                <div class="alert alert-info text-center">
                    You have no authorized applications.
                </div>
            <?php else: ?>
                <div class="card bg-base-100 shadow-sm">
                    <?php foreach ($this->authorizedApps as $app): ?>
                        <div class="flex items-center justify-between px-4 py-4">
                            <div>
                                <p class="font-semibold text-base-content"><?php echo htmlspecialchars($app['name']); ?></p>
                                <?php if (!empty($app['description'])): ?>
                                    <p class="text-sm text-base-content/60 mt-0.5"><?php echo htmlspecialchars($app['description']); ?></p>
                                <?php endif; ?>
                                <p class="text-xs text-base-content/60 mt-1">
                                    <?php echo (int) $app['token_count']; ?> active token<?php echo $app['token_count'] != 1 ? 's' : ''; ?>
                                    <?php if (!empty($app['last_used'])): ?>
                                        &middot; Last used <?php echo htmlspecialchars(date('d M Y', (int) $app['last_used'])); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <form method="post" action="<?php echo sURL . $routeBase; ?>/revokeapplication"
                                  onsubmit="return confirm('Revoke access for <?php echo htmlspecialchars(addslashes($app['name'])); ?>?')">
                                <input type="hidden" name="client_id" value="<?php echo htmlspecialchars($app['apikey']); ?>">
                                <button type="submit"
                                        class="btn btn-outline btn-error btn-sm">
                                    Revoke
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>
