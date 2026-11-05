<?php
/**
 * Skills (Tailwind theme) — `chat/skills`: this person's own skills (Pramnos\Llm\Chat\StoredSkills),
 * the drafts Ask proposed from a conversation, the skills they offered to a role, and — for those who
 * manage them — every role's skill and the offers waiting for approval. With `?skill=`, one open to edit.
 *
 * Variables:
 *   $this->enabled     — whether the application turned stored skills on
 *   $this->mine        — this person's own: id, name, description, tools (list), body, status (on|off|draft)
 *   $this->offered     — the ones they offered to a role: the same, with role, status pending or on
 *   $this->roles       — every role's skill, pending first (empty unless $this->manage)
 *   $this->manage      — whether this person writes a role's skills and approves offers
 *   $this->skill       — the one open to edit, or null
 *   $this->roleChoices — usertype => label: the roles that reach this Ask, a skill is for from one up
 *   $this->tools       — name => what it does: the tools this person's Ask has, to choose from
 *   $this->urls        — page, action (with %s for the action's name), chat
 */
$c      = ['page' => 'max-w-4xl mx-auto px-4 py-6 flex flex-col gap-4', 'card' => 'card bg-base-100 border border-base-300 p-4', 'muted' => 'text-sm opacity-70', 'bad' => 'text-sm text-error', 'btn' => 'btn btn-sm btn-primary', 'btnGhost' => 'btn btn-sm btn-ghost', 'danger' => 'btn btn-sm btn-ghost text-error', 'input' => 'input input-sm input-bordered', 'select' => 'select select-sm select-bordered', 'textarea' => 'textarea textarea-bordered w-full', 'badge' => 'badge badge-ghost badge-sm', 'row' => 'flex flex-wrap items-center gap-2', 'field' => 'flex flex-col gap-1 text-sm', 'answer' => 'prose max-w-none', 'h1' => 'text-2xl font-semibold', 'h2' => 'text-lg font-semibold'];
$e      = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t      = static fn (string $words, mixed ...$args): string => vsprintf(function_exists('t') ? (string) t($words) : $words, $args);
$token  = static fn (): string => \Pramnos\Http\Session::getInstance()->getTokenField();
$u      = (is_array($this->urls ?? null) ? $this->urls : []) + ['page' => sURL . 'chat/skills', 'action' => sURL . 'chat/%s', 'chat' => sURL . 'chat'];
$act    = static fn (string $action): string => str_replace('%s', $action, (string) $u['action']);
$roles  = (array) ($this->roleChoices ?? []);
$tools  = (array) ($this->tools ?? []);
$open   = is_array($this->skill ?? null) ? $this->skill : null;
$states = ['on' => 'On', 'off' => 'Off', 'draft' => 'Draft', 'pending' => 'Waiting for approval'];
$roleOf = static fn (?string $role): string => $role === null ? '' : (ctype_digit($role) && isset($roles[(int) $role]) ? $t($roles[(int) $role]) : $role);
$grid   = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(16rem,1fr));gap:12px';
$field  = 'display:flex;flex-direction:column;gap:6px';
$full   = 'width:100%;max-width:none';
$split  = 'display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-start;gap:12px';
$post   = static fn (string $action, int $id, string $label, string $class, string $extra = ''): string =>
    '<form method="post" action="' . $e($act($action)) . '" style="display:inline">' . $token() . '<input type="hidden" name="skill_id" value="' . $id . '">' . $extra
    . '<button class="' . $class . '">' . $e($label) . '</button></form>';
/** One skill: what it is and its state on the left, what may be done with it on the right, its method under. */
$card   = static function (array $skill, bool $offer, bool $decide) use ($c, $e, $t, $token, $act, $u, $roles, $states, $roleOf, $split, $post): string {
    $id      = (int) $skill['id'];
    $actions = '';
    if ($decide) {
        $actions .= $post('skillapprove', $id, $t('Approve'), $c['btn']) . $post('skillreject', $id, $t('Turn down'), $c['danger']);
    } elseif ($skill['status'] !== 'pending') {
        $on       = $skill['status'] === 'on';
        $actions .= $post('skillturn', $id, $t($on ? 'Turn off' : ($skill['status'] === 'draft' ? 'Keep and turn on' : 'Turn on')), $on ? $c['btnGhost'] : $c['btn'], $on ? '' : '<input type="hidden" name="on" value="1">')
            . '<a class="' . $c['btnGhost'] . '" href="' . $e($u['page'] . '?skill=' . $id) . '">' . $e($t('Edit')) . '</a>';
    }
    if ($offer && $roles !== []) {
        $actions .= '<form method="post" action="' . $e($act('skillshare')) . '" style="display:inline-flex;gap:6px">' . $token() . '<input type="hidden" name="skill_id" value="' . $id . '">'
            . '<select name="role" class="' . $c['select'] . '" aria-label="' . $e($t('Offer to a role')) . '">';
        foreach ($roles as $level => $label) {
            $actions .= '<option value="' . (int) $level . '">' . $e($t($label)) . '</option>';
        }
        $actions .= '</select><button class="' . $c['btnGhost'] . '">' . $e($t('Offer to a role')) . '</button></form>';
    }
    $actions .= $post('skilldelete', $id, $t('Delete'), $c['danger']);

    return '<div class="' . $c['card'] . '" data-pf-skill="' . $id . '" data-pf-skill-status="' . $e($skill['status']) . '">'
        . '<div style="' . $split . '"><div style="min-width:0;flex:1 1 16rem">'
        . '<div class="' . $c['row'] . '"><strong>' . $e($skill['name']) . '</strong> <span class="' . $c['badge'] . '">' . $e($t($states[$skill['status']] ?? $skill['status'])) . '</span>'
        . ($skill['role'] !== null ? ' <span class="' . $c['muted'] . '">' . $e($t('For %s and up', $roleOf($skill['role']))) . '</span>' : '') . '</div>'
        . '<div class="' . $c['muted'] . '">' . $e($skill['description']) . '</div>'
        . ($skill['tools'] !== [] ? '<div class="' . $c['muted'] . '">' . $e($t('Tools: %s', implode(', ', $skill['tools']))) . '</div>' : '')
        . '</div><div class="' . $c['row'] . '" style="justify-content:flex-end">' . $actions . '</div></div>'
        . '<details style="margin-top:8px"><summary class="' . $c['muted'] . '">' . $e($t('The method')) . '</summary><pre style="white-space:pre-wrap;margin:8px 0 0">' . $e($skill['body']) . '</pre></details>'
        . '</div>';
};
$chosen = array_flip($open['tools'] ?? []);
?>
<div class="<?php echo $c['page']; ?>" data-pf-skills>
    <div style="<?php echo $split; ?>;align-items:center">
        <h1 class="<?php echo $c['h1']; ?>" style="margin:0"><?php echo $e($t('My skills')); ?></h1>
        <a href="<?php echo $e($u['chat']); ?>" class="<?php echo $c['btnGhost']; ?>">&larr; <?php echo $e($t('Back to the conversations')); ?></a>
    </div>
<?php if (empty($this->enabled)): ?>
    <p class="<?php echo $c['muted']; ?>"><?php echo $e($t('Skills are not turned on here.')); ?></p>
<?php else: ?>
    <p class="<?php echo $c['muted']; ?>"><?php echo $e($t('A skill is a way of working Ask is handed when a question needs it. It opens nothing you may not already use.')); ?></p>

    <details class="<?php echo $c['card']; ?>"<?php echo $open !== null || empty($this->mine) ? ' open' : ''; ?> data-pf-skill-editor>
        <summary class="<?php echo $c['btn']; ?>" style="display:inline-block;cursor:pointer;list-style:none"><?php echo $e($t($open !== null ? 'Edit a skill' : 'A new skill')); ?></summary>
        <form method="post" action="<?php echo $e($act('skillsave')); ?>" data-pf-skill-form style="display:flex;flex-direction:column;gap:16px;margin-top:16px">
            <?php echo $token(); ?>
            <?php if ($open !== null): ?><input type="hidden" name="skill_id" value="<?php echo (int) $open['id']; ?>"><?php endif; ?>
            <div style="<?php echo $grid; ?>">
                <label style="<?php echo $field; ?>"><span><?php echo $e($t('Name')); ?></span>
                    <input name="name" required maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9_.\-]*" class="<?php echo $c['input']; ?>" style="<?php echo $full; ?>" value="<?php echo $e($open['name'] ?? ''); ?>" placeholder="weekly-sales">
                    <small class="<?php echo $c['muted']; ?>"><?php echo $e($t('Lowercase Latin letters, digits and dashes.')); ?></small></label>
                <label style="<?php echo $field; ?>"><span><?php echo $e($t('What it is for')); ?></span>
                    <input name="description" required maxlength="300" class="<?php echo $c['input']; ?>" style="<?php echo $full; ?>" value="<?php echo $e($open['description'] ?? ''); ?>" placeholder="<?php echo $e($t('The weekly sales figures, by market')); ?>">
                    <small class="<?php echo $c['muted']; ?>"><?php echo $e($t('One line. Ask chooses the skill by it.')); ?></small></label>
            </div>
            <fieldset style="<?php echo $field; ?>;border:0;padding:0;margin:0">
                <legend style="margin-bottom:6px"><?php echo $e($t('Tools it uses')); ?></legend>
                <?php if ($tools === []): ?>
                    <input name="tools" class="<?php echo $c['input']; ?>" style="<?php echo $full; ?>" value="<?php echo $e(implode(', ', $open['tools'] ?? [])); ?>">
                    <small class="<?php echo $c['muted']; ?>"><?php echo $e($t('Their names, separated by commas.')); ?></small>
                <?php else: ?>
                    <input type="search" class="<?php echo $c['input']; ?>" style="<?php echo $full; ?>" placeholder="<?php echo $e($t('Find a tool')); ?>" aria-label="<?php echo $e($t('Find a tool')); ?>" data-pf-skill-tool-filter>
                    <div style="max-height:16rem;overflow:auto;display:grid;grid-template-columns:repeat(auto-fill,minmax(14rem,1fr));gap:4px 12px;padding:4px 0" data-pf-skill-tools>
                        <?php foreach ($tools as $name => $does): ?>
                        <label title="<?php echo $e($does); ?>" style="display:flex;gap:6px;align-items:flex-start" data-pf-skill-tool="<?php echo $e(mb_strtolower($name . ' ' . $does)); ?>">
                            <input type="checkbox" name="tools[]" value="<?php echo $e($name); ?>"<?php echo isset($chosen[$name]) ? ' checked' : ''; ?>>
                            <span><code><?php echo $e($name); ?></code></span></label>
                        <?php endforeach; ?>
                        <?php foreach (array_diff(array_keys($chosen), array_keys($tools)) as $name): ?>
                        <input type="hidden" name="tools[]" value="<?php echo $e($name); ?>">
                        <?php endforeach; ?>
                    </div>
                    <small class="<?php echo $c['muted']; ?>"><?php echo $e($t('Only the tools you may use: a skill opens nothing more.')); ?></small>
                    <script nonce="<?php echo $e(defined('PAGE_NONCE') ? PAGE_NONCE : ''); ?>">
                    (function () {
                        var find = document.querySelector('[data-pf-skill-tool-filter]');
                        if (!find) { return; }
                        find.addEventListener('input', function () {
                            var q = find.value.trim().toLowerCase();
                            document.querySelectorAll('[data-pf-skill-tool]').forEach(function (row) {
                                row.hidden = q !== '' && row.getAttribute('data-pf-skill-tool').indexOf(q) === -1;
                            });
                        });
                    })();
                    </script>
                <?php endif; ?>
            </fieldset>
            <label style="<?php echo $field; ?>"><span><?php echo $e($t('The method')); ?></span>
                <textarea name="body" required rows="14" class="<?php echo $c['textarea']; ?>" style="<?php echo $full; ?>;font-family:ui-monospace,monospace" placeholder="<?php echo $e($t("1. Start from last week's figures.\n2. Compare them with the same week last year.\n3. Say what changed most, and why it may have.")); ?>"><?php echo $e($open['body'] ?? ''); ?></textarea>
                <small class="<?php echo $c['muted']; ?>"><?php echo $e($t('Markdown. Step by step, as you would want to be told it.')); ?></small></label>
            <?php if (!empty($this->manage) && ($open === null || $open['level'] === 'role')): ?>
            <label style="<?php echo $field; ?>"><span><?php echo $e($t('For')); ?></span>
                <select name="for" class="<?php echo $c['select']; ?>">
                    <option value="user"><?php echo $e($t('Me')); ?></option>
                    <?php foreach ($roles as $level => $label): ?>
                    <option value="role:<?php echo (int) $level; ?>"<?php echo ($open['role'] ?? null) === (string) $level ? ' selected' : ''; ?>><?php echo $e($t('%s and up', $t($label))); ?></option>
                    <?php endforeach; ?>
                </select></label>
            <?php endif; ?>
            <div class="<?php echo $c['row']; ?>">
                <button class="<?php echo $c['btn']; ?>"><?php echo $e($t('Save')); ?></button>
                <?php if ($open !== null): ?><a class="<?php echo $c['btnGhost']; ?>" href="<?php echo $e($u['page']); ?>"><?php echo $e($t('Cancel')); ?></a><?php endif; ?>
            </div>
        </form>
    </details>

    <h2 class="<?php echo $c['h2']; ?>"><?php echo $e($t('Your own skills')); ?></h2>
    <?php if (empty($this->mine)): ?><p class="<?php echo $c['muted']; ?>"><?php echo $e($t('None yet. Write one above, or ask Ask to keep a way of working as a skill.')); ?></p><?php endif; ?>
    <?php foreach ((array) ($this->mine ?? []) as $skill) { echo $card($skill, true, false); } ?>

    <?php if (!empty($this->offered)): ?>
    <h2 class="<?php echo $c['h2']; ?>"><?php echo $e($t('Offered to a role')); ?></h2>
    <?php foreach ((array) $this->offered as $skill) { echo $card($skill, false, false); } ?>
    <?php endif; ?>

    <?php if (!empty($this->manage)): ?>
    <h2 class="<?php echo $c['h2']; ?>"><?php echo $e($t('Roles\' skills')); ?></h2>
    <?php if (empty($this->roles)): ?><p class="<?php echo $c['muted']; ?>"><?php echo $e($t('No role skills yet.')); ?></p><?php endif; ?>
    <?php foreach ((array) ($this->roles ?? []) as $skill) { echo $card($skill, false, $skill['status'] === 'pending'); } ?>
    <?php endif; ?>
<?php endif; ?>
</div>
