    <footer class="bg-dark text-light py-4 mt-auto">
        <div class="container text-center">
            <p class="mb-0">&copy; <?php echo date('Y'); ?> <?php echo \Pramnos\Application\Application::getInstance()->applicationInfo['name']; ?>. All rights reserved.</p>
        </div>
    </footer>
    <script src="<?php echo sURL; ?>assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <?php
    /*
     * The soft prompt for browser notifications, on every signed-in page. See the tailwind
     * theme's footer for why it is an invitation rather than the permission dialogue itself.
     */
    $_pushUser = \Pramnos\User\User::getCurrentUser();

    if ($_pushUser && (int) ($_pushUser->userid ?? 0) > 1):
    ?>
    <div class="toast show position-fixed bottom-0 end-0 m-3" data-push-invite hidden>
        <div class="toast-body">
            <p class="fw-semibold mb-1">Notifications on this device</p>
            <p class="small text-muted mb-2">
                Hear about your account straight away — even when this site is closed.
            </p>
            <button type="button" class="btn btn-sm btn-primary" data-push-subscribe>Turn on</button>
            <button type="button" class="btn btn-sm btn-link" data-push-later>Not now</button>
            <span class="small text-muted d-block" data-push-state></span>
        </div>
    </div>
    <script src="<?php echo sURL; ?>assets/js/push.js" defer></script>
    <?php endif; ?>
    <script src="<?php echo sURL; ?>assets/js/pf-utils.js"></script>
    <?php $this->document->renderJs(); ?>
