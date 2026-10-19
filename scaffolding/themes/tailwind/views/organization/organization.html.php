<?php
/**
 * The organisations the signed-in person manages (Tailwind theme).
 *
 * Variables: $this->organizations — id => name
 */
$orgs = is_array($this->organizations ?? null) ? $this->organizations : [];
$e    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
// Inside the account area: the same breadcrumb, sidebar and messages as every account page.
$this->activeNav = 'organization';
?>
<div class="container mx-auto px-4 py-8">

    <?php $this->insert('../partials/account_breadcrumb'); ?>

    <?php if ($this->hasMessages()): ?>
        <div role="status" class="alert alert-success mb-4"><?php echo $this->_printMessages(); ?></div>
    <?php endif; ?>
    <?php if ($this->hasErrors()): ?>
        <div role="alert" class="alert alert-error mb-4"><?php echo $this->_printErrors(); ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="md:col-span-3 min-w-0">
            <h2 class="text-lg font-semibold mb-4"><?php echo $e(t('Your organisations')); ?></h2>
            <?php if ($orgs === []): ?>
                <p class="text-sm opacity-70"><?php echo $e(t('You do not manage any organisation. An administrator makes you a manager of one.')); ?></p>
            <?php else: ?>
                <ul class="list-disc pl-6">
                    <?php foreach ($orgs as $id => $name): ?>
                        <li><a href="<?php echo sURL . 'organization/view/' . (int) $id; ?>"><?php echo $e($name); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
