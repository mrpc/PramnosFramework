<?php
/**
 * Passkey management page (Tailwind theme).
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
.pf-pk-item{list-style:none;padding:1rem;display:flex;justify-content:space-between;align-items:center;border-top:1px solid #f3f4f6}
.pf-pk-meta small{display:block;color:#6b7280}
.pf-pk-empty,.pf-pk-error{list-style:none;padding:1rem;color:#6b7280}
.pf-pk-error{color:#dc2626}
.pf-pk-actions button{font-size:.875rem;margin-left:1rem}
.pf-pk-actions .pf-pk-rename{color:#2563eb}
.pf-pk-actions .pf-pk-revoke{color:#dc2626}
[data-pf-passkey-message]{border-radius:.375rem;padding:.75rem;margin-bottom:1rem;font-size:.875rem}
[data-pf-passkey-message][data-ok="1"]{background:#dcfce7;color:#166534}
[data-pf-passkey-message][data-ok="0"]{background:#fee2e2;color:#991b1b}
</style>
<div class="container mx-auto px-4 py-8">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2 class="text-2xl font-semibold mb-6">Passkeys</h2>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="md:col-span-3" data-pf-passkey-manage data-base="<?php echo $base; ?>">
            <div class="flex justify-between items-center mb-3">
                <p class="text-sm text-base-content/70">Passkeys let you sign in without a password, using your device's fingerprint, face or screen lock.</p>
                <button type="button" data-pf-passkey-add class="btn btn-primary whitespace-nowrap">Add a passkey</button>
            </div>

            <p data-pf-passkey-message class="hidden"></p>

            <div class="card bg-base-100 border border-base-300 shadow-sm">
                <ul data-pf-passkey-list class="divide-y divide-base-300" style="margin:0;padding:0">
                    <li class="p-4 text-base-content/70">Loading&hellip;</li>
                </ul>
            </div>
        </div>
    </div>

</div>

<script src="<?php echo sURL; ?>assets/js/pf-webauthn.js"></script>
<script src="<?php echo sURL; ?>assets/js/pf-auth.js"></script>
