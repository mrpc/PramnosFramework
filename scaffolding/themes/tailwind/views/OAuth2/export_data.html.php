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
    <h2 class="text-2xl font-bold text-gray-800 mb-6">Export My Data</h2>

    <?php if ($this->hasErrors()): ?>
        <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded-sm">
            <?php echo $this->_printErrors(); ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="md:col-span-3">
            <div class="bg-white rounded-lg shadow-sm p-6 max-w-xl">
                <p class="text-sm text-gray-500 mb-3">
                    Download a copy of your personal data as a JSON file. The export includes:
                </p>
                <ul class="list-disc list-inside text-sm text-gray-700 space-y-1 mb-3">
                    <?php foreach (($this->exportSections ?? []) as $label): ?>
                        <li><?php echo htmlspecialchars($label); ?></li>
                    <?php endforeach; ?>
                </ul>
                <p class="text-xs text-gray-400 mb-5">
                    Secrets (password, two-factor secret, passkey keys, security tokens) are never included.
                </p>
                <form method="post" action="<?php echo sURL . $routeBase; ?>/exportdata">
                    <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                    <button type="submit"
                            class="py-2 px-4 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-sm transition-colors">
                        Download my data
                    </button>
                </form>
            </div>
        </div>
    </div>

</div>
