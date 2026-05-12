<?php
/**
 * OAuth2 authorization consent screen (Tailwind theme).
 *
 * Variables:
 *   $this->application     — Application object (name, logourl, termsurl, privacyurl)
 *   $this->user            — Current user (username, email, avatar)
 *   $this->allScopes       — array<category, array<scope, {description, is_default, inherits}>>
 *   $this->requestedScopes — string[] of requested scope identifiers
 * Query params:
 *   client_id, redirect_uri, response_type, state, scope
 */
?>
<div class="flex items-center justify-center min-h-screen bg-gray-100 px-4 py-8">
    <div class="w-full max-w-md bg-white rounded-xl shadow-md p-8">

        <div class="text-center mb-5">
            <?php if (!empty($this->application->logourl)): ?>
                <img src="<?php echo htmlspecialchars($this->application->logourl); ?>"
                     alt="<?php echo htmlspecialchars($this->application->name ?? ''); ?>"
                     class="w-16 h-16 rounded-xl mx-auto mb-3 object-cover">
            <?php endif; ?>
            <h1 class="text-xl font-semibold">Authorize <?php echo htmlspecialchars($this->application->name ?? ($_GET['client_id'] ?? 'Application')); ?></h1>
        </div>

        <div class="flex items-center border border-gray-200 rounded-lg p-3 mb-4">
            <img src="<?php echo htmlspecialchars($this->user->avatar ?? 'https://www.gravatar.com/avatar/' . md5(strtolower(trim($this->user->email ?? '')))); ?>"
                 alt="Avatar" class="w-9 h-9 rounded-full mr-3">
            <div>
                <div class="text-sm font-medium"><?php echo htmlspecialchars($this->user->username ?? ''); ?></div>
                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($this->user->email ?? ''); ?></div>
            </div>
        </div>

        <div class="bg-gray-50 rounded-lg p-4 mb-4">
            <p class="text-sm font-semibold mb-2">This application will be able to:</p>
            <?php foreach ($this->allScopes as $category => $scopesInCategory): ?>
                <?php $requested = array_intersect(array_keys($scopesInCategory), $this->requestedScopes); ?>
                <?php if (empty($requested)) continue; ?>
                <details class="mb-2">
                    <summary class="text-sm font-medium cursor-pointer">
                        <span class="text-green-600 mr-1">&#10003;</span><?php echo htmlspecialchars($category); ?>
                    </summary>
                    <ul class="list-disc list-inside ml-4 mt-1 space-y-1">
                        <?php foreach ($requested as $scope): ?>
                            <li class="text-xs text-gray-600"><?php echo htmlspecialchars($scopesInCategory[$scope]['description']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endforeach; ?>
        </div>

        <form method="POST">
            <input type="hidden" name="client_id" value="<?php echo htmlspecialchars($_GET['client_id'] ?? ''); ?>">
            <input type="hidden" name="redirect_uri" value="<?php echo htmlspecialchars($_GET['redirect_uri'] ?? ''); ?>">
            <input type="hidden" name="response_type" value="<?php echo htmlspecialchars($_GET['response_type'] ?? ''); ?>">
            <input type="hidden" name="state" value="<?php echo htmlspecialchars($_GET['state'] ?? ''); ?>">
            <input type="hidden" name="scope" value="<?php echo htmlspecialchars($_GET['scope'] ?? ''); ?>">
            <div class="flex gap-3 mt-2">
                <button type="submit" name="authorize" value="no" class="flex-1 border border-gray-300 text-gray-700 font-medium py-2 px-4 rounded-md hover:bg-gray-50 transition-colors">Deny</button>
                <button type="submit" name="authorize" value="yes" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition-colors">Allow</button>
            </div>
        </form>

        <p class="text-xs text-gray-500 text-center mt-4">
            <?php if (!empty($this->application->termsurl) || !empty($this->application->privacyurl)): ?>
                By allowing you agree to the
                <?php if (!empty($this->application->termsurl)): ?>
                    <a href="<?php echo htmlspecialchars($this->application->termsurl); ?>" class="underline" target="_blank">Terms of Service</a>
                <?php endif; ?>
                <?php if (!empty($this->application->termsurl) && !empty($this->application->privacyurl)): ?> and <?php endif; ?>
                <?php if (!empty($this->application->privacyurl)): ?>
                    <a href="<?php echo htmlspecialchars($this->application->privacyurl); ?>" class="underline" target="_blank">Privacy Policy</a>
                <?php endif; ?>.
            <?php else: ?>
                By allowing, you grant this application access to your data.
            <?php endif; ?>
        </p>
        <p class="text-xs text-gray-400 text-center mt-1 break-all">
            Redirect: <?php echo htmlspecialchars($_GET['redirect_uri'] ?? ''); ?>
        </p>
        <p class="text-center mt-2">
            <a href="<?php echo sURL; ?>logout?redirect_uri=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="text-xs text-blue-600 hover:underline">Use a different account</a>
        </p>
    </div>
</div>
