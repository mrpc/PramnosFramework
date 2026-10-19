<?php
/**
 * Account Dashboard overview (plain-CSS theme).
 *
 * Variables:
 *   $this->user            — User object
 *   $this->authorizedApps  — array[] {appid, name, apikey, description, last_used, token_count}
 *   $this->recentActivity  — array[] {action, created_at, ip_address, user_agent}
 *   $this->twoFactorEnabled — bool
 *   $this->routeBase       — Account controller route base
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'dashboard';
?>
<div class="page-section">

    <?php $this->insert('../partials/account_breadcrumb'); ?>

    <div style="display:flex;flex-wrap:wrap;gap:8px;justify-content:space-between;align-items:center;margin-bottom:16px">
        <div>
            <h2 style="margin:0"><?php echo htmlspecialchars(t('Account Dashboard'), ENT_QUOTES, 'UTF-8'); ?></h2>
            <small style="color:#666">
                <?php echo htmlspecialchars(t('Welcome back, %s', (string) ($this->user->firstname ?? $this->user->username ?? '')), ENT_QUOTES, 'UTF-8'); ?>
            </small>
        </div>
        <?php if ($this->twoFactorEnabled): ?>
            <span class="badge badge-success">&#10003; <?php echo htmlspecialchars(t('2FA Active'), ENT_QUOTES, 'UTF-8'); ?></span>
        <?php else: ?>
            <span class="badge badge-warning">&#9888; <?php echo htmlspecialchars(t('2FA Inactive'), ENT_QUOTES, 'UTF-8'); ?></span>
        <?php endif; ?>
    </div>

    <div class="account-grid">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <!-- Main column -->
        <div>
            <!-- Authorized apps -->
            <div class="card" style="margin-bottom:20px">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
                    <strong><?php echo htmlspecialchars(t('Authorized Applications'), ENT_QUOTES, 'UTF-8'); ?></strong>
                    <a href="<?php echo sURL . $routeBase; ?>/applications" class="btn btn-sm"><?php echo htmlspecialchars(t('Manage'), ENT_QUOTES, 'UTF-8'); ?></a>
                </div>
                <div class="card-body" style="padding:0">
                    <?php if (empty($this->authorizedApps)): ?>
                        <p style="padding:12px 16px;color:#666;margin:0"><?php echo htmlspecialchars(t('No authorized applications.'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php else: ?>
                        <ul style="list-style:none;margin:0;padding:0">
                            <?php foreach (array_slice($this->authorizedApps, 0, 3) as $app): ?>
                                <li style="border-bottom:1px solid #f0f0f0;padding:10px 16px;display:flex;justify-content:space-between;align-items:center">
                                    <div>
                                        <strong><?php echo htmlspecialchars($app['name']); ?></strong>
                                        <?php if (!empty($app['description'])): ?>
                                            <small style="display:block;color:#888">
                                                <?php echo htmlspecialchars($app['description']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                    <small style="color:#888">
                                        <?php echo htmlspecialchars(t((int) $app['token_count'] === 1 ? '%d token' : '%d tokens', (int) $app['token_count']), ENT_QUOTES, 'UTF-8'); ?>
                                    </small>
                                </li>
                            <?php endforeach; ?>
                            <?php if (count($this->authorizedApps) > 3): ?>
                                <li style="padding:10px 16px;text-align:center">
                                    <a href="<?php echo sURL . $routeBase; ?>/applications">
                                        <?php echo htmlspecialchars(t('+ %d more', count($this->authorizedApps) - 3), ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent activity -->
            <div class="card">
                <div class="card-header"><strong><?php echo htmlspecialchars(t('Recent Activity'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="card-body" style="padding:0">
                    <?php if (empty($this->recentActivity)): ?>
                        <p style="padding:12px 16px;color:#666;margin:0"><?php echo htmlspecialchars(t('No recent activity.'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php else: ?>
                        <ul style="list-style:none;margin:0;padding:0">
                            <?php foreach ($this->recentActivity as $entry): ?>
                                <li style="border-bottom:1px solid #f0f0f0;padding:10px 16px">
                                    <div style="display:flex;justify-content:space-between">
                                        <span><?php echo htmlspecialchars($entry['action']); ?></span>
                                        <small style="color:#888"><?php echo htmlspecialchars($entry['created_at']); ?></small>
                                    </div>
                                    <?php if (!empty($entry['ip_address'])): ?>
                                        <small style="color:#aaa">
                                            <?php echo htmlspecialchars(t('from %s', (string) $entry['ip_address']), ENT_QUOTES, 'UTF-8'); ?>
                                        </small>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div>
