<?php
/**
 * The brand block at the top of an auth card (plain-CSS theme).
 *
 * Says whose server this is before the screen asks for anything: the logo when the
 * brand has one, otherwise the brand name as a heading. Every card a visitor can reach
 * from a link — sign-in, the second factor, the consent screen, a device code — inserts
 * it as the first thing inside `.card`:
 *
 *     <?php $this->insert('../partials/auth_brand'); ?>
 *
 * Reads the view's `brand` when the controller handed one over, else
 * `Pramnos\Auth\Brand::current()` from the settings. The name is data, so it is never
 * passed through t().
 */
$brand = $this->brand ?? \Pramnos\Auth\Brand::current();
?>
<div class="card-header" style="text-align:center">
    <?php if (!empty($brand['logo'])): ?>
        <img src="<?php echo htmlspecialchars((string) $brand['logo'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars((string) ($brand['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" style="max-width:220px;max-height:64px">
    <?php else: ?>
        <h2 style="margin:0;font-size:1.25rem"><?php echo htmlspecialchars((string) ($brand['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h2>
    <?php endif; ?>
</div>
