<?php
/**
 * Privacy page (Tailwind theme).
 *
 * Variables:
 *   $this->privacySettings — array {analytics: bool, marketing: bool, notifysignin: bool}
 *   $this->routeBase       — Account controller route base
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'privacy';
?>
<div class="container mx-auto px-4 py-8">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2 class="text-2xl font-bold text-base-content mb-6">Privacy</h2>

    <?php if ($this->hasMessages()): ?>
        <div role="status" class="alert alert-success mb-4"><?php echo $this->_printMessages(); ?></div>
    <?php endif; ?>
    <?php if ($this->hasErrors()): ?>
        <div role="alert" class="alert alert-error mb-4"><?php echo $this->_printErrors(); ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="md:col-span-3 space-y-6">
            <div class="card bg-base-100 shadow-sm p-6">
                <p class="text-sm text-base-content/70 mb-6">
                    Control how your data is used. You can update these preferences at any time.
                </p>

                <form method="post" action="<?php echo sURL . $routeBase; ?>/privacy">
                    <div class="space-y-5 mb-6">
                        <label class="flex items-start gap-3 cursor-pointer">
                            <div class="mt-0.5">
                                <input type="checkbox" id="analytics" name="analytics"
                                       class="checkbox checkbox-primary checkbox-sm"
                                       <?php echo !empty($this->privacySettings['analytics']) ? 'checked' : ''; ?>>
                            </div>
                            <div>
                                <span class="font-semibold text-base-content">Analytics &amp; Usage Data</span>
                                <p class="text-sm text-base-content/70 mt-0.5">
                                    Allow anonymous usage analytics to help us improve the service.
                                </p>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 cursor-pointer">
                            <div class="mt-0.5">
                                <input type="checkbox" id="marketing" name="marketing"
                                       class="checkbox checkbox-primary checkbox-sm"
                                       <?php echo !empty($this->privacySettings['marketing']) ? 'checked' : ''; ?>>
                            </div>
                            <div>
                                <span class="font-semibold text-base-content">Marketing Communications</span>
                                <p class="text-sm text-base-content/70 mt-0.5">
                                    Receive occasional emails about new features and offers.
                                </p>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 cursor-pointer mt-4">
                            <div class="mt-0.5">
                                <input type="checkbox" id="notifysignin" name="notifysignin"
                                       class="checkbox checkbox-primary checkbox-sm"
                                       <?php echo !empty($this->privacySettings['notifysignin']) ? 'checked' : ''; ?>>
                            </div>
                            <div>
                                <span class="font-semibold text-base-content">New sign-in alerts</span>
                                <p class="text-sm text-base-content/70 mt-0.5">
                                    Email me when my account is signed in to from a browser or device I have not used before. You will not be emailed when your browser updates, or when your network address changes.
                                </p>
                            </div>
                        </label>
                    </div>

                    <button type="submit"
                            class="btn btn-primary">
                        Save Preferences
                    </button>
                </form>
            </div>

            <?php
            /*
             * Browser notifications, outside the form on purpose.
             *
             * The others are a column in a table and a checkbox can express them. This one is
             * not a preference the server can store: `Notification.requestPermission()` and
             * `PushManager.subscribe()` exist only in a page, only mean anything from a click,
             * and the answer lives in the browser rather than in the account. Putting it in the
             * form would offer a checkbox that ticks and does nothing.
             *
             * Hidden until the script says otherwise, so a browser that cannot do this at all
             * shows nothing rather than a control that fails when pressed.
             */
            ?>
            <div class="card bg-base-100 border border-base-300 shadow-xs">
                <div class="p-6">
                    <h3 class="font-semibold text-base-content">Browser notifications</h3>
                    <p class="text-sm text-base-content/70 mt-1">
                        Get told on this device when something happens on your account — a new
                        sign-in, a message — even when this site is not open. It applies to this
                        browser only: turning it on here does not turn it on on your phone.
                    </p>

                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <button type="button" class="btn btn-outline btn-sm" data-push-subscribe hidden>
                            Turn on notifications
                        </button>
                        <span class="text-sm text-base-content/70" data-push-state></span>
                    </div>
                </div>
            </div>
            <script src="<?php echo sURL; ?>assets/js/push.js" defer></script>

            <div class="text-sm text-base-content/70">
                <p>
                    Under GDPR you have the right to access, rectify, and erase your data.
                    <a href="<?php echo sURL . $routeBase; ?>/exportdata" class="text-primary hover:underline">Download your data</a>
                    or <a href="<?php echo sURL . $routeBase; ?>/deleteaccount" class="text-error hover:underline">delete your account</a>.
                </p>
            </div>
        </div>
    </div>

</div>
