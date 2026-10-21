<?php
/**
 * One segment: how many are in it, who came and went, where it is used, its newest members, and
 * why one person is in or out. The same markup in every theme.
 *
 * Variables:
 *   $this->segment   — Segments::get()
 *   $this->movement  — ['entered', 'left'] over seven days
 *   $this->uses      — what uses it: name, url (null where it has no page)
 *   $this->usedBy    — the same, names only
 *   $this->members   — the newest members: userid, name, since, url
 *   $this->person    — the account asked about, or 0
 *   $this->why       — Filter::explain() for that account, or null
 *   $this->isMember  — whether that account is in the segment as last worked out
 */
use Pramnos\Html\Icon;

$e       = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$segment = $this->segment;
$id      = (int) $segment['id'];
$uses    = $this->uses ?? array_map(static fn (string $name): array => ['name' => $name, 'url' => null], (array) ($this->usedBy ?? []));
$explain = static function (array $group) use (&$explain, $e): string {
    $html = '<ul class="pf-why">';
    foreach ($group['conditions'] as $c) {
        if (isset($c['conditions'])) {
            $html .= '<li class="pf-why-group"><span class="pf-label">' . $e($c['match'] === 'any' ? t('any of') : t('all of')) . ' '
                . ($c['ok'] ? '✓' : '✗') . '</span>' . $explain($c) . '</li>';
            continue;
        }
        $said  = \Pramnos\Audience\Explained::condition($c);
        $html .= '<li><span class="pf-why-mark ' . ($c['ok'] ? 'pf-up' : 'pf-down') . '" aria-label="' . $e($c['ok'] ? t('yes') : t('no')) . '">'
            . ($c['ok'] ? '✓' : '✗') . '</span><span>' . $e($said['what']) . ' ' . $e($said['how'])
            . ($said['value'] !== null ? ' <strong>' . $e($said['value']) . '</strong>' : '')
            . ' <span class="pf-muted">· ' . $e(t('has: %s', $said['actual'])) . '</span></span></li>';
    }

    return $html . '</ul>';
};
?>
<div class="pf-ui">
    <?php $this->activeNav = 'segments_view'; $this->insert('../partials/admin_breadcrumb'); ?>
    <header class="pf-page-head">
        <div>
            <h1 class="pf-page-title"><?php echo $e($segment['name']); ?></h1>
            <?php if ($segment['description'] !== ''): ?><p class="pf-page-sub"><?php echo $e($segment['description']); ?></p><?php endif; ?>
        </div>
        <div class="pf-page-actions">
            <form method="post" action="<?php echo $e(adminUrl('Segments/delete/') . $id); ?>" class="pf-inline-form" data-confirm="<?php echo $e(t('Delete this segment and its history?')); ?>">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <button type="submit" class="pf-btn pf-btn-ghost pf-btn-danger" <?php echo $uses === [] ? '' : 'disabled title="' . $e(t('In use')) . '"'; ?>><?php echo $e(t('Delete')); ?></button>
            </form>
            <form method="post" action="<?php echo $e(adminUrl('Segments/recompute/') . $id); ?>" class="pf-inline-form">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <button type="submit" class="pf-btn"><?php echo Icon::svg('retry', ''); ?><?php echo $e(t('Work it out now')); ?></button>
            </form>
            <a href="<?php echo $e(adminUrl('Segments/edit/') . $id); ?>" class="pf-btn pf-btn-primary"><?php echo Icon::svg('edit', ''); ?><?php echo $e(t('Edit')); ?></a>
        </div>
    </header>

    <div class="pf-card"><div class="pf-card-body pf-stats">
        <div><span class="pf-label"><?php echo $e(t('Now')); ?></span><span class="pf-stat-value" data-field="members"><?php echo $e(number_format($segment['members'])); ?></span></div>
        <div><span class="pf-label"><?php echo $e(t('Entered, 7 days')); ?></span><span class="pf-stat-value <?php echo $this->movement['entered'] > 0 ? 'pf-up' : 'pf-zero'; ?>">▲ <?php echo $e(number_format($this->movement['entered'])); ?></span></div>
        <div><span class="pf-label"><?php echo $e(t('Left, 7 days')); ?></span><span class="pf-stat-value <?php echo $this->movement['left'] > 0 ? 'pf-down' : 'pf-zero'; ?>">▼ <?php echo $e(number_format($this->movement['left'])); ?></span></div>
        <div><span class="pf-label"><?php echo $e(t('Worked out')); ?></span><span class="pf-stat-value pf-stat-small"><?php echo $e($segment['recomputedAt'] === null ? t('not yet') : date('Y-m-d H:i', $segment['recomputedAt'])); ?></span></div>
    </div></div>

    <div class="pf-grid-2 pf-mt">
        <div class="pf-card"><div class="pf-card-body">
            <h2 class="pf-section-title"><?php echo $e(t('Why is this person in or out?')); ?></h2>
            <form method="get" action="<?php echo $e(adminUrl('Segments/view/') . $id); ?>" class="pf-rule-sentence">
                <label class="pf-visually-hidden" for="segment-person"><?php echo $e(t('Account id')); ?></label>
                <input id="segment-person" type="number" min="1" name="person" class="pf-input pf-input-id" value="<?php echo $this->person > 0 ? (int) $this->person : ''; ?>" placeholder="<?php echo $e(t('Account id')); ?>">
                <button type="submit" class="pf-btn"><?php echo $e(t('Explain')); ?></button>
            </form>
            <?php if ($this->why !== null): ?>
                <p class="pf-why-verdict" data-field="verdict">
                    <span class="pf-badge <?php echo $this->why['ok'] ? 'pf-tone-ok' : 'pf-tone-bad'; ?>"><?php echo $e($this->why['ok'] ? t('Matches the filter now.') : t('Does not match the filter now.')); ?></span>
                    <span class="pf-muted"><?php echo $e(!empty($this->isMember) ? t('In the segment as last worked out.') : t('Not in the segment as last worked out.')); ?></span>
                </p>
                <?php if (!$this->why['inScope']): ?><p class="pf-hint"><?php echo $e(t('This account is not counted as a person here at all (the application leaves it out).')); ?></p><?php endif; ?>
                <?php echo $explain($this->why); ?>
            <?php endif; ?>
        </div></div>
        <div class="pf-card"><div class="pf-card-body">
            <h2 class="pf-section-title"><?php echo $e(t('Newest members')); ?></h2>
            <?php if ($this->members === []): ?>
                <p class="pf-muted"><?php echo $e(t('Nobody is in it.')); ?></p>
            <?php else: ?>
                <ul class="pf-people">
                <?php foreach ($this->members as $m): ?>
                    <li><a class="pf-person" href="<?php echo $e($m['url']); ?>">
                        <span class="pf-avatar" aria-hidden="true" style="--pf-hue: hsl(<?php echo ((int) $m['userid'] * 47) % 360; ?> 55% 42%)"><?php echo $e(mb_strtoupper(implode('', array_map(static fn (string $w): string => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($m['name'])) ?: ['?'], 0, 2))))); ?></span>
                        <span><span class="pf-person-name"><?php echo $e($m['name']); ?></span><span class="pf-person-line"><?php echo $e(t('since %s', date('Y-m-d', $m['since']))); ?></span></span>
                    </a></li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <h2 class="pf-section-title pf-spaced"><?php echo $e(t('Used by')); ?></h2>
            <?php if ($uses === []): ?>
                <p class="pf-muted"><?php echo $e(t('Nothing uses it.')); ?></p>
            <?php else: ?>
                <span class="pf-chips"><?php foreach ($uses as $use): ?><?php echo $use['url'] !== null
                    ? '<a class="pf-chip" href="' . $e($use['url']) . '">' . $e($use['name']) . '</a>'
                    : '<span class="pf-chip">' . $e($use['name']) . '</span>'; ?><?php endforeach; ?></span>
            <?php endif; ?>
        </div></div>
    </div>
</div>
