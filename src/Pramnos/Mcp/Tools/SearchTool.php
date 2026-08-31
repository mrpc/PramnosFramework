<?php

declare(strict_types=1);

namespace Pramnos\Mcp\Tools;

use Pramnos\Mcp\ScopedMcpTool;
use Pramnos\Search\Registry;

/**
 * One term, across everything this application has registered, for the person holding the token.
 *
 * The tool worth offering first, because it needs no code from the application beyond the
 * `Registry::register()` calls it already makes for its own search box. An installation with a
 * search box has one line to write:
 *
 * ```php
 * PublicRegistry::add(new \Pramnos\Mcp\Tools\SearchTool());
 * ```
 *
 * It is not registered for you. Enabling an authenticated endpoint should not also decide what
 * that endpoint exposes — the safe default is the empty one, even when the thing being exposed is
 * already permission-scoped.
 *
 * ### The permission model is already right, which is why this is safe
 *
 * {@see Registry} was built for a search box that several kinds of user share. Each source
 * declares a `permission`, each row is scoped by a `filter` callable that receives the current
 * user, and **both fail closed** — a source whose permission cannot be evaluated, or whose filter
 * does not return a scope, is dropped from the answer rather than returned unscoped. A dropped
 * source leaves no trace, not an empty group, because an empty group named "Invoices" tells
 * somebody who may not see invoices that invoices exist.
 *
 * None of that had to be built here. An MCP caller is a signed-in person with a token, so the
 * question this tool asks is the question the search box already asks, and the answer is scoped by
 * the same code.
 *
 * ### Grouped, not ranked, and that carries through
 *
 * `Registry` returns results grouped by source with a per-source cap and no score across sources,
 * on the grounds that a relevance number comparing a username to an invoice line is invented. That
 * shape is *better* for a language model than a flat ranked list: "5 users, 3 orders" is a fact it
 * can reason about, where a merged list invites it to treat position as meaning.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class SearchTool implements ScopedMcpTool
{
    /** Per source, matching the search box. Enough to answer, small enough to read. */
    public const PER_SOURCE = 5;

    public function name(): string
    {
        return 'search';
    }

    public function description(): string
    {
        $labels = Registry::labels();

        $what = $labels === []
            ? 'this application'
            : implode(', ', $labels);

        return 'Search ' . $what . ' for a term, and return matches grouped by what they are. '
            . 'Only what the signed-in person is allowed to see is returned.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'What to look for. Two characters or more.',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'How many results per group. Default ' . self::PER_SOURCE . '.',
                    'minimum'     => 1,
                    'maximum'     => 25,
                ],
            ],
            'required'   => ['query'],
        ];
    }

    /**
     * @param  array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function execute(array $input): mixed
    {
        $term = trim((string) ($input['query'] ?? ''));

        if ($term === '') {
            // Not an error. An empty term is a question with no content, and querying every
            // source for '' returns the first page of everything — which is the one answer this
            // must never give to a caller asking for nothing.
            return ['query' => '', 'total' => 0, 'groups' => []];
        }

        if (!Registry::hasSources()) {
            /*
             * Said plainly, because the alternative reads as "nothing matched".
             *
             * An installation that has registered no sources returns zero results for every term.
             * A model told "0 results" concludes the thing does not exist and says so to the
             * person; told that nothing is searchable, it says that instead.
             */
            return [
                'query'  => $term,
                'total'  => 0,
                'groups' => [],
                'note'   => 'This installation has registered no searchable sources.',
            ];
        }

        $limit = (int) ($input['limit'] ?? self::PER_SOURCE);
        $limit = max(1, min(25, $limit));

        return Registry::query($term, $limit);
    }

    /**
     * Reading, so the scope for reading a person's own data.
     *
     * Not a scope of its own. What this returns is whatever the sources return for this person,
     * and those are already gated per source; a `search` scope on top would be a second permission
     * that says nothing the first does not, and one more thing for a consent screen to explain.
     */
    public function requiredScope(): string
    {
        return 'user';
    }
}
