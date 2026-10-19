<?php
/**
 * Link back to the site, under a public auth card (Bootstrap theme).
 *
 * A visitor who reached a sign-in, registration or password screen and does
 * not want to continue gets a way back to the home page (`sURL`) without the
 * browser's back button.
 */
?>
<div class="text-center mt-3">
    <a href="<?php echo htmlspecialchars(sURL, ENT_QUOTES, 'UTF-8'); ?>" class="small text-muted"><?php echo htmlspecialchars(t('Back to the home page'), ENT_QUOTES, 'UTF-8'); ?></a>
</div>
