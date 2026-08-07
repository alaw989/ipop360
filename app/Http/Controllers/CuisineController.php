<?php

namespace App\Http\Controllers;

use App\Models\CuisineCategory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CuisineController extends Controller
{
    public function show(Request $request, CuisineCategory $category): Response
    {
        $category->load([
            'cuisines' => fn ($query) => $query->orderBy('sort_order'),
        ]);

        return Inertia::render('Cuisine/Subcategories', [
            'category' => $category,
            'coords' => $request->only(['lat', 'lng']),
        ]);
    }
}
