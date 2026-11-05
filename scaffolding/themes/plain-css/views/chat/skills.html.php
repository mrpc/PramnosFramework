<?php
/**
 * Skills (plain CSS theme) — `chat/skills`: this person's own skills (Pramnos\Llm\Chat\StoredSkills),
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
 *   $this->roleChoices — usertype => label, the roles a skill is for, from that one up
 *   $this->urls        — page, action (with %s for the action's name), chat
 */
$c      = ['page' => 'container', 'card' => 'card card-body', 'muted' => 'pf-muted', 'bad' => 'pf-bad', 'btn' => 'btn btn-primary btn-sm', 'btnGhost' => 'btn btn-outline-secondary btn-sm', 'danger' => 'btn btn-outline-secondary btn-sm', 'input' => 'form-control pf-ui-input-sm', 'select' => 'form-control pf-ui-input-sm', 'textarea' => 'form-control', 'badge' => 'badge', 'row' => 'pf-ui-row', 'field' => 'small', 'answer' => '', 'h1' => '', 'h2' => ''];
$e      = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t      = static fn (string $words, mixed ...$args): string => vsprintf(function_exists('t') ? (string) t($words) : $words, $args);
$token  = static fn (): string => \Pramnos\Http\Session::getInstance()->getTokenField();
$u      = (is_array($this->urls ?? null) ? $this->urls : []) + ['page' => sURL . 'chat/skills', 'action' => sURL . 'chat/%s', 'chat' => sURL . 'chat'];
$act    = static fn (string $action): string => str_replace('%s', $action, (string) $u['action']);
$roles  = (array) ($this->roleChoices ?? []);
$open   = is_array($this->skill ?? null) ? $this->skill : null;
$states = ['on' => 'On', 'off' => 'Off', 'draft' => 'Draft', 'pending' => 'Waiting for approval'];
$roleOf = static fn (?string $role): string => $role === null ? '' : (ctype_digit($role) && isset($roles[(int) $role]) ? $t($roles[(int) $role]) : $role);
/** One skill's card: what it is, its state, and what may be done with it. */
$card   = static function (array $skill, bool $offer, bool $decide) use ($c, $e, $t, $token, $act, $u, $roles, $states, $roleOf): string {
    $id   = (int) $skill['id'];
    $html = '<div class="' . $c['card'] . '" data-pf-skill="' . $id . '"><div class="' . $c['row'] . '"><strong>' . $e($skill['name']) . '</strong>'
        . ' <span class="' . $c['badge'] . '">' . $e($t($states[$skill['status']] ?? $skill['status'])) . '</span>'
        . ($skill['role'] !== null ? ' <span class="' . $c['muted'] . '">' . $e($t('For %s and up', $roleOf($skill['role']))) . '</span>' : '')
        . '</div><p class="' . $c['muted'] . '">' . $e($skill['description']) . '</p>'
        . ($skill['tools'] !== [] ? '<p class="' . $c['muted'] . '">' . $e($t('Tools: %s', implode(', ', $skill['tools']))) . '</p>' : '')
        . '<details><summary>' . $e($t('The method')) . '</summary><pre style="white-space:pre-wrap">' . $e($skill['body']) . '</pre></details>'
        . '<div class="' . $c['row'] . '">';
    if ($decide) {
        $html .= '<form method="post" action="' . $e($act('skillapprove')) . '">' . $token() . '<input type="hidden" name="skill_id" value="' . $id . '"><button class="' . $c['btn'] . '">' . $e($t('Approve')) . '</button></form>'
            . '<form method="post" action="' . $e($act('skillreject')) . '">' . $token() . '<input type="hidden" name="skill_id" value="' . $id . '"><button class="' . $c['danger'] . '">' . $e($t('Turn down')) . '</button></form>';
    } elseif ($skill['status'] !== 'pending') {
        $on    = $skill['status'] === 'on';
        $html .= '<form method="post" action="' . $e($act('skillturn')) . '">' . $token() . '<input type="hidden" name="skill_id" value="' . $id . '">'
            . ($on ? '' : '<input type="hidden" name="on" value="1">')
            . '<button class="' . ($on ? $c['btnGhost'] : $c['btn']) . '">' . $e($t($on ? 'Turn off' : ($skill['status'] === 'draft' ? 'Keep and turn on' : 'Turn on'))) . '</button></form>'
            . '<a class="' . $c['btnGhost'] . '" href="' . $e($u['page'] . '?skill=' . $id) . '">' . $e($t('Edit')) . '</a>';
    }
    if ($offer && $roles !== []) {
        $html .= '<form method="post" action="' . $e($act('skillshare')) . '" class="' . $c['row'] . '">' . $token() . '<input type="hidden" name="skill_id" value="' . $id . '">'
            . '<select name="role" class="' . $c['select'] . '" aria-label="' . $e($t('Offer to a role')) . '">';
        foreach ($roles as $level => $label) {
            $html .= '<option value="' . (int) $level . '">' . $e($t($label)) . '</option>';
        }
        $html .= '</select><button class="' . $c['btnGhost'] . '">' . $e($t('Offer to a role')) . '</button></form>';
    }
    $html .= '<form method="post" action="' . $e($act('skilldelete')) . '">' . $token() . '<input type="hidden" name="skill_id" value="' . $id . '"><button class="' . $c['danger'] . '">' . $e($t('Delete')) . '</button></form>';

    return $html . '</div></div>';
};
?>
<div class="<?php echo $c['page']; ?>" data-pf-skills>
    <div class="<?php echo $c['row']; ?>">
        <h1 class="<?php echo $c['h1']; ?>"><?php echo $e($t('My skills')); ?></h1>
        <a href="<?php echo $e($u['chat']); ?>" class="<?php echo $c['btnGhost']; ?>"><?php echo $e($t('Back to the conversations')); ?></a>
    </div>
<?php if (empty($this->enabled)): ?>
    <p class="<?php echo $c['muted']; ?>"><?php echo $e($t('Skills are not turned on here.')); ?></p>
<?php else: ?>
    <p class="<?php echo $c['muted']; ?>"><?php echo $e($t('A skill is a way of working Ask is handed when a question needs it. It opens nothing you may not already use.')); ?></p>

    <form method="post" action="<?php echo $e($act('skillsave')); ?>" class="<?php echo $c['card']; ?>" data-pf-skill-form>
        <?php echo $token(); ?>
        <?php if ($open !== null): ?><input type="hidden" name="skill_id" value="<?php echo (int) $open['id']; ?>"><?php endif; ?>
        <h2 class="<?php echo $c['h2']; ?>"><?php echo $e($t($open !== null ? 'Edit a skill' : 'A new skill')); ?></h2>
        <label class="<?php echo $c['field']; ?>"><?php echo $e($t('Name')); ?>
            <input name="name" required maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9_.\-]*" class="<?php echo $c['input']; ?>" value="<?php echo $e($open['name'] ?? ''); ?>" placeholder="weekly-sales"></label>
        <label class="<?php echo $c['field']; ?>"><?php echo $e($t('What it is for')); ?>
            <input name="description" required maxlength="300" class="<?php echo $c['input']; ?>" value="<?php echo $e($open['description'] ?? ''); ?>"></label>
        <label class="<?php echo $c['field']; ?>"><?php echo $e($t('Tools it uses, by name')); ?>
            <input name="tools" class="<?php echo $c['input']; ?>" value="<?php echo $e(implode(', ', $open['tools'] ?? [])); ?>"></label>
        <label class="<?php echo $c['field']; ?>"><?php echo $e($t('The method')); ?>
            <textarea name="body" required rows="8" class="<?php echo $c['textarea']; ?>"><?php echo $e($open['body'] ?? ''); ?></textarea></label>
        <?php if (!empty($this->manage) && ($open === null || $open['level'] === 'role')): ?>
        <label class="<?php echo $c['field']; ?>"><?php echo $e($t('For')); ?>
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
