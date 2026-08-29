<?php
/**
 * Compose a mass message (Bootstrap theme).
 *
 * Variables:
 *   $this->message      — the row being edited, empty for a new one
 *   $this->types        — channel number => label
 *   $this->criteria     — the stored audience criteria
 *   $this->options      — the stored send options (wrapper, list, tracking, action)
 *   $this->languages    — the languages accounts actually have
 *   $this->templates    — the mail wrappers this installation can render
 *   $this->tracking     — whether open/click tracking is switched on at all
 *   $this->audienceSize — how many accounts those criteria match, right now
 *   $this->groups       — groupid => name, for the picker
 *   $this->organizations— organization_id => name, for the picker
 *   $this->preview      — ['total' => int, 'sample' => rows, 'truncated' => int]
 *   $this->previewed    — true when this render came from pressing Preview
 *
 * The count is on the form on purpose. It is the one number that changes an operator's
 * mind, and it is the number nobody has when a send is a loop somebody wrote in a
 * controller: "this goes to 4,812 people" is a different decision from "this goes to the
 * users".
 *
 * Saving does not send. That is the other thing this form has to make obvious, because
 * every other editor in the administration area finishes the job when you press Save.
 */
$message  = is_array($this->message ?? null) ? $this->message : [];
$types    = is_array($this->types ?? null) ? $this->types : [];
$criteria = is_array($this->criteria ?? null) ? $this->criteria : [];
$size     = (int) ($this->audienceSize ?? 0);
$groups        = is_array($this->groups ?? null) ? $this->groups : [];
$organizations = is_array($this->organizations ?? null) ? $this->organizations : [];
$preview       = is_array($this->preview ?? null) ? $this->preview : ['total' => $size, 'sample' => [], 'truncated' => 0];
$previewed     = (bool) ($this->previewed ?? false);
$picked        = static function (array $criteria, string $key, $id): string {
    $chosen = $criteria[$key] ?? [];

    return in_array((int) $id, array_map('intval', is_array($chosen) ? $chosen : []), true)
        ? ' selected' : '';
};
$idList        = static function (array $criteria, string $key): string {
    $ids = $criteria[$key] ?? [];

    return implode(', ', array_map('intval', is_array($ids) ? $ids : []));
};
$options   = is_array($this->options ?? null) ? $this->options : [];
$languages = is_array($this->languages ?? null) ? $this->languages : [];
$templates = is_array($this->templates ?? null) ? $this->templates : [];
$tracking  = (bool) ($this->tracking ?? false);
$day       = static fn ($stamp): string => (int) $stamp > 0 ? date('Y-m-d', (int) $stamp) : '';
$id       = (int) ($message['messageid'] ?? 0);
$e        = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$checked  = static fn ($value): string => ($value ?? true) !== false ? ' checked' : '';
?>
<div class="container py-4">
    <?php $this->activeNav = 'massmessages'; $this->insert('../partials/admin_breadcrumb'); ?>

    <h2 class="text-lg font-semibold mb-4"><?php echo $id > 0 ? 'Edit mass message' : 'New mass message'; ?></h2>

    <form method="post" action="<?php echo adminUrl('MassMessages/save'); ?>" class="space-y-4 max-w-3xl">
        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
        <input type="hidden" name="messageid" value="<?php echo $id; ?>">

        <div class="card p-3 mb-3">
            <div>
                <label class="block text-sm font-medium mb-1" for="subject">Subject</label>
                <input type="text" name="subject" id="subject" class="form-control form-control-sm"
                       value="<?php echo $e($message['subject'] ?? ''); ?>" required>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" for="type">Channel</label>
                <select name="type" id="type" class="form-select form-select-sm">
                    <?php foreach ($types as $value => $label): ?>
                        <option value="<?php echo (int) $value; ?>"
                            <?php echo (int) ($message['type'] ?? 0) === (int) $value ? 'selected' : ''; ?>>
                            <?php echo $e($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-muted mt-1">
                    Email goes out through this installation's wrapper, in each recipient's own
                    language. An internal message lands in the account's inbox. Push reaches every
                    browser that subscribed — and an account with none is recorded as
                    <em>failed</em>, because most accounts have never granted permission and
                    counting those as delivered would report a send of thousands about a
                    message that reached dozens.
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" for="message">Body</label>
                <textarea name="message" id="message" rows="12" class="form-control form-control-sm font-monospace"><?php echo $e($message['message'] ?? ''); ?></textarea>
                <p class="text-xs text-muted mt-1">
                    Markup, kept as written — it is the body of a message. It is escaped
                    wherever this screen displays it.
                </p>
            </div>
        </div>

        <div class="card p-3 mb-3">
            <div class="d-flex align-items-baseline gap-3">
                <h3 class="font-medium">Audience</h3>
                <span class="badge bg-primary"><?php echo number_format($size); ?> account(s)</span>
            </div>

            <div class="row g-3">
                <div class="col-sm-6">
                    <label class="block text-sm font-medium mb-1" for="usertype_max">Usertype ceiling</label>
                    <input type="number" name="usertype_max" id="usertype_max" min="0" max="99"
                           class="form-control form-control-sm"
                           value="<?php echo (int) ($criteria['usertype_max'] ?? 0); ?>">
                    <p class="text-xs text-muted mt-1">
                        0 for no ceiling. With a floor alone, "everybody below staff" can only be
                        written as "everybody" — which also reaches the operators.
                    </p>
                </div>

                <div class="col-sm-6">
                    <label class="block text-sm font-medium mb-1" for="language">Language</label>
                    <select name="language" id="language" class="form-select form-select-sm">
                        <option value="">Any</option>
                        <?php foreach ($languages as $language): ?>
                            <option value="<?php echo $e($language); ?>"
                                <?php echo ($criteria['language'] ?? '') === $language ? 'selected' : ''; ?>>
                                <?php echo $e($language); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-muted mt-1">
                        The account's language, not yours. One message per language, each to its
                        own audience, is the only honest way to write this.
                    </p>
                </div>

                <div class="col-sm-6">
                    <label class="block text-sm font-medium mb-1" for="twofactor">Second factor</label>
                    <select name="twofactor" id="twofactor" class="form-select form-select-sm">
                        <option value="">Any</option>
                        <option value="with" <?php echo ($criteria['twofactor'] ?? '') === 'with' ? 'selected' : ''; ?>>Holds one</option>
                        <option value="without" <?php echo ($criteria['twofactor'] ?? '') === 'without' ? 'selected' : ''; ?>>Does not</option>
                    </select>
                </div>

                <div class="col-sm-6">
                    <label class="block text-sm font-medium mb-1" for="last_login_after">Signed in after</label>
                    <input type="date" name="last_login_after" id="last_login_after" class="form-control form-control-sm"
                           value="<?php echo $e($day($criteria['last_login_after'] ?? 0)); ?>">
                </div>

                <div class="col-sm-6">
                    <label class="block text-sm font-medium mb-1" for="last_login_before">Not signed in since</label>
                    <input type="date" name="last_login_before" id="last_login_before" class="form-control form-control-sm"
                           value="<?php echo $e($day($criteria['last_login_before'] ?? 0)); ?>">
                    <p class="text-xs text-muted mt-1">
                        The dormant audience. An account that never signed in at all is in it —
                        that is the most dormant an account can be.
                    </p>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" for="usertype_min">Usertype floor</label>
                <input type="number" name="usertype_min" id="usertype_min" min="0" max="99"
                       class="form-control form-control-sm" style="width:8rem"
                       value="<?php echo (int) ($criteria['usertype_min'] ?? 0); ?>">
                <p class="text-xs text-muted mt-1">
                    A usertype is a threshold, so 0 means every account and 90 means
                    administrators and above.
                </p>
            </div>

            <div class="row g-3">
                <?php if ($groups !== []): ?>
                <div class="col-md-6">
                    <label class="block text-sm font-medium mb-1" for="groups">Groups</label>
                    <select name="groups[]" id="groups" multiple size="5" class="form-select form-select-sm">
                        <?php foreach ($groups as $groupId => $name): ?>
                            <option value="<?php echo (int) $groupId; ?>"<?php echo $picked($criteria, 'groups', $groupId); ?>>
                                <?php echo $e($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-muted mt-1">
                        Accounts in <em>any</em> of the chosen groups. Nothing chosen means
                        the filter is not applied at all.
                    </p>
                </div>
                <?php endif; ?>

                <?php if ($organizations !== []): ?>
                <div class="col-md-6">
                    <label class="block text-sm font-medium mb-1" for="organizations">Organizations</label>
                    <select name="organizations[]" id="organizations" multiple size="5" class="form-select form-select-sm">
                        <?php foreach ($organizations as $orgId => $name): ?>
                            <option value="<?php echo (int) $orgId; ?>"<?php echo $picked($criteria, 'organizations', $orgId); ?>>
                                <?php echo $e($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-muted mt-1">
                        Accounts belonging to <em>any</em> of them.
                    </p>
                </div>
                <?php endif; ?>

                <div class="col-md-6">
                    <label class="block text-sm font-medium mb-1" for="only_ids">Only these accounts</label>
                    <textarea name="only_ids" id="only_ids" rows="2" class="form-control form-control-sm font-monospace"
                              placeholder="42, 108, 1904"><?php echo $e($idList($criteria, 'only_ids')); ?></textarea>
                    <p class="text-xs text-muted mt-1">
                        User ids, however you have them — commas, spaces or one per line. Filled
                        in, nobody else is reached. The filters above <em>still</em> apply, so an
                        account named here that is inactive or unsubscribed is not sent to, and
                        the preview shows you that.
                    </p>
                </div>

                <div class="col-md-6">
                    <label class="block text-sm font-medium mb-1" for="exclude_ids">Except these accounts</label>
                    <textarea name="exclude_ids" id="exclude_ids" rows="2" class="form-control form-control-sm font-monospace"
                              placeholder="7, 19"><?php echo $e($idList($criteria, 'exclude_ids')); ?></textarea>
                    <p class="text-xs text-muted mt-1">
                        Removed from whatever the rest of this matched.
                    </p>
                </div>
            </div>

            <label class="form-check">
                <input type="checkbox" name="validated_only" value="1" class="form-check-input"
                    <?php echo $checked($criteria['validated_only'] ?? null); ?>>
                Validated accounts only
            </label>

            <label class="form-check">
                <input type="checkbox" name="active_only" value="1" class="form-check-input"
                    <?php echo $checked($criteria['active_only'] ?? null); ?>>
                Active accounts only
            </label>

            <div>
                <label class="block text-sm font-medium mb-1" for="exclude_optouts">Exclude anyone who unsubscribed from</label>
                <input type="text" name="exclude_optouts" id="exclude_optouts" class="form-control form-control-sm"
                       placeholder="massmessages"
                       value="<?php echo $e((string) ($criteria['exclude_optouts'] ?? '')); ?>">
                <p class="text-xs text-muted mt-1">
                    They are skipped at delivery either way. Naming the list here is what makes
                    the <strong>count</strong> honest — and the count is the number that decides
                    whether the send happens at all.
                </p>
            </div>

            <p class="text-xs text-muted">
                The count above is what these criteria match now. The audience is resolved
                once, when the message is queued — so it cannot quietly grow to include
                accounts created after somebody approved the send.
            </p>

            <div style="padding-top:8px;border-top:1px solid rgba(128,128,128,.25)">
                <button type="submit" formaction="<?php echo adminUrl('MassMessages/preview'); ?>" formnovalidate class="btn btn-sm btn-outline-secondary">
                    Preview this audience
                </button>
                <span class="text-xs text-muted ms-2">
                    Nothing is saved and nothing is sent — it resolves the filters and shows you
                    who they mean.
                </span>
            </div>

            <?php if ($previewed): ?>
                <div class="border rounded bg-body-tertiary p-3">
                    <?php if ((int) $preview['total'] === 0): ?>
                        <p class="text-sm">
                            <strong>Nobody.</strong> These filters match no account — narrowed
                            past the point where anybody is left, which is the useful thing to
                            find out here rather than after pressing send.
                        </p>
                    <?php else: ?>
                        <p class="text-sm mb-2">
                            <strong><?php echo number_format((int) $preview['total']); ?></strong>
                            account(s).
                            <?php if ((int) $preview['truncated'] > 0): ?>
                                The first <?php echo count($preview['sample']); ?> are listed;
                                <?php echo number_format((int) $preview['truncated']); ?> more are not.
                            <?php endif; ?>
                        </p>
                        <div style="overflow-x:auto">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>ID</th><th>Username</th><th>Email</th>
                                        <th>Usertype</th><th>Language</th><th>Last signed in</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($preview['sample'] as $row): ?>
                                        <tr>
                                            <td><?php echo (int) $row['userid']; ?></td>
                                            <td><?php echo $e($row['username']); ?></td>
                                            <td><?php echo $e($row['email']); ?></td>
                                            <td><?php echo (int) $row['usertype']; ?></td>
                                            <td><?php echo $e($row['language']); ?></td>
                                            <td><?php echo (int) $row['lastlogin'] > 0
                                                ? date('d/m/Y', (int) $row['lastlogin']) : '—'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card p-3 mb-3">
            <h3 class="fw-medium">Message options</h3>

            <div>
                <label class="block text-sm font-medium mb-1" for="preheader">Preheader</label>
                <input type="text" name="preheader" id="preheader" class="form-control form-control-sm" maxlength="120"
                       value="<?php echo $e((string) ($options['preheader'] ?? '')); ?>">
                <p class="text-xs text-muted mt-1">
                    The line the inbox shows beside the subject. Left empty it is taken from the
                    message's own opening — which beats the wrapper's, but is not a sentence
                    written for the inbox.
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" for="link">Link</label>
                <input type="url" name="link" id="link" class="form-control form-control-sm" placeholder="https://"
                       value="<?php echo $e((string) ($options['link'] ?? '')); ?>">
                <p class="text-xs text-muted mt-1">Where a push notification opens.</p>
            </div>

            <div class="row g-3">
                <div class="col-sm-6">
                    <label class="block text-sm font-medium mb-1" for="template">Wrapper</label>
                    <?php $chosen = array_key_exists('template', $options) ? (string) $options['template'] : '__default__'; ?>
                    <select name="template" id="template" class="form-select form-select-sm">
                        <option value="__default__" <?php echo $chosen === '__default__' ? 'selected' : ''; ?>>The installation's default</option>
                        <option value="" <?php echo $chosen === '' ? 'selected' : ''; ?>>None — send the body bare</option>
                        <?php foreach ($templates as $template): ?>
                            <option value="<?php echo $e($template); ?>" <?php echo $chosen === $template ? 'selected' : ''; ?>>
                                <?php echo $e($template); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-sm-6">
                    <label class="block text-sm font-medium mb-1" for="list">Unsubscribe list</label>
                    <input type="text" name="list" id="list" class="form-control form-control-sm" placeholder="massmessages"
                           value="<?php echo $e((string) ($options['list'] ?? '')); ?>">
                    <p class="text-xs text-muted mt-1">
                        What a reader is unsubscribing <em>from</em>. Empty means the shared
                        `massmessages` list — one button, no more announcements.
                    </p>
                </div>
            </div>

            <label class="form-check">
                <input type="checkbox" name="tracking" value="1" class="form-check-input"
                       <?php echo $tracking ? '' : 'disabled'; ?>
                       <?php echo !empty($options['tracking']) ? 'checked' : ''; ?>>
                <span>
                    Track opens and clicks
                    <span class="text-xs text-muted">
                        <?php if ($tracking): ?>
                            One tracking id per recipient, so the numbers mean something. Opens are
                            inflated by mail clients that prefetch images; clicks are people.
                        <?php else: ?>
                            Switched off for this installation.
                        <?php endif; ?>
                    </span>
                </span>
            </label>

            <div>
                <label class="block text-sm font-medium mb-1">Gmail action</label>
                <div class="row g-3">
                    <div class="col-sm-4">
                        <select name="action_type" class="form-select form-select-sm">
                            <?php foreach (['' => 'No action', 'view' => 'View', 'confirm' => 'Confirm', 'save' => 'Save'] as $value => $label): ?>
                                <option value="<?php echo $e($value); ?>"
                                    <?php echo (string) ($options['action_type'] ?? '') === $value ? 'selected' : ''; ?>>
                                    <?php echo $e($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-4">
                        <input type="text" name="action_name" class="form-control form-control-sm" placeholder="Button text" maxlength="60"
                               value="<?php echo $e((string) ($options['action_name'] ?? '')); ?>">
                    </div>
                    <div class="col-sm-4">
                        <input type="url" name="action_url" class="form-control form-control-sm" placeholder="https://"
                               value="<?php echo $e((string) ($options['action_url'] ?? '')); ?>">
                    </div>
                </div>
                <p class="text-xs text-muted mt-1">
                    The same URL for everybody, so this is for a page rather than for anything
                    carrying a per-recipient token. <strong>Confirm</strong> must act on the first
                    request, with no page and no login — Gmail's server follows it.
                </p>
            </div>
        </div>

        <div class="card p-3 mb-3">
            <label class="block text-sm font-medium mb-1" for="scheduled">Send at</label>
            <input type="datetime-local" name="scheduled" id="scheduled" class="form-control form-control-sm"
                   value="<?php
                   $scheduled = (int) ($message['scheduled'] ?? 0);
                   echo $scheduled > 0 ? date('Y-m-d\TH:i', $scheduled) : '';
                   ?>">
            <p class="text-xs text-muted mt-1">
                Leave empty to send as soon as it is queued. A time in the future holds the
                message until then; nothing else about the two paths differs.
            </p>
        </div>

        <div class="d-flex align-items-center gap-3">
            <button type="submit" class="btn btn-primary btn-sm">Save</button>
            <a href="<?php echo adminUrl('MassMessages'); ?>" class="btn btn-link btn-sm">Cancel</a>
            <span class="text-xs text-muted">
                Saving does not send anything. Queueing is a separate, deliberate step on the
                message's own page.
            </span>
        </div>
    </form>
</div>
