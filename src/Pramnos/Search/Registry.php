<?php

declare(strict_types=1);

namespace Pramnos\Search;

use Pramnos\Application\ApiList\ApiListQuery;
use Pramnos\Application\ApiList\ApiListSource;
use Pramnos\Application\Controller;

/**
 * The registry behind one search box that covers several entities.
 *
 * ```php
 * // app/search.php
 * use Pramnos\Search\Registry;
 *
 * Registry::register('Users', \Pramnos\User\User::class, [
 *     'display' => ['username', 'email'],
 *     'url'     => '/admin/users/:id',
 * ]);
 * ```
 *
 * ## Why this is framework code and per-entity search is not
 *
 * Searching **one** entity was already solved: every model implements
 * {@see ApiListSource}, and {@see ApiListQuery} turns a term into a query with paging,
 * per-field search, dialect-correct `ILIKE`/`LIKE` and honest totals. A generated CRUD
 * gets that for free, and so does a `Datatable`.
 *
 * What no entity can implement for itself is the **aggregate**: one term, several
 * entities, grouped results. That is the only thing here — a registry, and a loop over
 * the engine that already exists.
 *
 * ## Configuration, not markup
 *
 * A source declares *which columns to show* and *where a result links to*. It does not
 * render. The class this replaces required each provider to return objects with a
 * `render()` method, which put HTML inside models — so the admin's markup could only be
 * changed by editing the model layer.
 *
 * ## Who sees what
 *
 * The endpoint guards the box as a whole. Per **source**, `permission` decides whether a
 * viewer sees that entity at all; per **row**, a `filter` callable receives the current
 * user and returns the WHERE body that scopes it. Both fail closed: a source whose
 * permission cannot be evaluated, or whose filter callable does not return a scope, is
 * dropped from the response rather than returned unscoped.
 *
 * A dropped source leaves **no trace** in the response — not an empty group. An empty
 * group named "Invoices" tells a viewer who may not see invoices that invoices exist.
 *
 * ## Grouped, not ranked
 *
 * Results come back grouped by source with a cap per source. There is no score across
 * sources, deliberately: a relevance number that compares a username to an invoice line
 * is invented, and the predecessor's answer — concatenate and hope — is worse than
 * saying "5 users, 3 orders" honestly.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
final class Registry
{
    /**
     * Registered sources, keyed by label so a second registration replaces the first.
     *
     * @var array<string, array{source: mixed, options: array<string, mixed>}>
     */
    private static array $sources = [];

    /** Guard so `app/search.php` is required once per process. */
    private static bool $loaded = false;

    /**
     * Register one searchable entity.
     *
     * @param string $label   Group heading, and the key — registering the same label
     *                        twice replaces the earlier entry rather than searching it
     *                        twice.
     * @param mixed  $source  An {@see ApiListSource} instance, the class name of one, or
     *                        a callable returning one. The callable form is the escape
     *                        hatch for a model whose constructor needs more than a
     *                        controller.
     * @param array{
     *     display?: list<string>,
     *     url?: string,
     *     limit?: int,
     *     fields?: list<string>,
     *     order?: string,
     *     filter?: string|callable,
     *     permission?: string|callable
     * } $options
     *
     *  - `display` — columns to show. The first is the result title, the rest become the
     *    subtitle. Defaults to the source's own default fields, which is a guess: set it.
     *  - `url` — link pattern; `:id` is replaced with the primary key value. Omit for a
     *    result that is not a link.
     *  - `limit` — cap for this source. Defaults to the per-source cap of the query.
     *  - `fields` — columns to select. Defaults to the primary key plus `display`.
     *  - `order` — order spec passed through to {@see ApiListQuery}.
     *  - `permission` — an ability name checked with {@see \Pramnos\Auth\Gate::allows()},
     *    or a callable receiving the current user and returning a bool. A source the
     *    viewer may not see is **left out of the response entirely** — not returned as an
     *    empty group, which would confirm the entity exists.
     *  - `filter` — a WHERE body applied before the search term, for rows that must never
     *    surface (soft-deleted, another tenant's). A **callable** receives the current
     *    user and returns that body, which is how a per-viewer scope is expressed.
     */
    public static function register(string $label, mixed $source, array $options = []): void
    {
        $label = trim($label);
        if ($label === '') {
            throw new \InvalidArgumentException('A search source needs a label: it is the group heading and the registry key.');
        }

        self::$sources[$label] = ['source' => $source, 'options' => $options];
    }

    /** Whether anything is registered — for a UI that should not render an empty box. */
    public static function hasSources(): bool
    {
        return self::$sources !== [];
    }

    /**
     * The registered labels, in registration order.
     *
     * @return list<string>
     */
    public static function labels(): array
    {
        return array_keys(self::$sources);
    }

    /**
     * Load the application's search definitions.
     *
     * The same convention as `app/schedule.php`: a plain PHP file that calls the static
     * API. Idempotent within a process; returns false when there is no such file, which
     * is not an error — an application with nothing registered has no omnibox.
     *
     * @param string|null $file Absolute path; defaults to ROOT/app/search.php.
     */
    public static function loadDefinitions(?string $file = null): bool
    {
        if (self::$loaded) {
            return self::hasSources();
        }

        self::$loaded = true;

        $file ??= defined('ROOT')
            ? ROOT . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'search.php'
            : '';

        if ($file === '' || !is_file($file)) {
            return false;
        }

        require $file; // registers sources via the static API

        return self::hasSources();
    }

    /**
     * Run one term across every registered source.
     *
     * @param string $term      What was typed
     * @param int    $perSource Cap per group, unless the source sets its own `limit`
     * @return array{query: string, total: int, groups: list<array{
     *     label: string, total: int, results: list<array{id: mixed, title: string, subtitle: string, url: ?string}>
     * }>}
     */
    public static function query(string $term, int $perSource = 5): array
    {
        $term = trim($term);
        if ($term === '') {
            // Not an error: an empty box is the normal state of a search box, and
            // querying every source for '' would return the first page of everything.
            return ['query' => '', 'total' => 0, 'groups' => []];
        }

        $groups = [];
        $total  = 0;

        foreach (self::$sources as $label => $entry) {
            $group = self::querySource($label, $entry, $term, $perSource);
            if ($group === null) {
                continue;
            }
            $groups[] = $group;
            $total   += $group['total'];
        }

        return ['query' => $term, 'total' => $total, 'groups' => $groups];
    }

    /**
     * One source's group, or null when it could not be searched.
     *
     * A source that throws is skipped rather than failing the whole request — one model
     * whose table a migration has not created yet must not take the search box down with
     * it. It is logged, though: a provider that silently returns nothing forever is the
     * bug this would otherwise hide.
     *
     * @param array{source: mixed, options: array<string, mixed>} $entry
     * @return array{label: string, total: int, results: list<array<string, mixed>>}|null
     */
    private static function querySource(string $label, array $entry, string $term, int $perSource): ?array
    {
        try {
            $options = $entry['options'];

            // Before the source is even constructed: an entity the viewer may not see
            // must not cost a query, and must not be distinguishable from one that is
            // not registered at all.
            if (!self::isVisible($options)) {
                return null;
            }

            $filter = self::rowFilter($options);

            $source     = self::resolve($entry['source']);
            $limit      = max(1, (int) ($options['limit'] ?? $perSource));
            $primaryKey = $source->apiListPrimaryKey();
            $display    = self::displayColumns($source, $options);
            $fields     = $options['fields'] ?? array_values(array_unique(array_merge([$primaryKey], $display)));

            $result = ApiListQuery::run(
                $source,
                $fields,
                $term,
                (string) ($options['order'] ?? ''),
                $filter,
                '',
                '',
                null,
                null,
                1,
                $limit
            );
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'Search source "' . $label . '" failed: ' . $exception->getMessage(),
                'search'
            );

            return null;
        }

        // ApiListQuery catches a source's exception and answers with an `error` key
        // rather than propagating, so a failing source arrives here looking like a
        // successful empty result. Left unchecked that produces an empty group — the one
        // shape this class must never emit, because a group headed "Invoices" with no
        // rows still tells the viewer that invoices exist.
        if (isset($result['error'])) {
            \Pramnos\Logs\Logger::log(
                'Search source "' . $label . '" returned an error: ' . (string) $result['error'],
                'search'
            );

            return null;
        }

        $rows = is_array($result['data'] ?? null) ? $result['data'] : [];

        return [
            'label'   => $label,
            // The engine's own total, so "5 of 137" is available to the UI. Falls back to
            // the row count when the source could not count.
            'total'   => (int) ($result['pagination']['totalitems'] ?? count($rows)),
            'results' => array_values(array_map(
                static fn(array $row): array => self::formatRow($row, $primaryKey, $display, $options['url'] ?? null),
                array_filter($rows, 'is_array')
            )),
        ];
    }

    /**
     * One row, reduced to what a result line needs.
     *
     * @param array<string, mixed> $row
     * @param list<string>         $display
     * @return array{id: mixed, title: string, subtitle: string, url: ?string}
     */
    private static function formatRow(array $row, string $primaryKey, array $display, ?string $urlPattern): array
    {
        $values = [];
        foreach ($display as $column) {
            $value = $row[$column] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $values[] = trim((string) $value);
            }
        }

        $id = $row[$primaryKey] ?? null;

        return [
            'id' => $id,
            // The first non-empty display column, not simply the first: a row whose name
            // is null would otherwise produce a result with no visible text at all.
            'title'    => $values === [] ? (string) $id : (string) array_shift($values),
            'subtitle' => implode(' · ', $values),
            'url'      => $urlPattern === null || $id === null
                ? null
                : str_replace(':id', rawurlencode((string) $id), $urlPattern),
        ];
    }

    /**
     * The signed-in user, or null.
     *
     * Wrapped because `getCurrentUser()` reaches for a session and a database, and both
     * can be absent — a CLI run, a test, a request before the session starts. A callable
     * receiving null must decide for an anonymous viewer, which is a decision it can
     * make; a thrown exception here would instead drop every source that has a
     * permission or a scope, and read as "search returns nothing".
     */
    private static function currentUser(): ?object
    {
        try {
            $user = \Pramnos\User\User::getCurrentUser();
        } catch (\Throwable) {
            return null;
        }

        return is_object($user) ? $user : null;
    }

    /**
     * Whether the current viewer may see this source at all.
     *
     * No `permission` means visible: the endpoint that reaches this registry does its own
     * permission check, so a source without one inherits that grant rather than being
     * open to the world.
     *
     * A named ability goes through {@see \Pramnos\Auth\Gate}, which is fail-closed —
     * an ability with no rule, policy or stored permission behind it decides `false`.
     * That is the correct default and the one surprising one: a `permission` naming an
     * ability nothing defines hides the source from everybody, including an
     * administrator. It is a configuration error, not a leak.
     *
     * Throwing here is caught by {@see querySource()} and turned into a skipped source,
     * so a typo costs one group and a log line rather than the endpoint.
     *
     * @param array<string, mixed> $options
     */
    private static function isVisible(array $options): bool
    {
        $permission = $options['permission'] ?? null;

        if ($permission === null || $permission === '') {
            return true;
        }

        if (is_string($permission)) {
            return \Pramnos\Auth\Gate::allows($permission);
        }

        if (is_callable($permission)) {
            return $permission(self::currentUser()) === true;
        }

        // Neither a name nor a callable: a typo in a hand-edited file. Refusing is the
        // only safe reading — a `permission` key that is ignored because it could not be
        // understood is a source that looks restricted and is not.
        throw new \InvalidArgumentException('A search source permission must be an ability name or a callable.');
    }

    /**
     * The WHERE body applied before the search term.
     *
     * A callable is how a per-viewer scope is written — `fn($user) => 'tenant_id = ' .
     * (int) $user->tenantId`. It must return a string: anything else is treated as a
     * failure and throws, because a scope callable that quietly yields no filter returns
     * **every** row of the table to a viewer who should have seen a subset. Fail closed
     * is the only acceptable direction for this one.
     *
     * As with {@see isVisible()}, the throw is caught upstream and becomes a skipped
     * source — the group disappears instead of appearing unscoped.
     *
     * @param array<string, mixed> $options
     */
    private static function rowFilter(array $options): string
    {
        $filter = $options['filter'] ?? '';

        if (is_string($filter)) {
            return $filter;
        }

        if (is_callable($filter)) {
            $resolved = $filter(self::currentUser());
            if (!is_string($resolved)) {
                throw new \UnexpectedValueException(
                    'A search source filter callable must return a WHERE body as a string.'
                );
            }

            return $resolved;
        }

        throw new \InvalidArgumentException('A search source filter must be a string or a callable returning one.');
    }

    /**
     * Which columns to show.
     *
     * @param array<string, mixed> $options
     * @return list<string>
     */
    private static function displayColumns(ApiListSource $source, array $options): array
    {
        $display = $options['display'] ?? null;
        if (is_array($display) && $display !== []) {
            return array_values(array_map('strval', $display));
        }

        // A guess, and documented as one: the source's own default fields minus its key,
        // capped at two so a wide table does not produce a paragraph per result.
        $defaults = array_values(array_diff($source->apiListDefaultFields(''), [$source->apiListPrimaryKey()]));

        return array_slice($defaults, 0, 2);
    }

    /**
     * An {@see ApiListSource} from whatever was registered.
     *
     * A class name is constructed here rather than at registration time so that
     * `app/search.php` costs nothing to load: a registry of six models must not open six
     * database connections on every request, including the ones that never search.
     */
    private static function resolve(mixed $source): ApiListSource
    {
        if ($source instanceof ApiListSource) {
            return $source;
        }

        if (is_callable($source)) {
            $resolved = $source();
            if (!$resolved instanceof ApiListSource) {
                throw new \UnexpectedValueException('A search source callable must return an ApiListSource.');
            }

            return $resolved;
        }

        if (!is_string($source) || !class_exists($source)) {
            throw new \InvalidArgumentException('A search source must be an ApiListSource, a class name, or a callable returning one.');
        }

        if (!is_subclass_of($source, ApiListSource::class) && !in_array(ApiListSource::class, class_implements($source) ?: [], true)) {
            throw new \InvalidArgumentException($source . ' does not implement ApiListSource, so it cannot be searched.');
        }

        // Models take a controller; User and anything extending Framework\Base do not.
        // Checking the declared type rather than the argument count means a model with a
        // different first parameter fails loudly here instead of being handed a
        // controller it did not ask for.
        $constructor = (new \ReflectionClass($source))->getConstructor();
        $first       = $constructor?->getParameters()[0] ?? null;
        $type        = $first?->getType();

        if ($type instanceof \ReflectionNamedType && is_a($type->getName(), Controller::class, true)) {
            return new $source(new Controller());
        }

        return new $source();
    }

    /**
     * Forget every registered source.
     *
     * Test isolation only — the same reason {@see \Pramnos\Scheduling\Scheduler::reset()}
     * exists.
     */
    public static function reset(): void
    {
        self::$sources = [];
        self::$loaded  = false;
    }
}
