<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

/**
 * `POST /Impersonation` — "Return to your account" from a page an administrator is seeing as
 * somebody else: back to the administrator, without a password, by the one-time way back
 * {@see \Pramnos\Auth\Impersonation} keeps. A POST with the session's token. Reached at its own
 * address in every application.
 */
class Impersonation extends \Pramnos\Application\Controller
{
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addWriteAction(['display']);
        parent::__construct($application);
    }

    /** Back to the administrator, and to the administration's front page; or a note why not. */
    public function display(): mixed
    {
        if (\Pramnos\Auth\Impersonation::end()) {
            $this->addMessage('You are back in your own account.');
            $this->redirect(adminUrl());
        } else {
            $this->addError('There is no account to return to.');
            $this->redirect(defined('sURL') ? \sURL : '/');
        }

        return null;
    }
}
