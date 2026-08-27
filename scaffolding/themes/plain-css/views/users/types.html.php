<?php
/**
 * What each user type is, and what it may do (plain-CSS theme).
 *
 * Variables:
 *   $this->types        — floor => label, from UserTypes::labels()
 *   $this->tones        — floor => tone
 *   $this->capabilities — floor => the capabilities declared *at* that floor
 *   $this->resolved     — floor => everything that floor ends up with
 *   $this->areaFloor    — the administration area's own usertype floor
 *
 * Rendered from the registry, so it cannot fall out of step with the behaviour: it reads
 * exactly what the guards read. An application that declared its own types, tones or
 * capabilities sees its own answer here.
 */
$types   = is_array($this->types ?? null) ? $this->types : [];
$tones   = is_array($this->tones ?? null) ? $this->tones : [];
$declared = is_array($this->capabilities ?? null) ? $this->capabilities : [];
$resolved = is_array($this->resolved ?? null) ? $this->resolved : [];
$e       = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$toneCls = ['danger' => 'background:#f8d7da;color:#b02a37', 'warning' => 'background:#fff3cd;color:#856404', 'neutral' => 'background:#eee;color:#555', 'primary' => 'background:#cfe2ff;color:#0d6efd'];
?>
<div class="page-section">
    <?php $this->activeNav = 'users_types'; $this->insert('../partials/admin_breadcrumb'); ?>

    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-bottom:14px">
        <a href="<?php echo adminUrl('Users'); ?>" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
        <h2 style="margin:0">User types</h2>
    </div>

    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="overflow-x-auto">
            <table class="table table-sm text-sm">
                <thead class="bg-base-200 text-xs uppercase text-base-content/70">
                    <tr>
                        <th class="w-20">Value</th>
                        <th>Type</th>
                        <th>Declared at this level</th>
                        <th>Ends up with</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($types as $floor => $label): ?>
                    <?php
                    $tone = $tones[$floor] ?? 'primary';
                    $own  = $declared[$floor] ?? [];
                    $all  = $resolved[$floor] ?? [];
                    ?>
                    <tr>
                        <td class="font-mono text-xs"><?php echo (int) $floor; ?></td>
                        <td>
                            <span style="padding:2px 8px;border-radius:10px;font-size:0.8em;<?php echo $e($toneCls[$tone] ?? $toneCls['primary']); ?>">
                                <?php echo $e($label); ?>
                            </span>
                        </td>
                        <td class="text-xs">
                            <?php if ($own === []): ?>
                            <span class="text-base-content/40">—</span>
                            <?php else: ?>
                                <?php foreach ($own as $capability): ?>
                                <code class="badge badge-ghost badge-xs"><?php echo $e($capability); ?></code>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-xs">
                            <?php if (in_array('*', $all, true)): ?>
                            <span class="badge badge-error badge-sm">everything</span>
                            <?php else: ?>
                            <span class="text-base-content/70"><?php echo count($all); ?> capabilit<?php echo count($all) === 1 ? 'y' : 'ies'; ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div style="display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(300px,1fr))">
        <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:16px;font-size:0.9em">
            <h3 class="font-semibold">How a value becomes a type</h3>
            <p class="text-base-content/70 mb-0">
                A usertype is an <strong>integer read as a threshold</strong>: a value falls
                into the highest type at or below it, so <code>95</code> is
                <?php echo $e(\Pramnos\User\UserTypes::label(95)); ?> and capabilities
                accumulate downwards.
            </p>
            <p class="text-base-content/70 mb-0">
                <code>1</code> is the exception — the machine account a Client Credentials
                grant authenticates as. It is matched <em>exactly</em> and inherits nothing,
                because it is not a very senior person.
            </p>
        </div>

        <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:16px;font-size:0.9em">
            <h3 class="font-semibold">Two different questions</h3>
            <p class="text-base-content/70 mb-0">
                A <strong>capability</strong> answers "may this kind of account reach this
                kind of screen". A <strong>permission</strong> answers "may this account
                touch this record", and lives per user — see a user's own screen.
            </p>
            <p class="text-base-content/70 mb-0">
                The administration area also applies its own floor, currently
                <code><?php echo (int) ($this->areaFloor ?? 0); ?></code>, which is a
                separate decision from any type's capabilities: it is what stops the area
                being browsable at all.
            </p>
        </div>
    </div>
</div>
