<?php
/**
 * One organization, read-only (plain-CSS theme).
 *
 * Variables:
 *   $this->org         — organization row array
 *   $this->members     — the most recent active members (up to ten)
 *   $this->memberCount — how many active members there are in total
 *
 * The list used to link straight to `edit`, so looking at a record meant opening the
 * form that changes it. Every field here is escaped: an organization's name and
 * description are operator-written text.
 */
$org   = is_array($this->org ?? null) ? $this->org : [];
$orgId = (int) ($org['organization_id'] ?? 0);
$e     = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$when  = static function ($value) use ($e): string {
    if ($value === null || $value === '' || $value === 0 || $value === '0') {
        return '—';
    }
    $time = is_numeric($value) ? (int) $value : strtotime((string) $value);

    return $time > 0 ? $e(localDateTime( $time)) : $e($value);
};
$members = [];
if (is_iterable($this->members ?? null)) {
    foreach ($this->members as $member) {
        $members[] = (array) $member;
    }
}
$skip = ['organization_id', 'name', 'org_type', 'description', 'parent_id',
         'is_active', 'created_at', 'updated_at'];
?>
<div class="page-section">
    <?php $this->activeNav = 'organizations_view'; $this->insert('../partials/admin_breadcrumb'); ?>

    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-bottom:14px">
        <a href="<?php echo adminUrl('Organizations'); ?>" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
        <h2 style="margin:0"><?php echo $e($org['name'] ?? 'Organization'); ?></h2>
        <span style="font-size:12px;padding:2px 8px;border-radius:10px;background:<?php echo (int) ($org['is_active'] ?? 0) === 1 ? '#e6f4ea;color:#137333' : '#eee;color:#666'; ?>">
            <?php echo (int) ($org['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive'; ?>
        </span>
        <span style="margin-left:auto;display:flex;gap:8px">
            <a href="<?php echo adminUrl('Organizations/edit/') . $orgId; ?>" class="btn btn-sm">Edit</a>
            <a href="<?php echo adminUrl('Organizations/members/') . $orgId; ?>" class="btn btn-sm btn-outline-secondary">
                Members<?php echo $this->memberCount > 0 ? ' (' . (int) $this->memberCount . ')' : ''; ?>
            </a>
            <a href="<?php echo adminUrl('Organizations/delete/') . $orgId; ?>" class="btn btn-sm"
               data-confirm="Delete this organization?">Delete</a>
        </span>
    </div>

    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-header" style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">Details</div>
        <table style="width:100%;border-collapse:collapse">
            <tbody>
                <tr><th style="text-align:left;padding:6px 16px;width:180px">ID</th><td style="padding:6px 16px"><?php echo $orgId; ?></td></tr>
                <tr><th style="text-align:left;padding:6px 16px">Name</th><td style="padding:6px 16px"><?php echo $e($org['name'] ?? ''); ?></td></tr>
                <tr><th style="text-align:left;padding:6px 16px">Type</th><td style="padding:6px 16px"><?php echo $e($org['org_type'] ?? '') !== '' ? $e($org['org_type']) : '—'; ?></td></tr>
                <?php if (array_key_exists('description', $org)): ?>
                <tr><th style="text-align:left;padding:6px 16px">Description</th><td style="padding:6px 16px"><?php echo $e($org['description'] ?? '') !== '' ? nl2br($e($org['description'])) : '—'; ?></td></tr>
                <?php endif; ?>
                <tr><th style="text-align:left;padding:6px 16px">Created</th><td style="padding:6px 16px"><?php echo $when($org['created_at'] ?? null); ?></td></tr>
                <?php foreach ($org as $column => $value): ?>
                    <?php if (in_array($column, $skip, true) || is_array($value) || is_object($value)) { continue; } ?>
                <tr><th style="text-align:left;padding:6px 16px"><?php echo $e(ucwords(str_replace('_', ' ', (string) $column))); ?></th><td style="padding:6px 16px"><?php echo $e($value); ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card" style="border:1px solid #ddd;border-radius:4px">
        <div class="card-header" style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">
            Members (<?php echo (int) $this->memberCount; ?>)
        </div>
        <?php if ($members === []): ?>
        <div style="padding:16px;color:#666">Nobody has been added to this organization yet.</div>
        <?php else: ?>
        <ul style="margin:0;padding:8px 16px 16px 32px">
            <?php foreach ($members as $member): ?>
            <li style="padding:2px 0">
                <a href="<?php echo adminUrl('Users/view/') . (int) ($member['userid'] ?? 0); ?>"><?php echo $e($member['username'] ?? ''); ?></a>
                <span style="color:#666;font-size:12px"><?php echo $e($member['email'] ?? ''); ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($this->memberCount > count($members)): ?>
        <div style="padding:0 16px 12px">
            <a href="<?php echo adminUrl('Organizations/members/') . $orgId; ?>">
                …and <?php echo (int) $this->memberCount - count($members); ?> more
            </a>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
