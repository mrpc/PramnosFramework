<?php
/**
 * One queue task (Tailwind theme).
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
<div class="max-w-6xl mx-auto py-6 px-4">
    <?php echo $bc->render(); ?>
    <div class="flex flex-wrap items-center gap-3 mb-4">
        <a href="<?php echo adminUrl('Queue'); ?>" class="btn btn-outline btn-xs">&larr; <?php echo $e(t('Back')); ?></a>
        <h2><?php echo $e(sprintf(t('Task #%d'), $id)); ?></h2>
        <?php echo $this->status; ?>
        <span class="ml-auto flex gap-1"><?php echo $this->actions; ?></span>
    </div>
    <div class="card bg-base-100 border border-base-300 mb-4">
        <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3 text-sm p-5">
            <?php foreach ($this->fields as $label => $value): ?>
                <div class="<?php echo strlen($value) > 80 ? 'sm:col-span-2 lg:col-span-3' : ''; ?>">
                    <dt class="font-semibold text-base-content/60 text-xs uppercase tracking-wide"><?php echo $e($label); ?></dt>
                    <dd class="break-words whitespace-pre-wrap"><?php echo $e($value); ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </div>
    <div class="card bg-base-100 border border-base-300">
        <div class="p-5">
            <h3 class="font-semibold mb-3"><?php echo $e(t('Payload')); ?></h3>
            <?php foreach ($this->payload as $key => $value): ?>
                <?php if ($key !== ''): ?><div class="text-xs font-semibold text-base-content/60 uppercase tracking-wide mt-2"><?php echo $e($key); ?></div><?php endif; ?>
                <pre class="bg-base-200 rounded p-3 text-xs overflow-x-auto whitespace-pre-wrap break-words"><?php echo $e($value); ?></pre>
            <?php endforeach; ?>
        </div>
    </div>
</div>
