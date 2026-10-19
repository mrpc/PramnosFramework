<?php
/**
 * Export My Data — confirmation page (plain-CSS theme). GDPR Article 20.
 *
 * GET shows this confirmation; the form POSTs back to exportdata which streams
 * the JSON download.
 *
 * Variables:
 *   $this->routeBase — Account controller route base
 *   $this->exportSections  — string[] labels of what the export will contain
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'exportdata';
?>
<div class="page-section">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2><?php echo htmlspecialchars(t('Export My Data'), ENT_QUOTES, 'UTF-8'); ?></h2>

    <?php if ($this->hasErrors()): ?>
        <div role="alert" class="alert alert-error"><?php echo $this->_printErrors(); ?></div>
    <?php endif; ?>

    <div class="account-grid">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div>
            <div class="card" style="max-width:560px">
                <div class="card-body">
                    <p style="color:#666;font-size:.9em;margin-bottom:12px">
                        <?php echo htmlspecialchars(t('Download a copy of your personal data as a JSON file. The export includes:'), ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <ul style="margin:0 0 16px;padding-left:20px;color:#444;font-size:.9em">
                        <?php foreach (($this->exportSections ?? []) as $label): ?>
                            <li><?php echo htmlspecialchars($label); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p style="color:#888;font-size:.85em;margin-bottom:16px">
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
