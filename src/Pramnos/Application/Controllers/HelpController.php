<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Assist\Help;
use Pramnos\Assist\HelpBook;

/**
 * `/admin/Help` — the installation's help pages, from `app/help/` and the framework's own.
 *
 *   - display()  the pages by section, and a search (`?q=`)
 *   - page()     one page (`?p=users/edit`)
 *   - image()    a picture a page shows (`?f=images/refund.png`), to somebody signed in
 *
 * For anybody inside the administration area — its floor, not a screen's: help is not a
 * privilege. Under `admin_access = permissions` it is granted like any screen, by `admin.help`.
 * See {@see \Pramnos\Assist\Help} for where pages come from and how they are written.
 */
class HelpController extends Controller
{
    /** The administration ability that opens this screen — its menu item's id. */
    protected string $adminAbility = 'admin.help';

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'page', 'image']);
        parent::__construct($application);
    }

    /** The pages this screen shows: the administration's. */
    protected function book(): HelpBook
    {
        return Help::admin();
    }

    /** Without the `help` feature there is no such screen. */
    protected function notHere(): string
    {
        http_response_code(404);
        \Pramnos\Framework\Factory::getDocument('raw');

        return '';
    }

    /** The area's own floor: whoever may enter the administration may read its help. */
    protected function adminFloor(): int
    {
        return \Pramnos\Http\AdminArea::minUserType();
    }

    /** The pages by section, or those that answer `?q=`. */
    public function display(): mixed
    {
        if (!Help::enabled()) {
            return $this->notHere();
        }

        $query = trim((string) ($_GET['q'] ?? ''));

        \Pramnos\Framework\Factory::getDocument()->title = t('Help');

        $view          = $this->getView('help');
        $view->query   = $query;
        $view->found   = $query === '' ? null : $this->book()->search($query, 20);
        // Built here and set once: a view's properties are magic, and `[]=` on one is lost.
        $bySection = [];
        foreach ($this->book()->all() as $page) {
            $bySection[$page['section'] !== '' ? $page['section'] : t('Help')][] = $page;
        }
        $view->bySection = $bySection;
        $view->pageUrl = fn (string $slug): string => $this->book()->url($slug);
        $view->listUrl = $this->book()->url();

        return $view->display('help');
    }

    /** One page, by `?p=`. */
    public function page(): mixed
    {
        if (!Help::enabled()) {
            return $this->notHere();
        }

        $page = $this->book()->page((string) ($_GET['p'] ?? ''));

        if ($page === null) {
            $this->addError(t('There is no such help page.'));
            $this->redirect($this->book()->url());

            return null;
        }

        \Pramnos\Framework\Factory::getDocument()->title = $page['title'];

        $view          = $this->getView('help');
        $view->page    = $page;
        $view->html    = $this->book()->pageHtml($page);
        $view->listUrl = $this->book()->url();

        return $view->display('page');
    }

    /** A picture beside the pages — only inside a help directory, only of a picture's type. */
    public function image(): mixed
    {
        if (!Help::enabled()) {
            return $this->notHere();
        }

        $file = $this->book()->image((string) ($_GET['f'] ?? ''));

        if ($file === null) {
            http_response_code(404);
            \Pramnos\Framework\Factory::getDocument('raw');

            return '';
        }

        \Pramnos\Framework\Factory::getDocument('raw');
        if (!headers_sent()) {
            $type = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            header('Content-Type: ' . ($type === 'svg' ? 'image/svg+xml' : 'image/' . ($type === 'jpg' ? 'jpeg' : $type)));
            header('Cache-Control: private, max-age=3600');
            // An SVG is a document: shown as a picture, never run as one.
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
            header('X-Content-Type-Options: nosniff');
        }

        return (string) file_get_contents($file);
    }
}
