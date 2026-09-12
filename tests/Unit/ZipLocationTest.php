<?php

namespace Tests\Unit;

use App\Support\ZipLocation;
use Tests\TestCase;

/**
 * ZipLocation reads an address's ZIP and says whether it is where the
 * restaurant is pinned (Census ZCTA centroids).
 */
class ZipLocationTest extends TestCase
{
    public function test_zip_is_the_final_token_of_the_address(): void
    {
        $this->assertSame('78703', ZipLocation::zipOf('North Lamar Boulevard, 600, Austin, 78703'));
        $this->assertSame('30319', ZipLocation::zipOf('2148 Johnson Ferry Rd NE, Atlanta, GA 30319'));
        $this->assertSame('33040', ZipLocation::zipOf('525 Duval St, Key West, FL 33040-6554'));
        $this->assertSame('98402', ZipLocation::zipOf('410 Harbor Way, Tacoma, WA 98402, USA'));
        $this->assertSame('78703', ZipLocation::zipOf('123 Main St, 78703'));
        $this->assertSame('27609', ZipLocation::zipOf('Park at North Hills Street, 160, 27609'));
    }

    public function test_a_house_number_is_not_a_zip_and_postal_code_is_the_fallback(): void
    {
        // BizData order: "Street, Number[, City]".
        $this->assertNull(ZipLocation::zipOf('Research Boulevard, 13376'));
        $this->assertNull(ZipLocation::zipOf('North 28th Drive, 12418, Phoenix'));
        $this->assertNull(ZipLocation::zipOf('1 Main St, Columbus'));
        $this->assertSame('78750', ZipLocation::zipOf('Research Boulevard, 13376', '78750'));
        $this->assertSame('98402', ZipLocation::zipOf(null, '98402-1234'));
        $this->assertNull(ZipLocation::zipOf(null, 'n/a'));
    }

    public function test_state_comes_from_the_census_relationship_file(): void
    {
        $this->assertSame('MD', ZipLocation::state('20910'), 'Silver Spring');
        $this->assertSame('VA', ZipLocation::state('22043'), 'Falls Church');
        $this->assertSame('KS', ZipLocation::state('66103'), 'Kansas City, KS');
        $this->assertSame('PA', ZipLocation::state('18977'), 'Washington Crossing, PA');
        $this->assertNull(ZipLocation::state('00000'));
    }

    public function test_census_points_in_a_detached_part_of_the_zcta_are_corrected(): void
    {
        // Midtown Anchorage: the Census point for 99503 is 448 km west.
        $this->assertFalse(ZipLocation::isFarFrom('99503', 61.1930, -149.9070), 'Bleu Sage Noshery, 3002 Spenard Rd');
        $this->assertFalse(ZipLocation::isFarFrom('33040', 24.5550, -81.8020), "Willi T's, 525 Duval St, Key West");
        $this->assertTrue(ZipLocation::isFarFrom('99503', 64.8378, -147.7164), 'still far from Fairbanks');
    }

    public function test_centroid_comes_from_the_census_file(): void
    {
        $this->assertEqualsWithDelta(30.29, ZipLocation::centroid('78703')['lat'] ?? 0, 0.01);
        $this->assertEqualsWithDelta(-97.77, ZipLocation::centroid('78703')['lng'] ?? 0, 0.01);
        $this->assertNull(ZipLocation::centroid('00000'));
    }

    public function test_far_means_a_different_place_than_the_pin(): void
    {
        $lamar = [30.2721, -97.7541];   // 24 Diner, 600 N Lamar Blvd (78703)
        $airport = [30.2026, -97.6641]; // 24 Diner at the airport (78719)

        $this->assertFalse(ZipLocation::isFarFrom('78703', ...$lamar));
        $this->assertTrue(ZipLocation::isFarFrom('78703', ...$airport), 'the Lamar ZIP on the airport location');
        $this->assertFalse(ZipLocation::isFarFrom('78719', ...$airport), 'a big ZIP gets room: radius × factor');
        $this->assertTrue(ZipLocation::isFarFrom('23462', 27.970, -82.563), 'a Virginia Beach ZIP on a Tampa pin');
        $this->assertFalse(ZipLocation::isFarFrom('78703', $airport[0], $airport[1], 50.0), 'a higher minimum distance');
        $this->assertNull(ZipLocation::isFarFrom('00000', ...$lamar), 'an unknown ZIP is no judgement');
    }
}
