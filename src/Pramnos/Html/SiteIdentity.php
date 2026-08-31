<?php

declare(strict_types=1);

namespace Pramnos\Html;

use Pramnos\Application\Settings;

/**
 * What this site *is*, said in a form a machine can put in a knowledge graph.
 *
 * Every page this framework rendered carried a `BreadcrumbList` and nothing else. There was no
 * `@type` describing the site itself — no name, no logo, no canonical home — so a search engine or
 * a language model had the shape of a page's position in a hierarchy and no idea what the
 * hierarchy belonged to. `Document::addStructuredData()` existed the whole time and was called
 * from nowhere in the framework.
 *
 * Two objects, because they answer two different questions:
 *
 * - **`Organization`** — who publishes this. The entity a knowledge panel is built around, and
 *   what a model cites when it names a source.
 * - **`WebSite`** — the site as a thing on the web, with its canonical URL. It carries
 *   `SearchAction` when there is something to search, which is what makes a sitelinks search box
 *   possible.
 *
 * ### It states only what is known
 *
 * A logo that was not configured is absent, not `""`. A `SearchAction` appears only when
 * {@see \Pramnos\Search\Registry} actually has sources. Structured data is a set of assertions,
 * and an empty assertion is a false one — `"logo": ""` says this organisation has no logo, which
 * is not what an unset field meant.
 *
 * This is the same rule the head renderer follows for meta tags and the one `Seo::jsonLd()` has
 * documented from the start.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class SiteIdentity
{
    /**
     * The `Organization` + `WebSite` graph for this installation, as a `<script>` block.
     *
     * Empty when the site has no name, because a name is the one field both objects need to be
     * worth anything. An `Organization` with no `name` is an assertion that something publishes
     * this site and a refusal to say what.
     */
    public static function jsonLd(): string
    {
        $graph = self::graph();

        return $graph === [] ? '' : Seo::jsonLd($graph);
    }

    /**
     * The graph as data, so an application can extend it before it is rendered.
     *
     * @return array<string, mixed>
     */
    public static function graph(): array
    {
        $name = trim((string) self::setting('sitename'));

        if ($name === '') {
            return [];
        }

        $url = self::url();

        $organization = ['@type' => 'Organization', 'name' => $name, 'url' => $url];
        $logo         = trim((string) self::setting('sitelogo'));

        if ($logo !== '') {
            $organization['logo'] = self::absolute($logo);
        }

        $sameAs = self::sameAs();

        if ($sameAs !== []) {
            // `sameAs` is how an organisation is joined to the profiles that describe it
            // elsewhere. It is the single strongest signal that two names are one entity.
            $organization['sameAs'] = $sameAs;
        }

        $website = ['@type' => 'WebSite', 'name' => $name, 'url' => $url];
        $search  = self::searchAction($url);

        if ($search !== []) {
            $website['potentialAction'] = $search;
        }

        return [
            '@context' => 'https://schema.org',
            '@graph'   => [$organization, $website],
        ];
    }

    /**
     * `SearchAction`, but only where there is something to search.
     *
     * Declaring one that leads to a page returning nothing is worse than declaring none: it is
     * offered to a reader, tried once, and teaches them the site is broken.
     *
     * @return array<string, mixed>
     */
    private static function searchAction(string $url): array
    {
        try {
            if (!\Pramnos\Search\Registry::hasSources()) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }

        return [
            '@type'       => 'SearchAction',
            'target'      => [
                '@type'       => 'EntryPoint',
                'urlTemplate' => $url . '/search?q={search_term_string}',
            ],
            'query-input' => 'required name=search_term_string',
        ];
    }

    /**
     * Profiles elsewhere that are this same organisation.
     *
     * @return list<string>
     */
    private static function sameAs(): array
    {
        $configured = self::setting('social_profiles');

        if (is_string($configured)) {
            // A newline- or comma-separated list, because that is what a settings textarea gives.
            $configured = preg_split('~[\r\n,]+~', $configured) ?: [];
        }

        if (!is_array($configured)) {
            return [];
        }

        $urls = [];

        foreach ($configured as $candidate) {
            $candidate = trim((string) $candidate);

            // Validated rather than trusted: a malformed entry in `sameAs` does not fail loudly,
            // it quietly stops the whole object being matched to the entity it names.
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_URL) !== false) {
                $urls[] = $candidate;
            }
        }

        return array_values(array_unique($urls));
    }

    /** The site root, without a trailing slash — the form a URL is compared in. */
    private static function url(): string
    {
        return rtrim(defined('sURL') ? (string) sURL : '', '/');
    }

    /** A configured path made absolute, so the value is usable away from this page. */
    private static function absolute(string $path): string
    {
        if (preg_match('~^https?://~i', $path) === 1) {
            return $path;
        }

        return self::url() . '/' . ltrim($path, '/');
    }

    /** A setting, or null when settings cannot be read at all. */
    private static function setting(string $key): mixed
    {
        try {
            return Settings::getSetting($key);
        } catch (\Throwable) {
            return null;
        }
    }
}
