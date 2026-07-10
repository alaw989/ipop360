<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class EngagementController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'restaurant_id' => 'required|integer',
            'action' => 'required|in:website,directions,call',
        ]);

        $actionMap = [
            'website' => 'website_click',
            'directions' => 'directions_click',
            'call' => 'call_click',
        ];

        \DB::table('restaurant_engagement')->insert([
            'restaurant_id' => $validated['restaurant_id'],
            'action_type' => $actionMap[$validated['action']],
            'user_id' => $request->user()?->id,
            'created_at' => now(),
        ]);

        return response()->noContent();
    }
}
