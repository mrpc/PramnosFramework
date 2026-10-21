<?php
/**
 * My signature (plain-css theme): the HTML added under a message when the composer's "With my
 * signature" is ticked — written in the application's message editor.
 *
 * Variables:
 *   $this->signature — the signed-in account's signature, HTML
 */
$e  = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$bc = \Pramnos\Html\Breadcrumb::admin([[$e(t('My signature')), '']]);
?>
<div class="page-section">
    <?php echo $bc; ?>
    <h2><?php echo $e(t('My signature')); ?></h2>
    <p><?php echo $e(t('Added under a message when "With my signature" is ticked.')); ?></p>
    <form method="post" action="<?php echo $e(adminUrl('Composer/signature')); ?>">
        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
        <textarea name="signature" id="pf-signature" rows="6" data-pf-editor="<?php echo $e(\Pramnos\Html\MessageEditor::mode()); ?>"><?php echo $e($this->signature ?? ''); ?></textarea>
        <button type="submit"><?php echo $e(t('Save')); ?></button>
    </form>
</div>
<?php echo \Pramnos\Html\MessageEditor::scripts(\Pramnos\Html\MessageEditor::mode()); ?>
