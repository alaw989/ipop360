<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EngagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_tracks_website_click(): void
    {
        $response = $this->post('/api/engage', [
            'restaurant_id' => 42,
            'action' => 'website',
        ]);

        $response->assertNoContent(204);

        $this->assertDatabaseHas('restaurant_engagement', [
            'restaurant_id' => 42,
            'action_type' => 'website_click',
        ]);
    }

    public function test_tracks_directions_click(): void
    {
        $response = $this->post('/api/engage', [
            'restaurant_id' => 7,
            'action' => 'directions',
        ]);

        $response->assertNoContent(204);

        $this->assertDatabaseHas('restaurant_engagement', [
            'restaurant_id' => 7,
            'action_type' => 'directions_click',
        ]);
    }

    public function test_tracks_call_click(): void
    {
        $response = $this->post('/api/engage', [
            'restaurant_id' => 99,
            'action' => 'call',
        ]);

        $response->assertNoContent(204);

        $this->assertDatabaseHas('restaurant_engagement', [
            'restaurant_id' => 99,
            'action_type' => 'call_click',
        ]);
    }

    public function test_rejects_invalid_action(): void
    {
        $response = $this->postJson('/api/engage', [
            'restaurant_id' => 1,
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
}
