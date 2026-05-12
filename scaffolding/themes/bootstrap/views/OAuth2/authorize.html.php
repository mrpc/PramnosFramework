<?php
/**
 * OAuth2 authorization consent screen (Bootstrap theme).
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
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-7 col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body p-4">

                    <div class="text-center mb-3">
                        <?php if (!empty($this->application->logourl)): ?>
                            <img src="<?php echo htmlspecialchars($this->application->logourl); ?>"
                                 alt="<?php echo htmlspecialchars($this->application->name ?? ''); ?>"
                                 style="max-height:64px;max-width:64px;border-radius:8px" class="mb-2">
                        <?php endif; ?>
                        <h1 class="h4 mb-0">Authorize <?php echo htmlspecialchars($this->application->name ?? ($_GET['client_id'] ?? 'Application')); ?></h1>
                    </div>

                    <div class="d-flex align-items-center border rounded p-2 mb-3">
                        <img src="<?php echo htmlspecialchars($this->user->avatar ?? 'https://www.gravatar.com/avatar/' . md5(strtolower(trim($this->user->email ?? '')))); ?>"
                             alt="Avatar" class="rounded-circle me-2" width="36" height="36">
                        <div>
                            <div class="fw-semibold small"><?php echo htmlspecialchars($this->user->username ?? ''); ?></div>
                            <div class="text-muted small"><?php echo htmlspecialchars($this->user->email ?? ''); ?></div>
                        </div>
                    </div>

                    <div class="bg-light rounded p-3 mb-3">
                        <p class="fw-semibold small mb-2">This application will be able to:</p>
                        <?php foreach ($this->allScopes as $category => $scopesInCategory): ?>
                            <?php $requested = array_intersect(array_keys($scopesInCategory), $this->requestedScopes); ?>
                            <?php if (empty($requested)) continue; ?>
                            <details class="mb-2">
                                <summary class="small fw-medium" style="cursor:pointer">
                                    <span class="text-success me-1">&#10003;</span><?php echo htmlspecialchars($category); ?>
                                </summary>
                                <ul class="list-unstyled ps-3 mt-1 mb-0">
                                    <?php foreach ($requested as $scope): ?>
                                        <li class="small text-muted"><?php echo htmlspecialchars($scopesInCategory[$scope]['description']); ?></li>
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
                        <div class="d-flex gap-2 mt-3">
                            <button type="submit" name="authorize" value="no" class="btn btn-outline-secondary flex-fill">Deny</button>
                            <button type="submit" name="authorize" value="yes" class="btn btn-primary flex-fill">Allow</button>
                        </div>
                    </form>

                    <p class="text-muted small text-center mt-3 mb-0">
                        <?php if (!empty($this->application->termsurl) || !empty($this->application->privacyurl)): ?>
                            By allowing you agree to the
                            <?php if (!empty($this->application->termsurl)): ?>
                                <a href="<?php echo htmlspecialchars($this->application->termsurl); ?>" target="_blank">Terms of Service</a>
                            <?php endif; ?>
                            <?php if (!empty($this->application->termsurl) && !empty($this->application->privacyurl)): ?> and <?php endif; ?>
                            <?php if (!empty($this->application->privacyurl)): ?>
                                <a href="<?php echo htmlspecialchars($this->application->privacyurl); ?>" target="_blank">Privacy Policy</a>
                            <?php endif; ?>.
                        <?php else: ?>
                            By allowing, you grant this application access to your data.
                        <?php endif; ?>
                    </p>
                    <p class="text-muted small text-center mt-2 mb-0">
                        Redirecting to: <code class="small"><?php echo htmlspecialchars($_GET['redirect_uri'] ?? ''); ?></code>
                    </p>
                    <p class="text-center mt-2 mb-0">
                        <a href="<?php echo sURL; ?>logout?redirect_uri=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="small">Use a different account</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
