<?php

declare(strict_types=1);

namespace Pramnos\Auth;

use Pramnos\General\Helpers;

/**
 * An application's limits: `applications.application_settings`.
 *
 * One row per application, edited on its administration page:
 *
 * | Setting | Enforced by |
 * | --- | --- |
 * | `rate_limit_requests` per `rate_limit_window_seconds`, `rate_limit_burst` | {@see \Pramnos\Http\Middleware\ApplicationPolicyMiddleware} — a token bucket per application |
 * | `require_https`, `ip_lock_enabled` / `allowed_ips`, `blocked_ips` | the same middleware on the API; {@see \Pramnos\Auth\OAuth2\ClientPolicy} at the token endpoint |
 * | `cors_enabled` / `cors_origins` | the middleware: a browser origin not on the list is refused |
 * | `enforce_pagination`, `default_page_size`, `max_page_size` | {@see paginate()}, in every list endpoint |
 *
 * An application with no row has {@see DEFAULTS}, except that pagination is not enforced:
 * a list that answered with every row keeps doing so until somebody decides otherwise.
 */
final class ApplicationSettings
{
    /** What an application has until its settings are saved. */
    public const DEFAULTS = [
        'rate_limit_requests'       => 1000,
        'rate_limit_window_seconds' => 3600,
        'rate_limit_burst'          => 100,
        'enforce_pagination'        => true,
        'max_page_size'             => 100,
        'default_page_size'         => 20,
        'ip_lock_enabled'           => false,
        'allowed_ips'               => [],
        'blocked_ips'               => [],
        'require_https'             => true,
        'cors_enabled'              => false,
        'cors_origins'              => [],
    ];

    private const TABLE = 'applications.application_settings';

    private const LISTS = ['allowed_ips', 'blocked_ips', 'cors_origins'];

    private const FLAGS = ['enforce_pagination', 'ip_lock_enabled', 'require_https', 'cors_enabled'];

    /** @var array<int, array<string, mixed>> appid => settings, for the life of the request */
    private static array $loaded = [];

    /**
     * An application's settings, every key present.
     *
     * @param int $appId The `applications.appid`
     * @return array<string, mixed>
     */
    public static function for(int $appId): array
    {
        if (isset(self::$loaded[$appId])) {
            return self::$loaded[$appId];
        }

        $row = null;
        $db  = \Pramnos\Framework\Factory::getDatabase();
        if ($db->schema()->hasTable(self::TABLE)) {
            $result = $db->queryBuilder()->table(self::TABLE)->where('appid', $appId)->first();
            $row    = ($result && $result->numRows > 0) ? $result->fields : null;
        }

        return self::$loaded[$appId] = $row === null
            ? ['enforce_pagination' => false] + self::DEFAULTS
            : self::normalise($row);
    }

    /**
     * Validate and store an application's settings.
     *
     * Every key of {@see DEFAULTS} is taken from `$input`: an absent flag is off, an absent
     * number is refused. Lists are one entry per line (or comma-separated). An address or range
     * that is not one, or an origin that is not `scheme://host[:port]`, is refused with the
     * entry named — a typo there would quietly lock an application out, or let one in.
     *
     * @param array<string, mixed> $input The form, as posted
     * @return list<string> What is wrong; nothing was saved when this is not empty
     */
    public static function save(int $appId, array $input): array
    {
        [$settings, $errors] = self::validate($input);
        if ($errors !== []) {
            return $errors;
        }

        $db     = \Pramnos\Framework\Factory::getDatabase();
        $isPg   = $db->schema()->getCapabilities()->isPostgreSQL();
        $values = $settings;
        foreach (self::LISTS as $list) {
            // Always written, an empty list as an empty list: emptying the field must clear it.
            $values[$list] = self::encodeList($settings[$list], $isPg);
        }
        foreach (self::FLAGS as $flag) {
            $values[$flag] = $isPg ? ($settings[$flag] ? 'true' : 'false') : (int) $settings[$flag];
        }

        $exists = $db->queryBuilder()->table(self::TABLE)->where('appid', $appId)->count() > 0;
        if ($exists) {
            $db->queryBuilder()->table(self::TABLE)->where('appid', $appId)->update($values);
        } else {
            $db->queryBuilder()->table(self::TABLE)->insert(['appid' => $appId] + $values);
        }
        unset(self::$loaded[$appId]);

        return [];
    }

    /**
     * The page and page size a list endpoint should use for the current API application.
     *
     * With pagination enforced, a request for everything (page 0) is the first page at the
     * default size. A page larger than the maximum is the maximum. Outside an API request, or
     * for the site's own key, the request is answered as asked.
     *
     * @return array{0: int, 1: int} page, page size
     */
    public static function paginate(int $page, int $pageSize): array
    {
        $appId = self::currentApplicationId();
        if ($appId === null) {
            return [$page, $pageSize];
        }

        $settings = self::for($appId);
        if ($page === 0 && $settings['enforce_pagination']) {
            $page     = 1;
            $pageSize = (int) $settings['default_page_size'];
        }
        if ($pageSize > (int) $settings['max_page_size']) {
            $pageSize = (int) $settings['max_page_size'];
        }

        return [$page, $pageSize];
    }

    /**
     * Whether an address may reach the application: never when blocked, and only from the
     * allowed list when the IP lock is on and the list has entries.
     *
     * @param array<string, mixed> $settings {@see for()}
     */
    public static function admitsAddress(array $settings, string $ip): bool
    {
        foreach ($settings['blocked_ips'] as $range) {
            if (Helpers::ipInRange($ip, $range)) {
                return false;
            }
        }
        if (!$settings['ip_lock_enabled'] || $settings['allowed_ips'] === []) {
            return true;
        }
        foreach ($settings['allowed_ips'] as $range) {
            if (Helpers::ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `require_https` refuses this request: plain HTTP, from anywhere but this machine.
     *
     * HTTPS directly, or through a proxy that says so in `X-Forwarded-Proto`. A request from the
     * loopback address is exempt — that is a developer's own server, with no certificate — and so
     * is one with no address at all, which did not come over a network.
     *
     * @param array<string, mixed> $settings {@see for()}
     * @param array<string, mixed> $server   The server variables
     */
    public static function refusesTransport(array $settings, string $address, array $server): bool
    {
        if (!$settings['require_https']) {
            return false;
        }
        $https = strtolower((string) ($server['HTTPS'] ?? ''));
        if ($https === 'on' || $https === '1' || strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return false;
        }

        // No address is no network: a command line, or a test.
        return $address !== '' && !Helpers::ipInRange($address, '127.0.0.0/8') && !Helpers::ipInRange($address, '::1');
    }

    /**
     * The application of the API request in progress, or null when there is none.
     */
    public static function currentApplicationId(): ?int
    {
        $app = \Pramnos\Application\Application::currentInstance();
        $key = ($app instanceof \Pramnos\Application\Api) ? $app->apiKey : null;
        $appId = is_object($key) ? (int) ($key->appid ?? 0) : 0;

        return $appId > 0 ? $appId : null;
    }

    /** Forget what was read, so the next call reads again. */
    public static function reset(): void
    {
        self::$loaded = [];
    }

    /**
     * The settings a form describes, or what is wrong with them.
     *
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private static function validate(array $input): array
    {
        $errors   = [];
        $settings = [];
        $numbers  = [
            'rate_limit_requests'       => [1, 'Requests per window'],
            'rate_limit_window_seconds' => [1, 'Window (seconds)'],
            'rate_limit_burst'          => [0, 'Burst'],
            'max_page_size'             => [1, 'Maximum page size'],
            'default_page_size'         => [1, 'Default page size'],
        ];
        foreach ($numbers as $key => [$minimum, $label]) {
            $value = trim((string) ($input[$key] ?? ''));
            if (!ctype_digit($value) || (int) $value < $minimum) {
                $errors[] = "{$label} must be a whole number of at least {$minimum}.";
                continue;
            }
            $settings[$key] = (int) $value;
        }
        if (isset($settings['default_page_size'], $settings['max_page_size'])
            && $settings['default_page_size'] > $settings['max_page_size']
        ) {
            $errors[] = 'The default page size cannot be larger than the maximum.';
        }

        foreach (self::FLAGS as $flag) {
            $settings[$flag] = in_array((string) ($input[$flag] ?? ''), ['1', 'on', 'true'], true);
        }

        foreach (['allowed_ips', 'blocked_ips'] as $list) {
            $settings[$list] = self::splitList($input[$list] ?? '');
            foreach ($settings[$list] as $entry) {
                if (!Helpers::validateIpOrCidr($entry)) {
                    $errors[] = "\"{$entry}\" is not an IP address or range.";
                }
            }
        }
        $settings['cors_origins'] = self::splitList($input['cors_origins'] ?? '');
        foreach ($settings['cors_origins'] as $origin) {
            if (preg_match('~^https?://[^/\s?#]+$~i', $origin) !== 1) {
                $errors[] = "\"{$origin}\" is not an origin, such as https://app.example.";
            }
        }

        return [$settings, $errors];
    }

    /**
     * A stored row as settings: booleans, integers and lists, whatever the driver returned.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalise(array $row): array
    {
        $settings = self::DEFAULTS;
        foreach (self::DEFAULTS as $key => $default) {
            if (!array_key_exists($key, $row) || $row[$key] === null) {
                continue;
            }
            $settings[$key] = match (true) {
                is_bool($default)  => in_array(strtolower((string) $row[$key]), ['1', 't', 'true'], true) || $row[$key] === true,
                is_int($default)   => (int) $row[$key],
                default            => self::decodeList($row[$key]),
            };
        }

        return $settings;
    }

    /**
     * Entries from a textarea or a comma list, trimmed, without blanks or repeats.
     *
     * @return list<string>
     */
    private static function splitList(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/[\s,]+/', (string) $value);

        return array_values(array_unique(array_filter(array_map('trim', array_map('strval', $items)), 'strlen')));
    }

    /**
     * A list as stored: JSON on MySQL, an array literal on PostgreSQL.
     *
     * @param list<string> $items
     */
    private static function encodeList(array $items, bool $isPg): string
    {
        if (!$isPg) {
            return (string) json_encode($items);
        }

        return '{' . implode(',', array_map(static fn (string $i): string => '"' . addcslashes($i, '"\\') . '"', $items)) . '}';
    }

    /**
     * A stored list back: JSON, or a PostgreSQL array literal such as `{10.0.0.0/8,"a b"}`.
     *
     * @return list<string>
     */
    private static function decodeList(mixed $value): array
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '{}') {
            return [];
        }
        if ($value[0] === '{') {
            preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|([^,{}]+)/', $value, $matches, PREG_SET_ORDER);

            return array_values(array_map(
                static fn (array $m): string => ($m[1] ?? '') !== '' ? stripcslashes($m[1]) : trim($m[2] ?? ''),
                $matches
            ));
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }
}
