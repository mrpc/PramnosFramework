<?php
/**
 * Passkey management page (Tailwind theme).
 *
 * Rendered by Pramnos\Auth\Controllers\Passkey::display(). Lists the user's
 * passkeys and lets them add / rename / revoke via pf-webauthn.js + the JSON
 * endpoints under <base>.
 */
$base = sURL . rawurlencode((string) ($this->routeBase ?? 'Passkey'));
?>
<div class="max-w-2xl mx-auto py-6 px-4">
    <p class="mb-3"><a href="<?php echo sURL; ?>account/security" class="text-blue-600 hover:underline">&larr; Back to Security</a></p>
    <div class="flex justify-between items-center mb-3">
        <h1 class="text-2xl font-semibold">Passkeys</h1>
        <button type="button" id="pk-add" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md">Add a passkey</button>
    </div>
    <p class="text-sm text-gray-500 mb-4">Passkeys let you sign in without a password, using your device's fingerprint, face or screen lock.</p>

    <div id="pk-message" class="hidden rounded-md p-3 mb-4 text-sm"></div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <ul id="pk-list" class="divide-y divide-gray-100">
            <li class="p-4 text-gray-500">Loading&hellip;</li>
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
        msgEl.className = 'rounded-md p-3 mb-4 text-sm ' + (ok ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800');
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
            listEl.innerHTML = '<li class="p-4 text-gray-500">No passkeys yet. Add one to sign in without a password.</li>';
            return;
        }
        listEl.innerHTML = '';
        items.forEach(function (pk) {
            var li = document.createElement('li');
            li.className = 'p-4 flex justify-between items-center';
            var used = pk.last_used_at ? ('Last used ' + esc(pk.last_used_at)) : 'Never used';
            li.innerHTML = '<div><strong>' + esc(pk.name || 'Passkey') + '</strong>'
                + '<small class="block text-gray-500">Added ' + esc(pk.created_at) + ' &middot; ' + used + '</small></div>';
            var actions = document.createElement('div');
            var rename = document.createElement('button');
            rename.type = 'button'; rename.className = 'text-sm text-blue-600 hover:underline'; rename.textContent = 'Rename';
            rename.onclick = function () {
                var name = prompt('New name for this passkey:', pk.name || '');
                if (name === null || name.trim() === '') { return; }
                post('rename', { id: pk.id, name: name.trim() }).then(load);
            };
            var revoke = document.createElement('button');
            revoke.type = 'button'; revoke.className = 'text-sm text-red-600 hover:underline ml-4'; revoke.textContent = 'Remove';
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
            .catch(function () { listEl.innerHTML = '<li class="p-4 text-red-600">Could not load passkeys.</li>'; });
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
