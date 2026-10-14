<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Assist\Help;
use Pramnos\Assist\HelpBook;

/**
 * `/Help` on the site — the visitors' help pages, from `app/help-site/`.
 *
 * The same three actions as {@see HelpController}, on the site's pages and open to everybody: a
 * visitor's help is read before signing in as often as after. It never shows the
 * administration's pages, nor the framework's, which describe the administration. An
 * application puts it on an address with `src/Controllers/Help.php` extending this class.
 */
class SiteHelpController extends HelpController
{
    /** Not an administration screen: no ability, no floor. */
    protected string $adminAbility = '';

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        // HelpController's constructor makes every action need a sign-in; this one does not.
        \Pramnos\Application\Controller::__construct($application);
    }

    /** The pages this screen shows: the site's. */
    protected function book(): HelpBook
    {
        return Help::site();
    }
}
