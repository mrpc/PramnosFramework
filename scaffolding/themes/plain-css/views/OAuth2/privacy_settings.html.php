<?php
/**
 * Privacy Settings page (plain-CSS theme).
 *
 * Variables:
 *   $this->privacySettings — array {analytics: bool, marketing: bool}
 */
?>
<div class="page-section" style="max-width:580px;margin:0 auto">

    <p><a href="<?php echo sURL . ($this->routeBase ?? 'Dashboard'); ?>">← Back to Dashboard</a></p>
    <h2>Privacy Settings</h2>

    <?php if (!empty($_GET['message']) && $_GET['message'] === 'saved'): ?>
        <div class="alert alert-success">Your privacy settings have been saved.</div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <p style="font-size:.9em;color:#666;margin-bottom:16px">
                Control how your data is used. You can update these preferences at any time.
            </p>

            <form method="post" action="<?php echo sURL . ($this->routeBase ?? 'Dashboard'); ?>/privacy">
                <div class="form-group" style="margin-bottom:20px">
                    <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer">
                        <input type="checkbox" name="analytics" id="analytics"
                               style="margin-top:3px"
                               <?php echo !empty($this->privacySettings['analytics']) ? 'checked' : ''; ?>>
                        <div>
                            <strong>Analytics &amp; Usage Data</strong>
                            <p style="font-size:.85em;color:#666;margin:4px 0 0">
                                Allow anonymous usage analytics to help us improve the service.
                            </p>
                        </div>
                    </label>
                </div>

                <div class="form-group" style="margin-bottom:24px">
                    <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer">
                        <input type="checkbox" name="marketing" id="marketing"
                               style="margin-top:3px"
                               <?php echo !empty($this->privacySettings['marketing']) ? 'checked' : ''; ?>>
                        <div>
                            <strong>Marketing Communications</strong>
                            <p style="font-size:.85em;color:#666;margin:4px 0 0">
                                Receive occasional emails about new features and offers.
                            </p>
                        </div>
                    </label>
                </div>

                <button type="submit" class="btn btn-primary">Save Preferences</button>
            </form>
        </div>
    </div>

    <p style="margin-top:16px;font-size:.85em;color:#666">
        Under GDPR you have the right to access, rectify, and erase your data.
        <a href="<?php echo sURL . ($this->routeBase ?? 'Dashboard'); ?>/exportdata">Download your data</a> or
        <a href="<?php echo sURL . ($this->routeBase ?? 'Dashboard'); ?>/deleteaccount" style="color:#c00">delete your account</a>.
    </p>

</div>
