<?php

namespace App\Http\Controllers;

use App\Services\HomeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __construct(private HomeService $homeService) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('Welcome', $this->homeService->getHomepageData(null, null));
    }

    public function apiData(Request $request): JsonResponse
    {
        $data = $this->homeService->getHomepageData(
            $request->query('city'),
            $request->query('state'),
        );
        unset($data['latestPosts']);

        return response()->json($data);
    }

    /**
     * Every cuisine category with its cuisines, for the header search on
     * pages that don't already carry the list (the home page does). Public
     * and the same for everyone, so browsers may cache it for an hour.
     */
    public function categories(): JsonResponse
    {
        return response()->json($this->homeService->allCategories())
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
