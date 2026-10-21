<?php
/**
 * One segment (plain-CSS theme): how many are in it, who came and went, where it is used, its
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
 */
$e         = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$segment   = $this->segment;
$id        = (int) $segment['id'];
$explain   = static function (array $group) use (&$explain, $e): string {
    $html = '<ul style="list-style:none;padding:0;margin:0;display:grid;gap:4px">';
    foreach ($group['conditions'] as $c) {
        if (isset($c['conditions'])) {
            $html .= '<li style="padding-left:12px;border-left:2px solid #ddd"><span style="font-size:.75rem;text-transform:uppercase;opacity:.7">'
                . $e($c['match'] === 'any' ? t('any of') : t('all of')) . ' ' . ($c['ok'] ? '✓' : '✗') . '</span>' . $explain($c) . '</li>';
            continue;
        }
        $said  = \Pramnos\Audience\Explained::condition($c);
        $html .= '<li style="display:flex;flex-wrap:wrap;gap:8px;align-items:baseline"><span style="font-weight:600;color:' . ($c['ok'] ? '#15803d' : '#b91c1c') . '" aria-label="'
            . $e($c['ok'] ? t('yes') : t('no')) . '">' . ($c['ok'] ? '✓' : '✗') . '</span> '
            . $e($said['what']) . ' ' . $e($said['how']) . ($said['value'] !== null ? ' <strong>' . $e($said['value']) . '</strong>' : '')
            . ' <span style="opacity:.7">' . $e(t('has: %s', $said['actual'])) . '</span></li>';
    }

    return $html . '</ul>';
};
?>
<div class="page-section">
    <?php $this->activeNav = 'segments_view'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px;margin-bottom:16px">
        <h2><?php echo $e($segment['name']); ?></h2>
        <div style="display:flex;flex-wrap:wrap;gap:8px">
            <a href="<?php echo $e(adminUrl('Segments/edit/') . $id); ?>" class="btn btn-secondary"><?php echo $e(t('Edit')); ?></a>
            <form method="post" action="<?php echo $e(adminUrl('Segments/recompute/') . $id); ?>">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <button type="submit" class="btn btn-secondary"><?php echo $e(t('Work it out now')); ?></button>
            </form>
            <form method="post" action="<?php echo $e(adminUrl('Segments/delete/') . $id); ?>" data-confirm="<?php echo $e(t('Delete this segment and its history?')); ?>">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <button type="submit" class="btn btn-danger" <?php echo $this->usedBy === [] ? '' : 'disabled title="' . $e(t('In use')) . '"'; ?>><?php echo $e(t('Delete')); ?></button>
            </form>
        </div>
    </div>
    <?php if ($segment['description'] !== ''): ?><p style="margin-bottom:16px;opacity:.8"><?php echo $e($segment['description']); ?></p><?php endif; ?>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(9rem,1fr));gap:.5rem;margin-bottom:1rem">
        <div style="border:1px solid #ddd;border-radius:4px;padding:12px"><div style="font-size:.8rem;opacity:.7"><?php echo $e(t('Now')); ?></div><div style="font-size:1.5rem;font-weight:700" data-field="members"><?php echo $e(number_format($segment['members'])); ?></div></div>
        <div style="border:1px solid #ddd;border-radius:4px;padding:12px"><div style="font-size:.8rem;opacity:.7"><?php echo $e(t('Entered, 7 days')); ?></div><div style="font-size:1.5rem;font-weight:700">+<?php echo $e(number_format($this->movement['entered'])); ?></div></div>
        <div style="border:1px solid #ddd;border-radius:4px;padding:12px"><div style="font-size:.8rem;opacity:.7"><?php echo $e(t('Left, 7 days')); ?></div><div style="font-size:1.5rem;font-weight:700">−<?php echo $e(number_format($this->movement['left'])); ?></div></div>
        <div style="border:1px solid #ddd;border-radius:4px;padding:12px"><div style="font-size:.8rem;opacity:.7"><?php echo $e(t('Worked out')); ?></div><div style="font-weight:600"><?php echo $e($segment['recomputedAt'] === null ? t('not yet') : date('Y-m-d H:i', $segment['recomputedAt'])); ?></div></div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(18rem,1fr));gap:1rem">
        <div class="card" style="border:1px solid #ddd;border-radius:4px"><div class="card-body" style="padding:16px">
            <h3 style="margin:0 0 8px"><?php echo $e(t('Why is this person in or out?')); ?></h3>
            <form method="get" action="<?php echo $e(adminUrl('Segments/view/') . $id); ?>" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px">
                <label style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)" for="segment-person"><?php echo $e(t('Account id')); ?></label>
                <input id="segment-person" type="number" min="1" name="person" style="width:9rem;padding:6px;border:1px solid #ccc;border-radius:4px" value="<?php echo $this->person > 0 ? (int) $this->person : ''; ?>" placeholder="<?php echo $e(t('Account id')); ?>">
                <button type="submit" class="btn btn-secondary"><?php echo $e(t('Explain')); ?></button>
            </form>
            <?php if ($this->why !== null): ?>
                <p style="margin-bottom:8px;font-weight:600" data-field="verdict"><?php echo $e($this->why['ok'] ? t('Matches the filter now.') : t('Does not match the filter now.')); ?>
                    <?php echo $e(!empty($this->isMember) ? t('In the segment as last worked out.') : t('Not in the segment as last worked out.')); ?></p>
                <?php if (!$this->why['inScope']): ?><p style="margin-bottom:8px;color:#a16207"><?php echo $e(t('This account is not counted as a person here at all (the application leaves it out).')); ?></p><?php endif; ?>
                <?php echo $explain($this->why); ?>
            <?php endif; ?>
        </div></div>
        <div class="card" style="border:1px solid #ddd;border-radius:4px"><div class="card-body" style="padding:16px">
            <h3 style="margin:0 0 8px"><?php echo $e(t('Newest members')); ?></h3>
            <?php if ($this->members === []): ?>
                <p style="opacity:.7"><?php echo $e(t('Nobody is in it.')); ?></p>
            <?php else: ?>
                <ul style="list-style:none;padding:0;margin:0;display:grid;gap:4px">
                <?php foreach ($this->members as $m): ?>
                    <li style="display:flex;justify-content:space-between;gap:8px"><a href="<?php echo $e($m['url']); ?>"><?php echo $e($m['name']); ?></a><span style="opacity:.7"><?php echo $e(date('Y-m-d', $m['since'])); ?></span></li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <h3 style="margin:16px 0 8px"><?php echo $e(t('Used by')); ?></h3>
            <p style="font-size:.9rem"><?php echo $e($this->usedBy === [] ? t('Nothing uses it.') : implode(' · ', $this->usedBy)); ?></p>
        </div></div>
    </div>
</div>
