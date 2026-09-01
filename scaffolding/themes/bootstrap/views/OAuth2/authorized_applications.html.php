<?php
/**
 * Authorized Applications page (Bootstrap theme).
 *
 * Variables:
 *   $this->authorizedApps — array[] {appid, name, apikey, description, last_used, token_count}
 *   $this->routeBase      — Account controller route base
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'applications';
?>
<div class="container py-4">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2 class="mb-4">Authorized Applications</h2>

    <?php if ($this->hasMessages()): ?>
        <div role="status" class="alert alert-success"><?php echo $this->_printMessages(); ?></div>
    <?php endif; ?>
    <?php if ($this->hasErrors()): ?>
        <div role="alert" class="alert alert-danger"><?php echo $this->_printErrors(); ?></div>
    <?php endif; ?>

    <div class="row g-4">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="col-lg-9 col-md-8">
            <?php if (empty($this->authorizedApps)): ?>
                <div role="status" class="alert alert-info">You have no authorized applications.</div>
            <?php else: ?>
                <div class="card">
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush">
                            <?php foreach ($this->authorizedApps as $app): ?>
                                <li class="list-group-item">
                                    <div class="row align-items-center">
                                        <div class="col">
                                            <strong><?php echo htmlspecialchars($app['name']); ?></strong>
                                            <?php if (!empty($app['description'])): ?>
                                                <p class="mb-0 text-muted small"><?php echo htmlspecialchars($app['description']); ?></p>
                                            <?php endif; ?>
                                            <small class="text-muted">
                                                <?php echo (int) $app['token_count']; ?> active token<?php echo $app['token_count'] != 1 ? 's' : ''; ?>
                                                <?php if (!empty($app['last_used'])): ?>
                                                    &nbsp;·&nbsp; Last used: <?php echo htmlspecialchars(date('d M Y', (int) $app['last_used'])); ?>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        <div class="col-auto">
                                            <form method="post" action="<?php echo sURL . $routeBase; ?>/revokeapplication"
                                                  onsubmit="return confirm('Revoke access for <?php echo htmlspecialchars(addslashes($app['name'])); ?>?')">
                                                <input type="hidden" name="client_id" value="<?php echo htmlspecialchars($app['apikey']); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Revoke</button>
                                            </form>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>
