<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

/**
 * Backward-compatible alias of {@see Account}.
 *
 * The account controller was promoted from `Dashboard` to the more general
 * {@see Account} (which now also hosts the public login / verify / logout flow).
 * This subclass is retained so existing routes, scaffolds and apps that reference
 * `Pramnos\Auth\Controllers\Dashboard` keep working unchanged — it only pins the
 * historical `Dashboard` route base used in internal redirects.
 *
 * @deprecated Use {@see Account} directly for new code.
 */
class Dashboard extends Account
{
    /** Preserve the historical route base for redirects issued by inherited actions. */
    protected string $routeBase = 'Dashboard';
}
