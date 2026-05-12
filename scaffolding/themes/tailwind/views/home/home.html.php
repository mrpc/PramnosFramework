<?php
/**
 * Auth server home/landing page (Tailwind theme).
 *
 * Variables:
 *   $this->serviceInfo — array{service_name, endpoints[]} (optional)
 */
$serviceName = htmlspecialchars($this->serviceInfo['service_name'] ?? 'OAuth2 Authentication Server');
?>
<div class="container mx-auto px-4 py-10 max-w-4xl">

    <div class="text-center mb-12">
        <h1 class="text-4xl font-bold mb-3"><?php echo $serviceName; ?></h1>
        <p class="text-gray-500 text-lg mb-6">Secure, standards-compliant OAuth2 authentication and single sign-on.</p>
        <div class="flex justify-center gap-3">
            <a href="<?php echo sURL; ?>Home/login" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 px-7 rounded-lg transition-colors">Sign In</a>
            <a href="<?php echo sURL; ?>Home/register" class="border border-gray-300 text-gray-700 hover:bg-gray-50 font-medium py-2.5 px-7 rounded-lg transition-colors">Create Account</a>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-12">
        <?php
        $features = [
            ['icon' => '&#128274;', 'title' => 'Authorization Code Flow', 'desc' => 'Standard three-legged OAuth2 with PKCE support for public clients.'],
            ['icon' => '&#128241;', 'title' => 'Device Authorization', 'desc' => 'Authorize input-constrained devices from a secondary browser (RFC 8628).'],
            ['icon' => '&#128273;', 'title' => 'JWT Client Assertion', 'desc' => 'High-security client authentication using signed JWT assertions (RFC 7523).'],
            ['icon' => '&#128257;', 'title' => 'Token Refresh & Exchange', 'desc' => 'Seamless token renewal and long-lived exchange flows.'],
            ['icon' => '&#127760;', 'title' => 'Single Sign-On', 'desc' => 'Sign in once and access all connected applications.'],
            ['icon' => '&#128203;', 'title' => 'Scope Management', 'desc' => 'Fine-grained access control with customizable scope definitions.'],
        ];
        foreach ($features as $f): ?>
        <div class="flex gap-4">
            <div class="text-3xl leading-none"><?php echo $f['icon']; ?></div>
            <div>
                <h3 class="font-semibold text-sm mb-1"><?php echo $f['title']; ?></h3>
                <p class="text-gray-500 text-xs"><?php echo $f['desc']; ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($this->serviceInfo['endpoints'])): ?>
    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <div class="px-6 py-3 border-b border-gray-100 font-semibold text-sm text-gray-700">OAuth2 Endpoints</div>
        <table class="w-full text-sm">
            <tbody class="divide-y divide-gray-50">
            <?php foreach ($this->serviceInfo['endpoints'] as $name => $url): ?>
                <tr>
                    <td class="px-6 py-3 font-medium text-gray-600 whitespace-nowrap w-48"><?php echo htmlspecialchars(ucfirst($name)); ?></td>
                    <td class="px-6 py-3"><code class="text-xs text-gray-700"><?php echo htmlspecialchars($url); ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div>
