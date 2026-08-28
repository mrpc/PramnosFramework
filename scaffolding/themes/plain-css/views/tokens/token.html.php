<?php
/**
 * One token, everything about it (plain-CSS theme).
 *
 * Variables:
 *   $this->token      — Token::getDetails()
 *   $this->stats      — Token::getStatistics()
 *   $this->actions    — one page of Token::getActions()
 *   $this->pagination — ['page', 'limit', 'total', 'pages']
 *
 * The page somebody opens when an integration misbehaves. `Token` could always answer
 * these three questions and nothing ever put them on a screen: whose token this is, which
 * application issued it, when it was last used and from where, how many calls it has made,
 * and what the last of them were.
 *
 * **The token value itself is never printed.** It is a bearer credential: anything that
 * can read this page could then act as that user. The id, the type and the fingerprint are
 * what an operator needs to identify it.
 */
$t     = is_array($this->token ?? null) ? $this->token : [];
$stats = is_array($this->stats ?? null) ? $this->stats : [];
$page  = is_array($this->pagination ?? null) ? $this->pagination : ['page' => 1, 'pages' => 1, 'limit' => 50, 'total' => 0];
$id    = (int) ($t['tokenid'] ?? 0);
$uid   = (int) ($t['userid'] ?? 0);
$e     = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$when  = static function ($value) use ($e): string {
    if ($value === null || $value === '' || $value === 0 || $value === '0') {
        return '—';
    }
    $time = is_numeric($value) ? (int) $value : strtotime((string) $value);

    return $time > 0 ? $e(localDateTime( $time)) : $e($value);
};
$field = static function (string $label, string $value): void {
    echo '<div class="flex gap-3 py-1.5 border-b border-base-200 last:border-0">'
        . '<div class="w-44 shrink-0 text-xs uppercase tracking-wide text-base-content/60 pt-0.5">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<div class="text-sm min-w-0 break-words">' . $value . '</div></div>';
};
$status = (int) ($t['status'] ?? 0);
?>
<div class="page-section">
    <?php $this->activeNav = 'tokens_view'; $this->insert('../partials/admin_breadcrumb'); ?>

    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-bottom:14px">
        <a href="<?php echo adminUrl('Tokens'); ?>" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
        <h2 style="margin:0">Token #<?php echo $id; ?></h2>
        <span class="pf-state <?php echo $status === 1 ? 'pf-state-on' : 'pf-state-off'; ?>">
            <?php echo $status === 1 ? 'Active' : 'Revoked'; ?>
        </span>
        <div style="margin-left:auto;display:flex;gap:8px">
            <?php if ($uid > 0): ?>
            <a href="<?php echo adminUrl('users/view/' . $uid); ?>" class="btn btn-sm btn-outline-secondary">
                <?php echo \Pramnos\Html\Icon::svg('view'); ?> The user
            </a>
            <?php endif; ?>
            <a href="<?php echo adminUrl('TokenActions?token_id=' . $id . '&from=tokens'); ?>"
               class="btn btn-sm btn-outline-secondary">
                <?php echo \Pramnos\Html\Icon::svg('log'); ?> All actions
            </a>
            <?php if ($status === 1): ?>
            <a href="<?php echo adminUrl('Tokens/revoke/' . $id); ?>" class="btn btn-sm"
               data-confirm="Revoke this token? Anything using it stops working immediately.">
                <?php echo \Pramnos\Html\Icon::svg('deactivate'); ?> Revoke
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Three numbers, because they are the ones that answer "is this thing working" -->
    <div style="display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin-bottom:14px">
        <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:14px">
            <div class="text-xs uppercase tracking-wide text-base-content/60">Actions</div>
            <div class="text-2xl font-semibold"><?php echo (int) ($stats['total_actions'] ?? 0); ?></div>
        </div>
        <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:14px">
            <div class="text-xs uppercase tracking-wide text-base-content/60">First seen</div>
            <div class="text-sm mt-1"><?php echo $when($stats['first_action'] ?? null); ?></div>
        </div>
        <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:14px">
            <div class="text-xs uppercase tracking-wide text-base-content/60">Last seen</div>
            <div class="text-sm mt-1"><?php echo $when($stats['last_action'] ?? ($t['lastused'] ?? null)); ?></div>
        </div>
    </div>

    <div style="display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));margin-bottom:14px">
        <div class="card" style="border:1px solid #ddd;border-radius:4px">
            <div style="padding:10px 16px;background:#f5f5f5;border-bottom:1px solid #ddd;font-weight:600;font-size:0.9em">The token</div>
            <div style="padding:10px 16px">
                <?php
                $field('ID', '<span class="font-mono text-xs">' . $id . '</span>');
                $field('Type', $e($t['tokentype'] ?? ''));
                // A fingerprint, never the value: this page must not be a way to obtain a
                // working credential.
                $token = (string) ($t['token'] ?? '');
                $field('Fingerprint', $token !== ''
                    ? '<span class="font-mono text-xs">' . $e(substr(hash('sha256', $token), 0, 16)) . '…</span>'
                    : '<span class="text-base-content/40">—</span>');
                $field('Scope', ($t['scope'] ?? '') !== '' ? '<code class="text-xs">' . $e($t['scope']) . '</code>' : '<span class="text-base-content/40">—</span>');
                $field('Created', $when($t['created'] ?? null));
                $field('Expires', ($t['expires'] ?? 0) ? $when($t['expires']) : 'Never');
                $field('Last used', $when($t['lastused'] ?? null));
                ?>
            </div>
        </div>

        <div class="card" style="border:1px solid #ddd;border-radius:4px">
            <div style="padding:10px 16px;background:#f5f5f5;border-bottom:1px solid #ddd;font-weight:600;font-size:0.9em">Who and what</div>
            <div style="padding:10px 16px">
                <?php
                $field('User', $uid > 0
                    ? '<a class="link link-primary" href="' . adminUrl('users/view/' . $uid) . '">'
                        . $e(($t['username'] ?? '') !== '' ? $t['username'] : ('#' . $uid)) . '</a>'
                    : '<span class="text-base-content/40">—</span>');
                $applicationId = (int) ($t['applicationid'] ?? 0);
                $field('Application', $applicationId > 0
                    ? '<a class="link link-primary" href="' . adminUrl('applications/view/' . $applicationId) . '">#'
                        . $applicationId . '</a>'
                    : '<span class="text-base-content/40">—</span>');
                if (($t['apikey'] ?? '') !== '') {
                    $field('API key', '<code class="text-xs break-all">' . $e($t['apikey']) . '</code>');
                }
                $field('IP address', ($t['ipaddress'] ?? '') !== '' ? '<span class="font-mono text-xs">' . $e($t['ipaddress']) . '</span>' : '<span class="text-base-content/40">—</span>');
                $field('Device', ($t['deviceinfo'] ?? '') !== '' ? '<span class="text-xs break-all">' . $e($t['deviceinfo']) . '</span>' : '<span class="text-base-content/40">—</span>');
                $field('Notes', ($t['notes'] ?? '') !== '' ? $e($t['notes']) : '<span class="text-base-content/40">—</span>');
                ?>
            </div>
        </div>
    </div>

    <div class="card" style="border:1px solid #ddd;border-radius:4px">
        <div style="padding:10px 16px;background:#f5f5f5;border-bottom:1px solid #ddd;display:flex;align-items:center;gap:8px">
            <span class="font-semibold text-sm">Actions</span>
            <span class="badge badge-neutral badge-sm"><?php echo (int) ($page['total'] ?? 0); ?></span>
            <span class="ms-auto text-xs text-base-content/60">
                page <?php echo (int) $page['page']; ?> of <?php echo max(1, (int) $page['pages']); ?>
            </span>
        </div>

        <?php $actions = is_iterable($this->actions ?? null) ? $this->actions : []; ?>
        <?php $rows = []; foreach ($actions as $row) { $rows[] = (array) $row; } ?>

        <?php if ($rows === []): ?>
        <div class="p-5 text-sm text-base-content/60">Nothing has been done with this token.</div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="table table-sm text-sm">
                <thead class="bg-base-100 text-xs uppercase text-base-content/60">
                    <tr><th>When</th><th>Action</th><th>Status</th><th>Time</th><th>IP</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="text-xs whitespace-nowrap"><?php echo $when($row['servertime'] ?? $row['actiondate'] ?? null); ?></td>
                        <td><?php echo $e($row['action'] ?? ''); ?></td>
                        <td>
                            <?php $ok = (int) ($row['return_status'] ?? $row['status'] ?? 0); ?>
                            <span class="pf-state <?php echo $ok >= 200 && $ok < 400 ? 'pf-state-on' : 'pf-state-off'; ?>">
                                <?php echo $ok !== 0 ? (int) $ok : '—'; ?>
                            </span>
                        </td>
                        <td class="text-xs"><?php echo isset($row['execution_time_ms']) ? (int) $row['execution_time_ms'] . ' ms' : '—'; ?></td>
                        <td class="font-mono text-xs"><?php echo $e($row['ipaddress'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ((int) $page['pages'] > 1): ?>
        <div class="px-5 py-3 border-t border-base-300 flex gap-2 items-center text-sm">
            <?php if ((int) $page['page'] > 1): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?php echo adminUrl('Tokens/view/' . $id) . '?page=' . ((int) $page['page'] - 1) . '&limit=' . (int) $page['limit']; ?>">&lsaquo; Previous</a>
            <?php endif; ?>
            <?php if ((int) $page['page'] < (int) $page['pages']): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?php echo adminUrl('Tokens/view/' . $id) . '?page=' . ((int) $page['page'] + 1) . '&limit=' . (int) $page['limit']; ?>">Next &rsaquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
