<?php
/**
 * Privacy Settings page (plain-CSS theme).
 *
 * Variables:
 *   $this->privacySettings — array {analytics: bool, marketing: bool}
 *   $this->routeBase       — Account controller route base
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'privacy';
?>
<div class="page-section">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2>Privacy</h2>

    <?php if ($this->hasMessages()): ?>
        <div class="alert alert-success"><?php echo $this->_printMessages(); ?></div>
    <?php endif; ?>
    <?php if ($this->hasErrors()): ?>
        <div class="alert alert-error"><?php echo $this->_printErrors(); ?></div>
    <?php endif; ?>

    <div class="account-grid">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div>
            <div class="card">
                <div class="card-body">
                    <p style="font-size:.9em;color:#666;margin-bottom:16px">
                        Control how your data is used. You can update these preferences at any time.
                    </p>

                    <form method="post" action="<?php echo sURL . $routeBase; ?>/privacy">
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
                <a href="<?php echo sURL . $routeBase; ?>/exportdata">Download your data</a> or
                <a href="<?php echo sURL . $routeBase; ?>/deleteaccount" style="color:#c00">delete your account</a>.
            </p>
        </div>
    </div>

</div>
