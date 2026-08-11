<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BlogController extends Controller
{
    public function index(Request $request): Response
    {
        $query = BlogPost::published()
            ->with('author:id,name')
            ->latest('published_at');

        $category = $request->query('category');
        if (is_string($category) && $category !== '') {
            $query->whereRaw('LOWER(category) = ?', [mb_strtolower($category)]);
        }

        $posts = $query->paginate(12)->withQueryString();

        $categories = BlogPost::published()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->select('category', DB::raw('COUNT(*) as count'))
            ->groupBy('category')
            ->orderBy('count', 'desc')
            ->orderBy('category')
            ->pluck('category');

        return Inertia::render('Blog/Index', [
            'posts' => $posts,
            'categories' => $categories,
            'filters' => [
                'category' => $category ?: null,
            ],
        ]);
    }

    public function show(BlogPost $post): Response
    {
        if ($post->status !== 'published' || $post->published_at === null || $post->published_at->gt(now())) {
            abort(404);
        }

        return Inertia::render('Blog/Show', [
            'post' => $post->load('author:id,name'),
        ]);
    }
}
