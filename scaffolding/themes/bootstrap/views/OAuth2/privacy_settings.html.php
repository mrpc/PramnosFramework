<?php
/**
 * Privacy page (Bootstrap theme). GDPR Article 7 — conditions for consent.
 *
 * Variables:
 *   $this->privacySettings — array {analytics: bool, marketing: bool, notifysignin: bool}
 *   $this->routeBase       — Account controller route base
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'privacy';
?>
<div class="container py-4">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2 class="mb-4">Privacy</h2>

    <?php if ($this->hasMessages()): ?>
        <div role="status" class="alert alert-success"><?php echo $this->_printMessages(); ?></div>
    <?php endif; ?>
    <?php if ($this->hasErrors()): ?>
        <div role="alert" class="alert alert-danger"><?php echo $this->_printErrors(); ?></div>
    <?php endif; ?>

    <div class="row g-4">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="col-lg-9 col-md-8">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted mb-4">
                        Control how your data is used. You can update these preferences at any time.
                        Changes take effect immediately.
                    </p>

                    <form method="post" action="<?php echo sURL . $routeBase; ?>/privacy">
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox"
                                       id="analytics" name="analytics" role="switch"
                                       <?php echo !empty($this->privacySettings['analytics']) ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold" for="analytics">
                                    Analytics &amp; Usage Data
                                </label>
                            </div>
                            <div class="form-text ms-4">
                                Allow collection of anonymous usage data to help us improve the service.
                                No personal information is shared with third parties.
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox"
                                       id="marketing" name="marketing" role="switch"
                                       <?php echo !empty($this->privacySettings['marketing']) ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold" for="marketing">
                                    Marketing Communications
                                </label>
                            </div>
                            <div class="form-text ms-4">
                                Receive occasional emails about new features, tips, and offers.
                                You can unsubscribe at any time.
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox"
                                       id="notifysignin" name="notifysignin" role="switch"
                                       <?php echo !empty($this->privacySettings['notifysignin']) ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold" for="notifysignin">
                                    New sign-in alerts
                                </label>
                            </div>
                            <div class="form-text ms-4">
                                Email me when my account is signed in to from a browser or device I have not used before. You will not be emailed when your browser updates, or when your network address changes.
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">Save Preferences</button>
                        <?php
                        /*
                         * Browser notifications, outside the form on purpose: this is not a
                         * preference the server can store. Permission and the subscription live
                         * in the browser, and a checkbox here would tick and do nothing.
                         */
                        ?>
                        <hr class="my-4">
                        <h5>Browser notifications</h5>
                        <p class="text-muted small">
                            Get told on this device when something happens on your account, even
                            when this site is not open. This browser only.
                        </p>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <button type="button" class="btn btn-outline-primary btn-sm" data-push-subscribe hidden>
                                Turn on notifications
                            </button>
                            <span class="text-muted small" data-push-state></span>
                        </div>
                        <script src="<?php echo sURL; ?>assets/js/push.js" defer></script>
                    </form>
                </div>
            </div>

            <div class="mt-4">
                <h5>Your Data Rights</h5>
                <p class="text-muted small">
                    Under GDPR you have the right to access, rectify, and erase your personal data.
                    <a href="<?php echo sURL . $routeBase; ?>/exportdata">Download a copy of your data</a> or
                    <a href="<?php echo sURL . $routeBase; ?>/deleteaccount" class="text-danger">delete your account</a>.
                </p>
            </div>
        </div>
    </div>

</div>
