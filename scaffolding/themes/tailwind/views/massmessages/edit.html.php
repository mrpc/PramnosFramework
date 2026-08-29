<?php
/**
 * Compose a mass message (Tailwind theme).
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
$criteria  = is_array($this->criteria ?? null) ? $this->criteria : [];
$options   = is_array($this->options ?? null) ? $this->options : [];
$languages = is_array($this->languages ?? null) ? $this->languages : [];
$templates = is_array($this->templates ?? null) ? $this->templates : [];
$tracking  = (bool) ($this->tracking ?? false);
$day       = static fn ($stamp): string => (int) $stamp > 0 ? date('Y-m-d', (int) $stamp) : '';
$size     = (int) ($this->audienceSize ?? 0);
$id       = (int) ($message['messageid'] ?? 0);
$e        = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$checked  = static fn ($value): string => ($value ?? true) !== false ? ' checked' : '';
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'massmessages'; $this->insert('../partials/admin_breadcrumb'); ?>

    <h2 class="text-lg font-semibold mb-4"><?php echo $id > 0 ? 'Edit mass message' : 'New mass message'; ?></h2>

    <form method="post" action="<?php echo adminUrl('MassMessages/save'); ?>" class="space-y-4 max-w-3xl">
        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
        <input type="hidden" name="messageid" value="<?php echo $id; ?>">

        <div class="card bg-base-100 border border-base-300 shadow-xs p-4 space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1" for="subject">Subject</label>
                <input type="text" name="subject" id="subject" class="input input-sm w-full"
                       value="<?php echo $e($message['subject'] ?? ''); ?>" required>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" for="type">Channel</label>
                <select name="type" id="type" class="select select-sm w-full">
                    <?php foreach ($types as $value => $label): ?>
                        <option value="<?php echo (int) $value; ?>"
                            <?php echo (int) ($message['type'] ?? 0) === (int) $value ? 'selected' : ''; ?>>
                            <?php echo $e($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-base-content/60 mt-1">
                    Email goes out through this installation's wrapper, in each recipient's own
                    language. An internal message lands in the account's inbox. Push reaches
                    every browser that subscribed — and an account with none is recorded as
                    <em>failed</em>, because most accounts have never granted permission and
                    counting those as delivered would report a send of thousands about a
                    message that reached dozens.
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" for="message">Body</label>
                <textarea name="message" id="message" rows="12" class="textarea textarea-sm w-full font-mono text-xs"><?php echo $e($message['message'] ?? ''); ?></textarea>
                <p class="text-xs text-base-content/60 mt-1">
                    Markup, kept as written — it is the body of a message. It is escaped
                    wherever this screen displays it.
                </p>
            </div>
        </div>

        <div class="card bg-base-100 border border-base-300 shadow-xs p-4 space-y-4">
            <div class="flex items-baseline gap-3">
                <h3 class="font-medium">Audience</h3>
                <span class="badge badge-primary badge-sm"><?php echo number_format($size); ?> account(s)</span>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium mb-1" for="usertype_min">Usertype floor</label>
                    <input type="number" name="usertype_min" id="usertype_min" min="0" max="99"
                           class="input input-sm w-full"
                           value="<?php echo (int) ($criteria['usertype_min'] ?? 0); ?>">
                    <p class="text-xs text-base-content/60 mt-1">
                        A usertype is a threshold, so 0 means every account and 90 means
                        administrators and above.
                    </p>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" for="usertype_max">Usertype ceiling</label>
                    <input type="number" name="usertype_max" id="usertype_max" min="0" max="99"
                           class="input input-sm w-full"
                           value="<?php echo (int) ($criteria['usertype_max'] ?? 0); ?>">
                    <p class="text-xs text-base-content/60 mt-1">
                        0 for no ceiling. With a floor alone, "everybody below staff" can only
                        be written as "everybody" — which also reaches the operators.
                    </p>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" for="language">Language</label>
                    <select name="language" id="language" class="select select-sm w-full">
                        <option value="">Any</option>
                        <?php foreach ($languages as $language): ?>
                            <option value="<?php echo $e($language); ?>"
                                <?php echo ($criteria['language'] ?? '') === $language ? 'selected' : ''; ?>>
                                <?php echo $e($language); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-base-content/60 mt-1">
                        The account's language, not yours. One message per language, each to its
                        own audience, is the only honest way to write this.
                    </p>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" for="twofactor">Second factor</label>
                    <select name="twofactor" id="twofactor" class="select select-sm w-full">
                        <option value="">Any</option>
                        <option value="with" <?php echo ($criteria['twofactor'] ?? '') === 'with' ? 'selected' : ''; ?>>
                            Holds one
                        </option>
                        <option value="without" <?php echo ($criteria['twofactor'] ?? '') === 'without' ? 'selected' : ''; ?>>
                            Does not
                        </option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" for="last_login_after">Signed in after</label>
                    <input type="date" name="last_login_after" id="last_login_after" class="input input-sm w-full"
                           value="<?php echo $e($day($criteria['last_login_after'] ?? 0)); ?>">
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" for="last_login_before">Not signed in since</label>
                    <input type="date" name="last_login_before" id="last_login_before" class="input input-sm w-full"
                           value="<?php echo $e($day($criteria['last_login_before'] ?? 0)); ?>">
                    <p class="text-xs text-base-content/60 mt-1">
                        The dormant audience. An account that never signed in at all is in it —
                        that is the most dormant an account can be.
                    </p>
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="validated_only" value="1" class="checkbox checkbox-sm"
                    <?php echo $checked($criteria['validated_only'] ?? null); ?>>
                Validated accounts only
            </label>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="active_only" value="1" class="checkbox checkbox-sm"
                    <?php echo $checked($criteria['active_only'] ?? null); ?>>
                Active accounts only
            </label>

            <div>
                <label class="block text-sm font-medium mb-1" for="exclude_optouts">Exclude anyone who unsubscribed from</label>
                <input type="text" name="exclude_optouts" id="exclude_optouts" class="input input-sm w-full"
                       placeholder="massmessages"
                       value="<?php echo $e((string) ($criteria['exclude_optouts'] ?? '')); ?>">
                <p class="text-xs text-base-content/60 mt-1">
                    They are skipped at delivery either way. Naming the list here is what makes
                    the <strong>count</strong> honest — and the count is the number that decides
                    whether the send happens at all.
                </p>
            </div>

            <p class="text-xs text-base-content/60">
                The count above is what these criteria match now. The audience is resolved
                once, when the message is queued — so it cannot quietly grow to include
                accounts created after somebody approved the send.
            </p>
        </div>

        <div class="card bg-base-100 border border-base-300 shadow-xs p-4 space-y-4">
            <h3 class="font-medium">Message options</h3>

            <div>
                <label class="block text-sm font-medium mb-1" for="link">Link</label>
                <input type="url" name="link" id="link" class="input input-sm w-full" placeholder="https://"
                       value="<?php echo $e((string) ($options['link'] ?? '')); ?>">
                <p class="text-xs text-base-content/60 mt-1">Where a push notification opens.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium mb-1" for="template">Wrapper</label>
                    <?php $chosen = array_key_exists('template', $options) ? (string) $options['template'] : '__default__'; ?>
                    <select name="template" id="template" class="select select-sm w-full">
                        <option value="__default__" <?php echo $chosen === '__default__' ? 'selected' : ''; ?>>
                            The installation's default
                        </option>
                        <option value="" <?php echo $chosen === '' ? 'selected' : ''; ?>>
                            None — send the body bare
                        </option>
                        <?php foreach ($templates as $template): ?>
                            <option value="<?php echo $e($template); ?>"
                                <?php echo $chosen === $template ? 'selected' : ''; ?>>
                                <?php echo $e($template); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" for="list">Unsubscribe list</label>
                    <input type="text" name="list" id="list" class="input input-sm w-full" placeholder="massmessages"
                           value="<?php echo $e((string) ($options['list'] ?? '')); ?>">
                    <p class="text-xs text-base-content/60 mt-1">
                        What a reader is unsubscribing <em>from</em>. Empty means the shared
                        `massmessages` list — one button, no more announcements.
                    </p>
                </div>
            </div>

            <label class="flex items-start gap-2 text-sm <?php echo $tracking ? '' : 'opacity-60'; ?>">
                <input type="checkbox" name="tracking" value="1" class="checkbox checkbox-sm mt-0.5"
                       <?php echo $tracking ? '' : 'disabled'; ?>
                       <?php echo !empty($options['tracking']) ? 'checked' : ''; ?>>
                <span>
                    Track opens and clicks
                    <span class="block text-xs text-base-content/60">
                        <?php if ($tracking): ?>
                            One tracking id per recipient, so the numbers mean something. Opens
                            are inflated by mail clients that prefetch images; clicks are people.
                        <?php else: ?>
                            Switched off for this installation.
                        <?php endif; ?>
                    </span>
                </span>
            </label>

            <div>
                <div class="text-sm font-medium mb-1">Gmail action</div>
                <div class="grid gap-2 sm:grid-cols-3">
                    <select name="action_type" class="select select-sm">
                        <?php foreach (['' => 'No action', 'view' => 'View', 'confirm' => 'Confirm', 'save' => 'Save'] as $value => $label): ?>
                            <option value="<?php echo $e($value); ?>"
                                <?php echo (string) ($options['action_type'] ?? '') === $value ? 'selected' : ''; ?>>
                                <?php echo $e($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="action_name" class="input input-sm" placeholder="Button text" maxlength="60"
                           value="<?php echo $e((string) ($options['action_name'] ?? '')); ?>">
                    <input type="url" name="action_url" class="input input-sm" placeholder="https://"
                           value="<?php echo $e((string) ($options['action_url'] ?? '')); ?>">
                </div>
                <p class="text-xs text-base-content/60 mt-1">
                    The same URL for everybody, so this is for a page rather than for anything
                    carrying a per-recipient token. <strong>Confirm</strong> must act on the
                    first request, with no page and no login — Gmail's server follows it.
                </p>
            </div>
        </div>

        <div class="card bg-base-100 border border-base-300 shadow-xs p-4">
            <label class="block text-sm font-medium mb-1" for="scheduled">Send at</label>
            <input type="datetime-local" name="scheduled" id="scheduled" class="input input-sm"
                   value="<?php
                   $scheduled = (int) ($message['scheduled'] ?? 0);
                   echo $scheduled > 0 ? date('Y-m-d\TH:i', $scheduled) : '';
                   ?>">
            <p class="text-xs text-base-content/60 mt-1">
                Leave empty to send as soon as it is queued. A time in the future holds the
                message until then; nothing else about the two paths differs.
            </p>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary btn-sm">Save</button>
            <a href="<?php echo adminUrl('MassMessages'); ?>" class="btn btn-ghost btn-sm">Cancel</a>
            <span class="text-xs text-base-content/60">
                Saving does not send anything. Queueing is a separate, deliberate step on the
                message's own page.
            </span>
        </div>
    </form>
</div>
