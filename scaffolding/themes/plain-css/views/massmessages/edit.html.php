<?php
/**
 * Compose a mass message (plain-css theme).
 *
 * Variables:
 *   $this->message      — the row being edited, empty for a new one
 *   $this->types        — channel number => label
 *   $this->criteria     — the stored audience criteria
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
$criteria = is_array($this->criteria ?? null) ? $this->criteria : [];
$size     = (int) ($this->audienceSize ?? 0);
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
                    language. An internal message lands in the account's inbox. Push has no
                    transport in the framework and every recipient is recorded as failed —
                    which is the honest answer rather than a message that reports itself sent.
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

            <p class="text-xs pf-muted">
                The count above is what these criteria match now. The audience is resolved
                once, when the message is queued — so it cannot quietly grow to include
                accounts created after somebody approved the send.
            </p>
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
