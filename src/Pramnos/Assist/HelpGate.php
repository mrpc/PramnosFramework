<?php

declare(strict_types=1);

namespace Pramnos\Assist;

/**
 * Whether a reader may see what a site help page or block requires.
 *
 * The framework cannot know what a requirement is — a plan, a feature, a role — so the
 * application answers. Named in `app/app.php` and built once per process:
 *
 * ```php
 * 'help' => ['site' => ['gate' => App\Help\PlanGate::class]],
 * ```
 *
 * A page says `requires: editorial` in its front matter; a part of one sits between a line
 * `<!-- requires: editorial -->` and a line `<!-- /requires -->`. What the gate refuses does
 * not exist for that reader: not in the list, the search, a page, a screen's page, or what an
 * assistant reads.
 */
interface HelpGate
{
    /** Whether `$reader` may see what needs `$requirement`. A guest has no user and no organisation. */
    public function allows(string $requirement, HelpReader $reader): bool;
}
