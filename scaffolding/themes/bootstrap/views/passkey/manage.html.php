<?php
/**
 * Passkey management page (Bootstrap theme).
 *
 * Rendered by Pramnos\Auth\Controllers\Passkey::display(). Behaviour lives in
 * the shared library (pf-auth.js): the content column is marked
 * [data-pf-passkey-manage] with data-base pointing at the Passkey JSON
 * endpoints. The account sidebar/breadcrumb point at the Account controller
 * via accountBase.
 *
 * Variables:
 *   $this->routeBase — Passkey controller route base (defaults to 'Passkey')
 */
$base = sURL . rawurlencode((string) ($this->routeBase ?? 'Passkey'));
$this->accountBase = 'Account';
$this->activeNav   = 'passkey';
?>
<style>
.pf-pk-item{list-style:none;border-bottom:1px solid #eee;padding:.75rem 1rem;display:flex;justify-content:space-between;align-items:center}
.pf-pk-meta small{display:block;color:#6c757d}
.pf-pk-empty,.pf-pk-error{list-style:none;padding:.75rem 1rem;color:#6c757d}
.pf-pk-error{color:#dc3545}
.pf-pk-actions .pf-pk-rename{margin-left:.5rem}
.pf-pk-actions button{margin-left:.5rem}
[data-pf-passkey-message]{padding:.75rem 1rem;border-radius:.375rem}
[data-pf-passkey-message][data-ok="1"]{background:#d1e7dd;color:#0f5132}
[data-pf-passkey-message][data-ok="0"]{background:#f8d7da;color:#842029}
</style>
<div class="container py-4">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2 class="mb-4">Passkeys</h2>

    <div class="row g-4">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="col-lg-9 col-md-8" data-pf-passkey-manage data-base="<?php echo $base; ?>">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <p class="text-muted small mb-0">Passkeys let you sign in without a password, using your device's fingerprint, face or screen lock.</p>
                <button type="button" data-pf-passkey-add class="btn btn-primary">Add a passkey</button>
            </div>

            <p data-pf-passkey-message class="d-none"></p>

            <div class="card">
                <ul data-pf-passkey-list class="list-group list-group-flush" style="margin:0;padding:0">
                    <li class="list-group-item text-muted">Loading&hellip;</li>
                </ul>
            </div>
        </div>
    </div>

</div>

<script src="<?php echo sURL; ?>assets/js/pf-webauthn.js"></script>
<script src="<?php echo sURL; ?>assets/js/pf-auth.js"></script>
