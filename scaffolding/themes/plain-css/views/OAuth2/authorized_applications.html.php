<?php
/**
 * Authorized Applications page (plain-CSS theme).
 *
 * Variables:
 *   $this->authorizedApps — array[] {appid, name, apikey, description, last_used, token_count}
 *   $this->routeBase      — Account controller route base
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'applications';
?>
<div class="page-section">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2><?php echo htmlspecialchars(t('Authorized Applications'), ENT_QUOTES, 'UTF-8'); ?></h2>

    <?php if ($this->hasMessages()): ?>
        <div role="status" class="alert alert-success"><?php echo $this->_printMessages(); ?></div>
    <?php endif; ?>
    <?php if ($this->hasErrors()): ?>
        <div role="alert" class="alert alert-error"><?php echo $this->_printErrors(); ?></div>
    <?php endif; ?>

    <div class="account-grid">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div>
            <?php if (empty($this->authorizedApps)): ?>
                <div role="status" class="alert alert-info"><?php echo htmlspecialchars(t('You have no authorized applications.'), ENT_QUOTES, 'UTF-8'); ?></div>
            <?php else: ?>
                <div class="card">
                    <div class="card-body" style="padding:0">
                        <ul style="list-style:none;margin:0;padding:0">
                            <?php foreach ($this->authorizedApps as $app): ?>
                                <li class="pf-row-split" style="border-bottom:1px solid #f0f0f0;padding:14px 16px">
                                    <div>
                                        <strong><?php echo htmlspecialchars($app['name']); ?></strong>
                                        <?php if (!empty($app['description'])): ?>
                                            <small style="display:block;color:#888">
                                                <?php echo htmlspecialchars($app['description']); ?>
                                            </small>
                                        <?php endif; ?>
                                        <small style="color:#aaa">
                                            <?php echo htmlspecialchars($app['token_count'] != 1 ? t('%d active tokens', (int) $app['token_count']) : t('%d active token', (int) $app['token_count']), ENT_QUOTES, 'UTF-8'); ?>
                                            <?php if (!empty($app['last_used'])): ?>
                                                &middot; <?php echo htmlspecialchars(t('Last used %s', date('d M Y', (int) $app['last_used'])), ENT_QUOTES, 'UTF-8'); ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <form method="post" action="<?php echo sURL . $routeBase; ?>/revokeapplication"
                                          onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(t('Revoke access for %s?', (string) $app['name']), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)">
                                        <input type="hidden" name="client_id" value="<?php echo htmlspecialchars($app['apikey']); ?>">
                                        <button type="submit" class="btn" style="border-color:#c00;color:#c00"><?php echo htmlspecialchars(t('Revoke'), ENT_QUOTES, 'UTF-8'); ?></button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>
