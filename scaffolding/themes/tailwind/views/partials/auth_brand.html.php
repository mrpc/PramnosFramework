<?php
/**
 * The brand block at the top of an auth card (Tailwind theme): the logo, or the name when there is
 * no logo.
 *
 * Every card screen inserts it — sign-in, the second factor, the consent screen, a device code —
 * so somebody who arrives from a link sees whose server this is before it asks for anything.
 *
 * Reads `$this->brand` when the controller handed the view one, and `Brand::current()` (the
 * `auth_brand_*` settings) otherwise. The name is the application's own data, not interface text,
 * so it never goes through t().
 *
 * The logo's size is two CSS variables, so a wide or tall logo needs a line of CSS, not a copy of
 * this partial: `:root { --pf-auth-logo-max-height: 96px; --pf-auth-logo-max-width: 320px; }`.
 */
$brand = $this->brand ?? \Pramnos\Auth\Brand::current();
?>
<div class="text-center mb-6">
    <?php if (!empty($brand['logo'])): ?>
        <img src="<?php echo htmlspecialchars((string) $brand['logo'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars((string) ($brand['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" class="pf-auth-logo mx-auto" style="max-width:var(--pf-auth-logo-max-width, 220px);max-height:var(--pf-auth-logo-max-height, 64px)">
    <?php else: ?>
        <h1 class="text-2xl font-semibold"><?php echo htmlspecialchars((string) ($brand['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h1>
    <?php endif; ?>
</div>
