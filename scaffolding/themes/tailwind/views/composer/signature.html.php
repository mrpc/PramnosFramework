<?php
/**
 * My signature (tailwind theme): the HTML added under a message when the composer's "With my
 * signature" is ticked — written in the application's message editor.
 *
 * Variables:
 *   $this->signature — the signed-in account's signature, HTML
 */
$e  = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$bc = \Pramnos\Html\Breadcrumb::admin([[$e(t('My signature')), '']]);
?>
<div class="max-w-3xl mx-auto py-6 px-4">
    <?php echo $bc; ?>
    <h2 class="mb-2"><?php echo $e(t('My signature')); ?></h2>
    <p class="text-sm text-base-content/60 mb-4"><?php echo $e(t('Added under a message when "With my signature" is ticked.')); ?></p>
    <form method="post" action="<?php echo $e(adminUrl('Composer/signature')); ?>">
        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
        <textarea name="signature" id="pf-signature" rows="6" class="textarea w-full" data-pf-editor="<?php echo $e(\Pramnos\Html\MessageEditor::mode()); ?>"><?php echo $e($this->signature ?? ''); ?></textarea>
        <button type="submit" class="btn btn-primary btn-sm mt-3"><?php echo $e(t('Save')); ?></button>
    </form>
</div>
<?php echo \Pramnos\Html\MessageEditor::scripts(\Pramnos\Html\MessageEditor::mode()); ?>
