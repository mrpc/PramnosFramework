    <footer class="main-footer">
        <div class="container">
            <div class="footer-content">
                <p>&copy; <?php echo date('Y'); ?> <?php echo \Pramnos\Application\Application::getInstance()->applicationInfo['name']; ?>. All rights reserved.</p>
            </div>
        </div>
    </footer>
    <?php
    /*
     * The soft prompt for browser notifications, on every signed-in page. See the tailwind
     * theme's footer for why it is an invitation rather than the permission dialogue itself.
     */
    $_pushUser = \Pramnos\User\User::getCurrentUser();

    if ($_pushUser && (int) ($_pushUser->userid ?? 0) > 1):
    ?>
    <div style="position:fixed;right:16px;bottom:16px;max-width:360px;background:#fff;border:1px solid #ddd;border-radius:6px;padding:14px;box-shadow:0 2px 10px rgba(0,0,0,.15);z-index:50"
         data-push-invite hidden>
        <strong>Notifications on this device</strong>
        <p style="color:#666;font-size:0.85em;margin:4px 0 10px">
            Hear about your account straight away — even when this site is closed.
        </p>
        <button type="button" class="btn btn-primary" data-push-subscribe>Turn on</button>
        <button type="button" class="btn btn-outline" data-push-later>Not now</button>
        <span style="display:block;color:#666;font-size:0.8em;margin-top:6px" data-push-state></span>
    </div>
    <script src="<?php echo sURL; ?>assets/js/push.js" defer></script>
    <?php endif; ?>
    <script src="<?php echo sURL; ?>assets/js/pf-utils.js"></script>
    <?php $this->document->renderJs(); ?>
