<?php
/**
 * User profile page (Tailwind theme) — editable.
 *
 * Matches the fields Account::profile() saves (firstname / lastname / email /
 * phone). Username and account dates are read-only. Uses the shared account
 * sidebar for consistent navigation.
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::profile):
 *   $this->routeBase — Account controller route base
 *   $this->user      — User object; `avatarurl` and `photo` drive the picture card,
 *                      whose form posts to Account::profilephoto()
 */
$routeBase = $this->routeBase ?? 'Account';
$u         = $this->user;
$this->activeNav = 'profile';
$inputCls = 'input w-full';
?>
<div class="container mx-auto py-8 px-4">

    <div class="flex flex-wrap items-center justify-between gap-2 mb-6">
        <h2 class="text-2xl font-semibold">My Profile</h2>
        <a href="<?php echo sURL . $routeBase; ?>" class="text-sm text-primary hover:underline">&larr; Back to Dashboard</a>
    </div>

    <?php if ($this->hasMessages()): ?>
        <div role="status" class="alert alert-success mb-4"><?php echo $this->_printMessages(); ?></div>
    <?php endif; ?>
    <?php if ($this->hasErrors()): ?>
        <div role="alert" class="alert alert-error mb-4"><?php echo $this->_printErrors(); ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        <?php include __DIR__ . '/../partials/account_sidebar.html.php'; ?>

        <div class="md:col-span-3 space-y-6">
            <div class="card bg-base-100 shadow-sm">
                <div class="px-6 py-3 border-b border-base-300 font-semibold text-base-content">Profile Picture</div>
                <div class="p-6 flex items-center gap-6 flex-wrap">
                    <?php if (!empty($u->avatarurl)): ?>
                        <img src="<?php echo htmlspecialchars((string) $u->avatarurl); ?>" alt="Your profile picture" width="96" height="96" class="rounded-full">
                    <?php endif; ?>
                    <form method="post" action="<?php echo sURL . $routeBase; ?>/profilephoto" enctype="multipart/form-data" class="flex gap-2 flex-wrap items-center">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <label for="photo" class="sr-only">Choose a picture</label>
                        <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/gif,image/webp" class="file-input file-input-bordered">
                        <button type="submit" class="btn btn-primary">Upload</button>
                        <?php if ((int) ($u->photo ?? 0) > 0): ?>
                            <button type="submit" name="remove" value="1" class="btn btn-outline btn-error">Remove</button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            <div class="card bg-base-100 shadow-sm">
                <div class="px-6 py-3 border-b border-base-300 font-semibold text-base-content">Personal Information</div>
                <div class="p-6">
                    <form method="post" action="<?php echo sURL . $routeBase; ?>/profile" class="space-y-4">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>

                        <div>
                            <label class="block text-sm font-medium text-base-content mb-1">Username</label>
                            <input type="text" class="<?php echo $inputCls; ?> bg-base-200 text-base-content/70" value="<?php echo htmlspecialchars((string) ($u->username ?? '')); ?>" disabled>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="firstname" class="block text-sm font-medium text-base-content mb-1">First Name</label>
                                <input type="text" id="firstname" name="firstname" class="<?php echo $inputCls; ?>" value="<?php echo htmlspecialchars((string) ($u->firstname ?? '')); ?>">
                            </div>
                            <div>
                                <label for="lastname" class="block text-sm font-medium text-base-content mb-1">Last Name</label>
                                <input type="text" id="lastname" name="lastname" class="<?php echo $inputCls; ?>" value="<?php echo htmlspecialchars((string) ($u->lastname ?? '')); ?>">
                            </div>
                        </div>

                        <div>
                            <label for="email" class="block text-sm font-medium text-base-content mb-1">Email</label>
                            <input type="email" id="email" name="email" class="<?php echo $inputCls; ?>" value="<?php echo htmlspecialchars((string) ($u->email ?? '')); ?>" required>
                        </div>

                        <div>
                            <label for="phone" class="block text-sm font-medium text-base-content mb-1">Phone</label>
                            <input type="text" id="phone" name="phone" class="<?php echo $inputCls; ?>" value="<?php echo htmlspecialchars((string) ($u->phone ?? '')); ?>">
                        </div>

                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </form>
                </div>
            </div>

            <?php if (!empty($u->regdate) || !empty($u->lastlogin)): ?>
            <div class="card bg-base-100 shadow-sm">
                <div class="px-6 py-3 border-b border-base-300 font-semibold text-base-content">Account</div>
                <div class="divide-y divide-base-300">
                    <?php if (!empty($u->regdate)): ?>
                    <div class="flex justify-between px-6 py-3 text-sm">
                        <span class="text-base-content/70">Member Since</span>
                        <span class="text-base-content"><?php echo htmlspecialchars(localDate( is_numeric($u->regdate) ? (int) $u->regdate : (int) strtotime((string) $u->regdate))); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($u->lastlogin)): ?>
                    <div class="flex justify-between px-6 py-3 text-sm">
                        <span class="text-base-content/70">Last Login</span>
                        <span class="text-base-content"><?php echo htmlspecialchars(localDateTime( is_numeric($u->lastlogin) ? (int) $u->lastlogin : (int) strtotime((string) $u->lastlogin))); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>
