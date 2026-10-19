<?php
/**
 * Auth server home/landing page (Bootstrap theme).
 *
 * Variables:
 *   $this->serviceInfo — array{service_name, endpoints[]} (optional)
 */
$serviceName = htmlspecialchars($this->serviceInfo['service_name'] ?? t('OAuth2 Authentication Server'));
?>
<div class="container py-5">

    <div class="py-4 mb-5 text-center">
        <h1 class="display-6 fw-bold"><?php echo $serviceName; ?></h1>
        <p class="lead text-muted"><?php echo htmlspecialchars(t('Secure, standards-compliant OAuth2 authentication and single sign-on.'), ENT_QUOTES, 'UTF-8'); ?></p>
        <div class="d-flex gap-2 justify-content-center mt-3">
            <a href="<?php echo sURL; ?>login" class="btn btn-primary btn-lg"><?php echo htmlspecialchars(t('Sign In'), ENT_QUOTES, 'UTF-8'); ?></a>
            <a href="<?php echo sURL; ?>register" class="btn btn-outline-secondary btn-lg"><?php echo htmlspecialchars(t('Create Account'), ENT_QUOTES, 'UTF-8'); ?></a>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <?php
        $features = [
            ['icon' => '&#128274;', 'title' => t('Authorization Code Flow'), 'desc' => t('Standard three-legged OAuth2 with PKCE support for public clients.')],
            ['icon' => '&#128241;', 'title' => t('Device Authorization (RFC 8628)'), 'desc' => t('Authorize input-constrained devices from a secondary browser.')],
            ['icon' => '&#128273;', 'title' => t('JWT Client Assertion (RFC 7523)'), 'desc' => t('High-security client authentication using signed JWT assertions.')],
            ['icon' => '&#128257;', 'title' => t('Token Refresh & Exchange'), 'desc' => t('Seamless token renewal and long-lived exchange flows.')],
            ['icon' => '&#127760;', 'title' => t('Single Sign-On'), 'desc' => t('Sign in once and access all connected applications.')],
            ['icon' => '&#128203;', 'title' => t('Scope Management'), 'desc' => t('Fine-grained access control with customizable scope definitions.')],
        ];
        foreach ($features as $f): ?>
        <div class="col-md-4">
            <div class="d-flex align-items-start">
                <div class="fs-3 me-3"><?php echo $f['icon']; ?></div>
                <div>
                    <h5 class="mb-1"><?php echo htmlspecialchars($f['title'], ENT_QUOTES, 'UTF-8'); ?></h5>
                    <p class="text-muted small mb-0"><?php echo htmlspecialchars($f['desc'], ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($this->serviceInfo['endpoints'])): ?>
    <div class="card">
        <div class="card-header fw-semibold"><?php echo htmlspecialchars(t('OAuth2 Endpoints'), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <tbody>
                <?php foreach ($this->serviceInfo['endpoints'] as $name => $url): ?>
                    <tr>
                        <td class="ps-3 fw-medium text-nowrap"><?php echo htmlspecialchars(ucfirst($name)); ?></td>
                        <td><code><?php echo htmlspecialchars($url); ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>
