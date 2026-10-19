<?php
/**
 * Export My Data — confirmation page (Bootstrap theme). GDPR Article 20.
 *
 * Variables:
 *   $this->routeBase — Account controller route base
 *   $this->exportSections  — string[] labels of what the export will contain
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'exportdata';
?>
<div class="container py-4">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2 class="mb-4"><?php echo htmlspecialchars(t('Export My Data'), ENT_QUOTES, 'UTF-8'); ?></h2>

    <?php if ($this->hasErrors()): ?>
        <div role="alert" class="alert alert-danger"><?php echo $this->_printErrors(); ?></div>
    <?php endif; ?>

    <div class="row g-4">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="col-lg-9 col-md-8">
            <div class="card" style="max-width:600px">
                <div class="card-body">
                    <p class="text-muted mb-3">
                        <?php echo htmlspecialchars(t('Download a copy of your personal data as a JSON file. The export includes:'), ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <ul class="mb-3">
                        <?php foreach (($this->exportSections ?? []) as $label): ?>
                            <li><?php echo htmlspecialchars($label); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="text-muted small mb-4">
                        <?php echo htmlspecialchars(t('Secrets (password, two-factor secret, passkey keys, security tokens) are never included.'), ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <form method="post" action="<?php echo sURL . $routeBase; ?>/exportdata">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('Download my data'), ENT_QUOTES, 'UTF-8'); ?></button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
