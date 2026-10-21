<?php
/**
 * One segment (Tailwind theme): how many are in it, who came and went, where it is used, its
 * newest members, and why one person is in or out.
 *
 * Variables:
 *   $this->segment   — Segments::get()
 *   $this->movement  — ['entered', 'left'] over seven days
 *   $this->usedBy    — names of what uses it
 *   $this->members   — the newest members: userid, name, since, url
 *   $this->person    — the account asked about, or 0
 *   $this->why       — Filter::explain() for that account, or null
 *   $this->isMember  — whether that account is in the segment as last worked out
 *   $this->labels    — attribute labels by key
 *   $this->operators — operator words by key
 */
$e         = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$segment   = $this->segment;
$id        = (int) $segment['id'];
$labels    = $this->labels ?? [];
$operators = $this->operators ?? [];
$value     = static fn ($v): string => is_array($v) ? implode(', ', array_map('strval', $v)) : ($v === null ? '—' : (is_bool($v) ? ($v ? t('yes') : t('no')) : (string) $v));
$explain   = static function (array $group) use (&$explain, $e, $labels, $operators, $value): string {
    $html = '<ul class="grid gap-1">';
    foreach ($group['conditions'] as $c) {
        if (isset($c['conditions'])) {
            $html .= '<li class="pl-3 border-l-2 border-base-300"><span class="text-xs uppercase text-base-content/60">'
                . $e($c['match'] === 'any' ? t('any of') : t('all of')) . ' ' . ($c['ok'] ? '✓' : '✗') . '</span>' . $explain($c) . '</li>';
            continue;
        }
        $what  = isset($c['segment']) ? $c['label'] : ($labels[$c['attribute']] ?? $c['attribute']);
        $html .= '<li class="flex flex-wrap gap-2 items-baseline"><span class="' . ($c['ok'] ? 'text-success' : 'text-error') . ' font-semibold" aria-label="'
            . $e($c['ok'] ? t('yes') : t('no')) . '">' . ($c['ok'] ? '✓' : '✗') . '</span> '
            . $e($what) . ' ' . $e($operators[$c['op']] ?? $c['op']) . (array_key_exists('value', $c) ? ' <strong>' . $e($value($c['value'])) . '</strong>' : '')
            . ' <span class="text-base-content/60">' . $e(t('has: %s', $value($c['actual']))) . '</span></li>';
    }

    return $html . '</ul>';
};
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'segments_view'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div class="flex flex-wrap justify-between items-center gap-2 mb-4">
        <h2><?php echo $e($segment['name']); ?></h2>
        <div class="flex flex-wrap gap-2">
            <a href="<?php echo $e(adminUrl('Segments/edit/') . $id); ?>" class="btn btn-sm"><?php echo $e(t('Edit')); ?></a>
            <form method="post" action="<?php echo $e(adminUrl('Segments/recompute/') . $id); ?>">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <button type="submit" class="btn btn-sm"><?php echo $e(t('Work it out now')); ?></button>
            </form>
            <form method="post" action="<?php echo $e(adminUrl('Segments/delete/') . $id); ?>" data-confirm="<?php echo $e(t('Delete this segment and its history?')); ?>">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <button type="submit" class="btn btn-sm btn-error btn-outline" <?php echo $this->usedBy === [] ? '' : 'disabled title="' . $e(t('In use')) . '"'; ?>><?php echo $e(t('Delete')); ?></button>
            </form>
        </div>
    </div>
    <?php if ($segment['description'] !== ''): ?><p class="mb-4 text-base-content/70"><?php echo $e($segment['description']); ?></p><?php endif; ?>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-4">
        <div class="stat bg-base-100 border border-base-300 rounded-box p-3"><div class="stat-title"><?php echo $e(t('Now')); ?></div><div class="stat-value text-2xl tabular-nums" data-field="members"><?php echo $e(number_format($segment['members'])); ?></div></div>
        <div class="stat bg-base-100 border border-base-300 rounded-box p-3"><div class="stat-title"><?php echo $e(t('Entered, 7 days')); ?></div><div class="stat-value text-2xl tabular-nums">+<?php echo $e(number_format($this->movement['entered'])); ?></div></div>
        <div class="stat bg-base-100 border border-base-300 rounded-box p-3"><div class="stat-title"><?php echo $e(t('Left, 7 days')); ?></div><div class="stat-value text-2xl tabular-nums">−<?php echo $e(number_format($this->movement['left'])); ?></div></div>
        <div class="stat bg-base-100 border border-base-300 rounded-box p-3"><div class="stat-title"><?php echo $e(t('Worked out')); ?></div><div class="stat-value text-base"><?php echo $e($segment['recomputedAt'] === null ? t('not yet') : date('Y-m-d H:i', $segment['recomputedAt'])); ?></div></div>
    </div>

    <div class="grid lg:grid-cols-2 gap-4">
        <div class="card bg-base-100 border border-base-300 shadow-xs"><div class="p-5">
            <h3 class="font-semibold mb-2"><?php echo $e(t('Why is this person in or out?')); ?></h3>
            <form method="get" action="<?php echo $e(adminUrl('Segments/view/') . $id); ?>" class="flex flex-wrap gap-2 mb-3">
                <label class="sr-only" for="segment-person"><?php echo $e(t('Account id')); ?></label>
                <input id="segment-person" type="number" min="1" name="person" class="input input-sm input-bordered w-36" value="<?php echo $this->person > 0 ? (int) $this->person : ''; ?>" placeholder="<?php echo $e(t('Account id')); ?>">
                <button type="submit" class="btn btn-sm"><?php echo $e(t('Explain')); ?></button>
            </form>
            <?php if ($this->why !== null): ?>
                <p class="mb-2 font-semibold" data-field="verdict"><?php echo $e($this->why['ok'] ? t('Matches the filter now.') : t('Does not match the filter now.')); ?>
                    <?php echo $e(!empty($this->isMember) ? t('In the segment as last worked out.') : t('Not in the segment as last worked out.')); ?></p>
                <?php if (!$this->why['inScope']): ?><p class="mb-2 text-warning"><?php echo $e(t('This account is not counted as a person here at all (the application leaves it out).')); ?></p><?php endif; ?>
                <?php echo $explain($this->why); ?>
            <?php endif; ?>
        </div></div>
        <div class="card bg-base-100 border border-base-300 shadow-xs"><div class="p-5">
            <h3 class="font-semibold mb-2"><?php echo $e(t('Newest members')); ?></h3>
            <?php if ($this->members === []): ?>
                <p class="text-base-content/60"><?php echo $e(t('Nobody is in it.')); ?></p>
            <?php else: ?>
                <ul class="grid gap-1 text-sm">
                <?php foreach ($this->members as $m): ?>
                    <li class="flex justify-between gap-2"><a href="<?php echo $e($m['url']); ?>"><?php echo $e($m['name']); ?></a><span class="text-base-content/60"><?php echo $e(date('Y-m-d', $m['since'])); ?></span></li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <h3 class="font-semibold mt-4 mb-2"><?php echo $e(t('Used by')); ?></h3>
            <p class="text-sm"><?php echo $e($this->usedBy === [] ? t('Nothing uses it.') : implode(' · ', $this->usedBy)); ?></p>
        </div></div>
    </div>
</div>
