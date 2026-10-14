<?php

declare(strict_types=1);

namespace Pramnos\Health\Checks;

use Pramnos\Health\HealthCheck;
use Pramnos\Health\HealthCheckResult;
use Pramnos\Http\SiteUrl;

/**
 * Does this installation know its own public address?
 *
 * A web request almost always infers one, so nothing on the site says it is missing. The
 * process that needs it most cannot: a scheduled task has no `Host` header, so an email, a
 * webhook payload or a feed built by the scheduler gets `http:///uploads/x.jpg` — a string
 * that looks like a bug in the caller and is actually a missing setting.
 *
 * **Degraded, not down.** Every page works; what does not work is the half of the
 * application nobody is looking at. Reporting `down` would page somebody for a site that
 * is up, and a check that cries wolf gets muted.
 *
 * The check also reports the resolved value, because the common way to get this wrong is
 * not to leave it unset but to set it to the wrong thing — a staging host copied into
 * production, a scheme that is `http` behind a TLS-terminating proxy. One line of
 * `health:check` output showing the address the application believes in is what makes that
 * visible at a glance.
 *
 * @see \Pramnos\Http\SiteUrl for why `sURL` cannot answer this.
 */
class SiteUrlCheck implements HealthCheck
{
    public function getName(): string
    {
        return 'site_url';
    }

    /**
     * Both failing answers stay `degraded`, and that is a decision rather than an omission.
     *
     * {@see \Pramnos\Health\HealthStatus::Notice} exists for "correct here, and would not
     * be everywhere", and the test for it is whether there is an installation where this
     * exact answer is the right one. There is not, for either branch: an unset `APP_URL`
     * means every URL built outside a request — a scheduled email, a queued job, a console
     * command — is wrong or empty, on a single server exactly as much as on five. It is a
     * latent defect that a web request cannot see, which is the reason the check exists.
     *
     * Sessions on local files are the opposite and got `notice`: they are the correct
     * choice on one machine.
     */
    public function run(): HealthCheckResult
    {
        $resolved = SiteUrl::get();

        if (SiteUrl::isConfigured()) {
            return HealthCheckResult::ok(
                $this->getName(),
                'Site root configured: ' . $resolved,
                ['url' => $resolved, 'source' => 'configured']
            );
        }

        if ($resolved === '') {
            return HealthCheckResult::degraded(
                $this->getName(),
                'No site root: APP_URL is unset and there is no request to infer one from' . $this->affected(),
                [
                    'url'    => '',
                    'source' => 'none',
                    'fix'    => "Set APP_URL in .env (or 'site_url' in app/config/app.php).",
                ]
            );
        }

        return HealthCheckResult::degraded(
            $this->getName(),
            'Site root inferred from this request (' . $resolved . '); '
            . 'a scheduled task has no request and would build an empty URL' . $this->affected(),
            [
                'url'    => $resolved,
                'source' => 'request',
                'fix'    => "Set APP_URL in .env (or 'site_url' in app/config/app.php).",
            ]
        );
    }

    /**
     * What else goes wrong without it, where the installation uses it: the analyst's files are
     * linked from the queue worker, and without a root only from the host's root.
     */
    private function affected(): string
    {
        $analyst = class_exists(\Pramnos\Llm\Llm::class) ? \Pramnos\Llm\Llm::config('analyst', []) : [];

        return is_array($analyst) && !empty($analyst['export'])
            ? '. The analyst\'s export links are built in the queue worker, from the host\'s root: wrong for a site in a subdirectory'
            : '';
    }
}
