    <footer class="bg-gray-800 text-gray-300 py-8 mt-auto">
        <div class="container mx-auto px-4 max-w-5xl text-center">
            <p class="mb-0">&copy; <?php echo date('Y'); ?> <?php echo \Pramnos\Application\Application::getInstance()->applicationInfo['name']; ?>. All rights reserved.</p>
        </div>
    </footer>
    <?php
    /*
     * The soft prompt for browser notifications, on every signed-in page.
     *
     * The settings screen reaches only the people who go looking — and the people who would
     * most want to know their account was signed in from a new device are not the people
     * browsing their privacy preferences.
     *
     * It is **not** the browser's permission dialogue: that is opened by the button inside it,
     * from a click. A real prompt on page load is denied by most people, and Chrome suppresses
     * it outright for visitors who habitually deny one — so the single chance a site gets is
     * spent before anybody has decided anything.
     *
     * `push.js` decides whether to show it at all: supported browser, permission never asked
     * for, this browser not already subscribed, and no "not now" in the last thirty days.
     */
    $_pushUser = \Pramnos\User\User::getCurrentUser();

    if ($_pushUser && (int) ($_pushUser->userid ?? 0) > 1):
    ?>
    <div class="toast toast-end z-50" data-push-invite hidden>
        <div class="alert alert-info shadow-lg max-w-sm">
            <div>
                <p class="font-semibold">Notifications on this device</p>
                <p class="text-sm opacity-90">
                    Hear about your account straight away — even when this site is closed.
                </p>
                <div class="mt-2 flex gap-2">
                    <button type="button" class="btn btn-sm btn-primary" data-push-subscribe>Turn on</button>
                    <button type="button" class="btn btn-sm btn-ghost" data-push-later>Not now</button>
                </div>
                <span class="text-xs opacity-70" data-push-state></span>
            </div>
        </div>
    </div>
    <script src="<?php echo sURL; ?>assets/js/push.js" defer></script>
    <?php endif; ?>
    <script src="<?php echo sURL; ?>assets/js/pf-utils.js"></script>
    <?php $this->document->renderJs(); ?>
