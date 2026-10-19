<?php
/**
 * Brand block at the top of an auth card (Bootstrap theme).
 *
 * Shows whose server this is before the screen asks for anything: the brand
 * logo when one is set, otherwise the brand name as the card's heading. Reads
 * the view's `brand` when the controller handed one over, else the settings
 * through \Pramnos\Auth\Brand::current(). The brand name is data — it is
 * escaped, never translated.
 *
 * The logo's size is two CSS variables, so a wide or tall logo needs a line of CSS, not a copy of
 * this partial: `:root { --pf-auth-logo-max-height: 96px; --pf-auth-logo-max-width: 320px; }`.
 */
$brand = $this->brand ?? \Pramnos\Auth\Brand::current();
?>
<div class="text-center mb-3">
    <?php if (!empty($brand['logo'])): ?>
        <img src="<?php echo htmlspecialchars((string) $brand['logo'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars((string) ($brand['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" class="pf-auth-logo" style="max-width:var(--pf-auth-logo-max-width, 220px);max-height:var(--pf-auth-logo-max-height, 64px)">
    <?php else: ?>
        <h1 class="h4 mb-0"><?php echo htmlspecialchars((string) ($brand['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h1>
    <?php endif; ?>
</div>
