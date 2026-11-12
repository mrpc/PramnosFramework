<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Http\Client as Http;
use Pramnos\Http\ClientResponse;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Weather\Weather;

/**
 * The weather skill: off without the application's identification, found by name through the
 * geocoder or the application's own places, the forecast summed up by day on the person's clock
 * with its sources named, asked again only after it expires — and then only if it changed.
 */
#[CoversClass(Weather::class)]
class WeatherTest extends LlmTestCase
{
    protected function tearDown(): void
    {
        Http::resetFakes();
        \Pramnos\Cache\Cache::getInstance('weather')->clear();
        parent::tearDown();
    }

    /** MET Norway's compact answer: two days, hourly steps then six-hourly. */
    private static function met(): array
    {
        $steps = [];
        foreach ([['2027-06-01T09:00:00Z', 22.0, 3.0, 0.2, 'partlycloudy_day'], ['2027-06-01T12:00:00Z', 27.5, 5.5, 0.0, 'clearsky_day'],
            ['2027-06-02T06:00:00Z', 18.0, 2.0, null, 'rain'], ['2027-06-02T12:00:00Z', 24.0, 8.0, null, 'rain']] as [$t, $temp, $wind, $rain, $sky]) {
            $data = ['instant' => ['details' => ['air_temperature' => $temp, 'wind_speed' => $wind]]];
            if ($rain !== null) {
                $data['next_1_hours'] = ['summary' => ['symbol_code' => $sky], 'details' => ['precipitation_amount' => $rain]];
            } else {
                $data['next_6_hours'] = ['summary' => ['symbol_code' => $sky], 'details' => ['precipitation_amount' => 4.0]];
            }
            $steps[] = ['time' => $t, 'data' => $data];
        }

        return ['properties' => ['timeseries' => $steps]];
    }

    /**
     * Off without a `user_agent`, in its space only; a place by name through the geocoder, asked
     * once; the forecast by day — low, high, rain, wind, the sky at midday — with its sources;
     * asked again only after it expires, and a 304 keeps what was there.
     */
    public function testAForecastByNameSummedUpAndKept(): void
    {
        // Arrange
        $this->configure(['chat' => ['weather' => []]]);
        $off = Weather::on(new Scope(1));
        $this->configure(['chat' => ['weather' => ['user_agent' => 'TestApp/1.0 https://app.test']]]);
        $asked = ['geo' => 0, 'met' => 0, 'agent' => ''];
        Http::fake([
            'https://nominatim.openstreetmap.org/*' => function (Http $r) use (&$asked) {
                $asked['geo']++;

                return ClientResponse::make([['lat' => '35.33872', 'lon' => '25.14415', 'name' => 'Ηράκλειο']]);
            },
            'https://api.met.no/*' => function (Http $r) use (&$asked) {
                $asked['met']++;

                return $asked['met'] === 1
                    ? ClientResponse::make(self::met(), 200, ['Expires' => gmdate('D, d M Y H:i:s', time() - 1) . ' GMT', 'Last-Modified' => 'Tue, 01 Jun 2027 08:00:00 GMT'])
                    : ClientResponse::make('', 304, ['Expires' => gmdate('D, d M Y H:i:s', time() + 600) . ' GMT']);
            },
        ]);
        $weather = new Weather(new Scope(1), 'Europe/Athens');

        // Act
        $first  = $weather->told('Ηράκλειο', 2);
        $second = $weather->told('ηράκλειο', 2);
        $third  = $weather->told('Ηράκλειο', 2);

        // Assert
        $this->assertFalse($off, 'off without the application\'s identification');
        $this->assertTrue(Weather::on(new Scope(1)));
        $this->assertFalse(Weather::on(new Scope(1, null, Scope::ADMIN)), 'the conversation page by default');
        $this->assertStringStartsWith('Ηράκλειο (35.34, 25.14)', $first);
        $this->assertStringContainsString('Now: 22°C, wind 3 m/s, partly cloudy.', $first);
        $this->assertStringContainsString('Tue 1 Jun: 22 to 27.5°C, rain 0.2 mm, wind up to 5.5 m/s, partly cloudy.', $first, 'by day, on the person\'s clock; the sky at their midday (09:00Z in Athens)');
        $this->assertStringContainsString('Wed 2 Jun: 18 to 24°C, rain 8 mm, wind up to 8 m/s, rain.', $first, 'six-hourly rain counted once each');
        $this->assertStringContainsString(Weather::CREDIT, $first, 'its sources named');
        $this->assertSame($first, $second, 'the same place, kept');
        $this->assertSame($first, $third);
        $this->assertSame(1, $asked['geo'], 'the place found once');
        $this->assertSame(2, $asked['met'], 'asked again after it expired — a 304 kept it — then not until the new Expires');
    }

    /**
     * The application's own places come first — and where the person is, when no place is said;
     * without either, and nothing found, it says why.
     */
    public function testTheApplicationsOwnPlaces(): void
    {
        // Arrange
        $this->configure(['chat' => ['weather' => ['user_agent' => 'TestApp/1.0 https://app.test',
            'place' => static fn (string $name): ?array => $name === 'Home' ? ['lat' => 37.9838, 'lon' => 23.7275, 'name' => 'Αθήνα'] : null,
            'here'  => static fn (Scope $s): ?array => $s->userId === 2 ? [40.6401, 22.9444, 'Θεσσαλονίκη'] : null]]]);
        Http::fake([
            'https://nominatim.openstreetmap.org/*' => ClientResponse::make([]),
            'https://api.met.no/*' => ClientResponse::make(self::met(), 200, ['Expires' => gmdate('D, d M Y H:i:s', time() + 600) . ' GMT']),
        ]);

        // Act
        $home    = (new Weather(new Scope(1)))->told('Home', 1);
        $here    = (new Weather(new Scope(2)))->told('', 1);
        $unknown = (new Weather(new Scope(1)))->told('', 1);
        $nowhere = (new Weather(new Scope(1)))->told('Atlantis', 1);
        $tool    = (new Weather(new Scope(1)))->tools()['weather'];

        // Assert
        $this->assertStringStartsWith('Αθήνα (37.98, 23.73)', $home);
        $this->assertStringStartsWith('Θεσσαλονίκη (40.64, 22.94)', $here, 'where the person is');
        $this->assertSame('Error: where the person is is not known — ask which place.', $unknown);
        $this->assertSame('Error: no place called "Atlantis" was found.', $nowhere);
        $this->assertSame(['weather'], $tool['skills']);
        $this->assertSame('place', $tool['outbound'], 'the place leaves: guarded as an outbound value');
    }
}
