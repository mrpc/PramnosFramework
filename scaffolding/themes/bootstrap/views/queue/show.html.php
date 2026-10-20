<?php
/**
 * One queue task (Bootstrap theme).
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
$bc = new \Pramnos\Html\Breadcrumb();
$bc->addItem(t('Home'), adminUrl());
$bc->addItem(t('Queue'), adminUrl('Queue'));
$bc->addItem('#' . $id, '');
?>
<div class="container py-4">
    <?php echo $bc->render(); ?>
    <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
        <a href="<?php echo adminUrl('Queue'); ?>" class="btn btn-sm btn-outline-secondary">&larr; <?php echo $e(t('Back')); ?></a>
        <h2><?php echo $e(sprintf(t('Task #%d'), $id)); ?></h2>
        <?php echo $this->status; ?>
        <span class="ms-auto d-flex gap-1"><?php echo $this->actions; ?></span>
    </div>
    <div class="card mb-3">
        <dl class="row g-3 small p-3 m-0">
            <?php foreach ($this->fields as $label => $value): ?>
                <div class="<?php echo strlen($value) > 80 ? 'col-12' : 'col-sm-6 col-lg-4'; ?>">
                    <dt class="text-muted small text-uppercase fw-semibold"><?php echo $e($label); ?></dt>
                    <dd class="text-break mb-0" style="white-space:pre-wrap"><?php echo $e($value); ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </div>
    <div class="card">
        <div class="p-3">
            <h3 class="h6 mb-3"><?php echo $e(t('Payload')); ?></h3>
            <?php foreach ($this->payload as $key => $value): ?>
                <?php if ($key !== ''): ?><div class="small fw-semibold text-muted text-uppercase mt-2"><?php echo $e($key); ?></div><?php endif; ?>
                <pre class="bg-light border rounded p-2 small text-break" style="white-space:pre-wrap"><?php echo $e($value); ?></pre>
            <?php endforeach; ?>
        </div>
    </div>
</div>
