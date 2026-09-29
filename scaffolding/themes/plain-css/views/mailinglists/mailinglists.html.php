<?php
/**
 * Mailing lists and their subscribers (plain CSS theme).
 *
 * Variables:
 *   $this->lists   — every opt-in list
 *   $this->counts  — list => ['pending' => n, 'confirmed' => n, 'unsubscribed' => n]
 *   $this->list    — the list shown
 *   $this->status  — '', or the one state shown
 *   $this->search  — part of an address, or ''
 *   $this->page    — the page
 *   $this->perPage — rows on a page
 *   $this->result  — ['rows' => one page of subscribers, 'total' => every match]
 *
 * Nobody is added here: an opt-in list holds the people who asked, with the proof that they did.
 */
$lists   = is_array($this->lists ?? null) ? $this->lists : [];
$counts  = is_array($this->counts ?? null) ? $this->counts : [];
$list    = (string) ($this->list ?? '');
$status  = (string) ($this->status ?? '');
$search  = (string) ($this->search ?? '');
$page    = max(1, (int) ($this->page ?? 1));
$perPage = max(1, (int) ($this->perPage ?? 50));
$rows    = $this->result['rows'] ?? [];
$total   = (int) ($this->result['total'] ?? 0);
$pages   = max(1, (int) ceil($total / $perPage));
$e       = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$when    = static fn ($t): string => $t ? localDateTime((int) $t) : '—';
$link    = static fn (array $q): string => adminUrl('MailingLists') . '?' . http_build_query(array_filter(
    $q + ['list' => $list, 'status' => $status, 'q' => $search],
    static fn ($v): bool => $v !== '' && $v !== null
));
$states  = ['pending' => ['Waiting to confirm', 'badge badge-info'], 'confirmed' => ['Confirmed', 'badge'], 'unsubscribed' => ['Left', 'badge']];
// The row's place, carried by its forms so the redirect comes back to it.
$here    = '<input type="hidden" name="list" value="' . $e($list) . '"><input type="hidden" name="status" value="' . $e($status) . '">'
         . '<input type="hidden" name="q" value="' . $e($search) . '"><input type="hidden" name="page" value="' . $page . '">';
?>
<div class="container">
    <h2 class="mb-3">Mailing lists</h2>

    <?php if ($lists === []): ?>
        <p class="pf-muted small">No opt-in list is registered. A list is a <code>MailType</code> with <code>optIn: true</code> — see the Email Guide.</p>
    <?php else: ?>
    <div class="row mb-3">
        <?php foreach ($lists as $name): $c = $counts[$name] ?? []; ?>
            <a href="<?php echo $e($link(['list' => $name, 'status' => '', 'q' => ''])); ?>" class="col-md-3 card p-3<?php echo $name === $list ? ' border-primary' : ''; ?>" style="text-decoration:none;color:inherit">
                <div class="mb-0"><?php echo $e($name); ?></div>
                <?php foreach ($states as $state => [$label, $badge]): ?>
                    <div class="pf-muted small"><?php echo $label; ?>: <strong><?php echo (int) ($c[$state] ?? 0); ?></strong></div>
                <?php endforeach; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <form method="get" action="<?php echo adminUrl('MailingLists'); ?>" class="d-flex mb-3" style="gap:8px;flex-wrap:wrap;align-items:center">
        <input type="hidden" name="list" value="<?php echo $e($list); ?>">
        <input type="search" name="q" value="<?php echo $e($search); ?>" placeholder="Search addresses" class="form-control" style="max-width:260px">
        <select name="status" class="form-control" style="max-width:200px">
            <option value="">Every state</option>
            <?php foreach ($states as $state => [$label]): ?>
                <option value="<?php echo $state; ?>" <?php echo $status === $state ? 'selected' : ''; ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline-secondary">Filter</button>
        <a href="<?php echo adminUrl('MailingLists/export') . '?list=' . rawurlencode($list); ?>" class="btn btn-outline-secondary">Export confirmed (CSV)</a>
    </form>

    <table class="table table-hover">
        <thead><tr><th>Address</th><th>State</th><th>Source</th><th>Language</th><th>Asked</th><th>Confirmed</th><th>Left</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): $id = (int) $row['subscriberid']; [$label, $badge] = $states[$row['status']] ?? [$row['status'], 'badge']; ?>
            <tr>
                <td>
                    <?php echo $e($row['email']); ?>
                    <?php if (!empty($row['userid'])): ?>
                        <a href="<?php echo adminUrl('users/view/') . (int) $row['userid']; ?>" class="pf-muted small">account</a>
                    <?php endif; ?>
                </td>
                <td><span class="<?php echo $badge; ?>"><?php echo $label; ?></span></td>
                <td><?php echo $e($row['source']); ?></td>
                <td><?php echo $e($row['language']); ?></td>
                <td><?php echo $when($row['created_at']); ?></td>
                <td><?php echo $when($row['confirmed_at']); ?></td>
                <td><?php echo $when($row['unsubscribed_at']); ?></td>
                <td style="white-space:nowrap">
                    <?php if ($row['status'] === 'pending'): ?>
                        <form method="post" action="<?php echo adminUrl('MailingLists/resend/') . $id; ?>" style="display:inline;margin:0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField() . $here; ?><button type="submit" class="btn btn-sm btn-outline-secondary">Send confirmation again</button></form>
                    <?php endif; ?>
                    <?php if ($row['status'] !== 'unsubscribed'): ?>
                        <form method="post" action="<?php echo adminUrl('MailingLists/unsubscribe/') . $id; ?>" style="display:inline;margin:0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField() . $here; ?><button type="submit" class="btn btn-sm btn-danger" data-confirm="Take <?php echo $e($row['email']); ?> off <?php echo $e($list); ?>?">Unsubscribe</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($rows === []): ?>
            <tr><td colspan="8" class="pf-muted small">Nobody matches.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
        <div class="d-flex mt-4" style="gap:8px;align-items:center">
            <?php if ($page > 1): ?><a href="<?php echo $e($link(['page' => $page - 1])); ?>" class="btn btn-sm btn-outline-secondary">Previous</a><?php endif; ?>
            <span class="pf-muted small">page <?php echo $page; ?> of <?php echo $pages; ?> · <?php echo $total; ?> addresses</span>
            <?php if ($page < $pages): ?><a href="<?php echo $e($link(['page' => $page + 1])); ?>" class="btn btn-sm btn-outline-secondary">Next</a><?php endif; ?>
        </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
