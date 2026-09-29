<?php
/**
 * The organisations the signed-in person manages (Tailwind theme).
 *
 * Variables: $this->organizations — id => name
 */
$orgs = is_array($this->organizations ?? null) ? $this->organizations : [];
$e    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="px-4 py-6">
    <h2 class="text-lg font-semibold mb-4">Your organisations</h2>
    <?php if ($orgs === []): ?>
        <p class="text-sm opacity-70">You do not manage any organisation. An administrator makes you a manager of one.</p>
    <?php else: ?>
        <ul class="list-disc pl-6">
            <?php foreach ($orgs as $id => $name): ?>
                <li><a href="<?php echo sURL . 'organization/view/' . (int) $id; ?>"><?php echo $e($name); ?></a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
