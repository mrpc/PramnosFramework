<?php
/**
 * Passkey management page (Bootstrap theme).
 *
 * Rendered by Pramnos\Auth\Controllers\Passkey::display(). Lists the user's
 * passkeys and lets them add / rename / revoke via pf-webauthn.js + the JSON
 * endpoints under <base>.
 */
$base = sURL . rawurlencode((string) ($this->routeBase ?? 'Passkey'));
?>
<div class="container py-4" style="max-width:720px">
    <p><a href="<?php echo sURL; ?>account/security">&larr; Back to Security</a></p>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">Passkeys</h1>
        <button type="button" id="pk-add" class="btn btn-primary">Add a passkey</button>
    </div>
    <p class="text-muted small">Passkeys let you sign in without a password, using your device's fingerprint, face or screen lock.</p>

    <div id="pk-message" class="alert d-none"></div>

    <div class="card">
        <ul id="pk-list" class="list-group list-group-flush">
            <li class="list-group-item text-muted">Loading&hellip;</li>
        </ul>
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
        msgEl.className = 'alert ' + (ok ? 'alert-success' : 'alert-danger');
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
            listEl.innerHTML = '<li class="list-group-item text-muted">No passkeys yet. Add one to sign in without a password.</li>';
            return;
        }
        listEl.innerHTML = '';
        items.forEach(function (pk) {
            var li = document.createElement('li');
            li.className = 'list-group-item d-flex justify-content-between align-items-center';
            var used = pk.last_used_at ? ('Last used ' + esc(pk.last_used_at)) : 'Never used';
            li.innerHTML = '<div><strong>' + esc(pk.name || 'Passkey') + '</strong>'
                + '<small class="d-block text-muted">Added ' + esc(pk.created_at) + ' &middot; ' + used + '</small></div>';
            var actions = document.createElement('div');
            var rename = document.createElement('button');
            rename.type = 'button'; rename.className = 'btn btn-sm btn-outline-secondary'; rename.textContent = 'Rename';
            rename.onclick = function () {
                var name = prompt('New name for this passkey:', pk.name || '');
                if (name === null || name.trim() === '') { return; }
                post('rename', { id: pk.id, name: name.trim() }).then(load);
            };
            var revoke = document.createElement('button');
            revoke.type = 'button'; revoke.className = 'btn btn-sm btn-outline-danger ms-2'; revoke.textContent = 'Remove';
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
            .catch(function () { listEl.innerHTML = '<li class="list-group-item text-danger">Could not load passkeys.</li>'; });
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
