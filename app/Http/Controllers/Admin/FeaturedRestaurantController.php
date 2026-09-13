<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeaturedRestaurant;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Admins pick the restaurant the home page spotlights, optionally with a
 * published blog story about it and a photo with its credit. A new pick ends the current one; stopping
 * returns the spotlight to the top-ranked restaurant near each visitor.
 */
class FeaturedRestaurantController extends Controller
{
    /** Active restaurants whose name contains the query, most-reviewed first. */
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        if (mb_strlen($query) < 2) {
            return response()->json([]);
        }

        $restaurants = Restaurant::active()
            ->where('name', 'like', '%'.addcslashes($query, '%_\\').'%')
            ->orderByDesc('google_review_count')
            ->limit(10)
            ->get(['id', 'name', 'city', 'state', 'google_rating', 'google_review_count']);

        return response()->json($restaurants);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'restaurant_id' => ['required', 'integer', Rule::exists('restaurants', 'id')->where('is_active', true)],
            'blog_post_id' => ['nullable', 'integer', Rule::exists('blog_posts', 'id')->where('status', 'published')],
            'image_url' => ['nullable', 'url:https', 'max:2048'],
            'image_credit' => ['nullable', 'string', 'max:255', 'required_with:image_url'],
            'ends_at' => ['nullable', 'date', 'after:now'],
        ]);

        DB::transaction(function () use ($data, $request): void {
            FeaturedRestaurant::endCurrent();
            FeaturedRestaurant::create([
                'restaurant_id' => $data['restaurant_id'],
                'blog_post_id' => $data['blog_post_id'] ?? null,
                'image_url' => $data['image_url'] ?? null,
                'image_credit' => $data['image_credit'] ?? null,
                'starts_at' => now(),
                'ends_at' => $data['ends_at'] ?? null,
                'created_by' => $request->user()?->id,
            ]);
        });

        return back()->with('success', 'The home page now features this restaurant.');
    }

    public function destroy(): RedirectResponse
    {
        FeaturedRestaurant::endCurrent();

        return back()->with('success', 'Stopped featuring it. The home page shows the top-ranked restaurant near each visitor.');
    }
}
