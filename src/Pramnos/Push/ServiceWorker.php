<?php

declare(strict_types=1);

namespace Pramnos\Push;

/**
 * Whether this installation's service worker can receive a notification.
 *
 * Push is delivered **to a service worker**. Every other part can be perfect — a VAPID pair on
 * disk, subscriptions in the table, the encryption library installed, a `201` from the push
 * service — and if the worker has no `push` listener the notification is discarded by the
 * browser, silently, on every device.
 *
 * There is no error anywhere. The send succeeds, the subscription stays healthy, and the only
 * symptom is that nobody ever mentions receiving anything.
 *
 * ### Why this class exists rather than a line in the guide
 *
 * The scaffolded worker gained the three handlers when web push was added. Every project
 * scaffolded **before** that has a worker without them, registered and working, and nothing in
 * the framework was ever going to tell it — the file is the application's, and the framework
 * does not rewrite an application's files.
 *
 * So it reads it instead, and says what is missing. Found exactly that way: an installation with
 * keys, subscriptions and a working endpoint, whose `sw.js` predated the feature by four days.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class ServiceWorker
{
    /**
     * The three handlers a worker needs, and what each is for.
     *
     * `pushsubscriptionchange` is the one people leave out, and it is the one that fails later:
     * browsers rotate a subscription's keys without asking, the page may never be open to see
     * it, and every push to the old endpoint then returns 410 and the row is deleted. The user
     * stops receiving notifications and has no way to find out.
     */
    public const HANDLERS = [
        'push'                   => 'receives the notification; without it the browser shows its own '
            . '"this site was updated in the background" instead',
        'notificationclick'      => 'makes a notification go somewhere when it is tapped',
        'pushsubscriptionchange' => 'survives the browser rotating the subscription — without it '
            . 'a device silently stops receiving, permanently',
    ];

    /** The file names looked for in each web root, after the configured one. */
    public const NAMES = ['sw.js', 'service-worker.js', 'serviceWorker.js'];

    /**
     * Where the installation's worker lives, or null when it has none.
     *
     * `'push' => ['service_worker' => 'serviceWorker.js']` in app.php names it — a path under the
     * web root, or an absolute one — and is looked at first. Then each of {@see NAMES} in
     * `www/`, `public/` and the project root: `<web root>/sw.js` is where
     * `pramnos init --service-worker=y` writes it. A worker anywhere else is reported as no
     * worker rather than guessed at.
     */
    public static function path(): ?string
    {
        foreach (static::candidates() as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The handlers this worker does not have.
     *
     * @return array<string, string> handler => what it is for
     */
    public static function missing(): array
    {
        // `static::`, not `self::`: these are the seam a caller overrides to look somewhere
        // else, and `self::` binds to this class and ignores them entirely.
        $path = static::path();

        if ($path === null) {
            return static::HANDLERS;
        }

        $source = @file_get_contents($path);

        if ($source === false) {
            return static::HANDLERS;
        }

        $missing = [];

        foreach (static::HANDLERS as $handler => $why) {
            /*
             * Matched on the listener registration, not on the word.
             *
             * `push` appears in `pushManager`, in a comment, in a cache name. What decides
             * whether a notification is received is whether something is listening for it.
             */
            $pattern = '~addEventListener\s*\(\s*[\'"]' . preg_quote($handler, '~') . '[\'"]~';

            if (preg_match($pattern, $source) !== 1) {
                $missing[$handler] = $why;
            }
        }

        return $missing;
    }

    /** Can this installation receive a push at all? */
    public static function handlesPush(): bool
    {
        return !array_key_exists('push', static::missing());
    }

    /**
     * The places a scaffolded service worker can be.
     *
     * @return list<string>
     */
    protected static function candidates(): array
    {
        $roots = [];

        if (defined('ROOT')) {
            $roots[] = (string) ROOT . DIRECTORY_SEPARATOR . 'www';
            $roots[] = (string) ROOT . DIRECTORY_SEPARATOR . 'public';
            $roots[] = (string) ROOT;
        }

        $paths      = [];
        $configured = static::configured();

        if ($configured !== null) {
            $paths[] = str_starts_with($configured, '/') ? $configured : ($roots[0] ?? '.') . DIRECTORY_SEPARATOR . $configured;
        }

        foreach ($roots as $root) {
            foreach (static::NAMES as $name) {
                $paths[] = $root . DIRECTORY_SEPARATOR . $name;
            }
        }

        return $paths;
    }

    /**
     * The worker's address to register, `?v=` its modification time and then `$params` — the
     * worker reads `skip` and `offline` from its own address:
     * `navigator.serviceWorker.register('<?php echo ServiceWorker::url('sw.js', ['skip' => 'admin/']); ?>')`.
     *
     * A new version is a new address the browser has not cached, so a deploy needs no `?v=` bumped
     * by hand. The worker itself must still be served with `Cache-Control: no-cache` — `push:setup`
     * checks it — or a CDN keeps answering with the old one.
     *
     * @param array<string, string> $params
     */
    public static function url(string $file = 'sw.js', array $params = []): string
    {
        $url = assetUrl(ltrim($file, '/'));

        return $params === [] ? $url : $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
    }

    /** The worker app.php names — `push.service_worker` — or null. */
    public static function configured(): ?string
    {
        $app  = \Pramnos\Application\Application::currentInstance();
        $path = is_object($app) ? ($app->applicationInfo['push']['service_worker'] ?? null) : null;

        return is_string($path) && trim($path) !== '' ? (string) preg_replace('#^\./#', '', trim($path)) : null;
    }
}
