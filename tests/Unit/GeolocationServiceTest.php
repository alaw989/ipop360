<?php

namespace Tests\Unit;

use App\Services\GeolocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeolocationServiceTest extends TestCase
{
    private GeolocationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GeolocationService;
    }

    public function test_resolve_coordinates_from_request_params(): void
    {
        $request = Request::create('/test', 'GET', ['lat' => '37.7749', 'lng' => '-122.4194']);

        $coords = $this->service->resolveCoordinates($request);

        $this->assertEquals(['lat' => 37.7749, 'lng' => -122.4194], $coords);
    }

    public function test_resolve_coordinates_from_session(): void
    {
        $request = Request::create('/test', 'GET');
        $session = app('session')->driver('array');
        $request->setLaravelSession($session);
        $session->put('user_coords', ['lat' => 40.7128, 'lng' => -74.0060]);

        $coords = $this->service->resolveCoordinates($request);

        $this->assertEquals(['lat' => 40.7128, 'lng' => -74.0060], $coords);
    }

    public function test_request_params_override_session(): void
    {
        $request = Request::create('/test', 'GET', ['lat' => '37.7749', 'lng' => '-122.4194']);
        $session = app('session')->driver('array');
        $request->setLaravelSession($session);
        $session->put('user_coords', ['lat' => 40.7128, 'lng' => -74.0060]);

        $coords = $this->service->resolveCoordinates($request);

        $this->assertEquals(['lat' => 37.7749, 'lng' => -122.4194], $coords);
    }

    public function test_out_of_range_request_params_fall_through_to_session(): void
    {
        $request = Request::create('/test', 'GET', ['lat' => '95', 'lng' => '200']);
        $session = app('session')->driver('array');
        $request->setLaravelSession($session);
        $session->put('user_coords', ['lat' => 40.7128, 'lng' => -74.0060]);

        $coords = $this->service->resolveCoordinates($request);

        $this->assertEquals(['lat' => 40.7128, 'lng' => -74.0060], $coords);
    }

    public function test_ip_lookup_returns_null_for_localhost(): void
    {
        $this->assertNull($this->service->ipLookup('127.0.0.1'));
        $this->assertNull($this->service->ipLookup('::1'));
    }

    public function test_ip_lookup_returns_coordinates_from_api(): void
    {
        Http::fake([
            'ipapi.co/*' => Http::response([
                'latitude' => 37.7749,
                'longitude' => -122.4194,
            ], 200),
        ]);

        $coords = $this->service->ipLookup('8.8.8.8');

        $this->assertEquals(['lat' => 37.7749, 'lng' => -122.4194], $coords);
    }

    public function test_ip_lookup_returns_null_on_api_failure(): void
    {
        Http::fake([
            'ipapi.co/*' => Http::response([], 500),
        ]);

        $this->assertNull($this->service->ipLookup('8.8.8.8'));
    }

    public function test_ip_lookup_returns_null_on_missing_fields(): void
    {
        Http::fake([
            'ipapi.co/*' => Http::response(['city' => 'San Francisco'], 200),
        ]);

        $this->assertNull($this->service->ipLookup('8.8.8.8'));
    }

    public function test_ip_lookup_returns_null_on_exception(): void
    {
        Http::fake(function () {
            throw new \Exception('Connection failed');
        });

        $this->assertNull($this->service->ipLookup('8.8.8.8'));
    }

    public function test_ip_lookup_caches_result(): void
    {
        Http::fake([
            'ipapi.co/*' => Http::response([
                'latitude' => 37.7749,
                'longitude' => -122.4194,
            ], 200),
        ]);

        $coords1 = $this->service->ipLookup('8.8.4.4');
        $coords2 = $this->service->ipLookup('8.8.4.4');

        $this->assertEquals($coords1, $coords2);
        Http::assertSentCount(1);
    }

    public function test_ip_lookup_failure_is_not_cached_for_a_full_day(): void
    {
        // A transient failure (rate limit, timeout, outage) should not lock
        // an IP out of location resolution for 24h — it should be safe to
        // retry shortly after, once ipapi.co is fixed/faked to succeed.
        Http::fake([
            'ipapi.co/*' => Http::sequence()
                ->push([], 429)
                ->push(['latitude' => 37.7749, 'longitude' => -122.4194], 200),
        ]);

        $this->assertNull($this->service->ipLookup('8.8.5.5'));

        // Simulate the short negative-cache TTL expiring, without waiting
        // out the full window in the test.
        Cache::forget('geo_full:8.8.5.5');

        $coords = $this->service->ipLookup('8.8.5.5');
        $this->assertEquals(['lat' => 37.7749, 'lng' => -122.4194], $coords);
    }

    public function test_reverse_geocode_returns_city_and_state(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                'address' => [
                    'city' => 'San Francisco',
                    'state' => 'California',
                ],
            ], 200),
        ]);

        $result = $this->service->reverseGeocode(37.7749, -122.4194);

        $this->assertEquals(['city' => 'San Francisco', 'state' => 'California'], $result);
    }

    public function test_reverse_geocode_handles_town_fallback(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                'address' => [
                    'town' => 'Some Town',
                    'state' => 'Some State',
                ],
            ], 200),
        ]);

        $result = $this->service->reverseGeocode(40.0, -74.0);

        $this->assertEquals(['city' => 'Some Town', 'state' => 'Some State'], $result);
    }

    public function test_reverse_geocode_returns_null_on_failure(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([], 500),
        ]);

        $this->assertNull($this->service->reverseGeocode(0, 0));
    }

    public function test_reverse_geocode_returns_null_on_empty_address(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                'address' => [],
            ], 200),
        ]);

        $this->assertNull($this->service->reverseGeocode(0, 0));
    }

    public function test_forward_geocode_returns_coordinates(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                ['lat' => '40.7128', 'lon' => '-74.0060'],
            ], 200),
        ]);

        $result = $this->service->forwardGeocode('New York', 'NY');

        $this->assertEquals(['lat' => 40.7128, 'lng' => -74.006], $result);
    }

    public function test_forward_geocode_works_without_state(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                ['lat' => '48.8566', 'lon' => '2.3522'],
            ], 200),
        ]);

        $result = $this->service->forwardGeocode('Paris', null);

        $this->assertEquals(['lat' => 48.8566, 'lng' => 2.3522], $result);
    }

    public function test_forward_geocode_returns_null_on_empty_results(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([], 200),
        ]);

        $this->assertNull($this->service->forwardGeocode('Nowhere', null));
    }

    public function test_forward_geocode_returns_null_on_failure(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([], 500),
        ]);

        $this->assertNull($this->service->forwardGeocode('Nowhere', 'XX'));
    }

    public function test_search_cities_resolves_zip_from_local_gazetteer_without_photon(): void
    {
        Cache::flush();
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                'address' => ['city' => 'Beverly Hills', 'state' => 'California'],
            ], 200),
        ]);

        $results = $this->service->searchCities('90210');

        $this->assertCount(1, $results);
        $this->assertSame('Beverly Hills', $results[0]['city']);
        $this->assertSame('CA', $results[0]['state']);
        $this->assertSame('US', $results[0]['country']);
        $this->assertSame('90210', $results[0]['zip']);
        $this->assertEquals(34.1005, $results[0]['lat']);
        $this->assertEquals(-118.4146, $results[0]['lng']);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'photon.komoot.io'));
    }

    public function test_search_cities_normalizes_zip_plus_four(): void
    {
        Cache::flush();
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                'address' => ['city' => 'Beverly Hills', 'state' => 'California'],
            ], 200),
        ]);

        $results = $this->service->searchCities('90210-1234');

        $this->assertCount(1, $results);
        $this->assertSame('90210', $results[0]['zip']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'photon.komoot.io'));
    }

    public function test_search_cities_returns_empty_for_unknown_zip(): void
    {
        Cache::flush();
        Http::fake();

        $this->assertSame([], $this->service->searchCities('00000'));
        Http::assertNothingSent();
    }

    public function test_search_cities_extracts_zip_from_surrounding_words(): void
    {
        Cache::flush();
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                'address' => ['city' => 'Austin', 'state' => 'Texas'],
            ], 200),
        ]);

        foreach (['Austin, TX 78703', '78703 Austin', '  78703  '] as $query) {
            $results = $this->service->searchCities($query);
            $this->assertCount(1, $results, $query);
            $this->assertSame('78703', $results[0]['zip'], $query);
        }

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'photon.komoot.io'));
    }

    public function test_search_cities_skips_photon_for_digits_that_are_not_a_zip(): void
    {
        Cache::flush();
        Http::fake();

        foreach (['78', '787', '7870', '787031', '78703-12', '90210 90211', '787 03'] as $query) {
            $this->assertSame([], $this->service->searchCities($query), $query);
        }

        Http::assertNothingSent();
    }

    public function test_search_cities_sends_mixed_digit_queries_to_photon(): void
    {
        Cache::flush();
        Http::fake(['photon.komoot.io/*' => Http::response(['features' => []], 200)]);

        $this->service->searchCities('Route 66');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'photon.komoot.io'));
    }

    public function test_search_cities_zip_retries_city_after_reverse_geocode_outage(): void
    {
        Cache::flush();
        Http::fakeSequence('nominatim.openstreetmap.org/*')
            ->push([], 500)
            ->push(['address' => ['city' => 'Austin', 'state' => 'Texas']], 200);

        $this->assertNull($this->service->searchCities('78703')[0]['city']);
        $this->assertSame('Austin', $this->service->searchCities('78703')[0]['city']);
    }

    public function test_search_cities_zip_survives_reverse_geocode_failure(): void
    {
        Cache::flush();
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([], 500),
        ]);

        $results = $this->service->searchCities('78703');

        $this->assertCount(1, $results);
        $this->assertNull($results[0]['city']);
        $this->assertSame('TX', $results[0]['state']);
        $this->assertSame('78703', $results[0]['zip']);
        $this->assertEquals(30.2933, $results[0]['lat']);
    }

    public function test_search_cities_non_zip_query_still_uses_photon(): void
    {
        Cache::flush();
        Http::fake([
            'photon.komoot.io/*' => Http::response([
                'features' => [[
                    'properties' => [
                        'osm_value' => 'city',
                        'countrycode' => 'US',
                        'name' => 'Austin',
                        'state' => 'Texas',
                    ],
                    'geometry' => ['coordinates' => [-97.7431, 30.2672]],
                ]],
            ], 200),
        ]);

        $results = $this->service->searchCities('Austin');

        $this->assertCount(1, $results);
        $this->assertSame('Austin', $results[0]['city']);
        $this->assertNull($results[0]['zip']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'photon.komoot.io'));
    }
}
