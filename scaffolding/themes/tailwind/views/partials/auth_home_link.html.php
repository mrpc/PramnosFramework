<?php
/**
 * A small link back to the site's home page, placed under a public auth card (Tailwind theme).
 *
 * The sign-in, registration and password-reset screens stand alone, with no site header, so
 * without it somebody who reached one by mistake has only the browser's back button.
 */
?>
<p class="text-center text-xs mt-4">
    <a href="<?php echo sURL; ?>" class="text-base-content/60 hover:underline"><?php echo htmlspecialchars(t('Back to the home page'), ENT_QUOTES, 'UTF-8'); ?></a>
</p>
