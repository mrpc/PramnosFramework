<?php
/**
 * The organisations the signed-in person manages (plain CSS theme).
 *
 * Variables: $this->organizations — id => name
 */
$orgs = is_array($this->organizations ?? null) ? $this->organizations : [];
$e    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="container">
    <h2 class="mb-3">Your organisations</h2>
    <?php if ($orgs === []): ?>
        <p class="pf-muted small">You do not manage any organisation. An administrator makes you a manager of one.</p>
    <?php else: ?>
        <ul class="">
            <?php foreach ($orgs as $id => $name): ?>
                <li><a href="<?php echo sURL . 'organization/view/' . (int) $id; ?>"><?php echo $e($name); ?></a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
