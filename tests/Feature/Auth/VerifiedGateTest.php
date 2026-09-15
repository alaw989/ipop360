<?php

namespace Tests\Feature\Auth;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * spec-113: the spec-089 "gate favorites on verified email" deferral shipped
 * without its kill-switch — `config('auth.require_verified_for_favorites')`
 * was never added, so enabling the gate required a code change. These routes
 * now honor a runtime config flag (checked per-request, so `route:cache` does
 * not freeze the decision), and profile writes get the same treatment via
 * `auth.require_verified_for_profile`.
 *
 * Both gates default OFF: the prod mailer is `log`, so no real user can
 * verify — an always-on gate would lock every user out of favorites/profile.
 */
class VerifiedGateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function togglePayload(Restaurant $restaurant): array
    {
        return [
            'restaurant' => [
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
                'address' => $restaurant->address,
                'city' => $restaurant->city,
                'state' => $restaurant->state,
                'lat' => $restaurant->latitude,
                'lng' => $restaurant->longitude,
            ],
            'id' => $restaurant->id,
        ];
    }

    public function test_favorites_writes_are_ungated_by_default(): void
    {
        $user = User::factory()->unverified()->create();
        $restaurant = Restaurant::factory()->create();

        $this->assertFalse(config('auth.require_verified_for_favorites'));

        $this->actingAs($user)
            ->postJson('/favorites/toggle', $this->togglePayload($restaurant))
            ->assertOk();

        $this->assertTrue($user->favorites()->where('restaurant_id', $restaurant->id)->exists());
    }

    public function test_favorites_writes_are_rejected_for_unverified_users_when_gate_enabled(): void
    {
        config(['auth.require_verified_for_favorites' => true]);

        $user = User::factory()->unverified()->create();
        $restaurant = Restaurant::factory()->create();

        // The client posts JSON (axios), so the `verified` middleware's
        // JSON branch aborts 403 rather than redirecting.
        $this->actingAs($user)
            ->postJson('/favorites/toggle', $this->togglePayload($restaurant))
            ->assertForbidden();

        $this->assertFalse($user->favorites()->where('restaurant_id', $restaurant->id)->exists());
    }

    public function test_favorites_writes_redirect_html_requests_when_gate_enabled(): void
    {
        config(['auth.require_verified_for_favorites' => true]);
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->post('/favorites/toggle', $this->togglePayload(Restaurant::factory()->create()))
            ->assertRedirect(route('verification.notice', absolute: false));
    }

    public function test_verified_users_still_write_favorites_when_gate_enabled(): void
    {
        config(['auth.require_verified_for_favorites' => true]);

        $user = User::factory()->create();
        $restaurant = Restaurant::factory()->create();

        $this->actingAs($user)
            ->postJson('/favorites/toggle', $this->togglePayload($restaurant))
            ->assertOk();

        $this->assertTrue($user->favorites()->where('restaurant_id', $restaurant->id)->exists());
    }

    public function test_profile_writes_are_ungated_by_default(): void
    {
        $user = User::factory()->unverified()->create();

        $this->assertFalse(config('auth.require_verified_for_profile'));

        $this->actingAs($user)
            ->patch('/profile', ['name' => 'Renamed', 'email' => $user->email])
            ->assertRedirect('/profile');

        $this->assertSame('Renamed', $user->refresh()->name);
    }

    public function test_profile_writes_redirect_unverified_users_when_gate_enabled(): void
    {
        config(['auth.require_verified_for_profile' => true]);

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->patch('/profile', ['name' => 'Renamed', 'email' => $user->email])
            ->assertRedirect(route('verification.notice', absolute: false));

        $this->assertNotSame('Renamed', $user->refresh()->name);
    }

    public function test_profile_page_stays_readable_when_gate_enabled(): void
    {
        // Deliberate split: only writes are gated. A verified-gate on the GET
        // would strand an unverified user with no way to fix their account.
        config(['auth.require_verified_for_profile' => true]);

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/profile')->assertOk();
    }

    public function test_password_confirmation_flow_is_removed(): void
    {
        // spec-113: the Breeze confirm-password flow was unreachable dead code —
        // no route applied `password.confirm` (sensitive actions use
        // `current_password`), so the routes, controller and page were removed.
        $this->assertFalse(app('router')->getRoutes()->hasNamedRoute('password.confirm'));

        $this->get('/confirm-password')->assertNotFound();
        $this->post('/confirm-password', ['password' => 'password'])->assertNotFound();
    }
}
