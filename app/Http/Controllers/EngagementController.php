<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class EngagementController extends Controller
{
    private const ALLOWED_ACTIONS = [
        'website' => 'website_click',
        'directions' => 'directions_click',
        'call' => 'call_click',
        'pageview' => 'pageview',
        'social_link_click' => 'social_link_click',
        'menu' => 'menu_click',
    ];

    private const BOT_UA_PATTERNS = [
        '/bot/',
        '/crawler/',
        '/spider/',
        '/scraper/',
        '/curl/',
        '/wget/',
        '/python/',
        '/Go-http-client/',
        '/Headless/',
        '/PetalBot/',
        '/SemrushBot/',
        '/AhrefsBot/',
        '/DotBot/',
        '/Bytespider/',
    ];

    public function store(Request $request)
    {
        $validated = $request->validate([
            'restaurant_id' => 'required|integer|exists:restaurants,id',
            'action' => 'required|in:'.implode(',', array_keys(self::ALLOWED_ACTIONS)),
        ]);

        $actionType = self::ALLOWED_ACTIONS[$validated['action']];

        // Bot detection: reject known bots and crawlers
        $ua = $request->userAgent();
        if ($ua) {
            foreach (self::BOT_UA_PATTERNS as $pattern) {
                if (preg_match($pattern, $ua)) {
                    return response()->noContent();
                }
            }
        }

        // Dedup: same authenticated user within 60 seconds for this restaurant+action
        if ($userId = $request->user()?->id) {
            $recent = \DB::table('restaurant_engagement')
                ->where('restaurant_id', $validated['restaurant_id'])
                ->where('action_type', $actionType)
                ->where('user_id', $userId)
                ->where('created_at', '>=', now()->subSeconds(60))
                ->exists();

            if ($recent) {
                return response()->noContent();
            }
        }

        \DB::table('restaurant_engagement')->insert([
            'restaurant_id' => $validated['restaurant_id'],
            'action_type' => $actionType,
            'user_id' => $request->user()?->id,
            'created_at' => now(),
        ]);

        return response()->noContent();
    }
}
