<?php
/**
 * Privacy Settings page (Bootstrap theme).
 * GDPR Article 7 — conditions for consent.
 *
 * Variables:
 *   $this->privacySettings — array {analytics: bool, marketing: bool}
 */
?>
<div class="container py-4" style="max-width:600px">

    <p><a href="<?php echo sURL; ?>Dashboard">← Back to Dashboard</a></p>
    <h2>Privacy Settings</h2>

    <?php if (!empty($_GET['message']) && $_GET['message'] === 'saved'): ?>
        <div class="alert alert-success">Your privacy settings have been saved.</div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <p class="text-muted mb-4">
                Control how your data is used. You can update these preferences at any time.
                Changes take effect immediately.
            </p>

            <form method="post" action="<?php echo sURL; ?>Dashboard/privacy">
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

                <button type="submit" class="btn btn-primary">Save Preferences</button>
            </form>
        </div>
    </div>

    <div class="mt-4">
        <h5>Your Data Rights</h5>
        <p class="text-muted small">
            Under GDPR you have the right to access, rectify, and erase your personal data.
            <a href="<?php echo sURL; ?>Dashboard/exportdata">Download a copy of your data</a> or
            <a href="<?php echo sURL; ?>Dashboard/deleteaccount" class="text-danger">delete your account</a>.
        </p>
    </div>

</div>
