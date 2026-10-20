<?php
/**
 * One queue task (plain-CSS theme).
 *
 * Variables:
 *   $this->task    — the row
 *   $this->fields  — [label => text]: every field it has a value for
 *   $this->payload — [key => text], each value readable JSON; `''` for a payload that is not an object
 *   $this->status  — the status badge (HTML)
 *   $this->actions — what can be done with it, as POST buttons (HTML)
 */
$e  = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$id = (int) ($this->task['taskid'] ?? 0);
$bc = \Pramnos\Html\Breadcrumb::admin([[$e(t('Queue')), adminUrl('Queue')], [$e('#' . $id), '']]);
?>
<div class="page-section">
    <?php echo $bc; ?>
    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-bottom:16px">
        <a href="<?php echo adminUrl('Queue'); ?>" class="btn">&larr; <?php echo $e(t('Back')); ?></a>
        <h2><?php echo $e(sprintf(t('Task #%d'), $id)); ?></h2>
        <?php echo $this->status; ?>
        <span style="margin-left:auto;display:flex;gap:4px"><?php echo $this->actions; ?></span>
    </div>
    <div class="card" style="margin-bottom:16px">
        <dl style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px 24px;padding:16px;margin:0">
            <?php foreach ($this->fields as $label => $value): ?>
                <div <?php echo strlen($value) > 80 ? 'style="grid-column:1/-1"' : ''; ?>>
                    <dt style="font-size:.75em;font-weight:600;color:#666;text-transform:uppercase"><?php echo $e($label); ?></dt>
                    <dd style="margin:0;white-space:pre-wrap;word-break:break-word"><?php echo $e($value); ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </div>
    <div class="card">
        <div style="padding:16px">
            <h3 style="margin:0 0 12px"><?php echo $e(t('Payload')); ?></h3>
            <?php foreach ($this->payload as $key => $value): ?>
                <?php if ($key !== ''): ?><div style="font-size:.75em;font-weight:600;color:#666;text-transform:uppercase;margin-top:8px"><?php echo $e($key); ?></div><?php endif; ?>
                <pre style="background:#f5f5f5;border-radius:4px;padding:8px;font-size:.85em;white-space:pre-wrap;word-break:break-word"><?php echo $e($value); ?></pre>
            <?php endforeach; ?>
        </div>
    </div>
</div>
