<?php
/**
 * Send one user a message (Tailwind theme).
 *
 * Variables:
 *   $this->user      — ['userid', 'username', 'email', 'firstname', 'lastname']
 *   $this->channels  — ['mail'|'database'|'push' => ['available' => bool, 'reason' => string]]
 *   $this->templates — mail wrapper names this installation can render
 *   $this->lists     — unsubscribe list names that have records, plus 'all'
 *   $this->tracking  — whether this installation has open/click tracking switched on at all
 *
 * The address is shown and not editable: this form exists so that a message to an account
 * goes to *that account's* address and is recorded against it. An operator who needs to
 * write somewhere else has a mail client.
 *
 * A channel this account cannot receive is shown disabled **with its reason**, rather than
 * hidden. Hidden, an operator wonders where push went; disabled and unexplained, they wonder
 * whose fault it is — and "no browser has subscribed" and "this installation has no key pair"
 * are two entirely different problems.
 */
$u    = is_array($this->user ?? null) ? $this->user : [];
$uid  = (int) ($u['userid'] ?? 0);
$e    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$name = trim((string) ($u['firstname'] ?? '') . ' ' . (string) ($u['lastname'] ?? ''));

$channels  = is_array($this->channels ?? null) ? $this->channels : [];
$templates = is_array($this->templates ?? null) ? $this->templates : [];
$lists     = is_array($this->lists ?? null) ? $this->lists : [];
$tracking  = (bool) ($this->tracking ?? false);

$labels = [
    'mail'     => ['Email', 'To the address on the account.'],
    'database' => ['Notification', 'A record in the application, readable next time they sign in.'],
    'push'     => ['Push', 'On the device, even with the browser closed.'],
];
?>
<div class="max-w-3xl mx-auto py-6 px-4">
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

            <fieldset class="mb-5">
                <legend class="text-sm font-medium mb-2">Send on</legend>
                <div class="grid gap-2 sm:grid-cols-3">
                    <?php foreach ($labels as $key => [$title, $hint]):
                        $state     = $channels[$key] ?? ['available' => false, 'reason' => ''];
                        $available = (bool) ($state['available'] ?? false);
                        ?>
                        <label class="border border-base-300 rounded-lg p-3 flex gap-3 items-start cursor-pointer
                                      <?php echo $available ? 'hover:bg-base-200' : 'opacity-60 cursor-not-allowed'; ?>">
                            <input type="checkbox" name="channels[]" value="<?php echo $e($key); ?>"
                                   class="checkbox checkbox-sm mt-0.5 pf-channel"
                                   <?php echo $key === 'mail' && $available ? 'checked' : ''; ?>
                                   <?php echo $available ? '' : 'disabled'; ?>>
                            <span>
                                <span class="block text-sm font-medium"><?php echo $e($title); ?></span>
                                <span class="block text-xs text-base-content/60">
                                    <?php echo $e($available ? $hint : (string) ($state['reason'] ?? '')); ?>
                                </span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <div class="mb-4">
                <label class="block text-sm font-medium mb-1" for="pf-message-subject">Subject</label>
                <input type="text" id="pf-message-subject" name="subject" class="input input-sm w-full" required
                       maxlength="200">
                <p class="text-xs text-base-content/60 mt-1">The mail subject, and the push notification's title.</p>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium mb-1" for="pf-message-body">Message</label>
                <textarea id="pf-message-body" name="message" class="textarea w-full" rows="8" required></textarea>
                <p class="text-xs text-base-content/60 mt-1">
                    Sent as text — line breaks are kept, markup is not. What was sent, on which
                    channels, and by whom, is recorded on the account's activity log.
                </p>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium mb-1" for="pf-message-link">Link <span class="pf-muted">(optional)</span></label>
                <input type="url" id="pf-message-link" name="link" class="input input-sm w-full"
                       placeholder="https://">
                <p class="text-xs text-base-content/60 mt-1">
                    Where a push notification opens, and the address stored with the in-app record.
                </p>
            </div>

            <details class="border border-base-300 rounded-lg mb-5">
                <summary class="px-4 py-2 text-sm font-medium cursor-pointer">Email options</summary>
                <div class="p-4 pt-2 border-t border-base-300 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium mb-1" for="pf-message-template">Wrapper</label>
                        <select id="pf-message-template" name="template" class="select select-sm w-full">
                            <option value="__default__">The installation's default</option>
                            <option value="">None — send the body bare</option>
                            <?php foreach ($templates as $template): ?>
                                <option value="<?php echo $e($template); ?>"><?php echo $e($template); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="pf-message-list">Unsubscribe list</label>
                        <input list="pf-message-lists" id="pf-message-list" name="list" class="input input-sm w-full"
                               placeholder="none — transactional">
                        <datalist id="pf-message-lists">
                            <?php foreach ($lists as $list): ?>
                                <option value="<?php echo $e($list); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <p class="text-xs text-base-content/60 mt-1">
                            Named, the message carries a working unsubscribe and is <em>not</em> sent to
                            somebody who opted out of that list. Empty means transactional — which is
                            right for "we locked your account" and wrong for anything promotional.
                        </p>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="flex items-start gap-2 text-sm <?php echo $tracking ? '' : 'opacity-60'; ?>">
                            <input type="checkbox" name="tracking" value="1" class="checkbox checkbox-sm mt-0.5"
                                   <?php echo $tracking ? '' : 'disabled'; ?>>
                            <span>
                                Track opens and clicks
                                <span class="block text-xs text-base-content/60">
                                    <?php if ($tracking): ?>
                                        Only takes effect for a message that names a list — tracking a
                                        transactional mail is tracking somebody who never agreed to it.
                                    <?php else: ?>
                                        Switched off for this installation.
                                    <?php endif; ?>
                                </span>
                            </span>
                        </label>
                    </div>

                    <div class="sm:col-span-2">
                        <div class="text-sm font-medium mb-1">Gmail action</div>
                        <div class="grid gap-2 sm:grid-cols-3">
                            <select name="action_type" class="select select-sm">
                                <option value="">No action</option>
                                <option value="view">View</option>
                                <option value="confirm">Confirm</option>
                                <option value="save">Save</option>
                            </select>
                            <input type="text" name="action_name" class="input input-sm" placeholder="Button text"
                                   maxlength="60">
                            <input type="url" name="action_url" class="input input-sm" placeholder="https://">
                        </div>
                        <p class="text-xs text-base-content/60 mt-1">
                            Gmail shows nothing until the sending domain is registered with Google, so
                            this is safe to send and will look like an ordinary mail until then.
                            <strong>Confirm</strong> must act on the first request, without a page and
                            without a login — Gmail's server follows it, not the reader.
                        </p>
                    </div>
                </div>
            </details>

            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm gap-2">
                    <?php echo \Pramnos\Html\Icon::svg('send'); ?> Send
                </button>
                <a href="<?php echo adminUrl('users/view/' . $uid); ?>" class="btn btn-outline btn-sm">Cancel</a>
            </div>
        </form>
    </div>
</div>
