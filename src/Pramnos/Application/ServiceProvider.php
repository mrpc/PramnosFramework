<?php

namespace Pramnos\Application;

/**
 * Base class for framework and add-on Service Providers.
 *
 * ## Lifecycle
 *
 * The framework calls providers in two phases during `Application::init()`:
 *
 * 1. **register** — bind things into the application or perform any work that
 *    must happen before other providers are booted.  All providers have their
 *    `register()` called before any `boot()` is called.
 * 2. **boot** — start services, register routes/listeners/scheduled tasks.
 *    At this point every other provider's `register()` has already run.
 *
 * Both methods have empty default implementations so subclasses only need to
 * override the phases they actually use.
 *
 * ## Linking a provider to a feature
 *
 * Register the provider FQCN when declaring the feature:
 *
 * ```php
 * FeatureRegistry::register('my_feature', [
 *     'provider' => MyFeature\MyServiceProvider::class,
 * ]);
 * ```
 *
 * The framework will then instantiate it automatically for every application
 * that has `'my_feature'` in its `app.php` features list.
 *
 * ## Adding a provider without a feature key
 *
 * For providers that are not tied to a registered feature (e.g. application-
 * level providers), use `Application::addProvider()` before `init()`:
 *
 * ```php
 * $app->addProvider(new MyApp\Providers\AppServiceProvider($app));
 * $app->init();
 * ```
 *
 * ## Example
 *
 * ```php
 * namespace MyAddon;
 *
 * use Pramnos\Application\ServiceProvider;
 *
 * class MyServiceProvider extends ServiceProvider
 * {
 *     public function register(): void
 *     {
 *         // bind MyService so other providers can rely on it during boot()
 *     }
 *
 *     public function boot(): void
 *     {
 *         // register routes, listeners, scheduled tasks …
 *     }
 * }
 * ```
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @package     PramnosFramework
 * @subpackage  Application
 */
abstract class ServiceProvider
{
    /**
     * The application instance passed at construction time.
     *
     * @var Application
     */
    protected Application $app;

    /**
     * @param Application $app The current application instance.
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Register any bindings or early-bootstrap work.
     *
     * Called for all providers before any provider's boot() runs.
     */
    public function register(): void
    {
    }

    /**
     * Bootstrap services.
     *
     * Called after all providers have been registered.  Safe to rely on
     * anything that was bound during another provider's register().
     */
    public function boot(): void
    {
    }
}
