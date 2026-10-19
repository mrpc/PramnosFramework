<?php
/**
 * The organisations the signed-in person manages (plain CSS theme).
 *
 * Variables: $this->organizations — id => name
 */
$orgs = is_array($this->organizations ?? null) ? $this->organizations : [];
$e    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
// Inside the account area: the same breadcrumb, sidebar and messages as every account page.
$this->activeNav = 'organization';
?>
<div class="page-section">

    <?php $this->insert('../partials/account_breadcrumb'); ?>

    <?php if ($this->hasMessages()): ?>
        <div role="status" class="alert alert-success"><?php echo $this->_printMessages(); ?></div>
    <?php endif; ?>
    <?php if ($this->hasErrors()): ?>
        <div role="alert" class="alert alert-error"><?php echo $this->_printErrors(); ?></div>
    <?php endif; ?>

    <div class="account-grid">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div>
            <h2 class="mb-3"><?php echo $e(t('Your organisations')); ?></h2>
            <?php if ($orgs === []): ?>
                <p class="pf-muted small"><?php echo $e(t('You do not manage any organisation. An administrator makes you a manager of one.')); ?></p>
            <?php else: ?>
                <ul class="">
                    <?php foreach ($orgs as $id => $name): ?>
                        <li><a href="<?php echo sURL . 'organization/view/' . (int) $id; ?>"><?php echo $e($name); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
