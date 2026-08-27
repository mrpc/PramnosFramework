<?php
/**
 * Send one user a message (Tailwind theme).
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
<div class="max-w-2xl mx-auto py-6 px-4">
    <?php $this->activeNav = 'users_notify'; $this->insert('../partials/admin_breadcrumb'); ?>

    <div class="flex flex-wrap items-center gap-3 mb-4">
        <a href="<?php echo adminUrl('users/view/' . $uid); ?>" class="btn btn-outline btn-xs">&larr; Back</a>
        <h2 class="text-lg font-semibold">Message <?php echo $e($u['username'] ?? ''); ?></h2>
    </div>

    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div class="px-5 py-3 bg-base-200 border-b border-base-300 text-sm">
            To
            <span class="font-medium"><?php echo $e($name !== '' ? $name : ($u['username'] ?? '')); ?></span>
            <span class="pf-muted">&lt;<?php echo $e($u['email'] ?? ''); ?>&gt;</span>
        </div>
        <form method="post" action="<?php echo adminUrl('users/sendnotification/' . $uid); ?>" class="p-5">
            <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1" for="pf-message-subject">Subject</label>
                <input type="text" id="pf-message-subject" name="subject" class="input input-sm w-full" required
                       maxlength="200">
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1" for="pf-message-body">Message</label>
                <textarea id="pf-message-body" name="message" class="textarea w-full" rows="8" required></textarea>
                <p class="text-xs text-base-content/60 mt-1">
                    Sent as text — line breaks are kept, markup is not. What was sent, and by
                    whom, is recorded on the account's activity log.
                </p>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm gap-2">
                    <?php echo \Pramnos\Html\Icon::svg('send'); ?> Send
                </button>
                <a href="<?php echo adminUrl('users/view/' . $uid); ?>" class="btn btn-outline btn-sm">Cancel</a>
            </div>
        </form>
    </div>
</div>
