<?php
/**
 * Passkey management page (plain-CSS theme).
 *
 * Rendered by Pramnos\Auth\Controllers\Passkey::display(). Lists the user's
 * passkeys and lets them add / rename / revoke. Behaviour lives in the shared
 * library (pf-auth.js): the content column is marked [data-pf-passkey-manage]
 * with data-base pointing at the Passkey JSON endpoints. The account
 * sidebar/breadcrumb point at the Account controller via accountBase.
 *
 * Variables:
 *   $this->routeBase — Passkey controller route base (defaults to 'Passkey')
 */
$base = sURL . rawurlencode((string) ($this->routeBase ?? 'Passkey'));
$this->accountBase = 'Account';
$this->activeNav   = 'passkey';
?>
<style>
.pf-pk-item{border-bottom:1px solid #f0f0f0;padding:12px 16px;display:flex;justify-content:space-between;align-items:center}
.pf-pk-meta small{display:block;color:#888}
.pf-pk-empty,.pf-pk-error{padding:12px 16px;color:#666}
.pf-pk-error{color:#721c24}
.pf-pk-actions button{margin-left:6px;padding:4px 10px;border:1px solid #ccc;border-radius:4px;background:#fff;cursor:pointer}
[data-pf-passkey-message][data-ok="1"]{background:#d4edda;color:#155724}
[data-pf-passkey-message][data-ok="0"]{background:#f8d7da;color:#721c24}
</style>
<div class="page-section">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2>Passkeys</h2>

    <div class="account-grid">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div data-pf-passkey-manage data-base="<?php echo $base; ?>">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                <p style="color:#666;font-size:.9em;margin:0">
                    Passkeys let you sign in without a password, using your device's fingerprint,
                    face or screen lock.
                </p>
                <button type="button" data-pf-passkey-add class="btn">Add a passkey</button>
            </div>

            <p data-pf-passkey-message style="display:none;padding:10px 12px;border-radius:4px"></p>

            <div class="card">
                <div class="card-body" style="padding:0">
                    <ul data-pf-passkey-list style="list-style:none;margin:0;padding:0">
                        <li style="padding:12px 16px;color:#666">Loading…</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="<?php echo sURL; ?>assets/js/pf-webauthn.js"></script>
<script src="<?php echo sURL; ?>assets/js/pf-auth.js"></script>
