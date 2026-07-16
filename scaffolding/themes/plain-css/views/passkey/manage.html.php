<?php
/**
 * Passkey management page (plain-CSS theme).
 *
 * Rendered by Pramnos\Auth\Controllers\Passkey::display(). Lists the user's
 * passkeys and lets them add / rename / revoke — all client-side via
 * pf-webauthn.js (register ceremony) and the JSON endpoints under <base>.
 *
 * Variables:
 *   $this->routeBase — controller route base (defaults to 'Passkey')
 */
$base = sURL . rawurlencode((string) ($this->routeBase ?? 'Passkey'));
?>
<div class="page-section" style="max-width:700px;margin:0 auto">
    <p><a href="<?php echo sURL; ?>account/security">&larr; Back to Security</a></p>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <h2 style="margin:0">Passkeys</h2>
        <button type="button" id="pk-add" class="btn">Add a passkey</button>
    </div>
    <p style="color:#666;font-size:.9em">
        Passkeys let you sign in without a password, using your device's fingerprint,
        face or screen lock.
    </p>

    <p id="pk-message" style="display:none;padding:10px 12px;border-radius:4px"></p>

    <div class="card">
        <div class="card-body" style="padding:0">
            <ul id="pk-list" style="list-style:none;margin:0;padding:0">
                <li style="padding:12px 16px;color:#666">Loading…</li>
            </ul>
        </div>
    </div>
</div>

<script>
(function () {
    var base = <?php echo json_encode($base); ?>;
    var listEl = document.getElementById('pk-list');
    var msgEl  = document.getElementById('pk-message');
    var addBtn = document.getElementById('pk-add');

    function msg(text, ok) {
        msgEl.textContent = text;
        msgEl.style.display = 'block';
        msgEl.style.background = ok ? '#d4edda' : '#f8d7da';
        msgEl.style.color = ok ? '#155724' : '#721c24';
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function post(action, body) {
        return fetch(base + '/' + action, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body || {})
        });
    }

    function render(items) {
        if (!items.length) {
            listEl.innerHTML = '<li style="padding:12px 16px;color:#666">No passkeys yet. Add one to sign in without a password.</li>';
            return;
        }
        listEl.innerHTML = '';
        items.forEach(function (pk) {
            var li = document.createElement('li');
            li.style.cssText = 'border-bottom:1px solid #f0f0f0;padding:12px 16px;display:flex;justify-content:space-between;align-items:center';
            var used = pk.last_used_at ? ('Last used ' + esc(pk.last_used_at)) : 'Never used';
            li.innerHTML = '<div><strong>' + esc(pk.name || 'Passkey') + '</strong>'
                + '<small style="display:block;color:#888">Added ' + esc(pk.created_at) + ' · ' + used + '</small></div>';
            var actions = document.createElement('div');
            var rename = document.createElement('button');
            rename.type = 'button'; rename.className = 'btn btn-sm'; rename.textContent = 'Rename';
            rename.onclick = function () {
                var name = prompt('New name for this passkey:', pk.name || '');
                if (name === null || name.trim() === '') { return; }
                post('rename', { id: pk.id, name: name.trim() }).then(load);
            };
            var revoke = document.createElement('button');
            revoke.type = 'button'; revoke.className = 'btn btn-sm'; revoke.style.marginLeft = '6px'; revoke.textContent = 'Remove';
            revoke.onclick = function () {
                if (!confirm('Remove this passkey? You will no longer be able to sign in with it.')) { return; }
                post('revoke', { id: pk.id }).then(load);
            };
            actions.appendChild(rename); actions.appendChild(revoke);
            li.appendChild(actions);
            listEl.appendChild(li);
        });
    }

    function load() {
        fetch(base + '/list', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) { render(d.passkeys || []); })
            .catch(function () { listEl.innerHTML = '<li style="padding:12px 16px;color:#721c24">Could not load passkeys.</li>'; });
    }

    if (!window.PramnosWebAuthn || !window.PramnosWebAuthn.supported()) {
        addBtn.style.display = 'none';
        msg('This browser does not support passkeys.', false);
    } else {
        addBtn.addEventListener('click', function () {
            addBtn.disabled = true;
            var label = prompt('Name this passkey (e.g. "My laptop"):', '');
            window.PramnosWebAuthn.register(base + '/registerOptions', base + '/register', { label: label || '' })
                .then(function () { msg('Passkey added.', true); load(); })
                .catch(function () { msg('Could not add passkey. Please try again.', false); })
                .finally(function () { addBtn.disabled = false; });
        });
    }

    load();
})();
</script>
