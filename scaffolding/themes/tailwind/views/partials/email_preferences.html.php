<?php
/**
 * "Email" — what this account receives, and turning it on or off at the person's request.
 *
 * Inserted by the user's page. Data: `$this->emailPreferences` from
 * `UsersController::emailPreferencesFor()` — `all` (left everything) and `types`, each with
 * list, label, description, optIn and state (`on`/`off` for mail sent unless they say stop;
 * `off`, `pending`, `confirmed`, `unsubscribed` for an opt-in list).
 *
 * Turning an opt-in list on sends the confirmation mail: consent to marketing is the person's
 * to give, and confirming is how they give it.
 */
$_ep = $this->emailPreferences ?? null;
if (!is_array($_ep) || (empty($_ep['types']) && empty($_ep['all']))) {
    return;
}
$_e      = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$_action = adminUrl('users/emailpreference/') . (int) ($this->user['userid'] ?? 0);
$_button = static function (string $list, string $state, string $label, string $confirm = '') use ($_action, $_e): string {
    return '<form method="post" action="' . $_e($_action) . '" style="display:inline;margin:0">'
        . \Pramnos\Http\Session::getInstance()->getTokenField()
        . '<input type="hidden" name="list" value="' . $_e($list) . '"><input type="hidden" name="state" value="' . $state . '">'
        . '<button type="submit" class="btn btn-outline btn-xs"' . ($confirm !== '' ? ' data-confirm="' . $_e($confirm) . '"' : '') . '>' . $label . '</button></form>';
};
$_words = ['on' => 'On', 'off' => 'Off', 'pending' => 'Waiting to confirm', 'confirmed' => 'Subscribed', 'unsubscribed' => 'Left'];
?>
<div class="card bg-base-100 border border-base-300 shadow-xs mb-4" id="email-preferences">
    <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm">Email</div>
    <div class="p-5">
        <?php if (!empty($_ep['all'])): ?>
            <div role="status" class="alert alert-warning text-sm mb-4">
                This address left <strong>every</strong> optional mail. Nothing below reaches it until that is cleared.
                <?php echo $_button('all', 'on', 'Clear', 'Only at the person\'s own request. Clear their unsubscribe from everything?'); ?>
            </div>
        <?php endif; ?>
        <table class="table table-sm text-sm">
            <thead><tr><th>Mail</th><th>State</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($_ep['types'] as $_t): $_on = in_array($_t['state'], ['on', 'confirmed', 'pending'], true); ?>
                <tr>
                    <td><?php echo $_e($_t['label']); ?><?php if (!empty($_t['optIn'])): ?> <span class="text-xs text-base-content/60">opt-in list</span><?php endif; ?>
                        <div class="text-xs text-base-content/60"><?php echo $_e($_t['description']); ?></div></td>
                    <td><?php echo $_e($_words[$_t['state']] ?? $_t['state']); ?></td>
                    <td style="white-space:nowrap">
                        <?php if ($_on): ?>
                            <?php echo $_button($_t['list'], 'off', 'Turn off'); ?>
                        <?php elseif (!empty($_t['optIn'])): ?>
                            <?php echo $_button($_t['list'], 'on', 'Send confirmation', 'Only at the person\'s own request. Send them the confirmation mail?'); ?>
                        <?php else: ?>
                            <?php echo $_button($_t['list'], 'on', 'Turn on', 'Only at the person\'s own request. Turn this back on?'); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (empty($_ep['all'])): ?>
            <?php echo $_button('all', 'off', 'Unsubscribe from everything optional', 'Take this address off every optional mail?'); ?>
        <?php endif; ?>
    </div>
</div>
