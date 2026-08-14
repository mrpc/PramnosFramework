<?php

declare(strict_types=1);

namespace Pramnos\Application;

use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;
use Pramnos\Framework\Factory;

/**
 * Base class for application services.
 *
 * In the services-oriented application style a service owns one slice of
 * application logic together with its data access: controllers stay thin and
 * delegate, the queries live behind intention-revealing methods, and nothing is
 * hung off an ActiveRecord model. Until now those classes had no framework base
 * — which read as a virtue (nothing to learn, nothing to inherit) and cost two
 * things:
 *
 * 1. **They were invisible.** `Model` appears in the debug toolbar because it is
 *    a framework base with load/save hooks to record from. A plain service has
 *    no such seam, so in a Services + API + SPA project the toolbar's domain tab
 *    was empty for a request that had done all of its work in services.
 * 2. **Every service re-implemented the same three lines** — take an optional
 *    `Database`, fall back to the factory, keep it in a property — and each copy
 *    was free to connect at construction time, which a unit test does not want.
 *
 * Extending this class fixes both without changing how a service is written:
 *
 * ```php
 * class InvoiceService extends \Pramnos\Application\Service
 * {
 *     public function overdue(int $days): array
 *     {
 *         return $this->measure('overdue', fn() => $this->queryBuilder('invoices')
 *             ->where('due_at', '<', gmdate('Y-m-d', time() - $days * 86400))
 *             ->where('paid', 0)
 *             ->get());
 *     }
 * }
 * ```
 *
 * The connection is resolved on first use, not in the constructor, so
 * `new InvoiceService()` in a test that never touches the database never opens
 * one. Instrumentation is automatic for the fact that the service ran, and
 * opt-in — one `measure()` call — for what a single method cost; a
 * container-resolved timing proxy that needs neither is a later step, and waits
 * until services are actually resolved through the container.
 *
 * Recording never affects behaviour: with no toolbar registered (production, or
 * any CLI run) the calls below find no collector and return immediately.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license     MIT
 */
abstract class Service
{
    /**
     * The connection this service reads and writes through.
     *
     * Nullable and lazily filled by {@see database()} rather than typed
     * non-null and assigned in the constructor: a subclass is free to declare
     * its own constructor and forget `parent::__construct()`, and an
     * uninitialised typed property would then throw on first use — an error
     * about the framework, in a class whose author never mentioned a database.
     *
     * @var Database|null
     */
    protected ?Database $database = null;

    /**
     * @param Database|null $database The connection to use, or null for the
     *                                application's default one, resolved on
     *                                first use.
     */
    public function __construct(?Database $database = null)
    {
        $this->database = $database;
        self::recordUse(static::class);
    }

    /**
     * The connection, resolved on first use.
     *
     * **Converting an existing class: pass the connection explicitly.** This falls back to
     * `Factory::getDatabase()`, which is right for a service written against this base and
     * wrong for one being moved onto it. A class that previously reached its database some
     * other way — an application-level singleton, an injected handle, a second connection —
     * silently changes which database it talks to the moment its constructor is left
     * defaulted. Nothing reports it, every query still succeeds, and they succeed somewhere
     * else.
     *
     * A consumer converting one class caught this before it shipped: 59 call sites
     * constructed it, and it had been built on an application-level `getInstance()`. Had the
     * two resolvers differed, a conversion sold as observability would have quietly
     * repointed all 59. They passed the instance in and pinned it with a test, which is the
     * right shape:
     *
     * ```php
     * // Converting: keep the handle the class already had
     * public function __construct(MyDatabase $db)
     * {
     *     parent::__construct($db);
     * }
     * ```
     *
     * @return Database
     */
    protected function database(): Database
    {
        if ($this->database === null) {
            $this->database = Factory::getDatabase();
        }
        return $this->database;
    }

    /**
     * A query builder on this service's connection.
     *
     * The builder is the only layer that knows the dialect — it resolves a
     * qualified name to a schema on PostgreSQL and a prefixed table on MySQL,
     * quotes per driver, and binds parameters instead of interpolating them. A
     * service that hand-builds SQL gives all of that up, so the shortest path
     * from a service to its data goes through here.
     *
     * @param  string|null $table Optional table to start from.
     * @return QueryBuilder
     */
    protected function queryBuilder(?string $table = null): QueryBuilder
    {
        $builder = $this->database()->queryBuilder();
        return $table === null ? $builder : $builder->table($table);
    }

    /**
     * Run one operation, and record what it cost.
     *
     * The return value is the callback's, untouched, and an exception is
     * re-thrown after the timing is recorded — a call that failed is exactly the
     * one worth seeing in the toolbar, and swallowing it here would be a
     * debugging aid that hides bugs.
     *
     * @template T
     * @param  string       $operation Name to show in the toolbar, e.g. `overdue`
     * @param  callable():T $callback  The work to run and time
     * @return mixed The callback's return value
     */
    protected function measure(string $operation, callable $callback): mixed
    {
        $started = microtime(true);
        try {
            return $callback();
        } finally {
            self::recordOperation(
                static::class,
                $operation,
                (microtime(true) - $started) * 1000
            );
        }
    }

    /**
     * Note that this service took part in the request.
     *
     * @param  string $class
     * @return void
     */
    private static function recordUse(string $class): void
    {
        self::collector()?->record($class);
    }

    /**
     * Note one named operation and its duration.
     *
     * @param  string $class
     * @param  string $operation
     * @param  float  $milliseconds
     * @return void
     */
    private static function recordOperation(string $class, string $operation, float $milliseconds): void
    {
        self::collector()?->record($class, $operation, $milliseconds);
    }

    /**
     * The services collector, when a toolbar is collecting at all.
     *
     * Anything thrown on the way there is swallowed: the toolbar is an
     * observer, and instrumentation must never be the reason a request fails.
     *
     * @return \Pramnos\Debug\Collectors\ServicesCollector|null
     */
    private static function collector(): ?\Pramnos\Debug\Collectors\ServicesCollector
    {
        try {
            $collector = \Pramnos\Debug\DebugBar::getInstance()->getCollector('services');
            return $collector instanceof \Pramnos\Debug\Collectors\ServicesCollector
                ? $collector
                : null;
        // @codeCoverageIgnoreStart
        // The catch is not reachable from a test: the lookup is an array read on
        // a typed private property, and the singleton's constructor takes no
        // arguments, so there is no input that makes either throw. It is kept
        // because the alternative is a service method failing on its way to
        // annotate itself — and because the same guard in Model has outlived
        // several changes to how collectors are found.
        } catch (\Throwable) {
            return null;
        }
        // @codeCoverageIgnoreEnd
    }
}
