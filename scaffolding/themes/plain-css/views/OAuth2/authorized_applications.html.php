<?php
/**
 * Authorized Applications page (plain-CSS theme).
 *
 * Variables:
 *   $this->authorizedApps — array[] {appid, name, apikey, description, last_used, token_count}
 */
?>
<div class="page-section" style="max-width:680px;margin:0 auto">

    <p><a href="<?php echo sURL; ?>Dashboard">← Back to Dashboard</a></p>
    <h2>Authorized Applications</h2>

    <?php if (!empty($_GET['error'])): ?>
        <div class="alert alert-error">
            <?php echo htmlspecialchars(urldecode($_GET['error'])); ?>
        </div>
    <?php endif; ?>

    <?php if (empty($this->authorizedApps)): ?>
        <div class="alert alert-info">You have no authorized applications.</div>
    <?php else: ?>
        <div class="card">
            <div class="card-body" style="padding:0">
                <ul style="list-style:none;margin:0;padding:0">
                    <?php foreach ($this->authorizedApps as $app): ?>
                        <li style="border-bottom:1px solid #f0f0f0;padding:14px 16px;display:flex;justify-content:space-between;align-items:center">
                            <div>
                                <strong><?php echo htmlspecialchars($app['name']); ?></strong>
                                <?php if (!empty($app['description'])): ?>
                                    <small style="display:block;color:#888">
                                        <?php echo htmlspecialchars($app['description']); ?>
                                    </small>
                                <?php endif; ?>
                                <small style="color:#aaa">
                                    <?php echo (int) $app['token_count']; ?> active token<?php echo $app['token_count'] != 1 ? 's' : ''; ?>
                                    <?php if (!empty($app['last_used'])): ?>
                                        &middot; Last used <?php echo htmlspecialchars(date('d M Y', (int) $app['last_used'])); ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                            <form method="post" action="<?php echo sURL; ?>Dashboard/revokeapplication"
                                  onsubmit="return confirm('Revoke access for <?php echo htmlspecialchars(addslashes($app['name'])); ?>?')">
                                <input type="hidden" name="client_id" value="<?php echo htmlspecialchars($app['apikey']); ?>">
                                <button type="submit" class="btn" style="border-color:#c00;color:#c00">Revoke</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

</div>
