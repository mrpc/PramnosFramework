<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;

/**
 * `/SiteMessages/dismiss/{id}` — the close button of a site message ({@see \Pramnos\Html\SiteMessages}).
 *
 * A POST with the session's token, from the person the message is for; back to the page it was
 * on — a path on this site, never an address elsewhere.
 */
class SiteMessages extends Controller
{
    /** Registers the action: signed in, a POST with the session's token. */
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['dismiss']);
        $this->addWriteAction(['dismiss']);
        parent::__construct($application);
    }

    /** Close a message, and go back. */
    public function dismiss(mixed $id = null): mixed
    {
        $user = \Pramnos\User\User::current();
        if ($user !== null) {
            \Pramnos\Audience\SiteMessages::dismiss((int) \Pramnos\Http\Request::staticGetOption(), (int) $user->userid);
        }
        $this->redirect(self::back((string) ($_POST['back'] ?? '')));

        return null;
    }

    /** Where to go back to: a path on this site, or its home. */
    public static function back(string $back): string
    {
        $home = defined('sURL') ? (string) \sURL : '/';

        return preg_match('~^/(?![/\\\\])~', $back) === 1 ? rtrim($home, '/') . $back : $home;
    }
}
