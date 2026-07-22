<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EngagementApiTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restaurant = Restaurant::factory()->create();
    }

    public function test_tracks_website_click(): void
    {
        $response = $this->post('/api/engage', [
            'restaurant_id' => $this->restaurant->id,
            'action' => 'website',
        ]);

        $response->assertNoContent(204);

        $this->assertDatabaseHas('restaurant_engagement', [
            'restaurant_id' => $this->restaurant->id,
            'action_type' => 'website_click',
        ]);
    }

    public function test_tracks_directions_click(): void
    {
        $response = $this->post('/api/engage', [
            'restaurant_id' => $this->restaurant->id,
            'action' => 'directions',
        ]);

        $response->assertNoContent(204);

        $this->assertDatabaseHas('restaurant_engagement', [
            'restaurant_id' => $this->restaurant->id,
            'action_type' => 'directions_click',
        ]);
    }

    public function test_tracks_call_click(): void
    {
        $response = $this->post('/api/engage', [
            'restaurant_id' => $this->restaurant->id,
            'action' => 'call',
        ]);

        $response->assertNoContent(204);

        $this->assertDatabaseHas('restaurant_engagement', [
            'restaurant_id' => $this->restaurant->id,
            'action_type' => 'call_click',
        ]);
    }

    public function test_tracks_pageview(): void
    {
        $response = $this->post('/api/engage', [
            'restaurant_id' => $this->restaurant->id,
            'action' => 'pageview',
        ]);

        $response->assertNoContent(204);

        $this->assertDatabaseHas('restaurant_engagement', [
            'restaurant_id' => $this->restaurant->id,
            'action_type' => 'pageview',
        ]);
    }

    public function test_tracks_social_link_click(): void
    {
        $response = $this->post('/api/engage', [
            'restaurant_id' => $this->restaurant->id,
            'action' => 'social_link_click',
        ]);

        $response->assertNoContent(204);

        $this->assertDatabaseHas('restaurant_engagement', [
            'restaurant_id' => $this->restaurant->id,
            'action_type' => 'social_link_click',
        ]);
    }

    public function test_tracks_menu_click(): void
    {
        $response = $this->post('/api/engage', [
            'restaurant_id' => $this->restaurant->id,
            'action' => 'menu',
        ]);

        $response->assertNoContent(204);

        $this->assertDatabaseHas('restaurant_engagement', [
            'restaurant_id' => $this->restaurant->id,
            'action_type' => 'menu_click',
        ]);
    }

    public function test_rejects_invalid_action(): void
    {
        $response = $this->postJson('/api/engage', [
            'restaurant_id' => $this->restaurant->id,
            'action' => 'invalid',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('action');
    }

    public function test_rejects_missing_restaurant_id(): void
    {
        $response = $this->postJson('/api/engage', [
            'action' => 'website',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('restaurant_id');
    }

    public function test_rejects_nonexistent_restaurant(): void
    {
        $response = $this->postJson('/api/engage', [
            'restaurant_id' => 99999,
            'action' => 'website',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('restaurant_id');
    }

    public function test_dedup_same_authenticated_user(): void
    {
        $user = \App\Models\User::factory()->create();

        // First request
        $this->actingAs($user)->post('/api/engage', [
            'restaurant_id' => $this->restaurant->id,
            'action' => 'website',
        ]);

        // Second request within 60s — should be deduped
        $this->actingAs($user)->post('/api/engage', [
            'restaurant_id' => $this->restaurant->id,
            'action' => 'website',
        ]);

        $this->assertDatabaseCount('restaurant_engagement', 1);
    }
}
