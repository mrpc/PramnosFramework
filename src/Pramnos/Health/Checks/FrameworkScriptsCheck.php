<?php

declare(strict_types=1);

namespace Pramnos\Health\Checks;

use Pramnos\Application\FrameworkScripts;
use Pramnos\Health\HealthCheck;
use Pramnos\Health\HealthCheckResult;

/**
 * Whether the web root has every `pf-*.js` the framework ships.
 *
 * A framework view that starts using a new script arrives with `composer update`; the script
 * does not — it is published by `project:resync --js --all`. Until then the page works as a
 * plain form and nothing on it says why, so this says it: **degraded**, with the files and the
 * command. Never down: every such page still works without its script.
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
        $missing = FrameworkScripts::missing($root);

        if ($missing === []) {
            return HealthCheckResult::ok($this->getName(), 'Every framework script is published');
        }

        return HealthCheckResult::degraded(
            $this->getName(),
            count($missing) . ' framework script(s) are not in the web root — run `php bin/pramnos project:resync --js --all`',
            ['missing' => $missing, 'web_root' => $root]
        );
    }
}
