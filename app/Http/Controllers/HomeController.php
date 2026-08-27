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
}
