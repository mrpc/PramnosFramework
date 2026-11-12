<?php

declare(strict_types=1);

namespace Pramnos\Llm\Weather;

use Pramnos\Http\Client;
use Pramnos\Llm\Chat\Scope;

/**
 * The weather, for a model: `weather(place, days)` in the `weather` skill — the forecast from MET
 * Norway's Locationforecast (api.met.no), a place found by its name through Nominatim
 * (OpenStreetMap), unless the application finds it itself.
 *
 * ```php
 * 'llm' => ['chat' => ['weather' => [
 *     'user_agent' => 'MyApp/1.0 https://myapp.example',            // required: both services identify their callers
 *     'place'      => fn (string $name, Scope $scope): ?array => …, // [lat, lon, name] of the application's, before Nominatim
 *     'here'       => fn (Scope $scope): ?array => …,               // [lat, lon, name] when no place is said: the person's own
 *     'spaces'     => ['chat'],                                      // where it is offered; the conversation page by default
 * ]]],
 * ```
 *
 * Off without a `user_agent`: MET Norway refuses a request that does not say who makes it, and
 * Nominatim's policy asks the same. Each service's terms — attribution, rates, caching — are the
 * application's to keep; this keeps the technical ones: the forecast is asked again only after
 * its `Expires`, and with `If-Modified-Since`; coordinates at four decimals; a place found is kept
 * for {@see PLACE_DAYS} days, and Nominatim is asked at most once a second ({@see geocode()}).
 * The answer names its sources, as both licences ask.
 */
final class Weather
{
    public const FORECAST = 'https://api.met.no/weatherapi/locationforecast/2.0/compact';

    public const GEOCODE = 'https://nominatim.openstreetmap.org/search';

    /** How long a place found is kept. */
    public const PLACE_DAYS = 30;

    /** The days a forecast is told for, at most. */
    public const DAYS = 7;

    /** Where the data are from, said with every answer. */
    public const CREDIT = 'Forecast: MET Norway (api.met.no), CC BY 4.0, summarised. Place: © OpenStreetMap contributors, ODbL.';

    /** The settings, `llm.chat.weather`. @return array<string, mixed> */
    public static function config(): array
    {
        $chat = \Pramnos\Llm\Llm::config('chat', []);
        $own  = is_array($chat) ? ($chat['weather'] ?? null) : null;

        return is_array($own) ? $own : [];
    }

    /** Whether the weather is offered — given a scope, in its space: on with a `user_agent`. */
    public static function on(?Scope $scope = null): bool
    {
        $config = self::config();
        if (trim((string) ($config['user_agent'] ?? '')) === '') {
            return false;
        }
        $spaces = is_array($config['spaces'] ?? null) ? array_map('strval', $config['spaces']) : [Scope::CHAT];

        return $scope === null || in_array($scope->space, $spaces, true);
    }

    public function __construct(private readonly ?Scope $scope = null, private readonly string $timezone = 'UTC')
    {
    }

    /**
     * `weather`, in the `weather` skill.
     *
     * @return array<string, array<string, mixed>>
     */
    public function tools(): array
    {
        return ['weather' => [
            'description' => 'The weather forecast for a place: now, and each day ahead — temperatures, rain, wind. '
                . 'place: a town or an address, in any language; leave it out for where the person is, where that is known. '
                . 'Say where the forecast is from, as the result does.',
            'parameters'  => ['type' => 'object', 'properties' => [
                'place' => ['type' => 'string'],
                'days'  => ['type' => 'integer', 'description' => '1 to ' . self::DAYS . '; 3 by default'],
            ]],
            'run'      => fn (array $a): string => $this->told(trim((string) ($a['place'] ?? '')), max(1, min(self::DAYS, (int) ($a['days'] ?? 3)))),
            'parallel' => true,
            'skills'   => ['weather'],
            // The place's name leaves for the geocoder: once personal data is in the answer, only one the person gave.
            'outbound' => 'place',
        ]];
    }

    /** The forecast for a place, as the model reads it; or why not. */
    public function told(string $place, int $days = 3): string
    {
        $where = $place === '' ? $this->here() : $this->find($place);
        if ($where === null) {
            return $place === '' ? 'Error: where the person is is not known — ask which place.' : 'Error: no place called "' . $place . '" was found.';
        }
        $forecast = $this->forecast($where[0], $where[1]);
        if ($forecast === null) {
            return 'Error: the forecast could not be had just now.';
        }

        return $where[2] . ' (' . round($where[0], 2) . ', ' . round($where[1], 2) . ")\n"
            . self::summary($forecast, $days, $this->timezone) . "\n" . self::CREDIT;
    }

    /**
     * A place by its name: the application's (`place`), else Nominatim's, kept for {@see PLACE_DAYS}.
     *
     * @return array{0: float, 1: float, 2: string}|null
     */
    public function find(string $name): ?array
    {
        $own = self::config()['place'] ?? null;
        if (is_callable($own) && $this->scope !== null && ($given = self::place($own($name, $this->scope))) !== null) {
            return $given;
        }

        return self::geocode($name);
    }

    /** @return array{0: float, 1: float, 2: string}|null Where the person is, by the application's word (`here`). */
    private function here(): ?array
    {
        $here = self::config()['here'] ?? null;

        return is_callable($here) && $this->scope !== null ? self::place($here($this->scope)) : null;
    }

    /**
     * A place's coordinates through Nominatim — at most one request a second for the whole
     * installation, each answer kept. Null for none found, or no answer.
     *
     * @return array{0: float, 1: float, 2: string}|null
     */
    public static function geocode(string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $cache = self::cache();
        $key   = 'weather_place_' . md5(mb_strtolower($name));
        $kept  = json_decode((string) $cache->load($key, null, self::PLACE_DAYS * 86400), true);
        if (is_array($kept)) {
            return $kept === [] ? null : self::place($kept);
        }
        // ponytail: one shared slot through the cache, a second apart; a busy installation runs its own geocoder (`place`).
        $last = (float) $cache->load('weather_geocoded', null, 5);
        if (($wait = $last + 1.0 - microtime(true)) > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $cache->save((string) microtime(true), 'weather_geocoded');
        try {
            $response = Client::get(self::GEOCODE . '?' . http_build_query(['q' => $name, 'format' => 'jsonv2', 'limit' => 1]))
                ->timeout(10)->header('User-Agent', self::agent())->header('Accept', 'application/json')->send();
        } catch (\Throwable $why) {
            \Pramnos\Logs\Logger::log('weather geocode: ' . $why->getMessage(), 'llm');

            return null;
        }
        if (!$response->successful()) {
            return null;
        }
        $hit   = (array) (json_decode($response->body(), true)[0] ?? []);
        $found = isset($hit['lat'], $hit['lon']) ? [(float) $hit['lat'], (float) $hit['lon'], (string) ($hit['name'] ?? $hit['display_name'] ?? $name)] : [];
        $cache->save((string) json_encode($found, JSON_UNESCAPED_UNICODE), $key);

        return $found === [] ? null : $found;
    }

    /**
     * MET Norway's forecast for a point: the one kept until its `Expires`, then asked again with
     * `If-Modified-Since` — a 304 keeps it. Null where there is none.
     *
     * @return array<string, mixed>|null
     */
    public static function forecast(float $lat, float $lon): ?array
    {
        [$lat, $lon] = [round($lat, 4), round($lon, 4)];
        $cache = self::cache();
        $key   = 'weather_forecast_' . str_replace(['.', '-'], ['_', 'm'], $lat . '_' . $lon);
        $kept  = json_decode((string) $cache->load($key, null, 2 * 86400), true);
        if (is_array($kept) && (int) ($kept['expires'] ?? 0) > time()) {
            return $kept['body'];
        }
        try {
            $request = Client::get(self::FORECAST . '?' . http_build_query(['lat' => $lat, 'lon' => $lon]))
                ->timeout(15)->header('User-Agent', self::agent())->header('Accept', 'application/json');
            if (is_array($kept) && ($kept['modified'] ?? '') !== '') {
                $request->header('If-Modified-Since', (string) $kept['modified']);
            }
            $response = $request->send();
        } catch (\Throwable $why) {
            \Pramnos\Logs\Logger::log('weather forecast: ' . $why->getMessage(), 'llm');

            return is_array($kept) ? $kept['body'] : null;
        }
        $expires = strtotime($response->header('Expires') ?: '') ?: time() + 1800;
        if ($response->status() === 304 && is_array($kept)) {
            $cache->save((string) json_encode(['body' => $kept['body'], 'expires' => $expires, 'modified' => $kept['modified']]), $key);

            return $kept['body'];
        }
        $body = json_decode($response->body(), true);
        if (!$response->successful() || !is_array($body)) {
            return is_array($kept) ? $kept['body'] : null;
        }
        $cache->save((string) json_encode(['body' => $body, 'expires' => $expires, 'modified' => $response->header('Last-Modified')]), $key);

        return $body;
    }

    /**
     * A forecast as lines a model reads: now, then each day on the person's clock — low and high,
     * rain, the strongest wind, and the sky at midday.
     *
     * @param array<string, mixed> $forecast MET Norway's `compact` answer
     */
    public static function summary(array $forecast, int $days, string $timezone = 'UTC'): string
    {
        try {
            $zone = new \DateTimeZone($timezone);
        } catch (\Throwable) {
            $zone = new \DateTimeZone('UTC');
        }
        $series = (array) ($forecast['properties']['timeseries'] ?? []);
        if ($series === []) {
            return 'No forecast.';
        }
        $first = $series[0]['data'] ?? [];
        $lines = [sprintf('Now: %s°C, wind %s m/s, %s.', self::number($first['instant']['details']['air_temperature'] ?? null),
            self::number($first['instant']['details']['wind_speed'] ?? null), self::sky((string) ($first['next_1_hours']['summary']['symbol_code'] ?? $first['next_6_hours']['summary']['symbol_code'] ?? '')))];
        $byDay = [];
        foreach ($series as $step) {
            $at  = (new \DateTimeImmutable((string) ($step['time'] ?? 'now')))->setTimezone($zone);
            $day = $at->format('Y-m-d');
            $d   = $step['data'] ?? [];
            $byDay[$day]['temps'][] = (float) ($d['instant']['details']['air_temperature'] ?? 0);
            $byDay[$day]['wind'][]  = (float) ($d['instant']['details']['wind_speed'] ?? 0);
            // Rain by the hour where the hours are given, else by six hours, counted once.
            if (isset($d['next_1_hours']['details']['precipitation_amount'])) {
                $byDay[$day]['rain'] = ($byDay[$day]['rain'] ?? 0) + (float) $d['next_1_hours']['details']['precipitation_amount'];
                $byDay[$day]['hourly'] = true;
            } elseif (empty($byDay[$day]['hourly']) && isset($d['next_6_hours']['details']['precipitation_amount'])
                && (int) (new \DateTimeImmutable((string) $step['time']))->setTimezone(new \DateTimeZone('UTC'))->format('G') % 6 === 0) {
                $byDay[$day]['rain'] = ($byDay[$day]['rain'] ?? 0) + (float) $d['next_6_hours']['details']['precipitation_amount'];
            }
            $code = (string) ($d['next_6_hours']['summary']['symbol_code'] ?? $d['next_1_hours']['summary']['symbol_code'] ?? '');
            if ($code !== '' && (!isset($byDay[$day]['sky']) || abs((int) $at->format('G') - 12) < ($byDay[$day]['skyAt'] ?? 99))) {
                $byDay[$day]['sky']   = $code;
                $byDay[$day]['skyAt'] = abs((int) $at->format('G') - 12);
            }
        }
        foreach (array_slice($byDay, 0, $days, true) as $day => $one) {
            $lines[] = sprintf('%s: %s to %s°C, rain %s mm, wind up to %s m/s, %s.', (new \DateTimeImmutable($day))->format('D j M'),
                self::number(min($one['temps'])), self::number(max($one['temps'])), self::number($one['rain'] ?? 0), self::number(max($one['wind'])), self::sky((string) ($one['sky'] ?? '')));
        }

        return implode("\n", $lines);
    }

    /** MET's symbol in words: `partlycloudy_day` → "partly cloudy". */
    private static function sky(string $code): string
    {
        $code = (string) preg_replace('/_(day|night|polartwilight)$/', '', $code);

        return $code === '' ? 'sky not given' : str_replace(['partlycloudy', 'lightrain', 'heavyrain', 'lightsnow', 'heavysnow', 'andthunder', 'showers', '_'],
            ['partly cloudy', 'light rain', 'heavy rain', 'light snow', 'heavy snow', ' and thunder', ' showers', ' '], $code);
    }

    /** A number at one decimal, or a dash. */
    private static function number(mixed $value): string
    {
        return is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.') : '–';
    }

    /** @return array{0: float, 1: float, 2: string}|null A place as the application gave it: `[lat, lon, name]` or `{lat, lon, name}`. */
    private static function place(mixed $given): ?array
    {
        if (!is_array($given)) {
            return null;
        }
        $lat  = $given['lat'] ?? $given[0] ?? null;
        $lon  = $given['lon'] ?? $given[1] ?? null;
        $name = $given['name'] ?? $given[2] ?? '';

        return is_numeric($lat) && is_numeric($lon) ? [(float) $lat, (float) $lon, (string) $name] : null;
    }

    /** Who asks, as the application says: both services require it. */
    private static function agent(): string
    {
        return trim((string) (self::config()['user_agent'] ?? ''));
    }

    private static function cache(): \Pramnos\Cache\Cache
    {
        return \Pramnos\Cache\Cache::getInstance('weather');
    }
}
