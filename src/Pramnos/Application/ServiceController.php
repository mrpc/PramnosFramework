<?php

namespace Pramnos\Application;

/**
 * A `Controller` for code that has no MVC request behind it.
 *
 * `Model::__construct()` requires a `Controller`. That reads like a hard dependency
 * on the MVC stack and is not one: of the five references to `$this->controller`
 * inside `Model`, two are real uses — `getModel()` delegation and the error path —
 * and the other three exist only to hand the same controller to the next model it
 * constructs. `Orm\Relations\Relation` says as much in a comment that used to give
 * the wrong reason: *"so the model can reach the database"*. It does not; `Model`
 * calls `Database::getInstance()` directly.
 *
 * So a service, a queue worker, a console command or an attribute-routed API
 * controller can use models today by constructing a bare `Controller`. Measured at
 * **1.54 µs**, with the enclosing `Application::getInstance()` at 1.3 ms cold and
 * 0.002 ms warm — which is to say the dependency costs nothing and looks like it
 * costs a great deal.
 *
 * This class exists so that fact has a name. Every application that worked it out
 * invented its own, and each one had to rediscover that `new Controller()` is safe
 * to call.
 *
 * ```php
 * $post = new \App\Models\Post(ServiceController::shared());
 * $post->load($id);
 * ```
 *
 * ## Use the shared instance
 *
 * A service that builds several models wants one controller, not one per model:
 * models constructed from the same controller can find each other through
 * `getModel()`, and constructing a fresh controller each time re-runs a reflection
 * and a permissions normalisation for nothing.
 *
 * ## What it does not do
 *
 * It grants no permissions. `Controller::__construct()` takes a permissions array
 * and this passes none, so `can()` and any `#[RequirePermission]`-style check answer
 * exactly as they would for a request with no permissions at all. Code outside a
 * request has no user, and a controller that quietly behaved as though it did would
 * be a much worse thing to have in the framework than the small inconvenience it
 * removes.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class ServiceController extends Controller
{
    /**
     * The shared instance, built on first use.
     *
     * @var self|null
     */
    private static ?self $shared = null;

    /**
     * A controller shared by everything in this process that needs one.
     *
     * @return self
     */
    public static function shared(): self
    {
        if (self::$shared === null) {
            self::$shared = new self();
        }

        return self::$shared;
    }

    /**
     * Drops the shared instance.
     *
     * For tests: the instance holds a reference to the `Application` that was current
     * when it was built, and a test that swaps applications would otherwise inherit
     * the previous one — the kind of leak that shows up as a failure three classes
     * later, in whichever test happens to run next.
     *
     * @return void
     */
    public static function forget(): void
    {
        self::$shared = null;
    }
}
