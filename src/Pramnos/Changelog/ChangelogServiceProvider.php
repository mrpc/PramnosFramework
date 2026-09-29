<?php

declare(strict_types=1);

namespace Pramnos\Changelog;

use Pramnos\Application\ServiceProvider;
use Pramnos\Database\WriteSpool;

/**
 * Bootstraps the change log.
 *
 * Activated by listing `changelog` in app.php features, which also decides whether the
 * tables exist at all — the migrations live under a directory named for the feature, and
 * an application that does not enable it gets none of them.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class ChangelogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Nothing to bind: the writer is a listener, not a service.
    }

    public function boot(): void
    {
        // The JSON columns are encoded by the writer itself, before each append — see
        // ChangelogWriter::registerEncoders() for why here was not enough.
        ChangelogWriter::registerEncoders();
        ChangelogWriter::listen();
    }
}
