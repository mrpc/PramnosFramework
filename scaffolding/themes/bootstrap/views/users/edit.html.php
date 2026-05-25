<?php
/**
 * User create/edit form (Bootstrap theme).
 *
 * Variables:
 *   $this->user   — user row array (null when creating)
 *   $this->isNew  — bool
 *   $this->error  — string error message
 */
$u = $this->user ?? [];
?>
<div class="container py-4" style="max-width:640px">
    <h2 class="mb-4"><?php echo $this->isNew ? 'New User' : 'Edit User'; ?></h2>
    <?php if (!empty($this->error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <div class="card">
        <div class="card-body">
            <form method="post" action="<?php echo sURL; ?>Users/save">
                <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
                <?php if (!$this->isNew): ?>
                    <input type="hidden" name="userid" value="<?php echo (int)($u['userid'] ?? 0); ?>">
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control" required value="<?php echo htmlspecialchars($u['username'] ?? ''); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($u['email'] ?? ''); ?>">
                </div>
                <?php if ($this->isNew): ?>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label">First Name</label>
                    <input type="text" name="firstname" class="form-control" value="<?php echo htmlspecialchars($u['firstname'] ?? ''); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Last Name</label>
                    <input type="text" name="lastname" class="form-control" value="<?php echo htmlspecialchars($u['lastname'] ?? ''); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">User Type</label>
                    <select name="usertype" class="form-select">
                        <?php $maxType = $this->currentUserType ?? 100; $curType = $u['usertype'] ?? 1; ?>
                        <option value="1" <?php echo $curType == 1 ? 'selected' : ''; ?>>User (1)</option>
                        <?php if ($maxType >= 50): ?><option value="50" <?php echo $curType == 50 ? 'selected' : ''; ?>>Editor (50)</option><?php endif; ?>
                        <?php if ($maxType >= 80): ?><option value="80" <?php echo $curType == 80 ? 'selected' : ''; ?>>Manager (80)</option><?php endif; ?>
                        <?php if ($maxType >= 90): ?><option value="90" <?php echo $curType == 90 ? 'selected' : ''; ?>>Admin (90)</option><?php endif; ?>
                        <?php if ($maxType >= 100): ?><option value="100" <?php echo $curType == 100 ? 'selected' : ''; ?>>Super Admin (100)</option><?php endif; ?>
                    </select>
                </div>
                <div class="mb-3 d-flex gap-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="active" value="1" id="chk_active" <?php echo ($u['active'] ?? 1) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="chk_active">Active</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="validated" value="1" id="chk_validated" <?php echo ($u['validated'] ?? 1) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="chk_validated">Validated</label>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a href="<?php echo sURL; ?>users" class="btn btn-outline-secondary">Cancel</a>
                    <?php if (!$this->isNew): ?>
                        <a href="<?php echo sURL; ?>users/tokens/<?php echo (int)($u['userid'] ?? 0); ?>" class="btn btn-outline-info ms-auto">Tokens</a>
                        <a href="<?php echo sURL; ?>users/sessions/<?php echo (int)($u['userid'] ?? 0); ?>" class="btn btn-outline-secondary">Sessions</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>
