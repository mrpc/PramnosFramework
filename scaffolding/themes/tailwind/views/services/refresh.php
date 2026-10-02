<?php
/**
 * Keeps the services screen's numbers current without reloading it.
 *
 * Included by services.html.php. Every ten seconds, while the tab is visible, it reads
 * Services/status and updates the cells marked with data-field: a pool's workers, backlog,
 * load and decision, a batch's counts, the supervisor's last cycle. Rows are not rebuilt,
 * so an open edit form keeps what was typed in it; a pool or batch the page does not have
 * yet is announced with a link to reload instead. Theme-agnostic, the same in every theme.
 */
?>
<script>
(function () {
    var screen = document.getElementById('services-screen');
    if (!screen || !window.fetch) {
        return;
    }
    var url = screen.getAttribute('data-status-url');

    function set(scope, field, value) {
        var cell = scope.querySelector('[data-field="' + field + '"]');
        if (cell && cell.textContent !== String(value)) {
            cell.textContent = String(value);
        }
    }

    function announce() {
        if (document.getElementById('services-stale')) {
            return;
        }
        var note = document.createElement('p');
        note.id = 'services-stale';
        note.setAttribute('role', 'status');
        note.innerHTML = 'Something new is running. <a href="">Reload</a> to see it.';
        screen.insertBefore(note, screen.children[1] || null);
    }

    function refresh() {
        if (document.hidden) {
            return;
        }
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (status) {
                if (!status) {
                    return;
                }
                (status.pools || []).forEach(function (pool) {
                    var row = screen.querySelector('[data-pool="' + CSS.escape(pool.name) + '"]');
                    if (!row) {
                        announce();
                        return;
                    }
                    set(row, 'size', pool.size === null ? '—' : pool.size);
                    set(row, 'backlog', pool.backlog === null ? '—' : pool.backlog);
                    set(row, 'load', pool.load === null ? '—' : Number(pool.load).toFixed(2));
                    set(row, 'decision', pool.decision || '');
                    var waiting = row.querySelector('[data-field="waiting"]');
                    if (waiting && !pool.waiting) {
                        waiting.remove();
                    }
                });
                (status.batches || []).forEach(function (batch) {
                    var row = screen.querySelector('[data-batch="' + CSS.escape(batch.id) + '"]');
                    if (!row) {
                        announce();
                        return;
                    }
                    set(row, 'pending', batch.pending);
                    set(row, 'processing', batch.processing);
                    set(row, 'done', batch.completed + batch.warning);
                    set(row, 'failed', batch.failed);
                    set(row, 'finished', batch.finished ? 'done' : 'running');
                });
                var supervisor = status.orchestrator || {};
                if (supervisor.running && supervisor.heartbeat_age_seconds !== null) {
                    set(screen, 'supervisor', 'Supervisor running, pid ' + supervisor.pid
                        + ', last cycle ' + supervisor.heartbeat_age_seconds + 's ago.');
                }
            })
            .catch(function () { /* the next tick tries again */ });
    }

    setInterval(refresh, 10000);
})();
</script>
