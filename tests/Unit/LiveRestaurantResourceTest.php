<?php

namespace Tests\Unit;

use App\Http\Resources\LiveRestaurantResource;
use Tests\TestCase;

/**
 * Reconciliation contract for the merged-search response path: UnifiedSearchService
 * returns a list of ARRAYS (DB rows folded with live overlays + persisted live
 * rows), and that union must render with the same card fields as the persisted
 * DB path — most importantly `distance` (nearest sort + "X km" on the card) and
 * `rank_change` (rank badge), which RestaurantResource emits but
 * LiveRestaurantResource historically did not.
 */
class LiveRestaurantResourceTest extends TestCase
{
    public function test_emits_distance_and_rank_change_when_present(): void
    {
        $row = (new LiveRestaurantResource([
            'id' => 1,
            'name' => 'China Palace',
            'slug' => 'china-palace',
            'distance' => 1.25,
            'rank_change' => 3,
        ]))->resolve();

        $this->assertArrayHasKey('distance', $row);
        $this->assertSame(1.25, $row['distance']);
        $this->assertArrayHasKey('rank_change', $row);
        $this->assertSame(3, $row['rank_change']);
    }

    public function test_defaults_distance_and_rank_change_to_null_when_absent(): void
    {
        $row = (new LiveRestaurantResource([
            'id' => 1,
            'name' => 'China Palace',
            'slug' => 'china-palace',
        ]))->resolve();

        $this->assertNull($row['distance'], 'Merged live rows without a distance must still emit a null distance (card guard)');
        $this->assertNull($row['rank_change'], 'Merged live rows without a rank_change must still emit a null rank_change (badge guard)');
    }

    public function test_merged_union_array_renders_full_card_shape(): void
    {
        // A persisted-then-folded live row as UnifiedSearchService returns it
        // (persistUnmergedLiveRows strips `_persist`, mints a real id, and keeps
        // the union score/distance/breakdown).
        $row = (new LiveRestaurantResource([
            'id' => 42,
            'name' => 'Golden Dragon',
            'slug' => 'golden-dragon',
            'lat' => 30.67,
            'lng' => -88.22,
            'distance' => 2.0,
            'popularity_score' => 0.87,
            'score_breakdown' => ['total' => 0.87],
            'google_rating' => 4.8,
            'cuisines' => [['id' => 1, 'name' => 'Chinese', 'slug' => 'chinese']],
            'source' => 'serpapi',
        ]))->resolve();

        $this->assertSame(42, $row['id']);
        $this->assertSame('Golden Dragon', $row['name']);
        $this->assertSame(2.0, $row['distance']);
        $this->assertSame(0.87, $row['popularity_score']);
        $this->assertSame('serpapi', $row['source']);
        $this->assertSame([['id' => 1, 'name' => 'Chinese', 'slug' => 'chinese']], $row['cuisines']);
        $this->assertNull($row['rank_change'], 'Union rows carry no day-over-day rank delta; must default to null');
    }
}
