<?php

declare(strict_types=1);

namespace Pramnos\Health\Checks;

use Pramnos\Application\FrameworkScripts;
use Pramnos\Health\HealthCheck;
use Pramnos\Health\HealthCheckResult;

/**
 * Whether the web root has every `pf-*.js` the framework ships, as the framework ships it.
 *
 * A framework view that starts using a new script arrives with `composer update`; the script
 * does not — it is published by `project:resync --js --all`. Until then the page works as a
 * plain form and nothing on it says why, so this says it: **degraded**, with the files and the
 * command. Never down: every such page still works without its script.
 *
 * A copy that differs from the shipped file is reported the same way: the update changed the
 * script in vendor/ and not the one the page loads, so the page runs the old behaviour.
 */
class FrameworkScriptsCheck implements HealthCheck
{
    public function __construct(private readonly ?string $webRoot = null)
    {
    }

    public function getName(): string
    {
        return 'framework_scripts';
    }

    public function run(): HealthCheckResult
    {
        $root    = $this->webRoot ?? FrameworkScripts::webRoot(defined('ROOT') ? (string) ROOT : (string) getcwd());
        // A script only a feature's screens load is not missing where that feature is off.
        $app      = \Pramnos\Application\Application::currentInstance();
        $missing  = FrameworkScripts::missing($root, $app !== null && is_array($app->applicationInfo['features'] ?? null) ? $app->applicationInfo['features'] : null);
        $outdated = FrameworkScripts::outdated($root);

        if ($missing === [] && $outdated === []) {
            return HealthCheckResult::ok($this->getName(), 'Every framework script is published');
        }

        $problems = [];
        if ($missing !== []) {
            $problems[] = count($missing) . ' framework script(s) are not in the web root';
        }
        if ($outdated !== []) {
            $problems[] = count($outdated) . ' differ from the framework\'s copy';
        }

        return HealthCheckResult::degraded(
            $this->getName(),
            implode('; ', $problems) . ' — run `php bin/pramnos project:resync --js --all`',
            ['missing' => $missing, 'outdated' => $outdated, 'web_root' => $root]
        );
    }
}
