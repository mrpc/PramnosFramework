<?php
/**
 * Auth server home/landing page (Bootstrap theme).
 *
 * Variables:
 *   $this->serviceInfo — array{service_name, endpoints[]} (optional)
 */
$serviceName = htmlspecialchars($this->serviceInfo['service_name'] ?? 'OAuth2 Authentication Server');
?>
<div class="container py-5">

    <div class="py-4 mb-5 text-center">
        <h1 class="display-6 fw-bold"><?php echo $serviceName; ?></h1>
        <p class="lead text-muted">Secure, standards-compliant OAuth2 authentication and single sign-on.</p>
        <div class="d-flex gap-2 justify-content-center mt-3">
            <a href="<?php echo sURL; ?>Home/login" class="btn btn-primary btn-lg">Sign In</a>
            <a href="<?php echo sURL; ?>Home/register" class="btn btn-outline-secondary btn-lg">Create Account</a>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <?php
        $features = [
            ['icon' => '&#128274;', 'title' => 'Authorization Code Flow', 'desc' => 'Standard three-legged OAuth2 with PKCE support for public clients.'],
            ['icon' => '&#128241;', 'title' => 'Device Authorization (RFC 8628)', 'desc' => 'Authorize input-constrained devices from a secondary browser.'],
            ['icon' => '&#128273;', 'title' => 'JWT Client Assertion (RFC 7523)', 'desc' => 'High-security client authentication using signed JWT assertions.'],
            ['icon' => '&#128257;', 'title' => 'Token Refresh & Exchange', 'desc' => 'Seamless token renewal and long-lived exchange flows.'],
            ['icon' => '&#127760;', 'title' => 'Single Sign-On', 'desc' => 'Sign in once and access all connected applications.'],
            ['icon' => '&#128203;', 'title' => 'Scope Management', 'desc' => 'Fine-grained access control with customizable scope definitions.'],
        ];
        foreach ($features as $f): ?>
        <div class="col-md-4">
            <div class="d-flex align-items-start">
                <div class="fs-3 me-3"><?php echo $f['icon']; ?></div>
                <div>
                    <h5 class="mb-1"><?php echo $f['title']; ?></h5>
                    <p class="text-muted small mb-0"><?php echo $f['desc']; ?></p>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($this->serviceInfo['endpoints'])): ?>
    <div class="card">
        <div class="card-header fw-semibold">OAuth2 Endpoints</div>
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
