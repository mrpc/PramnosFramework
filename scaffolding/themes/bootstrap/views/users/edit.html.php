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
                        <option value="1" <?php echo ($u['usertype'] ?? 1) == 1 ? 'selected' : ''; ?>>User (1)</option>
                        <option value="50" <?php echo ($u['usertype'] ?? 1) == 50 ? 'selected' : ''; ?>>Editor (50)</option>
                        <option value="80" <?php echo ($u['usertype'] ?? 1) == 80 ? 'selected' : ''; ?>>Manager (80)</option>
                        <option value="90" <?php echo ($u['usertype'] ?? 1) == 90 ? 'selected' : ''; ?>>Admin (90)</option>
                        <option value="100" <?php echo ($u['usertype'] ?? 1) == 100 ? 'selected' : ''; ?>>Super Admin (100)</option>
                    </select>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a href="<?php echo sURL; ?>Users" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
