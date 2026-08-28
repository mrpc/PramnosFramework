<?php
/**
 * One organization, read-only (Tailwind theme).
 *
 * Variables:
 *   $this->org         — organization row array
 *   $this->members     — the most recent active members (up to ten)
 *   $this->memberCount — how many active members there are in total
 *
 * The list used to link straight to `edit`, so looking at a record meant opening
 * the form that changes it. Every field here is escaped: an organization's name and
 * description are operator-written text.
 */
$org   = is_array($this->org ?? null) ? $this->org : [];
$orgId = (int) ($org['organization_id'] ?? 0);
$e     = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$when  = static function ($value) use ($e): string {
    if ($value === null || $value === '' || $value === 0 || $value === '0') {
        return '<span class="text-base-content/40">—</span>';
    }
    // A timestamp or a datetime string, depending on the driver that wrote it.
    $time = is_numeric($value) ? (int) $value : strtotime((string) $value);

    return $time > 0 ? $e(localDateTime( $time)) : $e($value);
};

/** One label/value row, so the panel below is a list of facts rather than markup. */
$field = static function (string $label, string $value): void {
    echo '<div class="flex gap-3 py-2 border-b border-base-200 last:border-0">'
        . '<div class="w-40 shrink-0 text-xs uppercase tracking-wide text-base-content/60 pt-0.5">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<div class="text-sm min-w-0 break-words">' . $value . '</div></div>';
};
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'organizations_view'; $this->insert('../partials/admin_breadcrumb'); ?>

    <div class="flex flex-wrap items-center gap-3 mb-4">
        <a href="<?php echo adminUrl('Organizations'); ?>" class="btn btn-outline btn-xs">&larr; Back</a>
        <h2 class="text-lg font-semibold"><?php echo $e($org['name'] ?? 'Organization'); ?></h2>
        <?php if ((int) ($org['is_active'] ?? 0) === 1): ?>
        <span class="badge badge-success badge-sm">Active</span>
        <?php else: ?>
        <span class="badge badge-ghost badge-sm">Inactive</span>
        <?php endif; ?>

        <div class="ms-auto flex gap-2">
            <a href="<?php echo adminUrl('Organizations/edit/') . $orgId; ?>" class="btn btn-primary btn-sm">Edit</a>
            <a href="<?php echo adminUrl('Organizations/members/') . $orgId; ?>" class="btn btn-outline btn-sm">
                Members<?php echo $this->memberCount > 0 ? ' (' . (int) $this->memberCount . ')' : ''; ?>
            </a>
            <a href="<?php echo adminUrl('Organizations/delete/') . $orgId; ?>"
               class="btn btn-error btn-outline btn-sm"
               data-confirm="Delete this organization?">Delete</a>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2 card bg-base-100 border border-base-300 shadow-xs">
            <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm">Details</div>
            <div class="px-5 py-3">
                <?php
                $field('ID', '<span class="font-mono text-xs">' . $orgId . '</span>');
                $field('Name', $e($org['name'] ?? ''));
                $field('Type', $e($org['org_type'] ?? '') !== '' ? $e($org['org_type']) : '<span class="text-base-content/40">—</span>');
                if (array_key_exists('description', $org)) {
                    $field('Description', $e($org['description'] ?? '') !== ''
                        ? nl2br($e($org['description']))
                        : '<span class="text-base-content/40">—</span>');
                }
                if (array_key_exists('parent_id', $org)) {
                    $parent = (int) ($org['parent_id'] ?? 0);
                    $field('Parent', $parent > 0
                        ? '<a class="link link-primary" href="' . adminUrl('Organizations/view/') . $parent . '">#' . $parent . '</a>'
                        : '<span class="text-base-content/40">—</span>');
                }
                $field('Created', $when($org['created_at'] ?? null));
                if (array_key_exists('updated_at', $org)) {
                    $field('Updated', $when($org['updated_at']));
                }
                // Anything the schema carries that this screen does not name: shown
                // rather than hidden, because a column added by a migration is a
                // column somebody wanted to see.
                foreach ($org as $column => $value) {
                    if (in_array($column, [
                        'organization_id', 'name', 'org_type', 'description', 'parent_id',
                        'is_active', 'created_at', 'updated_at',
                    ], true)) {
                        continue;
                    }
                    if (is_array($value) || is_object($value)) {
                        continue;
                    }
                    $field(ucwords(str_replace('_', ' ', (string) $column)), $e($value));
                }
                ?>
            </div>
        </div>

        <div class="card bg-base-100 border border-base-300 shadow-xs self-start">
            <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm flex items-center gap-2">
                Members
                <span class="badge badge-neutral badge-sm"><?php echo (int) $this->memberCount; ?></span>
            </div>
            <?php
            $members = [];
            if (is_iterable($this->members ?? null)) {
                foreach ($this->members as $member) {
                    $members[] = is_object($member) ? (array) $member : (array) $member;
                }
            }
            ?>
            <?php if ($members === []): ?>
            <div class="p-5 text-sm text-base-content/60">Nobody has been added to this organization yet.</div>
            <?php else: ?>
            <ul class="divide-y divide-base-200">
                <?php foreach ($members as $member): ?>
                <li class="px-5 py-2 text-sm flex items-center gap-2">
                    <a class="link link-primary truncate"
                       href="<?php echo adminUrl('Users/view/') . (int) ($member['userid'] ?? 0); ?>">
                        <?php echo $e($member['username'] ?? ''); ?>
                    </a>
                    <span class="text-xs text-base-content/50 truncate"><?php echo $e($member['email'] ?? ''); ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($this->memberCount > count($members)): ?>
            <div class="px-5 py-2 text-xs">
                <a class="link" href="<?php echo adminUrl('Organizations/members/') . $orgId; ?>">
                    …and <?php echo (int) $this->memberCount - count($members); ?> more
                </a>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
