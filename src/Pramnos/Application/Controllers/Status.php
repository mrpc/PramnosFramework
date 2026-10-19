<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

/**
 * `/Status` — the public status page: whether the service is up, in words a person reads.
 *
 *   - `GET /Status`          the page: the overall verdict and each check's, by name
 *   - `GET /Status/check`    the full report as JSON, 200 when healthy and 503 when not
 *   - `GET /Status/status`   the verdict flattened, for a monitor that reads three keys
 *
 * The two monitor endpoints are {@see Health}'s; the page is what a footer's "Status" link should
 * reach, rather than a wall of JSON. It shows each check's verdict and **never** its message or
 * details: those are measurements of the host — the database's version, the disk's free space —
 * on a page with nothing in front of it. The administration's `/health` dashboard shows them,
 * behind a sign-in.
 *
 * Answers 200 whatever the verdict: it is a page, and a 503 would keep a browser from drawing
 * the very explanation somebody came for. `check()` is the endpoint whose status code means
 * something.
 */
class Status extends Health
{
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        parent::__construct($application);
        // Health's display() is the signed-in dashboard; here it is the public page.
        $this->actions_auth = array_values(array_diff($this->actions_auth, ['display']));
        $this->actions[]    = 'display';
    }

    /** The page. */
    public function display(): mixed
    {
        $report = \Pramnos\Health\HealthRegistry::runAll();

        \Pramnos\Framework\Factory::getDocument()->title = t('Service status');

        $view            = $this->getView('status');
        $view->report    = $report;
        $view->checkedAt = date('Y-m-d H:i:s T');

        return $view->display();
    }
}
