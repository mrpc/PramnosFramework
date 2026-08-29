<?php
/**
 * Compose a mass message (plain-css theme).
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
<div style="padding:24px 16px">
    <?php $this->activeNav = 'massmessages'; $this->insert('../partials/admin_breadcrumb'); ?>

    <h2 class="text-lg font-semibold mb-4"><?php echo $id > 0 ? 'Edit mass message' : 'New mass message'; ?></h2>

    <form method="post" action="<?php echo adminUrl('MassMessages/save'); ?>" class="space-y-4 max-w-3xl">
        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
        <input type="hidden" name="messageid" value="<?php echo $id; ?>">

        <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:16px">
            <div>
                <label class="block text-sm font-medium mb-1" for="subject">Subject</label>
                <input type="text" name="subject" id="subject" style="width:100%;padding:6px 8px"
                       value="<?php echo $e($message['subject'] ?? ''); ?>" required>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" for="type">Channel</label>
                <select name="type" id="type" style="width:100%;padding:6px 8px">
                    <?php foreach ($types as $value => $label): ?>
                        <option value="<?php echo (int) $value; ?>"
                            <?php echo (int) ($message['type'] ?? 0) === (int) $value ? 'selected' : ''; ?>>
                            <?php echo $e($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs pf-muted mt-1">
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
                <textarea name="message" id="message" rows="12" style="width:100%;padding:8px;font-family:monospace;font-size:12px"><?php echo $e($message['message'] ?? ''); ?></textarea>
                <p class="text-xs pf-muted mt-1">
                    Markup, kept as written — it is the body of a message. It is escaped
                    wherever this screen displays it.
                </p>
            </div>
        </div>

        <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:16px">
            <div class="flex items-baseline gap-3">
                <h3 class="font-medium">Audience</h3>
                <span style="background:#0d6efd;color:#fff;padding:2px 8px;border-radius:10px;font-size:12px"><?php echo number_format($size); ?> account(s)</span>
            </div>

            <div style="display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
                <div>
                    <label style="display:block;font-size:0.85em;margin-bottom:4px" for="usertype_max">Usertype ceiling</label>
                    <input type="number" name="usertype_max" id="usertype_max" min="0" max="99"
                           style="width:100%;padding:6px 8px"
                           value="<?php echo (int) ($criteria['usertype_max'] ?? 0); ?>">
                    <p style="color:#666;font-size:0.8em;display:block;margin-top:4px">
                        0 for no ceiling. With a floor alone, "everybody below staff" can only be
                        written as "everybody" — which also reaches the operators.
                    </p>
                </div>

                <div>
                    <label style="display:block;font-size:0.85em;margin-bottom:4px" for="language">Language</label>
                    <select name="language" id="language" style="width:100%;padding:6px 8px">
                        <option value="">Any</option>
                        <?php foreach ($languages as $language): ?>
                            <option value="<?php echo $e($language); ?>"
                                <?php echo ($criteria['language'] ?? '') === $language ? 'selected' : ''; ?>>
                                <?php echo $e($language); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p style="color:#666;font-size:0.8em;display:block;margin-top:4px">
                        The account's language, not yours. One message per language, each to its
                        own audience, is the only honest way to write this.
                    </p>
                </div>

                <div>
                    <label style="display:block;font-size:0.85em;margin-bottom:4px" for="twofactor">Second factor</label>
                    <select name="twofactor" id="twofactor" style="width:100%;padding:6px 8px">
                        <option value="">Any</option>
                        <option value="with" <?php echo ($criteria['twofactor'] ?? '') === 'with' ? 'selected' : ''; ?>>Holds one</option>
                        <option value="without" <?php echo ($criteria['twofactor'] ?? '') === 'without' ? 'selected' : ''; ?>>Does not</option>
                    </select>
                </div>

                <div>
                    <label style="display:block;font-size:0.85em;margin-bottom:4px" for="last_login_after">Signed in after</label>
                    <input type="date" name="last_login_after" id="last_login_after" style="width:100%;padding:6px 8px"
                           value="<?php echo $e($day($criteria['last_login_after'] ?? 0)); ?>">
                </div>

                <div>
                    <label style="display:block;font-size:0.85em;margin-bottom:4px" for="last_login_before">Not signed in since</label>
                    <input type="date" name="last_login_before" id="last_login_before" style="width:100%;padding:6px 8px"
                           value="<?php echo $e($day($criteria['last_login_before'] ?? 0)); ?>">
                    <p style="color:#666;font-size:0.8em;display:block;margin-top:4px">
                        The dormant audience. An account that never signed in at all is in it —
                        that is the most dormant an account can be.
                    </p>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" for="usertype_min">Usertype floor</label>
                <input type="number" name="usertype_min" id="usertype_min" min="0" max="99"
                       style="width:8rem;padding:6px 8px"
                       value="<?php echo (int) ($criteria['usertype_min'] ?? 0); ?>">
                <p class="text-xs pf-muted mt-1">
                    A usertype is a threshold, so 0 means every account and 90 means
                    administrators and above.
                </p>
            </div>

            <div style="display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr))">
                <?php if ($groups !== []): ?>
                <div>
                    <label style="display:block;font-size:0.85em;margin-bottom:4px" for="groups">Groups</label>
                    <select name="groups[]" id="groups" multiple size="5" style="width:100%;padding:6px 8px">
                        <?php foreach ($groups as $groupId => $name): ?>
                            <option value="<?php echo (int) $groupId; ?>"<?php echo $picked($criteria, 'groups', $groupId); ?>>
                                <?php echo $e($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs pf-muted mt-1">
                        Accounts in <em>any</em> of the chosen groups. Nothing chosen means
                        the filter is not applied at all.
                    </p>
                </div>
                <?php endif; ?>

                <?php if ($organizations !== []): ?>
                <div>
                    <label style="display:block;font-size:0.85em;margin-bottom:4px" for="organizations">Organizations</label>
                    <select name="organizations[]" id="organizations" multiple size="5" style="width:100%;padding:6px 8px">
                        <?php foreach ($organizations as $orgId => $name): ?>
                            <option value="<?php echo (int) $orgId; ?>"<?php echo $picked($criteria, 'organizations', $orgId); ?>>
                                <?php echo $e($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs pf-muted mt-1">
                        Accounts belonging to <em>any</em> of them.
                    </p>
                </div>
                <?php endif; ?>

                <div>
                    <label style="display:block;font-size:0.85em;margin-bottom:4px" for="only_ids">Only these accounts</label>
                    <textarea name="only_ids" id="only_ids" rows="2" style="width:100%;padding:6px 8px;font-family:monospace"
                              placeholder="42, 108, 1904"><?php echo $e($idList($criteria, 'only_ids')); ?></textarea>
                    <p class="text-xs pf-muted mt-1">
                        User ids, however you have them — commas, spaces or one per line. Filled
                        in, nobody else is reached. The filters above <em>still</em> apply, so an
                        account named here that is inactive or unsubscribed is not sent to, and
                        the preview shows you that.
                    </p>
                </div>

                <div>
                    <label style="display:block;font-size:0.85em;margin-bottom:4px" for="exclude_ids">Except these accounts</label>
                    <textarea name="exclude_ids" id="exclude_ids" rows="2" style="width:100%;padding:6px 8px;font-family:monospace"
                              placeholder="7, 19"><?php echo $e($idList($criteria, 'exclude_ids')); ?></textarea>
                    <p class="text-xs pf-muted mt-1">
                        Removed from whatever the rest of this matched.
                    </p>
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="validated_only" value="1" 
                    <?php echo $checked($criteria['validated_only'] ?? null); ?>>
                Validated accounts only
            </label>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="active_only" value="1" 
                    <?php echo $checked($criteria['active_only'] ?? null); ?>>
                Active accounts only
            </label>

            <div>
                <label style="display:block;font-size:0.85em;margin-bottom:4px" for="exclude_optouts">Exclude anyone who unsubscribed from</label>
                <input type="text" name="exclude_optouts" id="exclude_optouts" style="width:100%;padding:6px 8px"
                       placeholder="massmessages"
                       value="<?php echo $e((string) ($criteria['exclude_optouts'] ?? '')); ?>">
                <p style="color:#666;font-size:0.8em;display:block;margin-top:4px">
                    They are skipped at delivery either way. Naming the list here is what makes
                    the <strong>count</strong> honest — and the count is the number that decides
                    whether the send happens at all.
                </p>
            </div>

            <p class="text-xs pf-muted">
                The count above is what these criteria match now. The audience is resolved
                once, when the message is queued — so it cannot quietly grow to include
                accounts created after somebody approved the send.
            </p>

            <div style="padding-top:8px;border-top:1px solid rgba(128,128,128,.25)">
                <button type="submit" formaction="<?php echo adminUrl('MassMessages/preview'); ?>" formnovalidate style="padding:6px 12px">
                    Preview this audience
                </button>
                <span class="text-xs pf-muted">
                    Nothing is saved and nothing is sent — it resolves the filters and shows you
                    who they mean.
                </span>
            </div>

            <?php if ($previewed): ?>
                <div style="border:1px solid #ddd;border-radius:4px;padding:12px;background:#fafafa">
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
                            <table style="width:100%;border-collapse:collapse;font-size:0.85em">
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

        <div style="border:1px solid #ddd;border-radius:4px;padding:14px;margin-bottom:14px">
            <h3 style="margin:0 0 10px;font-size:1em">Message options</h3>

            <div>
                <label style="display:block;font-size:0.85em;margin-bottom:4px" for="preheader">Preheader</label>
                <input type="text" name="preheader" id="preheader" style="width:100%;padding:6px 8px" maxlength="120"
                       value="<?php echo $e((string) ($options['preheader'] ?? '')); ?>">
                <p style="color:#666;font-size:0.8em;display:block;margin-top:4px">
                    The line the inbox shows beside the subject. Left empty it is taken from the
                    message's own opening — which beats the wrapper's, but is not a sentence
                    written for the inbox.
                </p>
            </div>

            <div>
                <label style="display:block;font-size:0.85em;margin-bottom:4px" for="link">Link</label>
                <input type="url" name="link" id="link" style="width:100%;padding:6px 8px" placeholder="https://"
                       value="<?php echo $e((string) ($options['link'] ?? '')); ?>">
                <p style="color:#666;font-size:0.8em;display:block;margin-top:4px">Where a push notification opens.</p>
            </div>

            <div style="display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
                <div>
                    <label style="display:block;font-size:0.85em;margin-bottom:4px" for="template">Wrapper</label>
                    <?php $chosen = array_key_exists('template', $options) ? (string) $options['template'] : '__default__'; ?>
                    <select name="template" id="template" style="width:100%;padding:6px 8px">
                        <option value="__default__" <?php echo $chosen === '__default__' ? 'selected' : ''; ?>>The installation's default</option>
                        <option value="" <?php echo $chosen === '' ? 'selected' : ''; ?>>None — send the body bare</option>
                        <?php foreach ($templates as $template): ?>
                            <option value="<?php echo $e($template); ?>" <?php echo $chosen === $template ? 'selected' : ''; ?>>
                                <?php echo $e($template); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label style="display:block;font-size:0.85em;margin-bottom:4px" for="list">Unsubscribe list</label>
                    <input type="text" name="list" id="list" style="width:100%;padding:6px 8px" placeholder="massmessages"
                           value="<?php echo $e((string) ($options['list'] ?? '')); ?>">
                    <p style="color:#666;font-size:0.8em;display:block;margin-top:4px">
                        What a reader is unsubscribing <em>from</em>. Empty means the shared
                        `massmessages` list — one button, no more announcements.
                    </p>
                </div>
            </div>

            <label style="display:flex;gap:8px;align-items:flex-start">
                <input type="checkbox" name="tracking" value="1" 
                       <?php echo $tracking ? '' : 'disabled'; ?>
                       <?php echo !empty($options['tracking']) ? 'checked' : ''; ?>>
                <span>
                    Track opens and clicks
                    <span style="color:#666;font-size:0.8em;display:block">
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
                <label style="display:block;font-size:0.85em;margin-bottom:4px">Gmail action</label>
                <div style="display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
                    <div>
                        <select name="action_type" style="width:100%;padding:6px 8px">
                            <?php foreach (['' => 'No action', 'view' => 'View', 'confirm' => 'Confirm', 'save' => 'Save'] as $value => $label): ?>
                                <option value="<?php echo $e($value); ?>"
                                    <?php echo (string) ($options['action_type'] ?? '') === $value ? 'selected' : ''; ?>>
                                    <?php echo $e($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <input type="text" name="action_name" style="width:100%;padding:6px 8px" placeholder="Button text" maxlength="60"
                               value="<?php echo $e((string) ($options['action_name'] ?? '')); ?>">
                    </div>
                    <div>
                        <input type="url" name="action_url" style="width:100%;padding:6px 8px" placeholder="https://"
                               value="<?php echo $e((string) ($options['action_url'] ?? '')); ?>">
                    </div>
                </div>
                <p style="color:#666;font-size:0.8em;display:block;margin-top:4px">
                    The same URL for everybody, so this is for a page rather than for anything
                    carrying a per-recipient token. <strong>Confirm</strong> must act on the first
                    request, with no page and no login — Gmail's server follows it.
                </p>
            </div>
        </div>

        <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:16px">
            <label class="block text-sm font-medium mb-1" for="scheduled">Send at</label>
            <input type="datetime-local" name="scheduled" id="scheduled" style="padding:6px 8px"
                   value="<?php
                   $scheduled = (int) ($message['scheduled'] ?? 0);
                   echo $scheduled > 0 ? date('Y-m-d\TH:i', $scheduled) : '';
                   ?>">
            <p class="text-xs pf-muted mt-1">
                Leave empty to send as soon as it is queued. A time in the future holds the
                message until then; nothing else about the two paths differs.
            </p>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" style="padding:6px 12px">Save</button>
            <a href="<?php echo adminUrl('MassMessages'); ?>" style="padding:6px 12px">Cancel</a>
            <span class="text-xs pf-muted">
                Saving does not send anything. Queueing is a separate, deliberate step on the
                message's own page.
            </span>
        </div>
    </form>
</div>
