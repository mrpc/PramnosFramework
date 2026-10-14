<?php
/**
 * One help page (plain-css theme).
 *
 * Variables:
 *   $this->page    — slug, title, section, updated
 *   $this->html    — the page, drawn by Help::pageHtml(), which escapes first
 *   $this->listUrl — every page
 */
$c = ['wrap' => 'pf-container', 'card' => 'pf-card', 'muted' => 'pf-muted', 'input' => '', 'btn' => 'btn', 'h1' => '', 'h2' => ''];
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="<?php echo $c['wrap']; ?>">
    <p class="<?php echo $c['muted']; ?>"><a href="<?php echo $e($this->listUrl); ?>">← <?php echo $e(t('Help')); ?></a><?php echo $this->page['section'] !== '' ? ' · ' . $e(t($this->page['section'])) : ''; ?></p>
    <article class="pf-help-page">
        <?php echo $this->html; ?>
    </article>
    <?php if ($this->page['updated'] !== ''): ?>
        <p class="<?php echo $c['muted']; ?>"><?php echo $e(sprintf(t('Updated %s'), $this->page['updated'])); ?></p>
    <?php endif; ?>
</div>
