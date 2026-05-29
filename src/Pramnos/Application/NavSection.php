<?php

declare(strict_types=1);

namespace Pramnos\Application;

/**
 * Logical sections of the application navigation bar.
 *
 * Each NavItem is assigned to exactly one section.  NavRegistry::getForUser()
 * returns items grouped by section value so that templates can render a "Main"
 * row, a "User" cluster (Login/Logout/Account), and an "Admin" dropdown without
 * any hardcoded knowledge of which items exist.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
enum NavSection: string
{
    /** Primary navigation links visible to all visitors. */
    case Main = 'main';

    /** User-context links: Login, Account, Logout. */
    case User = 'user';

    /** Admin/operator links: Logs, OAuth Apps, Users, etc. */
    case Admin = 'admin';

    /** Optional feature-specific links injected when a feature is enabled. */
    case Feature = 'feature';
}
