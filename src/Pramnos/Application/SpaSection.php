<?php

declare(strict_types=1);

namespace Pramnos\Application;

/**
 * A single-page section of a server-rendered page: the theme draws the page, the section its
 * content.
 *
 * ```php
 * // app/app.php
 * 'spa_sections' => [
 *     'users' => ['ability' => 'admin.users'],   // entry: frontend/sections/users/main.js
 * ],
 *
 * // src/Admin/Controllers/Users.php
 * public function display() { return $this->spaSection('users'); }
 * ```
 *
 * The controller's output is a mount element and the entry's tags; the theme wraps it like any
 * view, so the menu, the help panel, the flash and the debug toolbar are the page's. Every address
 * under the controller's — `/admin/Users/edit/5` — reaches `display()` (an action a controller
 * does not have falls back to it), and the section's router reads the rest of the path, so deep
 * links, reloads and back/forward work without a route per screen.
 *
 * The section's data comes from the API, as the page: the session cookie and the page's CSRF
 * token, which `ApiAuthMiddleware` accepts from the same origin. No token, no login of its own.
 *
 * Its assets come as a SPA shell's do: from the Vite dev server while it runs (the hot file), then
 * from the build's manifest, and in a project without a build from the entry file itself, stamped
 * with its modification time.
 */
final class SpaSection
{
    /** Where a Vite build writes, under the web root — the same directory a SPA shell reads. */
    public const BUILD_DIR = 'assets/spa';

    /** @var string|null The web root, where the served one cannot be found from ROOT; null for that */
    private static ?string $webRoot = null;

    /** Read the build and the entries from `$dir` instead of the project's web root; null to stop. */
    public static function useWebRoot(?string $dir): void
    {
        self::$webRoot = $dir;
    }

    /**
     * The section's markup, or '' with a 403 when the viewer may not open it.
     *
     * @param string               $name  As declared under `spa_sections`
     * @param string               $base  The address the section's router works under
     * @param array<string, mixed> $props Initial data the section reads, as JSON
     * @param Application|null     $app   The application whose config declares it; the current one by default
     */
    public static function render(string $name, string $base, array $props = [], ?Application $app = null): string
    {
        $app    ??= Application::currentInstance();
        $declared = self::declared($name, $app);

        $ability = (string) ($declared['ability'] ?? '');
        if ($ability !== '' && !\Pramnos\Auth\AdminAccess::allows(
            \Pramnos\User\User::current(),
            $ability,
            \Pramnos\Http\AdminArea::minUserType()
        )) {
            http_response_code(403);

            return '';
        }

        // A page whose content is one person's data, drawn in their browser from their session.
        \Pramnos\Cache\Page\PageCache::bypass('spa section');

        $entry  = (string) ($declared['entry'] ?? 'frontend/sections/' . $name . '/main.js');
        $config = [
            // The page's UI system, so the shared components draw with its classes: the theme
            // around a section is the project's, whatever the SPA itself is built with.
            'ui'        => (string) (is_object($app) ? ($app->applicationInfo['scaffold_theme'] ?? 'tailwind') : 'tailwind'),
            'apiPrefix' => self::apiPrefix($app),
            'apiKey'    => md5(str_replace('/api/', '/', (string) getUrl())),
        ];

        return '<div data-pf-section="' . self::e($name) . '"'
            . ' data-base="' . self::e(rtrim($base, '/')) . '"'
            . ' data-config="' . self::e((string) json_encode($config, JSON_UNESCAPED_SLASHES)) . '"'
            . ' data-props="' . self::e((string) json_encode((object) $props, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '"'
            . '></div>' . "\n"
            . self::tags($entry);
    }

    /**
     * The script and stylesheet tags for an entry: the dev server's, the build's, or the file's.
     */
    public static function tags(string $entry): string
    {
        $web = self::$webRoot ?? \Pramnos\Application\FrameworkScripts::webRoot(defined('ROOT') ? ROOT : (string) getcwd());
        $hot = $web . '/' . self::BUILD_DIR . '/.vite/hot';

        if (is_file($hot)) {
            $origin = rtrim(trim((string) file_get_contents($hot)), '/');

            return '<script type="module" src="' . self::e($origin . '/' . self::BUILD_DIR . '/@vite/client') . '"></script>'
                . '<script type="module" src="' . self::e($origin . '/' . self::BUILD_DIR . '/' . $entry) . '"></script>';
        }

        $manifest = $web . '/' . self::BUILD_DIR . '/.vite/manifest.json';
        if (is_file($manifest)) {
            $chunks = json_decode((string) file_get_contents($manifest), true);
            $chunk  = is_array($chunks) ? ($chunks[$entry] ?? null) : null;
            if (is_array($chunk) && isset($chunk['file'])) {
                $tags = '';
                foreach ((array) ($chunk['css'] ?? []) as $css) {
                    $tags .= '<link rel="stylesheet" href="' . self::e('/' . self::BUILD_DIR . '/' . $css) . '">';
                }

                return $tags . '<script type="module" src="' . self::e('/' . self::BUILD_DIR . '/' . $chunk['file']) . '"></script>';
            }
        }

        // No build: the entry is served as it is, from the web root.
        if (is_file($web . '/' . $entry)) {
            return '<script type="module" src="' . self::e('/' . $entry . '?v=' . (int) filemtime($web . '/' . $entry)) . '"></script>';
        }

        \Pramnos\Logs\Logger::log('SPA section entry ' . $entry . ' is in neither the dev server, the build manifest, nor the web root.', 'spa');

        return '<!-- spa section: ' . self::e($entry) . ' is not built -->';
    }

    /**
     * What `spa_sections` says about a section; a section nobody declared is refused.
     *
     * @return array<string, mixed>
     */
    private static function declared(string $name, ?Application $app): array
    {
        $sections = is_object($app) ? ($app->applicationInfo['spa_sections'] ?? null) : null;
        $declared = is_array($sections) ? ($sections[$name] ?? null) : null;

        if ($declared === null) {
            throw new \InvalidArgumentException('There is no SPA section "' . $name . '" in spa_sections in app/app.php.');
        }

        return is_array($declared) ? $declared : [];
    }

    /** The API's prefix, as the application declares it, with a leading slash and none after. */
    private static function apiPrefix(?Application $app): string
    {
        $prefix = trim((string) (is_object($app) ? ($app->applicationInfo['api_prefix'] ?? '/api/1.0') : '/api/1.0'), '/');

        return '/' . $prefix;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
