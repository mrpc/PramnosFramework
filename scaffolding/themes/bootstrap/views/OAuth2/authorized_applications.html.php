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
    <h2 class="mb-4"><?php echo htmlspecialchars(t('Authorized Applications'), ENT_QUOTES, 'UTF-8'); ?></h2>

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
                <div role="status" class="alert alert-info"><?php echo htmlspecialchars(t('You have no authorized applications.'), ENT_QUOTES, 'UTF-8'); ?></div>
            <?php else: ?>
                <div class="card">
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush">
                            <?php foreach ($this->authorizedApps as $app): ?>
                                <li class="list-group-item">
                                    <div class="row g-2 align-items-center">
                                        <div class="col-12 col-sm">
                                            <strong><?php echo htmlspecialchars($app['name']); ?></strong>
                                            <?php if (!empty($app['description'])): ?>
                                                <p class="mb-0 text-muted small"><?php echo htmlspecialchars($app['description']); ?></p>
                                            <?php endif; ?>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($app['token_count'] != 1 ? t('%d active tokens', (int) $app['token_count']) : t('%d active token', (int) $app['token_count']), ENT_QUOTES, 'UTF-8'); ?>
                                                <?php if (!empty($app['last_used'])): ?>
                                                    &nbsp;·&nbsp; <?php echo htmlspecialchars(t('Last used: %s', date('d M Y', (int) $app['last_used'])), ENT_QUOTES, 'UTF-8'); ?>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        <div class="col-auto">
                                            <form method="post" action="<?php echo sURL . $routeBase; ?>/revokeapplication"
                                                  onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(t('Revoke access for %s?', (string) $app['name']), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)">
                                                <input type="hidden" name="client_id" value="<?php echo htmlspecialchars($app['apikey']); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger"><?php echo htmlspecialchars(t('Revoke'), ENT_QUOTES, 'UTF-8'); ?></button>
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
