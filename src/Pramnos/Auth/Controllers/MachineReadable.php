<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Application\Settings;
use Pramnos\Search\Registry;

/**
 * What this site tells a machine that arrives without being sent.
 *
 * Three audiences, and the framework answered none of them. A crawler reads `robots.txt`. A
 * language model reads `llms.txt`. Both were absent, which does not mean "no policy" — it means
 * the policy is whatever the visiting crawler decides, and for the AI crawlers that is a decision
 * nobody here made.
 *
 * This is the half of GEO that actually moves: an MCP endpoint serves a model that already knows
 * you exist and holds somebody's token, and these serve the one that does not.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class MachineReadable extends \Pramnos\Application\Controller
{
    /**
     * The AI crawlers worth naming, because silence about them is not neutrality.
     *
     * Each of these reads `robots.txt` and honours it. Not listing them means each decides for
     * itself, and the decisions differ — `Google-Extended` opts a site out of model training
     * while leaving Search untouched, which is a distinction a site cannot express by saying
     * nothing.
     */
    public const AI_AGENTS = [
        'GPTBot', 'ChatGPT-User', 'OAI-SearchBot',
        'ClaudeBot', 'Claude-User', 'Claude-SearchBot',
        'PerplexityBot', 'Perplexity-User',
        'Google-Extended', 'Applebot-Extended', 'CCBot', 'meta-externalagent',
    ];

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addaction(['robots', 'llms']);
        parent::__construct($application);
    }

    /**
     * `/robots.txt`
     *
     * Generated rather than a file, because the one line that matters — the `Sitemap:` pointer —
     * is derived from the installation's own URL, and a static file in a scaffold is a static
     * file with somebody else's domain in it.
     */
    public function robots(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=3600');

        $url   = rtrim(defined('sURL') ? (string) sURL : '', '/');
        $lines = ['User-agent: *'];

        /*
         * The paths no crawler should be in, listed here rather than left to `noindex`.
         *
         * `noindex` keeps a page out of an index after it has been fetched. `Disallow` keeps it
         * from being fetched. For an authentication server the difference matters: every one of
         * these either costs a session, sends mail, or answers differently per visitor, and a
         * crawler walking them is a crawler generating side effects.
         */
        foreach (['/admin/', '/oauth/', '/account/', '/devpanel/', '/adminer'] as $path) {
            $lines[] = 'Disallow: ' . $path;
        }

        $lines[] = '';

        /*
         * The AI crawlers, each named, with the site's own answer.
         *
         * Default `allow`, because a framework must not decide a site's licensing posture — but
         * it must make the decision *visible and settable*, which absence never does.
         */
        $policy = strtolower(trim((string) self::setting('ai_crawler_policy'))) ?: 'allow';

        foreach (self::AI_AGENTS as $agent) {
            $lines[] = 'User-agent: ' . $agent;
            $lines[] = $policy === 'disallow' ? 'Disallow: /' : 'Allow: /';
            $lines[] = '';
        }

        /*
         * Named only when there is one.
         *
         * A `Sitemap:` line is an instruction, and an instruction pointing at a 404 is worse
         * than silence: a crawler that follows it learns the site is broken rather than that
         * it has no sitemap. The framework does not ship a sitemap generator, so the honest
         * question is whether this installation serves a file at that address — which
         * `sitemap_url` answers when it is somewhere else.
         */
        $sitemap = trim((string) self::setting('sitemap_url'));
        if ($sitemap === '' && $url !== '' && self::servesSitemap()) {
            $sitemap = $url . '/sitemap.xml';
        }
        if ($sitemap !== '') {
            $lines[] = 'Sitemap: ' . $sitemap;
        }

        \Pramnos\Framework\Factory::getDocument('raw')
            ->setContent(implode("\n", $lines) . "\n");
    }

    /**
     * `/llms.txt`
     *
     * The GEO counterpart of a sitemap: a short markdown document telling a language model what
     * this site is and where the things it should read actually are. A crawler follows links; a
     * model arriving cold has to guess, and guessing is how a site gets described wrongly and
     * confidently.
     *
     * It is deliberately short. The format's whole premise is that it fits in a context window
     * alongside the question somebody actually asked, and a `llms.txt` that reproduces the site
     * is a `llms.txt` nobody reads to the end.
     */
    public function llms(): void
    {
        header('Content-Type: text/markdown; charset=utf-8');
        header('Cache-Control: public, max-age=3600');

        $url  = rtrim(defined('sURL') ? (string) sURL : '', '/');
        $name = trim((string) self::setting('sitename')) ?: 'This site';
        $what = trim((string) self::setting('sitedescription'));

        $lines = ['# ' . $name, ''];

        if ($what !== '') {
            $lines[] = '> ' . $what;
            $lines[] = '';
        }

        $lines[] = '## Documentation';
        $lines[] = '';
        $lines[] = '- [Documentation](' . self::docsUrl()
            . '): how to integrate with this service.';
        $lines[] = '';

        /*
         * The MCP endpoint, announced here or nowhere.
         *
         * This is the one honest link between the two halves of this work. A model that reads
         * this learns the site has tools it can call and where to authenticate for them;
         * without it the endpoint is a service nobody discovers, which is the failure this whole
         * session kept finding — machinery complete and unreachable.
         */
        if (self::hasPublicTools()) {
            $lines[] = '## Tools';
            $lines[] = '';
            $lines[] = '- MCP endpoint: `' . self::mcpUrl() . '` (JSON-RPC over POST)';
            $lines[] = '- Authorization: [OAuth 2.1](' . $url
                . '/.well-known/oauth-protected-resource)';
            $lines[] = '';
        }

        if (self::hasSearch()) {
            $lines[] = '## Search';
            $lines[] = '';
            $lines[] = '- ' . implode(', ', Registry::labels()) . ' are searchable.';
            $lines[] = '';
        }

        \Pramnos\Framework\Factory::getDocument('raw')
            ->setContent(implode("\n", $lines));
    }

    /**
     * Where the generated API documentation is served.
     *
     * `<site>/api/docs/`, because that is where `init` writes it — and asked through
     * {@see \Pramnos\Application\Api::baseUrl()} rather than hard-coded, so a project
     * that moved the API front controller moves this with it.
     *
     * It was `<site>/docs`, which is nothing: a machine-readable file's whole job is to
     * be read by something that will not look around for the right URL.
     */
    private static function docsUrl(): string
    {
        $base = \Pramnos\Application\Api::baseUrl();

        return $base === '' ? '' : rtrim($base, '/') . '/docs/';
    }

    /**
     * Where the MCP endpoint answers.
     *
     * Inside the API's version prefix (`/api/1.0/mcp`), which is where the scaffolded
     * route puts it — not at the site root, where it was advertised and where nothing
     * has ever answered.
     */
    private static function mcpUrl(): string
    {
        $app    = \Pramnos\Application\Application::currentInstance();
        $prefix = is_object($app)
            ? trim((string) ($app->applicationInfo['api']['prefix'] ?? ''), '/')
            : '';

        if ($prefix !== '') {
            return rtrim(defined('sURL') ? (string) sURL : '', '/') . '/' . $prefix . '/mcp';
        }

        // No configured prefix: build the address the API would route, from the two things
        // it builds its own from. Better than falling back to `<site>/mcp`, which is where
        // this was announced and where nothing has ever answered.
        $base = \Pramnos\Application\Api::baseUrl();

        return $base === '' ? '' : rtrim($base, '/') . '/'
            . \Pramnos\Application\Api::version() . '/mcp';
    }

    /**
     * Is a file actually served at `/sitemap.xml`?
     *
     * Asked of the document root, because that is the same question a crawler asks. A
     * `sitemap_url` setting overrides this for an installation that generates one
     * elsewhere.
     */
    private static function servesSitemap(): bool
    {
        $root = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');

        return $root !== '' && is_file(rtrim($root, '/') . '/sitemap.xml');
    }

    private static function hasPublicTools(): bool
    {
        try {
            return \Pramnos\Mcp\PublicRegistry::hasTools();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function hasSearch(): bool
    {
        try {
            return Registry::hasSources();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function setting(string $key): mixed
    {
        try {
            return Settings::getSetting($key);
        } catch (\Throwable) {
            return null;
        }
    }
}
