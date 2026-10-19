<?php
/**
 * Brand block at the top of an auth card (Bootstrap theme).
 *
 * Shows whose server this is before the screen asks for anything: the brand
 * logo when one is set, otherwise the brand name as the card's heading. Reads
 * the view's `brand` when the controller handed one over, else the settings
 * through \Pramnos\Auth\Brand::current(). The brand name is data — it is
 * escaped, never translated.
 */
$brand = $this->brand ?? \Pramnos\Auth\Brand::current();
?>
<div class="text-center mb-3">
    <?php if (!empty($brand['logo'])): ?>
        <img src="<?php echo htmlspecialchars((string) $brand['logo'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars((string) ($brand['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" style="max-width:220px;max-height:64px">
    <?php else: ?>
        <h1 class="h4 mb-0"><?php echo htmlspecialchars((string) ($brand['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h1>
    <?php endif; ?>
</div>
