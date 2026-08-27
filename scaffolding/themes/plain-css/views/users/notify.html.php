<?php
/**
 * Send one user a message (plain-CSS theme).
 *
 * Variables:
 *   $this->user — ['userid', 'username', 'email', 'firstname', 'lastname']
 *
 * The address is shown and not editable: this form exists so that a message to an account
 * goes to *that account's* address and is recorded against it. An operator who needs to
 * write somewhere else has a mail client.
 */
$u    = is_array($this->user ?? null) ? $this->user : [];
$uid  = (int) ($u['userid'] ?? 0);
$e    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$name = trim((string) ($u['firstname'] ?? '') . ' ' . (string) ($u['lastname'] ?? ''));
?>
<div class="page-section" style="max-width:720px">
    <?php $this->activeNav = 'users_notify'; $this->insert('../partials/admin_breadcrumb'); ?>

    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-bottom:14px">
        <a href="<?php echo adminUrl('users/view/' . $uid); ?>" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
        <h2 style="margin:0">Message <?php echo $e($u['username'] ?? ''); ?></h2>
    </div>

    <div class="card" style="border:1px solid #ddd;border-radius:4px">
        <div style="padding:10px 16px;background:#f5f5f5;border-bottom:1px solid #ddd;font-size:0.9em">
            To
            <span class="font-medium"><?php echo $e($name !== '' ? $name : ($u['username'] ?? '')); ?></span>
            <span style="color:#666;font-size:0.85em">&lt;<?php echo $e($u['email'] ?? ''); ?>&gt;</span>
        </div>
        <form method="post" action="<?php echo adminUrl('users/sendnotification/' . $uid); ?>" class="p-5">
            <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
            <div style="margin-bottom:14px">
                <label style="display:block;font-size:0.85em;margin-bottom:4px" for="pf-message-subject">Subject</label>
                <input type="text" id="pf-message-subject" name="subject" style="width:100%;padding:6px 8px" required
                       maxlength="200">
            </div>
            <div style="margin-bottom:14px">
                <label style="display:block;font-size:0.85em;margin-bottom:4px" for="pf-message-body">Message</label>
                <textarea id="pf-message-body" name="message" style="width:100%;padding:6px 8px" rows="8" required></textarea>
                <p class="text-xs text-base-content/60 mt-1">
                    Sent as text — line breaks are kept, markup is not. What was sent, and by
                    whom, is recorded on the account's activity log.
                </p>
            </div>
            <div style="display:flex;gap:8px">
                <button type="submit" class="btn btn-primary btn-sm gap-2">
                    <?php echo \Pramnos\Html\Icon::svg('send'); ?> Send
                </button>
                <a href="<?php echo adminUrl('users/view/' . $uid); ?>" class="btn btn-outline btn-sm">Cancel</a>
            </div>
        </form>
    </div>
</div>
