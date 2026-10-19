<?php
/**
 * Export My Data — confirmation page (Tailwind theme). GDPR Article 20.
 *
 * Variables:
 *   $this->routeBase — Account controller route base
 *   $this->exportSections  — string[] labels of what the export will contain
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'exportdata';
?>
<div class="container mx-auto px-4 py-8">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2 class="text-2xl font-bold text-base-content mb-6"><?php echo htmlspecialchars(t('Export My Data'), ENT_QUOTES, 'UTF-8'); ?></h2>

    <?php if ($this->hasErrors()): ?>
        <div role="alert" class="alert alert-error mb-4">
            <?php echo $this->_printErrors(); ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="md:col-span-3">
            <div class="card bg-base-100 shadow-sm p-6 max-w-xl">
                <p class="text-sm text-base-content/70 mb-3">
                    <?php echo htmlspecialchars(t('Download a copy of your personal data as a JSON file. The export includes:'), ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <ul class="list-disc list-inside text-sm text-base-content space-y-1 mb-3">
                    <?php foreach (($this->exportSections ?? []) as $label): ?>
                        <li><?php echo htmlspecialchars($label); ?></li>
                    <?php endforeach; ?>
                </ul>
                <p class="text-xs text-base-content/60 mb-5">
                    <?php echo htmlspecialchars(t('Secrets (password, two-factor secret, passkey keys, security tokens) are never included.'), ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <form method="post" action="<?php echo sURL . $routeBase; ?>/exportdata">
                    <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                    <button type="submit"
                            class="btn btn-primary">
                        <?php echo htmlspecialchars(t('Download my data'), ENT_QUOTES, 'UTF-8'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

</div>
