<?php

declare(strict_types=1);

namespace Pramnos\Cache\Page;

use Pramnos\Cache\AdapterInterface;
use Pramnos\Cache\FlatCache;
use Pramnos\Http\Request;
use Pramnos\Http\Response;

/**
 * Opt-in, config-driven full-page cache.
 *
 * ## What it is for
 *
 * An application that wants to serve anonymous and bot traffic from cache ends
 * up writing the same hundred-and-forty lines of `if` inside its front
 * controller: is this a bot, is this a language prefix we cache, is this a
 * webhook, what is the TTL for this path, has it expired, is the visitor logged
 * in. This is that, once, with the rules in configuration.
 *
 * The storage, the bot detection, the response object and the middleware
 * plumbing already existed — {@see \Pramnos\Cache\FlatCache},
 * {@see \Pramnos\Http\Middleware\BotDetector}, {@see \Pramnos\Http\Response},
 * {@see \Pramnos\Http\MiddlewarePipeline}. What was missing was the decision,
 * the key, and serve/store. That is all this class is.
 *
 * ## Every default is the safe one
 *
 * `enabled` is false, `statuses` is `[200]`, a response carrying `Set-Cookie` is
 * never stored, and nothing is served to a request that presents an
 * authentication cookie or header. An application that switches the middleware
 * on without writing any configuration caches nothing rather than leaking a
 * private page — the failure mode of a page cache is somebody else's account
 * page, and it is silent.
 *
 * ## Storage
 *
 * Over {@see FlatCache} rather than {@see \Pramnos\Cache\Cache}, deliberately.
 * FlatCache writes keys verbatim under a prefix, so this class owns its own
 * namespace and can keep tag and URL indexes beside the entries; the
 * category-based Cache mangles keys and clears a category by scanning the whole
 * store, which for a per-record purge is O(everything).
 *
 * ```php
 * // app.php
 * 'middleware' => [\Pramnos\Http\Middleware\PageCacheMiddleware::class],
 * 'pagecache'  => ['enabled' => true, 'store' => 'redis', 'ttl' => 3600],
 * ```
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
final class PageCache
{
    /** Entry, tag-index, url-index and lock key prefixes. */
    private const K_ENTRY = 'pc:e:';
    private const K_TAG   = 'pc:t:';
    private const K_URL   = 'pc:u:';
    private const K_LOCK  = 'pc:l:';
    private const K_STAT  = 'pc:s:';

    /**
     * Runtime bypass, set from anywhere inside the render.
     *
     * Static because the caller is a controller, a view or a model that has no
     * reference to this object — WordPress solves the same problem with the
     * `DONOTCACHEPAGE` constant, and for the same reason.
     *
     * @var string|null Reason, or null when not bypassed.
     */
    private static ?string $bypassReason = null;

    /** @var string[] Tags declared by the current render. */
    private static array $tags = [];

    /** @var array<string,mixed> */
    private array $config;

    private ?FlatCache $flat = null;

    private ?AdapterInterface $adapter = null;

    /** @var array<string,int> Counters for this request. */
    private array $stats = [
        'hit' => 0, 'miss' => 0, 'stale' => 0, 'bypass' => 0, 'store' => 0,
    ];

    /**
     * Everything that is not given falls back to a default that caches nothing
     * it should not.
     *
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        return [
            'enabled'        => false,
            'store'          => 'file',
            'prefix'         => 'pagecache:',
            'ttl'            => 3600,
            'ttlRules'       => [],
            'botTtl'         => null,
            'methods'        => ['GET', 'HEAD'],
            'statuses'       => [200],
            'hosts'          => [],
            'onlyPaths'      => [],
            'bypassPaths'    => [],
            'bypassQuery'    => [],
            // The session cookie is on this list by name, not by guess. A request
            // that carries one is a visitor the server already holds state for:
            // serving them a page stored for somebody else, or storing their
            // response for everybody, are both wrong and the second is the
            // dangerous direction. `#^(auth|remember|logged)#i` never matched
            // `PHPSESSID`, so an application whose signed-in state lives only in
            // `$_SESSION` had nothing here to stop it.
            //
            // What makes this the right *default* rather than advice in a guide is
            // `'session' => 'lazy'`: with it an anonymous reader carries no session
            // cookie at all, so bypassing on one costs nothing and closes the hole
            // for everybody who has not opted out of it. Without lazy sessions the
            // framework set `PHPSESSID` on every response anyway, and `store()`
            // already refused those for their `Set-Cookie` — so this takes away no
            // caching that was previously happening.
            //
            // session_name() rather than the literal, so an application that renamed
            // its session is covered too.
            'bypassCookies'  => [
                '#^(auth|remember|logged)#i',
                '#^' . preg_quote((string) session_name(), '#') . '$#i',
            ],
            'bypassHeaders'  => ['Authorization'],
            'ignoreQuery'    => [
                'utm_*', 'fbclid', 'gclid', 'msclkid', '_ga', 'ref', 'mc_cid',
                'mc_eid',
            ],
            'varyQuery'      => null,
            'varyBy'         => [],
            'privateMarkers' => [],
            // Refuse to store a response while the debug toolbar is collecting. Set it
            // false only to measure the cache with the toolbar on, and only somewhere
            // the stored pages cannot reach anybody else.
            'skipWhileDebugging' => true,
            'staleWhileRevalidate' => 30,
            // How long a held lock is honoured. Zero means "do not lock at
            // all" — every arrival re-renders — which is only sensible for a
            // site whose renders are cheap enough not to need the protection.
            'lockTtl'        => 30,
            'gzip'           => true,
            'etag'           => true,
            // What a hit tells the *browser* — null leaves whatever PHP already put
            // there, which today is not a decision anybody made.
            //
            // `session_start()` emits `Pragma: no-cache`, `Expires: 1981` and
            // `Cache-Control: no-store, no-cache, must-revalidate`, because PHP's
            // `session.cache_limiter` defaults to `nocache` and nothing here changes
            // it. A front controller calls `$app->init()` before the pipeline runs,
            // so on a hit those headers are already queued before this class is asked
            // anything — and with `'session' => 'lazy'` an anonymous visitor starts no
            // session and gets none of them. The header set on a hit therefore depends
            // on whether a session happened to start, which is not something a cache
            // should leave to chance.
            //
            // Null is still the default because the accidental answer is the *safe*
            // one: a page-cache hit is a shared copy, and "do not store this" is the
            // right thing to tell a browser about one. The failure it prevents is the
            // reverse — a browser or CDN keeping the anonymous page and handing it back
            // after the visitor signs in.
            //
            // Set it when the pages really are public and a second cache layer is
            // worth having: `'cacheControl' => 'public, max-age=300'`. The leftover
            // `Pragma` and `Expires` are removed then, because they would contradict
            // it for anything speaking HTTP/1.0.
            'cacheControl'   => null,
            'debugHeader'    => true,
            // The diagnostics somebody actually needs when a page is not cached the way
            // they expect: which key it went under, how long it lives, when it dies.
            //
            // Off by default, unlike debugHeader. `X-Pramnos-Cache: HIT` and `Age` are
            // ordinary things for a cache to say; the key is internal, and publishing it
            // to every visitor hands anybody probing for cache-key collisions the
            // normalisation rules for free.
            //
            // Replaces `debugComment`, which was declared here and read nowhere — anybody
            // who set it got nothing and no explanation. It is not reinstated as a body
            // comment either: a body is what snapshot tools diff and what a search engine
            // indexes, and debug information does not belong in a stored page.
            'debugDetail'    => false,
            'writer'         => 'cache',
            'staticRoot'     => '',
            'headerWhitelist' => [
                'content-type', 'content-language', 'link', 'vary',
            ],
        ];
    }

    /**
     * @param array<string,mixed> $config      Merged over {@see defaults()}.
     * @param AdapterInterface|null $adapter   Injected store, for tests.
     */
    public function __construct(array $config = [], ?AdapterInterface $adapter = null)
    {
        $this->config  = array_merge(self::defaults(), $config);
        $this->adapter = $adapter;
    }

    // ── The runtime API a render calls ──────────────────────────────────────

    /**
     * Refuse to cache this page, from anywhere inside the render.
     *
     * The legacy implementations of this idea emit a marker into the HTML and
     * grep the finished body for it — which works, and answers *after* the page
     * has been built, in a string search over the whole document, and silently
     * does nothing if the marker is ever reworded. This says it directly.
     */
    public static function bypass(string $reason = 'runtime'): void
    {
        self::$bypassReason = $reason;
    }

    public static function isBypassed(): bool
    {
        return self::$bypassReason !== null;
    }

    public static function bypassReason(): string
    {
        return self::$bypassReason ?? '';
    }

    /**
     * Tag the page being rendered, so it can be purged by something other than
     * the clock.
     *
     * Without tags the only invalidation is the TTL, which means "an edit
     * appears within eight hours" — true of every full-page cache that ships
     * without this, and the reason they get switched off.
     */
    public static function tag(string ...$tags): void
    {
        foreach ($tags as $tag) {
            $tag = trim($tag);
            if ($tag !== '' && !in_array($tag, self::$tags, true)) {
                self::$tags[] = $tag;
            }
        }
    }

    /** @return string[] */
    public static function tags(): array
    {
        return self::$tags;
    }

    /**
     * Forget the per-request runtime state.
     *
     * A request is per request and a process may serve more than one — a test
     * run, a worker. Without this the first page to call {@see bypass()} would
     * suppress caching for everything after it.
     */
    public static function resetRuntime(): void
    {
        self::$bypassReason = null;
        self::$tags = [];
    }

    // ── Lookup and store ────────────────────────────────────────────────────

    /**
     * Decide, then look up.
     *
     * @return Response|null A response to send and stop, or null to carry on
     *                       into the application.
     */
    public function lookup(Request $request): ?Response
    {
        $reason = $this->bypassCheck($request);
        if ($reason !== null) {
            $this->stats['bypass']++;
            return null;
        }

        $key   = $this->keyFor($request);
        $entry = $this->flat()->get(self::K_ENTRY . $key);
        if (!is_array($entry) || !isset($entry['body'])) {
            $this->stats['miss']++;
            return null;
        }

        $age     = time() - (int) ($entry['created'] ?? 0);
        $ttl     = (int) ($entry['ttl'] ?? $this->config['ttl']);
        $expired = $age > $ttl;

        if ($expired) {
            $window = (int) $this->config['staleWhileRevalidate'];
            if ($window <= 0 || $age > $ttl + $window) {
                $this->stats['miss']++;
                return null;
            }

            // Inside the stale window: one request renders, the rest are served
            // the old copy. Whoever takes the lock is the one that renders.
            if ($this->acquireLock($key)) {
                $this->stats['miss']++;
                return null;
            }

            $this->stats['stale']++;
            return $this->responseFrom($entry, $request, 'STALE', $age, $key);
        }

        $this->stats['hit']++;

        return $this->responseFrom($entry, $request, 'HIT', $age, $key);
    }

    /**
     * Store a rendered response, if it is allowed to be stored.
     */
    public function store(Request $request, Response $response): bool
    {
        // The runtime bypass is inside bypassCheck() now, so this is one test rather
        // than two that had to be kept in step.
        if ($this->bypassCheck($request) !== null) {
            return false;
        }

        if (!in_array($response->getStatusCode(), (array) $this->config['statuses'], true)) {
            return false;
        }

        $body = $response->getBody();
        if ($body === '') {
            return false;
        }

        // A response that sets a cookie is answering *this* visitor. Storing it
        // would hand the next visitor that visitor's session, which is the worst
        // failure a page cache has and the one nobody notices until it is
        // somebody else's account page.
        if ($response->hasHeader('Set-Cookie')) {
            return false;
        }

        // A response carrying a debug toolbar is answering *this developer*. The bar
        // holds their SQL with its bound values, their timings, the files that ran and
        // whatever the model collector saw. Storing it serves all of that to everybody
        // who asks for the page next.
        //
        // Application::render() injects the toolbar into the string it returns, and a
        // front controller then wraps that string in a Response and hands it here —
        // there is nothing in between that could have noticed. Nothing else in store()
        // catches it either: privateMarkers is empty by default, and a toolbar sets no
        // cookie.
        //
        // APP_DEBUG is supposed to be off in production, which bounds this but does not
        // close it: a staging environment with real data and the page cache on is an
        // ordinary thing to have, and the failure is silent there.
        //
        // The same condition injectInto() uses, so "there is a toolbar in this body" and
        // "refuse to store this body" cannot drift apart.
        if ($this->config['skipWhileDebugging']
            && \Pramnos\Debug\DebugBar::getInstance()->getCollectors() !== []
        ) {
            return false;
        }

        foreach ((array) $this->config['privateMarkers'] as $marker) {
            if ($marker !== '' && str_contains($body, (string) $marker)) {
                return false;
            }
        }

        // A body carrying this request's CSP nonce is per-response by definition —
        // the same reasoning as privateMarkers, applied to the one marker the
        // framework itself puts in every page it renders.
        //
        // The framework generates a nonce per response (Application::$cspNonce) and
        // Document\DocumentTypes\Html and Raw stamp it into every inline <script>
        // and <style>. Storing such a body freezes that nonce for the whole TTL and
        // hands it to every visitor — and a nonce that is reused is not a nonce; its
        // entire security property is being unguessable per response. So a page with
        // nonced inline script and a page cache are mutually exclusive, and this is
        // the side of that choice that cannot go wrong silently.
        //
        // An application that wants those pages cached has a clear instruction
        // instead of a mystery: serve them without a nonce — external scripts,
        // hashes, or no inline script — which is work only it can decide to do.
        // `whyBypassed()` does not report this, because it is a property of the
        // response rather than the request; `X-Pramnos-Cache: MISS` on every hit of
        // an otherwise cacheable page is the symptom, and the guide names it.
        $nonce = self::renderNonce();
        if ($nonce !== '' && str_contains($body, $nonce)) {
            return false;
        }

        $key = $this->keyFor($request);
        $ttl = $this->ttlFor($request);

        $entry = [
            'body'     => $body,
            'gzipBody' => $this->config['gzip'] && function_exists('gzencode')
                ? gzencode($body, 6)
                : null,
            'status'   => $response->getStatusCode(),
            'headers'  => $this->storableHeaders($response),
            'created'  => time(),
            'ttl'      => $ttl,
            'etag'     => $this->config['etag'] ? '"' . sha1($body) . '"' : null,
            'tags'     => self::$tags,
            'url'      => $this->urlFor($request),
        ];

        $written = $this->flat()->set(self::K_ENTRY . $key, $entry, $ttl + 3600);
        if (!$written) {
            return false;
        }

        $this->indexEntry($key, $entry);
        $this->releaseLock($key);
        $this->stats['store']++;

        if ($this->config['writer'] === 'static') {
            $this->writeStatic($request, $entry);
        }

        return true;
    }

    // ── Early serve ─────────────────────────────────────────────────────────

    /**
     * Serve a hit before the application boots, and exit.
     *
     * The reason a page cache is fast is not the storage — it is how little has
     * run by the time it answers. This is usable immediately after the
     * autoloader, before `Application::init()`, and it is safe there because
     * every rule the decision uses reads the request and nothing else: method,
     * host, path, query, cookies, headers. No database, no session.
     *
     * A caller that wants to keep control gets `false` back instead:
     *
     * ```php
     * require 'vendor/autoload.php';
     * \Pramnos\Cache\Page\PageCache::serveEarly();   // hit ⇒ sends, exits
     * ```
     *
     * ## It reads `app.php` itself
     *
     * `$config` used to be required, which meant the `pagecache` block had to be
     * **copied by hand into `www/index.php`** beside the one in `app.php`. Two
     * declarations of the same rules, and the early path is the one that answers
     * first: change `bypassCookies` in `app.php`, forget the copy, and this keeps
     * serving a signed-in page to everybody from a rule set that exists nowhere
     * else. So it now reads the file, through
     * {@see \Pramnos\Application\Application::readApplicationConfig()} — a
     * `require` of an array literal, which is not a bootstrap by any measure that
     * matters here. Passing `$config` explicitly still works and still wins.
     *
     * ## The hit carries a Content-Security-Policy
     *
     * Reading the file gets the `csp` block for free, which is what closes the last
     * hole in this path: a hit answers before the application exists, so
     * `Application::render()` never ran and never sent a policy. The page went out
     * correct and unprotected — `default-src 'none'` is this framework's default and
     * a cached page had lost all of it.
     *
     * The policy carries **no nonce**, and that is right rather than a compromise:
     * {@see store()} refuses to store a body containing one, so a stored page has no
     * nonced inline script for a nonce source to cover.
     *
     * Only sent when the config file was actually found. Without it there is no
     * `csp` block to build from, and a guessed policy is worse than none — an
     * over-strict one breaks the page it was meant to protect.
     *
     * @param array<string,mixed>|null $config Null reads `app.php`'s `pagecache` block
     * @param bool $exit Whether to end the process on a hit. False returns true
     *                   instead, which is what the tests need.
     * @return bool True when a response was sent.
     */
    public static function serveEarly(?array $config = null, bool $exit = true): bool
    {
        $response = self::earlyResponse($config);

        if ($response === null) {
            return false;
        }

        $response->send();

        if ($exit) {
            exit;   // @codeCoverageIgnore — the tests pass $exit = false
        }

        return true;
    }

    /**
     * The response {@see serveEarly()} would send, or null on a miss.
     *
     * Separate from the sending because `send()` calls `header()`, which is a no-op
     * under the CLI SAPI — so the headers this attaches are unobservable from a test
     * that drives `serveEarly()` itself. The one that matters is the security policy,
     * which is exactly the kind of thing that must not be taken on trust.
     *
     * @param array<string,mixed>|null $config Null reads `app.php`'s `pagecache` block
     */
    private static function earlyResponse(?array $config): ?Response
    {
        $info = \Pramnos\Application\Application::readApplicationConfig();

        $cache    = new self($config ?? (array) ($info['pagecache'] ?? []));
        $response = $cache->lookup(Request::getInstance());

        if ($response === null) {
            return null;
        }

        if ($info === null) {
            return $response;
        }

        return $response->withHeader(
            'Content-Security-Policy',
            \Pramnos\Application\Application::buildCspPolicy((array) ($info['csp'] ?? []))
        );
    }

    // ── Purging ─────────────────────────────────────────────────────────────

    /**
     * Purge every variant of one URL.
     *
     * "Every variant" is why the URL index exists: one address can have several
     * entries — one per `varyBy` combination — and an invalidation that cleared
     * only the variant the purging request happens to match would leave the
     * others serving the old page to exactly the visitors who see them.
     */
    public function purgeUrl(string $url): bool
    {
        $index = self::K_URL . sha1($url);
        $keys  = $this->flat()->hashGetAll($index);
        foreach (array_keys($keys) as $key) {
            $this->forget((string) $key);
        }
        $this->flat()->delete($index);

        return true;
    }

    /**
     * Purge every page carrying any of these tags.
     *
     * @return int Entries removed.
     */
    public function purgeTag(string ...$tags): int
    {
        $removed = 0;
        foreach ($tags as $tag) {
            $index = self::K_TAG . sha1($tag);
            foreach (array_keys($this->flat()->hashGetAll($index)) as $key) {
                if ($this->forget((string) $key)) {
                    $removed++;
                }
            }
            $this->flat()->delete($index);
        }

        return $removed;
    }

    /** Remove everything this page cache owns. */
    public function flush(): bool
    {
        $this->flat()->clear();

        if ($this->config['writer'] === 'static') {
            $this->removeStaticTree($this->staticRoot());
        }

        return true;
    }

    /**
     * @return array<string,int>
     */
    public function stats(): array
    {
        return $this->stats;
    }

    // ── The decision ────────────────────────────────────────────────────────

    /**
     * Why this request must not be served or stored from cache, or null.
     *
     * The order is the spec's, and the order matters: the cheapest and most
     * absolute rules first, so a disabled cache costs one array read.
     *
     * **The session is never consulted.** That is what lets {@see serveEarly()}
     * run before anything boots — and it is why an application that keeps its
     * logged-in state only in `$_SESSION` must set a marker cookie for
     * `bypassCookies` to see. A cache that has to start a session to decide
     * whether to answer has already paid most of the cost of answering.
     */
    private function bypassCheck(Request $request): ?string
    {
        // First, because it is the only rule the application sets by hand and the only
        // one that can know something the configuration cannot — "this visitor is signed
        // in", "this page shows somebody's data".
        //
        // It used to be consulted by store() alone, and lookup() went its own way. So
        // bypass() meant "do not save this page" and never "do not serve one", which is
        // the dangerous half: a consuming application called it on every request with a
        // session and its signed-in users were served the anonymous cached page, header
        // and all. Reported as FW-012, found by an HTTP test rather than by reading the
        // code, because both halves look right in isolation.
        //
        // Here rather than at the top of lookup() so a third call site cannot forget it,
        // and so whyBypassed() sees it — that returned null for a request the
        // application had explicitly refused, which is a diagnostic tool disagreeing with
        // the thing it diagnoses.
        if (self::isBypassed()) {
            return 'runtime:' . self::bypassReason();
        }

        if (!$this->config['enabled']) {
            return 'disabled';
        }

        $method = strtoupper((string) $request->getRequestMethod());
        if (!in_array($method, array_map('strtoupper', (array) $this->config['methods']), true)) {
            return 'method:' . $method;
        }

        $hosts = (array) $this->config['hosts'];
        $host  = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($hosts !== [] && !in_array($host, $hosts, true)) {
            return 'host:' . $host;
        }

        $path = '/' . ltrim($this->pathOf($request), '/');

        $only = (array) $this->config['onlyPaths'];
        if ($only !== [] && !$this->matchesAny($path, $only)) {
            return 'not-in-onlyPaths';
        }

        if ($this->matchesAny($path, (array) $this->config['bypassPaths'])) {
            return 'bypassPaths';
        }

        foreach ((array) $this->config['bypassQuery'] as $name => $values) {
            if (!isset($_GET[$name])) {
                continue;
            }
            if ($values === true || $values === []) {
                return 'bypassQuery:' . $name;
            }
            if (in_array((string) $_GET[$name], array_map('strval', (array) $values), true)) {
                return 'bypassQuery:' . $name;
            }
        }

        foreach ((array) $this->config['bypassCookies'] as $pattern) {
            foreach (array_keys($_COOKIE ?? []) as $cookie) {
                if ($this->matches((string) $cookie, (string) $pattern)) {
                    return 'cookie:' . $cookie;
                }
            }
        }

        foreach ((array) $this->config['bypassHeaders'] as $header) {
            $server = 'HTTP_' . strtoupper(str_replace('-', '_', (string) $header));
            if (!empty($_SERVER[$server])) {
                return 'header:' . $header;
            }
        }

        return null;
    }

    /**
     * The reason this request is not cacheable, for a caller that wants to say
     * so in a header.
     */
    public function whyBypassed(Request $request): ?string
    {
        return $this->bypassCheck($request);
    }

    // ── The key ─────────────────────────────────────────────────────────────

    /**
     * The cache key for a request.
     *
     * Normalisation is where a page cache is won or lost. The legacy
     * implementation this replaces put every GET parameter into the key exactly
     * as it arrived, unsorted and unfiltered — so `?a=1&b=2` and `?b=2&a=1` were
     * two entries for one page, every advertising URL had a 0% hit rate for
     * ever, and anybody could fill the disk by appending junk parameters.
     *
     * - `HEAD` keys as `GET`: it is the same page with the body withheld.
     * - `ignoreQuery` globs are dropped, and what remains is sorted.
     * - Logged and anonymous do **not** vary the key. A logged request is never
     *   served from cache and never stored, so one key means one public view —
     *   splitting it would only create a second entry nobody can reach.
     * - Bots share the key with anonymous visitors and differ only in TTL.
     *   A separate key would double the cache to store the same bytes.
     */
    public function keyFor(Request $request): string
    {
        $method = strtoupper((string) $request->getRequestMethod());
        $class  = $method === 'HEAD' ? 'GET' : $method;

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            ? 'https' : 'http';
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $path   = '/' . ltrim($this->pathOf($request), '/');

        return sha1(implode('|', [
            $class,
            $scheme . '://' . $host,
            $path,
            $this->normalisedQuery(),
            $this->varyString($request),
        ]));
    }

    /** The query string, filtered and sorted, as it goes into the key. */
    private function normalisedQuery(): string
    {
        $query = is_array($_GET) ? $_GET : [];
        unset($query['r']);   // the front controller's own routing parameter

        $vary = $this->config['varyQuery'];
        if (is_array($vary)) {
            $query = array_intersect_key($query, array_flip($vary));
        } else {
            foreach (array_keys($query) as $name) {
                if ($this->matchesAnyGlob((string) $name, (array) $this->config['ignoreQuery'])) {
                    unset($query[$name]);
                }
            }
        }

        ksort($query);
        $parts = [];
        foreach ($query as $name => $value) {
            $parts[] = rawurlencode((string) $name) . '='
                . rawurlencode(is_scalar($value) ? (string) $value : json_encode($value));
        }

        return implode('&', $parts);
    }

    /** The concatenated `varyBy` values. */
    private function varyString(Request $request): string
    {
        $parts = [];
        foreach ((array) $this->config['varyBy'] as $name => $resolver) {
            $value = is_callable($resolver) ? $resolver($request) : $resolver;
            $parts[] = $name . '=' . preg_replace(
                '/[^A-Za-z0-9_\-\.]/', '', (string) $value
            );
        }
        sort($parts);

        return implode(';', $parts);
    }

    /** The TTL this request's page should live for. */
    public function ttlFor(Request $request): int
    {
        $path = '/' . ltrim($this->pathOf($request), '/');

        foreach ((array) $this->config['ttlRules'] as $pattern => $ttl) {
            if ($this->matches($path, (string) $pattern)) {
                return (int) $ttl;
            }
        }

        $botTtl = $this->config['botTtl'];
        if ($botTtl !== null && $this->isBot()) {
            return (int) $botTtl;
        }

        return (int) $this->config['ttl'];
    }

    /** Whether the caller looks like a crawler, per the framework's detector. */
    private function isBot(): bool
    {
        static $detector = null;
        if ($detector === null) {
            $detector = new \Pramnos\Http\Middleware\BotDetector();
        }

        return $detector->isBot((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    // ── Serving ─────────────────────────────────────────────────────────────

    /**
     * Rebuild a Response from a stored entry.
     *
     * `If-None-Match` is answered here rather than by the caller, because a 304
     * is the cheapest possible hit: no body over the wire, and nothing rendered.
     */
    private function responseFrom(
        array $entry, Request $request, string $state, int $age, ?string $key = null
    ): Response {
        $etag = $entry['etag'] ?? null;

        if ($etag !== null && $this->ifNoneMatchMatches($etag)) {
            // RFC 7232 §4.1: a 304 carries the validator. Without it some
            // clients cannot tell what they have been told is still fresh and
            // re-download on the next cycle — losing exactly the round trip the
            // 304 exists to save.
            return $this->decorate(
                Response::make('', 304)->withHeader('ETag', $etag),
                $entry, $state . '-304', $age, $key
            );
        }

        $body    = (string) $entry['body'];
        $gzipped = false;

        if ($entry['gzipBody'] !== null
            && str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '')), 'gzip')) {
            $body    = (string) $entry['gzipBody'];
            $gzipped = true;
        }

        if (strtoupper((string) $request->getRequestMethod()) === 'HEAD') {
            $body = '';
        }

        $response = Response::make($body, (int) $entry['status']);
        foreach ((array) $entry['headers'] as $name => $value) {
            $response = $response->withHeader((string) $name, (string) $value);
        }
        if ($gzipped) {
            $response = $response->withHeader('Content-Encoding', 'gzip');
        }
        if ($etag !== null) {
            $response = $response->withHeader('ETag', $etag);
        }

        return $this->decorate($response, $entry, $state, $age, $key);
    }

    /**
     * This response's CSP nonce, or an empty string when there is not one.
     *
     * Read from the application rather than passed in, because `store()` is handed
     * a `Response` by a middleware that has no reason to know about CSP. Guarded by
     * `class_exists` so this class keeps working outside an application — the tests
     * construct it directly, and so does `serveEarly()`.
     *
     * The raw value is what to search the body for: the nonce is base64, whose
     * alphabet contains nothing `htmlspecialchars()` rewrites, so the escaped form
     * the document writes is byte-identical.
     */
    private static function renderNonce(): string
    {
        if (!class_exists('\Pramnos\Application\Application')) {
            return '';   // @codeCoverageIgnore
        }

        $app = \Pramnos\Application\Application::currentInstance();

        return is_string($app->cspNonce ?? null) ? $app->cspNonce : '';
    }

    /**
     * Whether the request's `If-None-Match` covers this entry's ETag.
     *
     * Three things beyond a string comparison, because a conditional request is
     * written by a client and arrives in whatever shape that client prefers:
     *
     * - **A list.** `If-None-Match: "a", "b"` is legal and common from a client
     *   that holds more than one variant.
     * - **A weak validator.** `W/"a"` and `"a"` are the same entity for this
     *   purpose — a page cache serves whole stored bytes, so there is no strong
     *   comparison to fail.
     * - **`*`**, which matches any stored representation.
     *
     * Getting any of these wrong is not a correctness bug — the answer falls
     * back to a full 200 — but it silently throws away the saving the ETag was
     * added for, which is the kind of thing nobody notices.
     */
    private function ifNoneMatchMatches(string $etag): bool
    {
        $header = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($header === '') {
            return false;
        }

        if ($header === '*') {
            return true;
        }

        $normalise = static function (string $tag): string {
            $tag = trim($tag);
            if (stripos($tag, 'W/') === 0) {
                $tag = substr($tag, 2);
            }

            return trim($tag);
        };

        $mine = $normalise($etag);
        foreach (explode(',', $header) as $candidate) {
            if ($normalise($candidate) === $mine) {
                return true;
            }
        }

        return false;
    }

    /**
     * The headers this cache adds on the way out: `Vary`, and the debug pair.
     *
     * **`Vary: Accept-Encoding` is not optional.** When `gzip` is on, one URL has
     * two bodies and the choice is made from a request header — so a shared cache
     * in front of the application (a CDN, a corporate proxy, a reverse proxy)
     * must be told, or it will store one variant and serve it to a client that
     * asked for the other. A client that sent no `Accept-Encoding` then receives
     * compressed bytes it will not decompress: the classic "the page is broken,
     * but only for some people" report, which never reproduces locally.
     *
     * Emitted on **both** branches, not only the compressed one. A shared cache
     * that happened to see the plain copy first has the identical problem in
     * reverse, and it is the same URL either way.
     *
     * The `headerWhitelist` does not cover this even though `vary` is on it: that
     * preserves a `Vary` the *application* sent, and compression is this class's
     * decision, so the application has no reason to know it must declare it.
     * Reported by a consuming application within hours of the feature shipping —
     * the tests exercised both branches and asserted `Content-Encoding` on each,
     * which is precisely how a missing header survives a green suite.
     *
     * An application `Vary` is merged rather than overwritten; dropping its
     * `Cookie` or `Accept-Language` would break the caching of a page that needs
     * them.
     */
    private function decorate(
        Response $response, array $entry, string $state, int $age, ?string $key = null
    ): Response {
        if ($this->config['gzip']) {
            $response = $response->withRawHeader(
                'Vary', $this->mergedVary($response->getHeaderLine('Vary'))
            );
        }

        $response = $this->withCacheControl($response);

        if (!$this->config['debugHeader']) {
            return $response;
        }

        $response = $response
            ->withHeader('X-Pramnos-Cache', $state)
            ->withHeader('Age', (string) max(0, $age));

        if (!$this->config['debugDetail']) {
            return $response;
        }

        // When a page is not cached the way somebody expects, the question is almost
        // always "under what key did it go in?" — and with ignoreQuery, varyBy and
        // varyQuery all feeding the key, not being able to see it means debugging by
        // guesswork.
        $ttl = (int) ($entry['ttl'] ?? $this->config['ttl']);

        if ($key !== null) {
            $response = $response->withHeader('X-Pramnos-Cache-Key', $key);
        }

        return $response
            ->withHeader('X-Pramnos-Cache-TTL', (string) $ttl)
            ->withHeader(
                'X-Pramnos-Cache-Expires',
                gmdate('D, d M Y H:i:s \G\M\T', (int) ($entry['created'] ?? time()) + $ttl)
            );
    }

    /**
     * Replace the browser-facing cache headers, when the application said what they
     * should be.
     *
     * Does nothing unless `cacheControl` is configured — see {@see defaults()} for why
     * the default is to leave PHP's session limiter alone.
     *
     * **`header_remove()` rather than a `Response` header**, and it has to be:
     * `Pragma` and `Expires` were queued by `session_start()` long before this runs,
     * and a `Response` can only add or replace what it carries itself. Sending
     * `Cache-Control: public` while `Pragma: no-cache` is still queued is worse than
     * either alone — every HTTP/1.0 intermediary believes the `Pragma`.
     *
     * Guarded on `headers_sent()` because a call after output has begun is a warning
     * and nothing else; there is no useful recovery, and the page is already going.
     */
    private function withCacheControl(Response $response): Response
    {
        $value = $this->config['cacheControl'] ?? null;

        if (!is_string($value) || $value === '') {
            return $response;
        }

        if (!headers_sent()) {
            header_remove('Pragma');
            header_remove('Expires');
        }

        return $response->withHeader('Cache-Control', $value);
    }

    /**
     * `Accept-Encoding` added to whatever `Vary` the page already declared,
     * without duplicating it.
     */
    private function mergedVary(?string $existing): string
    {
        $fields = [];
        foreach (explode(',', (string) $existing) as $field) {
            $field = trim($field);
            if ($field !== '') {
                $fields[strtolower($field)] = $field;
            }
        }

        // A page that already varies on everything needs nothing added.
        if (isset($fields['*'])) {
            return '*';
        }

        $fields['accept-encoding'] = 'Accept-Encoding';

        return implode(', ', $fields);
    }

    /**
     * The headers worth storing.
     *
     * A whitelist, not a blacklist. Everything a response carries is either
     * about the page — its type, its language, its canonical link — or about
     * the exchange that produced it, and the second kind must not be replayed
     * to a different visitor. `Set-Cookie` is the obvious one, and a response
     * carrying it is refused outright rather than filtered; the whitelist is
     * what stops the next one nobody thought of.
     *
     * @return array<string,string>
     */
    private function storableHeaders(Response $response): array
    {
        $allowed = array_map('strtolower', (array) $this->config['headerWhitelist']);
        $stored  = [];

        foreach ($response->getHeaders() as $name => $values) {
            if (in_array(strtolower((string) $name), $allowed, true)) {
                $stored[(string) $name] = is_array($values)
                    ? implode(', ', $values)
                    : (string) $values;
            }
        }

        return $stored;
    }

    // ── The stampede lock ───────────────────────────────────────────────────

    /**
     * Try to become the one request that re-renders an expired page.
     *
     * **The store's own counter is not always a lock, and the framework says
     * so.** {@see \Pramnos\Cache\Cache::supportsAtomicCounter()} exists because
     * the File and Array adapters implement `increment()` as a load followed by
     * a save — under concurrency every caller reads the same value and every
     * caller believes it won. A lock built on that does not hold at exactly the
     * moment a stampede happens, which is the only moment it is for.
     *
     * So there are two implementations, chosen by asking the store rather than
     * assuming: `swap()` (Redis `GETSET`) where it is atomic, and a
     * `mkdir()`-based lock where it is not. `mkdir` is the same primitive this
     * repository's own test runner uses for its lock, and for the same stated
     * reason — it is atomic on Linux, macOS and WSL alike.
     */
    private function acquireLock(string $key): bool
    {
        if ($this->adapterSupportsAtomicCounter()) {
            $previous = $this->flat()->swap(self::K_LOCK . $key, (string) time());

            // Nobody held it, or whoever did has been holding it past its TTL —
            // a render that died mid-flight must not wedge the page for ever.
            return $previous === null
                || (time() - (int) $previous) >= (int) $this->config['lockTtl'];
        }

        return $this->acquireDirectoryLock($key);
    }

    /**
     * The lock for a store that cannot count atomically.
     *
     * @return bool True when this process took the lock.
     */
    private function acquireDirectoryLock(string $key): bool
    {
        $path = $this->lockDirectory() . '/' . $key;

        if (@mkdir($path, 0777, true)) {
            return true;
        }

        // Held. Unless it has been held too long, in which case the holder is
        // gone and somebody has to render.
        $age = time() - (int) @filemtime($path);
        if ($age >= (int) $this->config['lockTtl']) {
            @touch($path);
            return true;
        }

        return false;
    }

    private function releaseLock(string $key): void
    {
        if ($this->adapterSupportsAtomicCounter()) {
            $this->flat()->delete(self::K_LOCK . $key);
            return;
        }

        @rmdir($this->lockDirectory() . '/' . $key);
    }

    private function lockDirectory(): string
    {
        $base = defined('CACHE_PATH')
            ? CACHE_PATH
            : sys_get_temp_dir() . '/pramnos_cache';
        $path = $base . '/pagecache-locks';

        if (!is_dir($path)) {
            @mkdir($path, 0777, true);
        }

        return $path;
    }

    private function adapterSupportsAtomicCounter(): bool
    {
        $adapter = $this->adapter();

        return method_exists($adapter, 'supportsAtomicCounter')
            && $adapter->supportsAtomicCounter();
    }

    // ── Indexes ─────────────────────────────────────────────────────────────

    /**
     * Record this entry under its tags and its URL.
     *
     * A hash per tag holding its keys, so a purge is "read this tag's members
     * and delete them" — the size of the tag. The alternative is asking the
     * store for every key matching a pattern, and on Redis `SCAN` walks the
     * whole keyspace whatever the `MATCH` says: a per-record purge would then
     * cost the size of the database, measured at 268 ms on a store that was not
     * even large.
     */
    private function indexEntry(string $key, array $entry): void
    {
        foreach ((array) $entry['tags'] as $tag) {
            $this->flat()->hashSet(self::K_TAG . sha1((string) $tag), $key, 1);
        }

        $this->flat()->hashSet(self::K_URL . sha1((string) $entry['url']), $key, 1);
    }

    /** Remove one entry and its static twin. */
    private function forget(string $key): bool
    {
        $entry = $this->flat()->get(self::K_ENTRY . $key);
        $this->flat()->delete(self::K_ENTRY . $key);

        if (is_array($entry) && $this->config['writer'] === 'static') {
            $this->removeStatic((string) ($entry['url'] ?? ''));
        }

        return $entry !== null;
    }

    // ── Static-file writer ──────────────────────────────────────────────────

    /**
     * Write the page as a file the web server can serve without PHP.
     *
     * The mechanism WP Super Cache calls "mod_rewrite mode": the HTML lands in a
     * directory and a rewrite rule in front of the front controller serves it,
     * so a logged-out visitor never reaches PHP at all.
     *
     * **The gain scales with the weight of the bootstrap being skipped, not with
     * the web server**, and it is worth measuring before enabling: an
     * application that already short-circuits early — see {@see serveEarly()} —
     * saves a millisecond or two, while one that boots fully before consulting
     * the cache saves tens. One consuming application measured 5.8 ms for its
     * PHP hit path against 4.5 ms for a static file: real, and not the reason
     * WordPress plugins advertise this.
     *
     * Written to a temporary name and renamed, because `rename()` is atomic on
     * a local filesystem and a half-written page served to a visitor is worse
     * than a slow one.
     */
    private function writeStatic(Request $request, array $entry): void
    {
        $root = $this->staticRoot();
        if ($root === '' || $this->normalisedQuery() !== '') {
            // A rewrite rule cannot normalise a query string, so only the bare
            // path is safe to serve as a file. Anything with parameters stays on
            // the PHP path, where ignoreQuery still applies.
            return;
        }

        $directory = $this->staticDirectory($root, (string) $entry['url']);
        if ($directory === null || !$this->ensureDirectory($directory)) {
            return;
        }

        $this->atomicWrite($directory . '/index.html', (string) $entry['body']);

        if ($entry['gzipBody'] !== null) {
            $this->atomicWrite($directory . '/index.html.gz', (string) $entry['gzipBody']);
        }
    }

    private function removeStatic(string $url): void
    {
        $root = $this->staticRoot();
        if ($root === '') {
            return;
        }

        $directory = $this->staticDirectory($root, $url);
        if ($directory === null) {
            return;
        }

        @unlink($directory . '/index.html');
        @unlink($directory . '/index.html.gz');
    }

    /**
     * The directory a URL's static twin lives in, or null when the URL cannot
     * safely become a path.
     *
     * Refuses anything containing `..` after decoding: a page cache that writes
     * files from a URL is a directory traversal waiting to happen, and the check
     * belongs here rather than in the caller.
     */
    private function staticDirectory(string $root, string $url): ?string
    {
        $parts = parse_url($url);
        $host  = preg_replace('/[^A-Za-z0-9_\-\.]/', '', (string) ($parts['host'] ?? 'default'));
        $path  = trim(rawurldecode((string) ($parts['path'] ?? '/')), '/');

        if (str_contains($path, '..') || preg_match('/[\x00-\x1f]/', $path)) {
            return null;
        }

        $path = preg_replace('#[^A-Za-z0-9_\-\./]#', '', $path) ?? '';

        return rtrim($root, '/') . '/' . $host . ($path === '' ? '' : '/' . $path);
    }

    private function staticRoot(): string
    {
        $root = (string) $this->config['staticRoot'];
        if ($root !== '') {
            return $root;
        }

        return defined('ROOT') ? ROOT . '/www/cache' : '';
    }

    private function ensureDirectory(string $path): bool
    {
        return is_dir($path) || @mkdir($path, 0777, true) || is_dir($path);
    }

    /** Write through a temporary name, so no reader ever sees half a page. */
    private function atomicWrite(string $path, string $contents): void
    {
        $temporary = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temporary, $contents) === false) {
            return;
        }
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
        }
    }

    private function removeStaticTree(string $path): void
    {
        if ($path === '' || !is_dir($path)) {
            return;
        }
        foreach ((array) @scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeStaticTree($full) : @unlink($full);
        }
        @rmdir($path);
    }

    // ── Small helpers ───────────────────────────────────────────────────────

    /** The request path, without its query string. */
    private function pathOf(Request $request): string
    {
        $uri = (string) $request->getRequestUri();
        $at  = strpos($uri, '?');

        return $at === false ? $uri : substr($uri, 0, $at);
    }

    private function urlFor(Request $request): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            ? 'https' : 'http';

        return $scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? '')
            . '/' . ltrim($this->pathOf($request), '/');
    }

    /** @param string[] $patterns */
    private function matchesAny(string $subject, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($this->matches($subject, (string) $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A pattern is a regular expression when it is delimited, a glob otherwise.
     *
     * Both spellings appear in real configuration — `#^/api#` from somebody who
     * thinks in regexes, `/api/*` from somebody who does not — and refusing one
     * of them would only mean the configuration silently matched nothing.
     */
    private function matches(string $subject, string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }

        $first = $pattern[0];
        $last  = substr($pattern, -1);
        if (strlen($pattern) > 2
            && in_array($first, ['#', '/', '~', '%', '@'], true)
            && ($last === $first || in_array($last, ['i', 's', 'u', 'm'], true))) {
            return (bool) @preg_match($pattern, $subject);
        }

        return fnmatch($pattern, $subject);
    }

    /** @param string[] $globs */
    private function matchesAnyGlob(string $subject, array $globs): bool
    {
        foreach ($globs as $glob) {
            if (fnmatch((string) $glob, $subject)) {
                return true;
            }
        }

        return false;
    }

    // ── Storage ─────────────────────────────────────────────────────────────

    private function flat(): FlatCache
    {
        if ($this->flat === null) {
            $this->flat = new FlatCache(
                $this->adapter(), (string) $this->config['prefix']
            );
        }

        return $this->flat;
    }

    private function adapter(): AdapterInterface
    {
        if ($this->adapter === null) {
            $this->adapter = $this->buildAdapter((string) $this->config['store']);
        }

        return $this->adapter;
    }

    /**
     * Build the configured store.
     *
     * Falls back to the file adapter rather than throwing, for the same reason
     * {@see \Pramnos\Cache\Cache} does: a cache that cannot reach its backend
     * should degrade to a slower one, not take the site down.
     */
    private function buildAdapter(string $name): AdapterInterface
    {
        $settings = (array) \Pramnos\Application\Settings::getSetting('cache');

        switch (strtolower($name)) {
            case 'redis':
                return new \Pramnos\Cache\Adapter\RedisAdapter(
                    (string) ($settings['hostname'] ?? 'localhost'),
                    (int) ($settings['port'] ?? 6379),
                    (int) ($settings['database'] ?? 0),
                    $settings['password'] ?? null
                );
            case 'memcached':
                return new \Pramnos\Cache\Adapter\MemcachedAdapter(
                    (string) ($settings['hostname'] ?? 'localhost'),
                    (int) ($settings['port'] ?? 11211)
                );
            case 'memcache':
                return new \Pramnos\Cache\Adapter\MemcacheAdapter(
                    (string) ($settings['hostname'] ?? 'localhost'),
                    (int) ($settings['port'] ?? 11211)
                );
            case 'array':
                return new \Pramnos\Cache\Adapter\ArrayAdapter();
            case 'file':
            default:
                return new \Pramnos\Cache\Adapter\FileAdapter(
                    defined('CACHE_PATH')
                        ? CACHE_PATH
                        : sys_get_temp_dir() . '/pramnos_cache'
                );
        }
    }
}
