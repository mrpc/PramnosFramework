<?php
/**
 * Worker pools (Tailwind / daisyUI theme) — each pool's settings, on a card of its own.
 *
 * Variables:
 *   $this->pools     — pools as the supervisor last saw them, plus saved rows it has not seen
 *   $this->taskTypes — the task types to tick: those with a handler, those in the queue, those a pool names
 *
 * Every field says what it does, under it. A pool declared in code shows its declared values as
 * placeholders, and a field left blank keeps them. The last card adds a pool. Every change is a
 * POST with the session's token, applied by the supervisor on its next cycle.
 */
$e      = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES);
$pools  = $this->pools ?? [];
$known  = (array) ($this->taskTypes ?? []);
$fields = [
    'floor'        => [t('Minimum workers'), t('Always keep at least this many workers running.')],
    'ceiling'      => [t('Maximum workers'), t('Never run more than this many workers.')],
    'grow_above'   => [t('Grow above'), t('Start another worker when more than this many tasks are waiting.')],
    'shrink_below' => [t('Shrink below'), t('Stop a worker when fewer than this many tasks are waiting. Keep it below Grow above, so the pool settles.')],
    'load_percent' => [t('CPU limit (%)'), t("Add no workers while the server's CPU load is above this share of its capacity.")],
    'cooldown'     => [t('Cooldown'), t('Supervisor cycles to wait after a change before resizing again.')],
];
?>
<div class="px-4 py-6">
    <?php echo \Pramnos\Html\Breadcrumb::admin([[$e(t('Services')), adminUrl('Services')], [$e(t('Worker pools')), '']]); ?>
    <div class="flex justify-between items-center mb-2">
        <h2 class="mb-0"><?php echo $e(t('Worker pools')); ?></h2>
        <a href="<?php echo $e(adminUrl('Services')); ?>" class="btn btn-outline btn-sm"><?php echo $e(t('Back to services')); ?></a>
    </div>
    <p class="text-sm text-base-content/60 mb-6"><?php echo $e(t('A pool runs workers for its task types, as many as its backlog needs within its limits. The supervisor reads these settings on its next cycle.')); ?></p>

    <?php foreach (array_merge($pools, [null]) as $i => $pool): ?>
        <?php
        // The last entry is the empty card that adds a pool.
        $adding = $pool === null;
        $name   = $adding ? '' : (string) $pool['name'];
        $code   = !$adding && ($pool['source'] ?? '') === 'code';
        $row    = $adding ? null : ($pool['row'] ?? null);
        $config = $adding ? [] : (array) ($pool['config'] ?? []);
        // Ticked: what was saved here; for a code pool nothing saved means its declared types.
        $ticked = $row !== null && ($row['types'] ?? null) !== null
            ? explode(',', (string) $row['types'])
            : ($code || $adding ? [] : (array) ($pool['types'] ?? []));
        $id = 'pool-' . $i;
        ?>
        <div class="card bg-base-100 border border-base-300 shadow-xs mb-4"<?php echo $adding ? ' data-pool-new' : ' data-pool-card="' . $e($name) . '"'; ?>>
            <div class="px-4 py-3 border-b border-base-300 flex flex-wrap gap-2 justify-between items-center">
                <?php if ($adding): ?>
                    <strong><?php echo $e(t('Add a pool')); ?></strong>
                <?php else: ?>
                    <div class="flex flex-wrap gap-2 items-center">
                        <strong><?php echo $e($name); ?></strong>
                        <span class="badge <?php echo $code ? 'badge-ghost' : 'badge-info'; ?>"><?php echo $e($code ? t('declared in code') : t('added here')); ?></span>
                        <?php if (empty($pool['enabled'])): ?>
                            <span class="badge badge-neutral"><?php echo $e(t('Stopped')); ?></span>
                        <?php else: ?>
                            <span class="badge badge-success"><?php echo $e(t('Running')); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($pool['waiting'])): ?>
                            <span class="badge badge-warning"><?php echo $e(t('waiting for supervisor')); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="flex gap-2">
                        <?php if (empty($pool['enabled'])): ?>
                            <form method="post" action="<?php echo $e(adminUrl('Services/poolstart/') . urlencode($name)); ?>" class="m-0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><input type="hidden" name="back" value="pools"><button type="submit" class="btn btn-outline btn-success btn-xs"><?php echo $e(t('Start')); ?></button></form>
                        <?php else: ?>
                            <form method="post" action="<?php echo $e(adminUrl('Services/poolstop/') . urlencode($name)); ?>" class="m-0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><input type="hidden" name="back" value="pools"><button type="submit" class="btn btn-outline btn-warning btn-xs"><?php echo $e(t('Stop')); ?></button></form>
                        <?php endif; ?>
                        <?php if ($row !== null): ?>
                            <form method="post" action="<?php echo $e(adminUrl('Services/pooldelete/') . urlencode($name)); ?>" class="m-0">
                                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                                <button type="submit" class="btn btn-outline btn-error btn-xs"
                                        data-confirm="<?php echo $e($code ? t('Go back to the limits declared in code?') : t('Remove this pool? Its workers are stopped.')); ?>"><?php echo $e($code ? t('Reset to code') : t('Remove')); ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <form method="post" action="<?php echo $e(adminUrl('Services/poolsave')); ?>" class="p-4">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <?php if ($adding): ?>
                    <div class="mb-4 max-w-xs">
                        <label class="block text-sm font-semibold mb-1" for="<?php echo $id; ?>-name"><?php echo $e(t('Name')); ?></label>
                        <input class="input input-bordered input-sm w-full" id="<?php echo $id; ?>-name" name="name" required pattern="[A-Za-z0-9][A-Za-z0-9_.\-]{0,59}" placeholder="mail">
                        <p class="text-xs text-base-content/60 mt-1"><?php echo $e(t('Letters, digits, dots, dashes and underscores.')); ?></p>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="name" value="<?php echo $e($name); ?>">
                <?php endif; ?>

                <fieldset class="mb-4">
                    <legend class="block text-sm font-semibold mb-1"><?php echo $e(t('Task types')); ?></legend>
                    <div class="flex flex-wrap gap-x-4 gap-y-1">
                        <?php foreach ($known as $type): ?>
                            <label class="flex items-center gap-1 text-sm">
                                <input type="checkbox" class="checkbox checkbox-sm" name="types[]" value="<?php echo $e($type); ?>"<?php echo in_array($type, $ticked, true) ? ' checked' : ''; ?>>
                                <?php echo $e($type); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <input class="input input-bordered input-sm w-full max-w-md mt-2" name="types_other" aria-label="<?php echo $e(t('Other task types')); ?>" placeholder="<?php echo $e(t('Other task types, comma-separated')); ?>">
                    <p class="text-xs text-base-content/60 mt-1"><?php echo $e($code
                        ? sprintf(t('None ticked keeps the types declared in code: %s.'), implode(', ', (array) ($pool['types'] ?? [])) ?: t('every type'))
                        : t('None ticked: the pool takes every type.')); ?></p>
                </fieldset>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($fields as $field => [$label, $help]): ?>
                        <?php
                        $declared = $field === 'load_percent'
                            ? (isset($config['load_ceiling']) ? (string) round((float) $config['load_ceiling'] * 100) : '')
                            : (string) ($config[$field] ?? '');
                        ?>
                        <div>
                            <label class="block text-sm font-semibold mb-1" for="<?php echo $id . '-' . $field; ?>"><?php echo $e($label); ?></label>
                            <input class="input input-bordered input-sm w-full" id="<?php echo $id . '-' . $field; ?>" name="<?php echo $e($field); ?>" inputmode="numeric"
                                   value="<?php echo $e($row[$field] ?? ''); ?>" placeholder="<?php echo $e($declared); ?>">
                            <p class="text-xs text-base-content/60 mt-1"><?php echo $e($help); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($code): ?>
                    <p class="text-xs text-base-content/60 mt-3"><?php echo $e(t('A blank field keeps the value declared in code, shown in grey.')); ?></p>
                <?php endif; ?>
                <div class="mt-4"><button type="submit" class="btn btn-primary btn-sm"><?php echo $e($adding ? t('Add pool') : t('Save')); ?></button></div>
            </form>
        </div>
    <?php endforeach; ?>
</div>
