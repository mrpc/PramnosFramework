@php /**
 * Auth server home/landing page (Bootstrap theme).
 *
 * Variables:
 *   $this->serviceInfo — array{service_name, endpoints[]} (optional)
 */
$serviceName = htmlspecialchars($this->serviceInfo['service_name'] ?? t('OAuth2 Authentication Server')); @endphp
<div class="container py-5">

    <div class="py-4 mb-5 text-center">
        <h1 class="display-6 fw-bold">{!! $serviceName !!}</h1>
        <p class="lead text-muted">{{ t('Secure, standards-compliant OAuth2 authentication and single sign-on.') }}</p>
        <div class="d-flex gap-2 justify-content-center mt-3">
            <a href="{!! sURL !!}login" class="btn btn-primary btn-lg">{{ t('Sign In') }}</a>
            <a href="{!! sURL !!}register" class="btn btn-outline-secondary btn-lg">{{ t('Create Account') }}</a>
        </div>
    </div>

    <div class="row g-4 mb-5">
        @php $features = [
            ['icon' => '&#128274;', 'title' => t('Authorization Code Flow'), 'desc' => t('Standard three-legged OAuth2 with PKCE support for public clients.')],
            ['icon' => '&#128241;', 'title' => t('Device Authorization (RFC 8628)'), 'desc' => t('Authorize input-constrained devices from a secondary browser.')],
            ['icon' => '&#128273;', 'title' => t('JWT Client Assertion (RFC 7523)'), 'desc' => t('High-security client authentication using signed JWT assertions.')],
            ['icon' => '&#128257;', 'title' => t('Token Refresh & Exchange'), 'desc' => t('Seamless token renewal and long-lived exchange flows.')],
            ['icon' => '&#127760;', 'title' => t('Single Sign-On'), 'desc' => t('Sign in once and access all connected applications.')],
            ['icon' => '&#128203;', 'title' => t('Scope Management'), 'desc' => t('Fine-grained access control with customizable scope definitions.')],
        ];
        foreach ($features as $f): @endphp
        <div class="col-md-4">
            <div class="d-flex align-items-start">
                <div class="fs-3 me-3">{!! $f['icon'] !!}</div>
                <div>
                    <h5 class="mb-1">{{ $f['title'] }}</h5>
                    <p class="text-muted small mb-0">{{ $f['desc'] }}</p>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    @if (!empty($this->serviceInfo['endpoints']))
    <div class="card">
        <div class="card-header fw-semibold">{{ t('OAuth2 Endpoints') }}</div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <tbody>
                @foreach ($this->serviceInfo['endpoints'] as $name => $url)
                    <tr>
                        <td class="ps-3 fw-medium text-nowrap">{{ ucfirst($name) }}</td>
                        <td><code>{{ $url }}</code></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

</div>
