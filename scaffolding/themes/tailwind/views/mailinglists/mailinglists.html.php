<?php
/**
 * Mailing lists and their subscribers (Tailwind theme).
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
$states  = ['pending' => ['Waiting to confirm', 'badge badge-warning'], 'confirmed' => ['Confirmed', 'badge badge-success'], 'unsubscribed' => ['Left', 'badge badge-ghost']];
// The row's place, carried by its forms so the redirect comes back to it.
$here    = '<input type="hidden" name="list" value="' . $e($list) . '"><input type="hidden" name="status" value="' . $e($status) . '">'
         . '<input type="hidden" name="q" value="' . $e($search) . '"><input type="hidden" name="page" value="' . $page . '">';
?>
<div class="px-4 py-6">
    <h2 class="text-lg font-semibold mb-4">Mailing lists</h2>

    <?php if ($lists === []): ?>
        <p class="text-sm opacity-70">No opt-in list is registered. A list is a <code>MailType</code> with <code>optIn: true</code> — see the Email Guide.</p>
    <?php else: ?>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
        <?php foreach ($lists as $name): $c = $counts[$name] ?? []; ?>
            <a href="<?php echo $e($link(['list' => $name, 'status' => '', 'q' => ''])); ?>" class="card bg-base-100 border border-base-300 p-3<?php echo $name === $list ? ' border-primary' : ''; ?>" style="text-decoration:none;color:inherit">
                <div class="font-semibold mb-1"><?php echo $e($name); ?></div>
                <?php foreach ($states as $state => [$label, $badge]): ?>
                    <div class="text-sm opacity-70"><?php echo $label; ?>: <strong><?php echo (int) ($c[$state] ?? 0); ?></strong></div>
                <?php endforeach; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <form method="get" action="<?php echo adminUrl('MailingLists'); ?>" class="flex flex-wrap gap-2 items-center mb-3">
        <input type="hidden" name="list" value="<?php echo $e($list); ?>">
        <input type="search" name="q" value="<?php echo $e($search); ?>" placeholder="Search addresses" class="input input-sm" style="max-width:260px">
        <select name="status" class="input input-sm" style="max-width:200px">
            <option value="">Every state</option>
            <?php foreach ($states as $state => [$label]): ?>
                <option value="<?php echo $state; ?>" <?php echo $status === $state ? 'selected' : ''; ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        <a href="<?php echo adminUrl('MailingLists/export') . '?list=' . rawurlencode($list); ?>" class="btn btn-outline btn-sm">Export confirmed (CSV)</a>
    </form>

    <table class="table w-full">
        <thead><tr><th>Address</th><th>State</th><th>Source</th><th>Language</th><th>Asked</th><th>Confirmed</th><th>Left</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): $id = (int) $row['subscriberid']; [$label, $badge] = $states[$row['status']] ?? [$row['status'], 'badge badge-ghost']; ?>
            <tr>
                <td>
                    <?php echo $e($row['email']); ?>
                    <?php if (!empty($row['userid'])): ?>
                        <a href="<?php echo adminUrl('users/view/') . (int) $row['userid']; ?>" class="text-sm opacity-70">account</a>
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
                        <form method="post" action="<?php echo adminUrl('MailingLists/resend/') . $id; ?>" style="display:inline;margin:0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField() . $here; ?><button type="submit" class="btn btn-outline btn-xs">Send confirmation again</button></form>
                    <?php endif; ?>
                    <?php if ($row['status'] !== 'unsubscribed'): ?>
                        <form method="post" action="<?php echo adminUrl('MailingLists/unsubscribe/') . $id; ?>" style="display:inline;margin:0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField() . $here; ?><button type="submit" class="btn btn-outline btn-error btn-xs" data-confirm="Take <?php echo $e($row['email']); ?> off <?php echo $e($list); ?>?">Unsubscribe</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($rows === []): ?>
            <tr><td colspan="8" class="text-sm opacity-70">Nobody matches.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
        <div class="flex gap-2 items-center mt-3">
            <?php if ($page > 1): ?><a href="<?php echo $e($link(['page' => $page - 1])); ?>" class="btn btn-outline btn-xs">Previous</a><?php endif; ?>
            <span class="text-sm opacity-70">page <?php echo $page; ?> of <?php echo $pages; ?> · <?php echo $total; ?> addresses</span>
            <?php if ($page < $pages): ?><a href="<?php echo $e($link(['page' => $page + 1])); ?>" class="btn btn-outline btn-xs">Next</a><?php endif; ?>
        </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
