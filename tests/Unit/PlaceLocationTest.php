<?php

namespace Tests\Unit;

use App\Support\PlaceLocation;
use Tests\TestCase;

/**
 * PlaceLocation says whether a city name is where the restaurant is pinned
 * (Census place internal points).
 */
class PlaceLocationTest extends TestCase
{
    public function test_names_are_compared_in_one_form(): void
    {
        $this->assertSame('st louis', PlaceLocation::normalize('Saint Louis'));
        $this->assertSame('st louis', PlaceLocation::normalize('St. Louis'));
        $this->assertSame('ft lauderdale', PlaceLocation::normalize('Fort Lauderdale'));
        $this->assertSame('mt pleasant', PlaceLocation::normalize('Mount Pleasant'));
        $this->assertSame('canon city', PlaceLocation::normalize('Cañon City'));
        $this->assertSame('coeur d alene', PlaceLocation::normalize("Coeur d'Alene"));
    }

    public function test_census_descriptors_and_consolidated_names_are_listed_by_the_city(): void
    {
        $this->assertArrayHasKey('TX', PlaceLocation::named('Austin'));
        $this->assertArrayHasKey('MO', PlaceLocation::named('Kansas City'));
        $this->assertArrayHasKey('NV', PlaceLocation::named('Carson City'));
        // "Macon-Bibb County", "Nashville-Davidson metropolitan government (balance)",
        // "Louisville/Jefferson County metro government (balance)".
        $this->assertArrayHasKey('GA', PlaceLocation::named('Macon'));
        $this->assertArrayHasKey('TN', PlaceLocation::named('Nashville'));
        $this->assertArrayHasKey('KY', PlaceLocation::named('Louisville'));
        $this->assertArrayHasKey('HI', PlaceLocation::named('Honolulu'));
        $this->assertArrayHasKey('ID', PlaceLocation::named('Boise'));
        // New York City's boroughs aren't Census places.
        $this->assertArrayHasKey('NY', PlaceLocation::named('Brooklyn'));
        $this->assertSame([], PlaceLocation::named('Nowhereville Heights'));
    }

    public function test_far_means_the_named_place_is_somewhere_else(): void
    {
        $novi = [42.4806, -83.4755];
        $farzi = [40.7172, -74.0053];

        $this->assertTrue(PlaceLocation::isFarFrom('Ann Arbor', 'MI', ...$novi), 'the grid city on a venue 30 km away');
        $this->assertFalse(PlaceLocation::isFarFrom('Novi', 'MI', ...$novi));
        $this->assertTrue(PlaceLocation::isFarFrom('Anchorage', 'AK', ...$farzi));
        $this->assertFalse(PlaceLocation::isFarFrom('New York', 'NY', ...$farzi));
        $this->assertFalse(PlaceLocation::isFarFrom('Brooklyn', 'NY', 40.6782, -73.9442));
        $this->assertNull(PlaceLocation::isFarFrom('Cheyenne', 'KY', 38.2921, -85.5618), 'Kentucky has no Cheyenne');
        $this->assertEqualsWithDelta(5378, PlaceLocation::nearest('Anchorage', 'AK', ...$farzi)['km'] ?? 0, 5);
    }

    public function test_states_at_finds_the_state_a_name_sits_in_at_the_pin(): void
    {
        $this->assertSame(['WA'], PlaceLocation::statesAt('Vancouver', 45.6387, -122.6615));
        $this->assertSame(['AL'], PlaceLocation::statesAt('Mobile', 30.6954, -88.1777));
        $this->assertSame([], PlaceLocation::statesAt('Cheyenne', 38.2921, -85.5618));
    }

    public function test_a_grid_label_with_its_state_glued_on_reads_as_the_place(): void
    {
        $this->assertSame('Washington', PlaceLocation::withoutStateSuffix('Washington Dc'));
        $this->assertSame('Charleston', PlaceLocation::withoutStateSuffix('Charleston Wv'));
        $this->assertSame('portland', PlaceLocation::withoutStateSuffix('portland me'));
        $this->assertNull(PlaceLocation::withoutStateSuffix('Kansas City'), 'a real place name');
        $this->assertNull(PlaceLocation::withoutStateSuffix('Austin'));
    }
}
